<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Media\AssetCache;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Read-model for the dashboard cockpit (Phase 7 first pass): a KPI strip, the
 * active-runs panel, and the awaiting-approval strip with render thumbnails —
 * all from REAL data now available (runs, jobs, renders, cache). Countdown and
 * growth deltas stay placeholders until Phase 10 wires the schedule/metrics.
 *
 * Every query is workspace-scoped (tenant isolation). Read-only — never writes.
 */
final class Cockpit
{
    public function __construct(
        private readonly Database $db,
        private readonly AssetCache $cache,
    ) {
    }

    /**
     * @return array{
     *   kpis: array{active: int, awaiting: int, completed: int, renders: int, cache_hits: int},
     *   activeRuns: list<array<string, mixed>>,
     *   awaiting: list<array<string, mixed>>
     * }
     */
    public function snapshot(WorkspaceContext $ctx): array
    {
        $ws = $ctx->id();

        return [
            'kpis' => $this->kpis($ws),
            'activeRuns' => $this->activeRuns($ws),
            'awaiting' => $this->awaiting($ws),
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

    /**
     * Jobs paused for approval, newest first, with the draft render thumbnail
     * (when present) pulled from the job's result_json.
     *
     * @return list<array<string, mixed>>
     */
    private function awaiting(int $ws): array
    {
        $rows = $this->db->all(
            "SELECT id, run_id, node, type, result_json
             FROM jobs
             WHERE workspace_id = ? AND status = 'awaiting_approval'
             ORDER BY id DESC LIMIT 6",
            [$ws],
        );

        return array_map(static function (array $row): array {
            $result = json_decode((string) $row['result_json'], true);
            $result = is_array($result) ? $result : [];

            return [
                'id' => (int) $row['id'],
                'run_id' => (int) $row['run_id'],
                'node' => (string) $row['node'],
                'type' => (string) $row['type'],
                'draft_render_id' => isset($result['draft_render_id']) ? (int) $result['draft_render_id'] : null,
                'ai_label_required' => (bool) ($result['ai_label_required'] ?? false),
            ];
        }, $rows);
    }
}
