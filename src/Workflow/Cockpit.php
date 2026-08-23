<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Media\AssetCache;
use Kuyash\Publish\AccountRepository;
use Kuyash\Usage\CreditLedger;
use Kuyash\Usage\UsageRepository;
use Kuyash\Workspace\WorkspaceContext;

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
    public function __construct(
        private readonly Database $db,
        private readonly AssetCache $cache,
        private readonly CreditLedger $ledger,
        private readonly UsageRepository $usage,
        private readonly AccountRepository $accountRepo,
        private readonly JobRepository $jobs,
    ) {
    }

    /**
     * @return array{
     *   kpis: array{active: int, awaiting: int, completed: int, renders: int, cache_hits: int},
     *   business: array{balance_cents: int, spent_mtd_cents: int, charges_mtd: int, granted_week_cents: int, budget_cap_cents: int|null, remaining_budget_cents: int|null, cost_per_content_cents: int|null, awaiting: int},
     *   pipeline: array{run_id: int, template: string, nodes: list<array{name: string, state: string}>}|null,
     *   activeRuns: list<array<string, mixed>>,
     *   awaiting: list<array<string, mixed>>,
     *   accounts: list<array<string, mixed>>,
     *   nextPublish: array{run_id: int, run_after: string}|null
     * }
     */
    public function snapshot(WorkspaceContext $ctx, string $now): array
    {
        $ws = $ctx->id();
        $kpis = $this->kpis($ws);

        return [
            'kpis' => $kpis,
            'business' => $this->business($ws, $now, $kpis['awaiting'], $kpis['renders']),
            'pipeline' => $this->pipeline($ws),
            'activeRuns' => $this->activeRuns($ws),
            'awaiting' => array_slice($this->jobs->awaitingApproval($ctx), 0, 4),
            'accounts' => array_slice($this->accountRepo->listFor($ctx, 6), 0, 4),
            // Phase 23: the soonest publish actually waiting in the queue. Read
            // from the real job gate, not from the slot plan — a slot is only a
            // template, this is a scheduled fact.
            'nextPublish' => $this->nextPublish($ws, $now),
        ];
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
             FROM runs r JOIN workflows w ON w.id = r.workflow_id
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
             FROM runs r JOIN workflows w ON w.id = r.workflow_id
             WHERE r.workspace_id = ? AND r.status IN ('running', 'awaiting_approval')
             ORDER BY r.updated_at DESC, r.id DESC
             LIMIT 8",
            [$ws],
        );
    }

}
