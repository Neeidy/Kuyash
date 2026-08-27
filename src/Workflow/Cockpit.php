<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Media\AssetCache;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\PlanBoard;
use Kuyash\Workspace\WorkspaceSettings;
use Kuyash\Usage\CreditLedger;
use Kuyash\Usage\UsageRepository;
use Kuyash\Workspace\WorkspaceContext;
use Throwable;

/**
 * Read-model for the dashboard cockpit. Phase 7 gave it the operational KPIs +
 * active-runs + awaiting strip; Phase 17 adds the business KPI strip (balance,
 * month-to-date spend, cost-per-content), the rich approval cards (reusing the
 * queue's awaitingApproval shape so the dashboard inline player has real draft
 * data) and a connected-accounts read.
 *
 * HONESTY: every number is real. cost-per-content is null (→ "—") when there are
 * no renders yet; the accounts read exposes ONLY stored fields (platform/handle/
 * health/reference) — there are no follower/engagement metrics to fabricate.
 *
 * Every query is workspace-scoped (tenant isolation). Read-only — never writes.
 */
final class Cockpit
{
    /** How many awaiting RUNS the dashboard shows before deferring to /queue. */
    private const AWAITING_RUNS = 4;

    public function __construct(
        private readonly Database $db,
        private readonly AssetCache $cache,
        private readonly CreditLedger $ledger,
        private readonly UsageRepository $usage,
        private readonly AccountRepository $accountRepo,
        private readonly JobRepository $jobs,
        // Phase 24 — optional so every existing construction site (and the tests
        // that build a Cockpit by hand) keeps working unchanged; a null board
        // simply means the plan line is not shown.
        private readonly ?PlanBoard $board = null,
        private readonly ?WorkspaceSettings $settings = null,
        private readonly ?\Kuyash\Media\AssetPoster $posters = null,
    ) {
    }

    /**
     * @return array{
     *   kpis: array{active: int, awaiting: int, completed: int, renders: int, cache_hits: int},
     *   business: array{balance_cents: int, spent_mtd_cents: int, charges_mtd: int, granted_week_cents: int, budget_cap_cents: int|null, remaining_budget_cents: int|null, cost_per_content_cents: int|null, awaiting: int},
     *   pipeline: array{run_id: int, template: string, nodes: list<array{name: string, state: string}>}|null,
     *   activeRuns: list<array<string, mixed>>,
     *   awaiting: list<array<string, mixed>>,
     *   awaitingShownRuns: int,
     *   planWeek: array{unavailable: true}|array<string, int>|null,
     *   accounts: list<array<string, mixed>>|null,
     *   nextPublish: array{run_id: int, run_after: string}|null
     * }
     */
    public function snapshot(WorkspaceContext $ctx, string $now): array
    {
        $ws = $ctx->id();
        $kpis = $this->kpis($ws);

        /* Slice by RUN, not by card.
           The badge, the KPI and the /live tick all count runs awaiting your
           approval, so the card's "and N more" has to be measured in runs too —
           and it can only be read correctly if what the card SHOWS is a whole
           number of runs. Cutting at four cards instead let one run's second
           open gate land inside the window and its first outside it, so four
           cards + "and four more" added up to the queue's card count and not to
           the badge. Whole runs in, whole runs out. */
        $awaitingRuns = [];
        $awaitingCards = [];
        foreach ($this->jobs->awaitingApproval($ctx) as $job) {
            $runId = (int) ($job['run_id'] ?? 0);
            if (!isset($awaitingRuns[$runId])) {
                if (count($awaitingRuns) >= self::AWAITING_RUNS) {
                    break;
                }
                $awaitingRuns[$runId] = true;
            }
            // same flag the queue resolves: the card asks before requesting
            // a poster, instead of emitting an <img> that 404s
            $job['has_poster'] = $this->posters !== null && $this->posters->existsForJob($job);
            $awaitingCards[] = $job;
        }

        return [
            'kpis' => $kpis,
            'business' => $this->business($ws, $now, $kpis['awaiting'], $kpis['renders']),
            'pipeline' => $this->pipeline($ws),
            'activeRuns' => $this->activeRuns($ws),
            'awaiting' => $awaitingCards,
            // how many runs the cards above account for — the subtrahend of the
            // card's "and N more", in the badge's own unit
            'awaitingShownRuns' => count($awaitingRuns),
            'accounts' => $this->accounts($ctx),
            // frames a SAMPLE account card may show; empty when there is no demo
            // content, which is why a real card can never accidentally get one
            'samplePosters' => $this->posters?->samplePool($this->db, $ws, \Kuyash\Demo\ShowcaseSeed::MARK) ?? [],
            // Phase 23: the soonest publish actually waiting in the queue. Read
            // from the real job gate, not from the slot plan — a slot is only a
            // template, this is a scheduled fact.
            'nextPublish' => $this->nextPublish($ws, $now),
            // Phase 24: a one-line read of the week's plan. Derive-only, and a
            // zero stays a zero — nothing here is invented to fill the line.
            'planWeek' => $this->planWeek($ctx, $ws, $now),
        ];
    }

    /**
     * The account cards, or null when they could not be read.
     *
     * Same guard, same reason, as planWeek() below: the accounts card is a side
     * card — the dashboard is fully useful without it — and `account_metrics`
     * is the second-newest table on this page, so it is the next one to go
     * missing on a database behind on its migrations, exactly as
     * `slot_occurrences` did.
     *
     * Null, NOT an empty list. An empty list is what "No accounts connected
     * yet" is rendered from, so a failed read returning one would tell an
     * operator with live channels that they have none — the same borrowed lie
     * the plan line was fixed for.
     *
     * @return list<array<string, mixed>>|null
     */
    private function accounts(WorkspaceContext $ctx): ?array
    {
        try {
            return array_slice($this->accountRepo->listFor($ctx, 6), 0, 4);
        } catch (Throwable $e) {
            error_log('Kuyash: dashboard accounts read failed for workspace ' . $ctx->id() . ' — ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The week's plan line, or null when there is none to show.
     *
     * GUARDED, for the same reason the worker guards its plan tick
     * (PlanRunner::tick): the plan is ONE line on this page, and it must never
     * be able to take the operator's main screen down with it — the KPIs, the
     * approvals waiting, the accounts and the balance all have nothing to do
     * with it. A database behind on its migrations did exactly that: /dashboard
     * answered 500 for every workspace that had a publishing time and stayed
     * fine for every workspace that had none, so the fault looked unrelated to
     * the plan and went unnoticed while the worker quietly logged it every
     * five minutes.
     *
     * Never zeros: a read that failed means we do not KNOW what the week holds,
     * and "0 planned" — above all "0 missed" — would state a number nobody
     * measured, which is the one thing this class refuses to do.
     *
     * But it does not return null either, and that distinction is the point.
     * Null is what a workspace with no plan board looks like, and the dashboard
     * renders THAT as "nothing planned — approved videos publish straight away".
     * Handing a failed read the same value would make the page say something
     * false to a workspace that does have a plan. So a failure is its own
     * third state, and the screen says the count is missing rather than zero.
     *
     * @return array{unavailable: true}|array<string, int>|null
     */
    private function planWeek(WorkspaceContext $ctx, int $workspaceId, string $now): ?array
    {
        if ($this->board === null || $this->settings === null) {
            return null;
        }

        try {
            return $this->board->summary($ctx, $this->settings->timezone($workspaceId), $now);
        } catch (Throwable $e) {
            error_log("Kuyash: dashboard plan summary failed for workspace {$workspaceId} — " . $e->getMessage());

            return ['unavailable' => true];
        }
    }

    /**
     * The earliest queued publish job still in the future, workspace-scoped.
     * Null when nothing is scheduled — the cockpit says so plainly rather than
     * implying a plan that does not exist.
     *
     * @return array{run_id: int, run_after: string}|null
     */
    private function nextPublish(int $workspaceId, string $now): ?array
    {
        $row = $this->db->one(
            "SELECT run_id, run_after FROM jobs
             WHERE workspace_id = ? AND type = 'publish' AND status = 'queued' AND run_after > ?
             ORDER BY run_after ASC, id ASC LIMIT 1",
            [$workspaceId, $now],
        );

        return $row === null
            ? null
            : ['run_id' => (int) $row['run_id'], 'run_after' => (string) $row['run_after']];
    }

    /**
     * The "production line" node-graph for the most-recently-active run: the
     * ordered nodes, each tagged with a display state derived from REAL job
     * status (done / active / wait / failed). Pure VISUALIZE — the engine stays
     * linear; this never changes a run. Null when nothing is in production.
     *
     * @return array{run_id: int, template: string, nodes: list<array{name: string, state: string, results: array<string, array<string, mixed>>}>}|null
     */
    private function pipeline(int $ws): ?array
    {
        $run = $this->db->one(
            "SELECT r.id, r.nodes_json, w.template
             FROM runs r JOIN workflows w ON w.id = r.workflow_id AND w.workspace_id = r.workspace_id
             WHERE r.workspace_id = ? AND r.status IN ('running', 'awaiting_approval')
             ORDER BY r.updated_at DESC, r.id DESC LIMIT 1",
            [$ws],
        );
        if ($run === null) {
            return null;
        }
        $nodes = json_decode((string) $run['nodes_json'], true);
        if (!is_array($nodes) || $nodes === []) {
            return null;
        }
        // status drives the node-graph state; result_json carries the REAL output
        // each stage produced, surfaced read-only in the node drawer (Phase 21 §4).
        $jobs = $this->db->all('SELECT node, status, type, result_json FROM jobs WHERE workspace_id = ? AND run_id = ?', [$ws, (int) $run['id']]);
        $byNode = [];
        $resultsByNode = [];
        foreach ($jobs as $j) {
            $node = (string) $j['node'];
            $byNode[$node][] = (string) $j['status'];
            $decoded = json_decode((string) ($j['result_json'] ?? ''), true);
            if (is_array($decoded) && $decoded !== []) {
                $resultsByNode[$node][(string) $j['type']] = $decoded;
            }
        }
        $out = [];
        foreach ($nodes as $n) {
            $name = (string) ($n['node'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'state' => $this->nodeGraphState($name, $byNode[$name] ?? []),
                'results' => $resultsByNode[$name] ?? [],
            ];
        }

        return ['run_id' => (int) $run['id'], 'template' => (string) $run['template'], 'nodes' => $out];
    }

    /**
     * Map a node's REAL job statuses to a graph state. Same precedence the run
     * timeline uses (failed > awaiting/active > done > waiting); collapsed to the
     * four states the node-graph draws.
     *
     * @param list<string> $statuses
     */
    private function nodeGraphState(string $node, array $statuses): string
    {
        if ($statuses === []) {
            return 'wait';
        }
        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }
        if (in_array('awaiting_approval', $statuses, true)
            || in_array('processing', $statuses, true)
            || in_array('queued', $statuses, true)) {
            return 'active';
        }
        if (in_array('cancelled', $statuses, true)) {
            return 'wait';
        }
        $expected = count(Nodes::NODE_JOBS[$node] ?? []);

        return count($statuses) >= max(1, $expected) ? 'done' : 'active';
    }

    /**
     * The tiny live snapshot the SSE endpoint (Phase 19) emits on each (very
     * short) connection — ONE workspace-scoped count query, read-only. Kept
     * minimal on purpose: it is polled every few seconds, so it must stay O(1).
     *
     * @return array{active: int, awaiting: int}
     */
    public function liveSnapshot(int $ws): array
    {
        $row = $this->db->one(
            "SELECT
                COALESCE(SUM(status IN ('running', 'awaiting_approval')), 0) AS active,
                COALESCE(SUM(status = 'awaiting_approval'), 0) AS awaiting
             FROM runs WHERE workspace_id = ?",
            [$ws],
        );

        return ['active' => (int) ($row['active'] ?? 0), 'awaiting' => (int) ($row['awaiting'] ?? 0)];
    }

    /**
     * Business KPI strip — all real, BYO-key budget model. The headline is
     * REMAINING BUDGET = monthly cap − month-to-date spend (null when no cap is
     * set → the UI says "no limit"); the cap is the same value Settings writes to
     * workspaces.budget_cap_cents and the Phase 11 PreflightGate enforces.
     * Month-to-date spend is the live usage ledger. cost-per-content is the
     * all-time average REAL spend per produced render — null (→ "no data") when
     * there is no recorded spend OR no render yet (never a divide-by-zero, never a
     * misleading "$0.00 per render"). balance_cents/granted_week_cents stay for the
     * Usage screen's credit-ledger view; the dashboard no longer surfaces them.
     *
     * @return array{balance_cents: int, spent_mtd_cents: int, charges_mtd: int, granted_week_cents: int, budget_cap_cents: int|null, remaining_budget_cents: int|null, cost_per_content_cents: int|null, awaiting: int}
     */
    private function business(int $ws, string $now, int $awaiting, int $renders): array
    {
        $weekAgo = gmdate('Y-m-d\TH:i:s\Z', (strtotime($now . ' -7 days')) ?: time());
        $grantedWeek = $this->db->one(
            "SELECT COALESCE(SUM(amount_cents), 0) AS c FROM credit_transactions
             WHERE workspace_id = ? AND type = 'grant' AND created_at >= ?",
            [$ws, $weekAgo],
        );
        $spentMtd = $this->usage->monthToDateSpendCents($ws, $now);
        // the budget cap Settings saved (NULL = no monthly limit); read straight
        // from the tenant row, the same column PreflightGate consults.
        $capRow = $this->db->one('SELECT budget_cap_cents FROM workspaces WHERE id = ?', [$ws]);
        $cap = isset($capRow['budget_cap_cents']) && $capRow['budget_cap_cents'] !== null
            ? (int) $capRow['budget_cap_cents'] : null;
        // all-time REAL spend (usage ledger) for the honest per-content average
        $spentAll = (int) ($this->db->one(
            'SELECT COALESCE(SUM(cost_cents), 0) AS c FROM usage_events WHERE workspace_id = ?',
            [$ws],
        )['c'] ?? 0);

        return [
            'balance_cents' => $this->ledger->balanceCents($ws),
            'spent_mtd_cents' => $spentMtd,
            'charges_mtd' => $this->usage->monthToDateEventCount($ws, $now),
            'granted_week_cents' => (int) ($grantedWeek['c'] ?? 0),
            'budget_cap_cents' => $cap,
            'remaining_budget_cents' => $cap === null ? null : max(0, $cap - $spentMtd),
            'cost_per_content_cents' => ($renders > 0 && $spentAll > 0) ? intdiv($spentAll, $renders) : null,
            'awaiting' => $awaiting,
        ];
    }

    /**
     * @return array{active: int, awaiting: int, completed: int, renders: int, cache_hits: int}
     */
    private function kpis(int $ws): array
    {
        $runs = $this->db->one(
            "SELECT
                COALESCE(SUM(status IN ('running', 'awaiting_approval')), 0) AS active,
                COALESCE(SUM(status = 'awaiting_approval'), 0) AS awaiting,
                COALESCE(SUM(status = 'completed'), 0) AS completed
             FROM runs WHERE workspace_id = ?",
            [$ws],
        );
        $renders = $this->db->one('SELECT COUNT(*) AS c FROM renders WHERE workspace_id = ?', [$ws]);

        return [
            'active' => (int) ($runs['active'] ?? 0),
            'awaiting' => (int) ($runs['awaiting'] ?? 0),
            'completed' => (int) ($runs['completed'] ?? 0),
            'renders' => (int) ($renders['c'] ?? 0),
            'cache_hits' => $this->cache->hitCountFor($ws),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function activeRuns(int $ws): array
    {
        return $this->db->all(
            "SELECT r.id, r.status, r.current_node, r.updated_at, w.name AS workflow_name, w.template
             FROM runs r JOIN workflows w ON w.id = r.workflow_id AND w.workspace_id = r.workspace_id
             WHERE r.workspace_id = ? AND r.status IN ('running', 'awaiting_approval')
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT 8",
            [$ws],
        );
    }

}
