<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Usage\BudgetExceededException;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceSettings;
use Throwable;

/**
 * The weekly plan's worker-side half (Phase 24). Runs from the worker loop on
 * the ordinary chore cadence — and, critically, ON WORKER STARTUP BEFORE the
 * first job is claimed (see bin/worker.php).
 *
 * Three things, in this order, per workspace:
 *   1. MATERIALIZE — fill the next two weeks of calendar cells, so automatic
 *      times work whether or not anybody opened the plan screen.
 *   2. SWEEP — close out cells whose time has passed beyond the grace window.
 *      This runs BEFORE the claim loop on purpose: a worker that was down for
 *      three days must cancel those stale publishes, not fire them all at once.
 *   3. PRODUCE — start content for automatic cells inside their lead window.
 *
 * TENANCY: the worker is sessionless (no WorkspaceContext). Workspaces are
 * iterated explicitly and their id is passed into every read and write — the
 * pattern DailySnapshot/Maintenance already use.
 *
 * COST: step 3 is the only part that can spend, and it spends nothing before
 * the guardrails below have all passed. Steps 1 and 2 are pure bookkeeping.
 *
 * PHASE 24 PROMISE: nothing here publishes anything, and nothing here approves
 * anything. It creates work that a human (or, in Auto mode, the compliance
 * agent at the render gate) still has to approve.
 */
final class PlanRunner
{
    /**
     * How late a planned time may still fire. Beyond this the cell is closed as
     * missed and its queued publish is cancelled: a post that is a day late is
     * not the post the operator planned, and publishing is irreversible.
     */
    public const GRACE_MINUTES = 60;

    public function __construct(
        private readonly Database $db,
        private readonly OccurrenceRepository $occurrences,
        private readonly OccurrenceMaterializer $materializer,
        private readonly SlotRepository $slots,
        private readonly WorkspaceSettings $settings,
        private readonly WorkflowRepository $workflows,
        private readonly AccountRepository $accounts,
        private readonly PublishCounter $counter,
        private readonly Engine $engine,
        private readonly EventLog $events,
    ) {
    }

    /**
     * @return array{materialized: int, swept: int, started: int}
     */
    public function tick(string $nowIso): array
    {
        $totals = ['materialized' => 0, 'swept' => 0, 'started' => 0];

        // Retention: cells long past their time are history nobody reads. Aged
        // out by their own instant, so this is not a tenant query and cannot
        // touch one workspace's rows using another's id.
        $this->occurrences->pruneBefore(
            self::shift($nowIso, -OccurrenceMaterializer::RETENTION_DAYS * 86400),
        );

        foreach ($this->db->all('SELECT id FROM workspaces ORDER BY id ASC') as $row) {
            $wsId = (int) $row['id'];
            try {
                $this->tickWorkspace($wsId, $nowIso, $totals);
            } catch (Throwable $e) {
                // One tenant's contention (or bad data) must not abort the plan
                // for every workspace after it in the loop.
                error_log("Kuyash: plan tick failed for workspace {$wsId} — " . $e->getMessage());
            }
        }

        return $totals;
    }

    /** @param array{materialized: int, swept: int, started: int} $totals */
    private function tickWorkspace(int $wsId, string $nowIso, array &$totals): void
    {
        {
            $slots = $this->slots->listForWorkspace($wsId);
            if ($slots === []) {
                // No plan: nothing to materialize. Still sweep — a workspace can
                // delete its times while a planned post is still queued.
                $totals['swept'] += $this->sweep($wsId, $nowIso);

                return;
            }

            $counts = $this->materializer->materialize(
                $wsId,
                $this->settings->timezone($wsId),
                $slots,
                $nowIso,
            );
            $totals['materialized'] += $counts['created'];
            $totals['swept'] += $this->sweep($wsId, $nowIso);
            $totals['started'] += $this->produce($wsId, $nowIso);
        }
    }

    // ── 2. sweep ────────────────────────────────────────────────────────────

    /**
     * Close out every cell whose time has passed by more than the grace window
     * and that has not published. Each one is recorded with a REAL reason —
     * never a silent gap on the calendar.
     */
    private function sweep(int $workspaceId, string $nowIso): int
    {
        $cutoff = self::shift($nowIso, -self::GRACE_MINUTES * 60);
        $closed = 0;

        foreach ($this->occurrences->overdue($workspaceId, $cutoff) as $cell) {
            $reason = $this->closeReason($workspaceId, $cell);
            $runId = $cell['run_id'] === null ? null : (int) $cell['run_id'];

            if ($runId !== null) {
                // A run parked at an approval gate is LEFT ALIVE — the operator's
                // work is not thrown away; they can still approve it and choose a
                // new time. Only its stale schedule is cleared, so approving later
                // cannot silently fall through to "publish now".
                if ($reason === 'not_approved') {
                    $this->engine->setPublishAfter($workspaceId, $runId, null);
                } else {
                    // Anything else means a publish was queued and must not fire
                    // late. Cancelling is safe here: a queued publish job has not
                    // reached the provider yet.
                    $this->engine->cancelRun($workspaceId, $runId, null, 'plan.' . $reason);
                }
            }

            if ($this->occurrences->markSkipped($workspaceId, (int) $cell['id'], $reason, $nowIso)) {
                $closed++;
                $this->events->record($workspaceId, 'warn', 'guardrail', 'plan.slot_missed', [
                    'reason' => $reason,
                    'when' => (string) $cell['publish_at'],
                ], $runId);
            }
        }

        return $closed;
    }

    /**
     * Why did this cell produce nothing? Read from the real job state, so the
     * calendar reports what happened rather than what was assumed.
     *
     * @param array<string, mixed> $cell
     */
    private function closeReason(int $workspaceId, array $cell): string
    {
        if ($cell['run_id'] === null) {
            // produce() may already have recorded WHY it never started; that is
            // more specific than anything we could infer now.
            $noted = (string) ($cell['skip_reason'] ?? '');
            if ($noted !== '') {
                return $noted;
            }

            return (string) $cell['mode'] === 'auto' ? 'not_produced' : 'no_content';
        }

        $runId = (int) $cell['run_id'];
        $awaiting = $this->db->one(
            "SELECT id FROM jobs WHERE run_id = ? AND workspace_id = ? AND status = 'awaiting_approval' LIMIT 1",
            [$runId, $workspaceId],
        );
        if ($awaiting !== null) {
            return 'not_approved';
        }

        $run = $this->db->one('SELECT status FROM runs WHERE id = ? AND workspace_id = ?', [$runId, $workspaceId]);
        if ($run !== null && (string) $run['status'] === 'cancelled') {
            // The compliance agent cancels a run when a check BLOCKS it.
            $blocked = $this->db->one(
                "SELECT id FROM events WHERE run_id = ? AND workspace_id = ? AND kind = 'compliance'
                   AND key LIKE '%block%' LIMIT 1",
                [$runId, $workspaceId],
            );

            return $blocked !== null ? 'compliance_block' : 'cancelled';
        }

        $publish = $this->db->one(
            "SELECT status, error_message FROM jobs
             WHERE run_id = ? AND workspace_id = ? AND type = 'publish' ORDER BY id DESC LIMIT 1",
            [$runId, $workspaceId],
        );
        if ($publish !== null
            && (string) $publish['status'] === 'queued'
            && str_contains((string) ($publish['error_message'] ?? ''), 'daily_cap')
        ) {
            // The publish gate deferred it to the next UTC midnight — which is
            // not the time that was planned, so the plan closes it honestly.
            return 'daily_cap';
        }

        return 'missed';
    }

    // ── 3. produce ──────────────────────────────────────────────────────────

    /**
     * Start content for automatic cells inside their lead window.
     *
     * Every guardrail below is checked BEFORE a single row is written, and the
     * first one that trips ends the attempt with a reason on the calendar.
     */
    private function produce(int $workspaceId, string $nowIso): int
    {
        $plan = $this->settings->plan($workspaceId);
        $horizon = self::shift($nowIso, $plan['auto_lead_minutes'] * 60);
        $due = $this->occurrences->dueAuto($workspaceId, $nowIso, $horizon);
        if ($due === []) {
            return 0;
        }

        $compliance = $this->settings->compliance($workspaceId);

        // A blocked cell is NOTED, not closed. Every block below can clear on
        // its own before the time arrives — a cap resets, a switch goes back on —
        // so declaring the cell missed now would be a lie. It stays open with an
        // honest reason; if the time really does pass, the sweep closes it.

        // 1 + 2. Production paused, by the plan's own switch or the kill switch.
        if ($plan['plan_paused']) {
            return $this->noteAll($workspaceId, $due, 'plan_paused', $nowIso);
        }
        if ($compliance['kill_switch']) {
            return $this->noteAll($workspaceId, $due, 'kill_switch', $nowIso);
        }

        // 3. Every connected account already at its daily cap → producing would
        // create something that cannot go out. Cheaper and more honest to wait.
        $targets = $this->accounts->connectedFor($workspaceId);
        if ($targets === []) {
            return $this->noteAll($workspaceId, $due, 'no_account', $nowIso);
        }
        $atCap = true;
        foreach ($targets as $account) {
            if ($this->counter->publishedToday($workspaceId, $nowIso, (int) $account['id']) < $compliance['daily_post_cap']) {
                $atCap = false;
                break;
            }
        }
        if ($atCap) {
            return $this->noteAll($workspaceId, $due, 'daily_cap', $nowIso);
        }

        // 4. runs.created_by is NOT NULL: an automatic run is attributed to the
        // workspace owner, never to a fabricated or borrowed user.
        $owner = $this->db->one(
            "SELECT user_id FROM workspace_users WHERE workspace_id = ? AND role = 'owner' ORDER BY id ASC LIMIT 1",
            [$workspaceId],
        );
        if ($owner === null) {
            return $this->noteAll($workspaceId, $due, 'no_owner', $nowIso);
        }
        $ownerId = (int) $owner['user_id'];

        // 5. The full pipeline is what an automatic time produces.
        $workflow = $this->workflows->findByTemplateFor($workspaceId, 'full');
        if ($workflow === null) {
            return $this->noteAll($workspaceId, $due, 'no_workflow', $nowIso);
        }

        $started = 0;
        foreach ($due as $cell) {
            $id = (int) $cell['id'];
            // Guarded take: two chore ticks (or two workers) can only win once.
            // reserve() also clears any previously noted block.
            if (!$this->occurrences->reserve($workspaceId, $id, null, $nowIso)) {
                continue;
            }

            try {
                // 6. The budget gate lives INSIDE startRunFor and throws before
                // any row is created — no half-started run, no spend.
                $runId = $this->engine->startRunFor($workspaceId, (int) $workflow['id'], null, $ownerId);
            } catch (BudgetExceededException) {
                $this->occurrences->release($workspaceId, $id, $nowIso);
                if ($this->occurrences->noteBlocked($workspaceId, $id, 'budget_cap', $nowIso)) {
                    $this->events->record($workspaceId, 'warn', 'guardrail', 'plan.slot_blocked', [
                        'reason' => 'budget_cap',
                        'when' => (string) $cell['publish_at'],
                    ]);
                }
                continue;
            } catch (WorkflowException $e) {
                $this->occurrences->release($workspaceId, $id, $nowIso);
                $this->events->record($workspaceId, 'error', 'guardrail', 'plan.produce_failed', [
                    'reason' => $e->getMessage(),
                    'when' => (string) $cell['publish_at'],
                ]);
                continue;
            }

            // The planned instant is written at BIRTH, not at approval: approve()
            // only writes a time when one is named, and the compliance agent's
            // auto-approval never goes through approve() at all. Without this an
            // auto-approved planned post would ignore its slot and go out now.
            $this->engine->setPublishAfter($workspaceId, $runId, (string) $cell['publish_at']);
            $this->occurrences->attachRun($workspaceId, $id, $runId, $nowIso);
            $started++;

            $this->events->record($workspaceId, 'info', 'transition', 'plan.content_started', [
                'when' => (string) $cell['publish_at'],
            ], $runId);
        }

        return $started;
    }

    /**
     * Note the same block on every due cell, auditing it only when it is new.
     *
     * @param list<array<string, mixed>> $cells
     *
     * @return int always 0 — nothing was produced
     */
    private function noteAll(int $workspaceId, array $cells, string $reason, string $nowIso): int
    {
        foreach ($cells as $cell) {
            if ($this->occurrences->noteBlocked($workspaceId, (int) $cell['id'], $reason, $nowIso)) {
                $this->events->record($workspaceId, 'warn', 'guardrail', 'plan.slot_blocked', [
                    'reason' => $reason,
                    'when' => (string) $cell['publish_at'],
                ]);
            }
        }

        return 0;
    }

    private static function shift(string $iso, int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($iso) + $seconds);
    }
}
