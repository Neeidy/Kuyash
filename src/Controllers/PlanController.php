<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\RateLimiter;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Library\AssetRepository;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\OccurrenceMaterializer;
use Kuyash\Publish\OccurrenceRepository;
use Kuyash\Publish\PlanBoard;
use Kuyash\Publish\PostRepository;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
use Kuyash\Usage\CostEstimator;
use Kuyash\Usage\BudgetExceededException;
use Kuyash\Workflow\Decision;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\Nodes;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * The weekly publishing plan — a CALENDAR of dated cells (Phase 24), on top of
 * the weekly time templates Phase 23 introduced.
 *
 * TWO MODES, chosen per publishing time:
 *   • manual — you put one of your own videos on a specific day;
 *   • automatic — Kuyash produces one ahead of the time, into the approval queue.
 *
 * ONE PROMISE, both modes: nothing publishes without an approval. Assigning a
 * video starts the work; it still stops at the approval gate, and the planned
 * instant travels the path that already existed (runs.publish_after → the
 * queue's run_after gate → the adapter's scheduledFor).
 */
final class PlanController
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly View $view,
        private readonly SlotRepository $slots,
        private readonly SlotResolver $resolver,
        private readonly WorkspaceSettings $settings,
        private readonly PostRepository $posts,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly OccurrenceRepository $occurrences,
        private readonly OccurrenceMaterializer $materializer,
        private readonly PlanBoard $board,
        private readonly AssetRepository $assets,
        private readonly WorkflowRepository $workflows,
        private readonly Engine $engine,
        private readonly EventLog $events,
        private readonly Auth $auth,
        private readonly AccountRepository $accounts,
        // Optional, like the webhook's: a null limiter simply does not throttle,
        // which keeps every test construction unchanged. These routes decide WHEN
        // content reaches live accounts, so they get the same treatment as the
        // other state-changing surfaces (a Phase 23 follow-up).
        private readonly ?RateLimiter $rateLimiter = null,
        // Optional: without it the cost line is simply not shown, rather than
        // shown with a made-up number.
        private readonly ?CostEstimator $estimator = null,
    ) {
    }

    /** Per-IP throttle bucket for every plan mutation. */
    private const RATE_BUCKET = 'plan_write';

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $now = gmdate(self::ISO);
        // resolved once, not per slot: each call re-queries and re-scans tzdata
        $zone = $this->settings->timezone($wsId);
        $slots = $this->slots->listFor($this->workspace);

        // Keep the calendar filled even if the worker has never run. Idempotent
        // by (time, day) behind a UNIQUE index, so a page view cannot duplicate.
        if ($slots !== []) {
            $this->materializer->materialize($wsId, $zone, $slots, $now);
        }

        $plan = $this->settings->plan($wsId);
        $committed = $this->occurrences->committedCountsBySlot($this->workspace);

        return Response::html($this->view->render('plan/index', [
            'title' => 'Weekly plan — Kuyash',
            'active' => 'plan',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'timezone' => $zone,
            'timezones' => timezone_identifiers_list(),
            'planPaused' => $plan['plan_paused'],
            // In Auto mode the human gate is the script, and the compliance agent
            // approves the finished render. Promising "until YOU approve it"
            // there would overstate the operator's involvement.
            'autoApproves' => $this->settings->compliance($wsId)['approval_mode'] === 'auto',
            'leadMinutes' => $plan['auto_lead_minutes'],
            'hasAutoSlot' => array_reduce($slots, static fn (bool $c, array $s): bool => $c || $s['mode'] === 'auto', false),
            // What automatic mode will actually cost, from the same estimator the
            // budget gate uses. Null when it cannot be worked out — an operator
            // turning on automatic publishing is told the price or told nothing,
            // never a guess.
            'autoCost' => $this->autoCost($slots),
            // the per-video price shown BESIDE the "Kuyash makes one" choice, so
            // the cost is known before the commitment rather than after it
            'perVideoCost' => $this->perVideoCost(),
            'slots' => array_map(function (array $slot) use ($zone, $now, $committed): array {
                // how many videos this time is holding — the remove button is
                // labelled from this, so it can never describe a smaller action
                // than the one it performs
                $slot['committed'] = $committed[(int) $slot['id']] ?? 0;
                $slot['next_at'] = $this->resolver->nextOccurrence(
                    $zone,
                    (int) $slot['weekday'],
                    (string) $slot['time_hhmm'],
                    $now,
                );

                return $slot;
            }, $slots),
            'days' => $this->board->calendar($this->workspace, $zone, $now),
            // videos that can be put on a day right now
            'library' => $this->assets->readyVideosFor($this->workspace),
            // A plan with no connected channel publishes nowhere. Saying so here
            // beats letting the operator find out as a red "Missed" afterwards.
            'hasAccounts' => $this->accounts->connectedFor($wsId) !== [],
            // what is ACTUALLY queued — a plan is not a promise that something
            // is scheduled, so the two are shown separately
            'nextScheduled' => $this->posts->nextScheduled($this->workspace, $now),
        ], 'layout/app'));
    }

    // ── content ↔ day ───────────────────────────────────────────────────────

    /**
     * Put one of your own videos on a specific day.
     *
     * The work starts immediately — captions and hashtags are written now, and
     * the result waits in the approval queue until you approve it. The planned
     * instant is written onto the run AT BIRTH, so approving without naming a
     * time keeps the day you chose instead of silently publishing at once.
     *
     * @param array<string, string> $params
     */
    public function assign(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $id = $params['id'] ?? '';
        $assetRaw = trim((string) ($_POST['asset_id'] ?? ''));
        if (!ctype_digit($id) || !ctype_digit($assetRaw)) {
            return $this->back('error', 'plan.assign_invalid');
        }

        $wsId = $this->workspace->id();
        $now = gmdate(self::ISO);

        $cell = $this->occurrences->find($this->workspace, (int) $id);
        if ($cell === null) {
            return $this->back('error', 'plan.cell_not_found');
        }
        if ((string) $cell['mode'] !== 'manual') {
            return $this->back('error', 'plan.assign_auto');
        }
        if ((string) $cell['status'] !== 'open' || $cell['run_id'] !== null) {
            return $this->back('error', 'plan.cell_taken');
        }
        if ((string) $cell['publish_at'] <= $now) {
            return $this->back('error', 'plan.cell_past');
        }

        // tenant-scoped, and it must be a video that is actually usable
        $asset = $this->assets->find($this->workspace, (int) $assetRaw);
        if ($asset === null || (string) $asset['kind'] !== 'video' || (string) $asset['status'] !== 'ready') {
            return $this->back('error', 'plan.asset_not_ready');
        }

        $workflow = $this->workflows->findByTemplate($this->workspace, 'distribution');
        if ($workflow === null) {
            return $this->back('error', 'plan.no_workflow');
        }

        // Writing a caption costs real money, and the publish gate would refuse
        // to send the result while the kill switch is on. Say so before spending.
        if ($this->settings->compliance($wsId)['kill_switch']) {
            return $this->back('error', 'plan.kill_switch_on');
        }

        // Take the cell FIRST: a double-submitted form can then only win once.
        if (!$this->occurrences->reserve($wsId, (int) $cell['id'], (int) $asset['id'], $now)) {
            return $this->back('error', 'plan.cell_taken');
        }

        try {
            $runId = $this->engine->startRun($this->workspace, (int) $workflow['id'], (int) $asset['id'], $this->userId());
        } catch (BudgetExceededException) {
            $this->occurrences->release($wsId, (int) $cell['id'], $now);

            return $this->back('error', 'run.budget_exceeded');
        } catch (WorkflowException $e) {
            $this->occurrences->release($wsId, (int) $cell['id'], $now);

            return $this->back('error', $e->getMessage());
        }

        $this->engine->setPublishAfter($wsId, $runId, (string) $cell['publish_at']);
        $this->occurrences->attachRun($wsId, (int) $cell['id'], $runId, $now);
        $this->events->record($wsId, 'info', 'transition', 'plan.assigned', [
            'when' => (string) $cell['publish_at'],
            'user' => $this->userEmail(),
        ], $runId);

        return $this->back('success', 'plan.assigned');
    }

    /**
     * Take the content back off a day.
     *
     * Safe while the publish is still waiting its turn: nothing has been sent to
     * the platform yet, so cancelling really does mean nothing goes out. Once it
     * is being published this refuses rather than claiming an un-publish it
     * cannot perform.
     *
     * @param array<string, string> $params
     */
    /** Is this run finished for good — nothing running, nothing left to stop? */
    private function runIsOver(int $workspaceId, int $runId): bool
    {
        $run = $this->occurrences->runStatus($workspaceId, $runId);

        return $run !== null && in_array($run, Nodes::RUN_TERMINAL, true);
    }

    public function unassign(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->back('error', 'plan.cell_not_found');
        }
        $wsId = $this->workspace->id();
        $now = gmdate(self::ISO);

        $cell = $this->occurrences->find($this->workspace, (int) $id);
        if ($cell === null) {
            return $this->back('error', 'plan.cell_not_found');
        }
        if ((string) $cell['status'] !== 'assigned') {
            return $this->back('error', 'plan.nothing_to_clear');
        }

        if ($cell['run_id'] !== null) {
            $decision = $this->engine->cancelRun($wsId, (int) $cell['run_id'], $this->userEmail(), 'plan.cleared');
            // "Already decided" covers two very different situations. A run that
            // is PUBLISHING is genuinely past the point of no return and the day
            // must stay as it is. A run that is already over — cancelled by
            // compliance, say, or failed — has nothing left to cancel, and
            // refusing there left the day occupied by a dead run FOREVER: the
            // operator could not clear it and could not reassign that date.
            if ($decision !== Decision::Ok && !$this->runIsOver($wsId, (int) $cell['run_id'])) {
                return $this->back('error', 'plan.too_late');
            }
        }

        $this->occurrences->release($wsId, (int) $cell['id'], $now);
        $this->events->record($wsId, 'info', 'transition', 'plan.cleared', [
            'when' => (string) $cell['publish_at'],
            'user' => $this->userEmail(),
        ]);

        return $this->back('success', 'plan.cleared');
    }

    // ── plan settings ───────────────────────────────────────────────────────

    /**
     * The timezone the plan is written in. Scheduling stays UTC throughout; this
     * decides what "Mon 09:00" means and keeps that wall-clock time across
     * daylight-saving shifts.
     *
     * @param array<string, string> $params
     */
    public function saveTimezone(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $wsId = $this->workspace->id();
        if (!$this->settings->setTimezone($wsId, (string) ($_POST['timezone'] ?? ''))) {
            return $this->back('error', 'slots.timezone_invalid');
        }
        $this->audit('guardrail.plan_timezone', ['zone' => (string) ($_POST['timezone'] ?? '')]);

        // empty days are re-timed to the new zone right away; days that already
        // carry content keep their instant (they are a commitment) and are
        // listed on the screen instead
        $slots = $this->slots->listFor($this->workspace);
        if ($slots !== []) {
            $this->materializer->materialize($wsId, $this->settings->timezone($wsId), $slots, gmdate(self::ISO));
        }

        return $this->back('success', 'slots.timezone_saved');
    }

    /** @param array<string, string> $params */
    public function savePlanSettings(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $wsId = $this->workspace->id();
        $lead = trim((string) ($_POST['lead_minutes'] ?? ''));
        if (!ctype_digit($lead) || !$this->settings->setAutoLeadMinutes($wsId, (int) $lead)) {
            return $this->back('error', 'plan.lead_invalid');
        }
        $this->audit('guardrail.plan_lead', ['minutes' => (int) $lead]);

        return $this->back('success', 'plan.settings_saved');
    }

    /**
     * Pause automatic production. Deliberately narrower than the kill switch:
     * posts a human already approved keep their time, and the screen says so.
     *
     * @param array<string, string> $params
     */
    public function togglePause(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $wsId = $this->workspace->id();
        $paused = !$this->settings->plan($wsId)['plan_paused'];
        $this->settings->setPlanPaused($wsId, $paused);
        $this->audit($paused ? 'guardrail.plan_paused' : 'guardrail.plan_resumed', []);

        return $this->back('success', $paused ? 'plan.paused' : 'plan.resumed');
    }

    // ── publishing times ────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    public function addSlot(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $weekday = (string) ($_POST['weekday'] ?? '');
        $time = trim((string) ($_POST['time_hhmm'] ?? ''));
        $mode = (string) ($_POST['mode'] ?? 'manual');

        // A publishing time is workspace-wide in this phase. The schema keeps an
        // account column for per-channel plans later, but nothing reads it yet —
        // so the UI offers no control that would silently do nothing, and a
        // narrowing value posted by hand is REJECTED rather than quietly widened.
        if (array_key_exists('account_id', $_POST) && trim((string) $_POST['account_id']) !== '') {
            return $this->back('error', 'slots.invalid');
        }
        if (!ctype_digit($weekday)) {
            return $this->back('error', 'slots.invalid');
        }

        $id = $this->slots->add($this->workspace, (int) $weekday, $time, null, gmdate(self::ISO), $mode);
        if ($id === null) {
            // a duplicate is not the same mistake as bad input — say which
            return $this->back('error', match ($this->slots->lastAddFailure()) {
                'duplicate' => 'slots.duplicate',
                'too_many' => 'slots.too_many',
                default => 'slots.invalid',
            });
        }
        $this->audit('guardrail.plan_time_added', ['weekday' => (int) $weekday, 'time' => $time, 'mode' => $mode]);
        $this->rematerialize();

        return $this->back('success', 'slots.added');
    }

    /** @param array<string, string> $params */
    public function removeSlot(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->back('error', 'slots.not_found');
        }
        $now = gmdate(self::ISO);
        $wsId = $this->workspace->id();

        // Days that already carry content are a commitment: removing the time
        // cancels them, so it is a decision the operator confirms, not a click.
        $committed = $this->occurrences->committedForSlot($this->workspace, (int) $id);
        if ($committed !== [] && trim((string) ($_POST['cascade'] ?? '')) !== '1') {
            return $this->back('error', 'plan.remove_needs_confirm');
        }
        foreach ($committed as $cell) {
            if ($cell['run_id'] !== null
                && $this->engine->cancelRun($wsId, (int) $cell['run_id'], $this->userEmail(), 'plan.time_removed') !== Decision::Ok
            ) {
                // already publishing — refuse the whole removal rather than
                // deleting the time out from under a post that is going live
                return $this->back('error', 'plan.too_late');
            }
            $this->occurrences->markSkipped($wsId, (int) $cell['id'], 'cancelled', $now);
        }
        // detach the cancelled runs so the time itself can now be removed
        $this->occurrences->detachRunsForSlot($wsId, (int) $id, $now);

        if (!$this->slots->remove($this->workspace, (int) $id)) {
            return $this->back('error', 'slots.not_found');
        }
        $this->audit('guardrail.plan_time_removed', ['cancelled' => count($committed)]);

        return $this->back('success', 'slots.removed');
    }

    /**
     * Pause/resume a single publishing time. A paused time stops producing new
     * days; days that already carry content keep going, and the screen says so.
     *
     * @param array<string, string> $params
     */
    public function toggleSlot(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->back('error', 'slots.not_found');
        }
        $slot = $this->slots->find($this->workspace, (int) $id);
        if ($slot === null) {
            return $this->back('error', 'slots.not_found');
        }
        $this->slots->setEnabled($this->workspace, (int) $id, !$slot['enabled'], gmdate(self::ISO));
        $this->audit('guardrail.plan_time_toggled', ['enabled' => !$slot['enabled']]);

        return $this->back('success', $slot['enabled'] ? 'slots.paused' : 'slots.resumed');
    }

    /** @param array<string, string> $params */
    public function setSlotMode(array $params = []): Response
    {
        if ($this->throttled()) {
            return $this->back('error', 'rate.limited');
        }
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->back('error', 'slots.not_found');
        }
        $slot = $this->slots->find($this->workspace, (int) $id);
        if ($slot === null) {
            return $this->back('error', 'slots.not_found');
        }
        $mode = $slot['mode'] === 'auto' ? 'manual' : 'auto';
        if (!$this->slots->setMode($this->workspace, (int) $id, $mode, gmdate(self::ISO))) {
            return $this->back('error', 'slots.invalid');
        }
        $this->audit('guardrail.plan_mode_changed', ['mode' => $mode]);
        // empty days follow the time's new mode; days already carrying content
        // keep the mode they were created with
        $this->rematerialize();

        return $this->back('success', $mode === 'auto' ? 'plan.mode_auto' : 'plan.mode_manual');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Per-video and per-week cost of the automatic times, in cents.
     *
     * Uses CostEstimator over the workspace's own `full` workflow — the same
     * numbers the pre-flight budget gate refuses runs with, so the screen and the
     * guardrail can never disagree.
     *
     * @param list<array<string, mixed>> $slots
     *
     * @return array{per_video: int, per_week: int, count: int}|null
     */
    private function autoCost(array $slots): ?array
    {
        $autoCount = count(array_filter($slots, static fn (array $s): bool => $s['mode'] === 'auto' && $s['enabled']));
        if ($autoCount === 0) {
            return null;
        }
        $perVideo = $this->perVideoCost();
        if ($perVideo === null) {
            return null;
        }

        return ['per_video' => $perVideo, 'per_week' => $perVideo * $autoCount, 'count' => $autoCount];
    }

    /** What one Kuyash-made video is estimated to cost, in cents; null if unknown. */
    private function perVideoCost(): ?int
    {
        if ($this->estimator === null) {
            return null;
        }
        $workflow = $this->workflows->findByTemplate($this->workspace, 'full');
        if ($workflow === null) {
            return null;
        }

        return (int) $this->estimator->estimateRun('full', (array) ($workflow['nodes'] ?? []))['total_cents'];
    }

    /**
     * True when this IP has changed the plan too often. Authenticated, so the
     * blast radius is self-inflicted — but a stuck script should not be able to
     * churn the calendar, and the limiter is already in the codebase.
     */
    private function throttled(): bool
    {
        return $this->rateLimiter !== null
            && $this->rateLimiter->tooMany(self::RATE_BUCKET, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    private function rematerialize(): void
    {
        $wsId = $this->workspace->id();
        $slots = $this->slots->listFor($this->workspace);
        if ($slots !== []) {
            $this->materializer->materialize($wsId, $this->settings->timezone($wsId), $slots, gmdate(self::ISO));
        }
    }

    /**
     * Plan changes decide WHEN content reaches live accounts, so they are
     * audited like the other guardrails (Phase 23 left this open).
     *
     * @param array<string, mixed> $params
     */
    private function audit(string $key, array $params): void
    {
        $params['user'] = $this->userEmail();
        $this->events->record($this->workspace->id(), 'info', 'guardrail', $key, $params);
    }

    private function userId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    private function userEmail(): string
    {
        return (string) ($this->auth->user()['email'] ?? 'unknown');
    }

    private function back(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/plan', 303);
    }
}
