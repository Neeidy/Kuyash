<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * The daily digest read-model (derive-only, in-app — no email, no new table):
 * everything the agent did autonomously on one UTC date, so a human can audit
 * a day at a glance. Web-facing: takes WorkspaceContext (tenant isolation).
 */
final class DigestReport
{
    public function __construct(
        private readonly Database $db,
        private readonly QualityScore $quality,
    ) {
    }

    /**
     * @param string $date validated 'YYYY-MM-DD'
     *
     * @return array{
     *   date: string,
     *   auto_approved: list<array<string, mixed>>,
     *   auto_published: list<array<string, mixed>>,
     *   guardrail_events: list<array<string, mixed>>,
     *   quality: array<string, mixed>,
     *   approval_mode: string,
     *   kill_switch: bool,
     *   fell_back_to_manual: bool
     * }
     */
    public function forDate(WorkspaceContext $ctx, string $date): array
    {
        $wsId = $ctx->id();
        $dayStart = $date . 'T00:00:00Z';
        $nextDay = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($dayStart) + 86400);

        $autoApproved = $this->db->all(
            "SELECT a.run_id, a.node, a.decided_at, a.policy_version, a.score_json
             FROM approvals a
             WHERE a.workspace_id = ? AND a.mode = 'auto' AND a.decision = 'approved'
               AND a.decided_at >= ? AND a.decided_at < ?
             ORDER BY a.id ASC",
            [$wsId, $dayStart, $nextDay],
        );

        // latest render per auto-approved run for the thumbnail strip
        $renders = $this->latestRenders($wsId, array_map(
            static fn (array $row): int => (int) $row['run_id'],
            $autoApproved,
        ));
        foreach ($autoApproved as &$row) {
            $row['run_id'] = (int) $row['run_id'];
            $row['render'] = $renders[$row['run_id']] ?? null;
            $score = json_decode((string) ($row['score_json'] ?? ''), true);
            $row['score'] = is_array($score) ? $score : null;
        }
        unset($row);

        $autoPublished = $this->db->all(
            "SELECT j.id, j.run_id, j.finished_at, j.result_json
             FROM jobs j
             WHERE j.workspace_id = ? AND j.type = 'publish' AND j.status = 'published'
               AND j.finished_at >= ? AND j.finished_at < ?
               AND j.run_id IN (
                   SELECT run_id FROM approvals
                   WHERE workspace_id = ? AND mode = 'auto' AND decision = 'approved'
               )
             ORDER BY j.id ASC",
            [$wsId, $dayStart, $nextDay, $wsId],
        );
        foreach ($autoPublished as &$row) {
            $row['id'] = (int) $row['id'];
            $row['run_id'] = (int) $row['run_id'];
            $result = json_decode((string) ($row['result_json'] ?? ''), true);
            $row['result'] = is_array($result) ? $result : [];
        }
        unset($row);

        $guardrailEvents = $this->db->all(
            "SELECT * FROM events
             WHERE workspace_id = ? AND kind = 'guardrail'
               AND created_at >= ? AND created_at < ?
             ORDER BY id ASC",
            [$wsId, $dayStart, $nextDay],
        );
        $fellBack = false;
        foreach ($guardrailEvents as &$event) {
            $params = json_decode((string) $event['params_json'], true);
            $event['params'] = is_array($params) ? $params : [];
            if ($event['key'] === 'guardrail.fallback_to_manual') {
                $fellBack = true;
            }
        }
        unset($event);

        $ws = $this->db->one(
            'SELECT approval_mode, kill_switch FROM workspaces WHERE id = ?',
            [$wsId],
        ) ?? ['approval_mode' => 'manual', 'kill_switch' => 0];

        return [
            'date' => $date,
            'auto_approved' => $autoApproved,
            'auto_published' => $autoPublished,
            'guardrail_events' => $guardrailEvents,
            'quality' => $this->quality->compute($wsId),
            'approval_mode' => (string) $ws['approval_mode'],
            'kill_switch' => (int) $ws['kill_switch'] === 1,
            'fell_back_to_manual' => $fellBack,
        ];
    }

    /**
     * @param list<int> $runIds
     *
     * @return array<int, array<string, mixed>> newest render per run id
     */
    private function latestRenders(int $wsId, array $runIds): array
    {
        $runIds = array_values(array_unique(array_filter($runIds, static fn (int $id): bool => $id > 0)));
        if ($runIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($runIds), '?'));
        $rows = $this->db->all(
            "SELECT id, run_id, poster_name, kind FROM renders
             WHERE workspace_id = ? AND run_id IN ({$in})
             ORDER BY run_id, id DESC",
            [$wsId, ...$runIds],
        );

        $byRun = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $byRun[$runId] ??= ['id' => (int) $row['id'], 'poster_name' => $row['poster_name'], 'kind' => $row['kind']];
        }

        return $byRun;
    }
}
