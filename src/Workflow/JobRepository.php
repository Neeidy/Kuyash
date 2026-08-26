<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Workspace-scoped job reads for the UI. Every query filters by workspace_id.
 * Queue mechanics (claim/finalize/requeue) live in Worker/Engine — this class
 * never writes.
 */
final class JobRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Newest-first flat job list for /queue.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, int $limit = 100): array
    {
        // created_at DESC first so the (workspace_id, created_at DESC) index
        // serves the page; id DESC breaks same-second ties deterministically
        return array_map(self::shape(...), $this->db->all(
            'SELECT * FROM jobs WHERE workspace_id = ?
             ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit)),
            [$ctx->id()],
        ));
    }

    /** @return array<string, mixed>|null null = not found OR other tenant's job */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM jobs WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Jobs paused for a human decision, oldest first (approval queue order).
     *
     * @return list<array<string, mixed>>
     */
    public function awaitingApproval(WorkspaceContext $ctx): array
    {
        return array_map(self::shape(...), $this->db->all(
            // library_poster: whether the source clip HAS a still frame on disk,
            // resolved here rather than by the template emitting an <img> that
            // 404s for every run whose clip has none. Correlated on the asset the
            // render_review result names, which is the same clip the card plays.
            "SELECT j.*, (
                 SELECT a.sha256 FROM assets a
                 WHERE a.workspace_id = j.workspace_id
                   AND a.id = CAST(json_extract(j.result_json, '$.library_asset_id') AS INTEGER)
             ) AS library_sha256
             FROM jobs j WHERE j.workspace_id = ? AND j.status = 'awaiting_approval' ORDER BY j.id ASC",
            [$ctx->id()],
        ));
    }

    /**
     * Execution-ordered jobs of one run (the run detail node track).
     *
     * @return list<array<string, mixed>>
     */
    public function jobsForRun(WorkspaceContext $ctx, int $runId): array
    {
        return array_map(self::shape(...), $this->db->all(
            'SELECT * FROM jobs WHERE workspace_id = ? AND run_id = ? ORDER BY step ASC',
            [$ctx->id(), $runId],
        ));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $result = json_decode((string) ($row['result_json'] ?? ''), true);
        $row['result'] = is_array($result) ? $result : [];
        foreach (['id', 'workspace_id', 'run_id', 'step', 'retry_count', 'max_retries', 'priority'] as $col) {
            $row[$col] = (int) $row[$col];
        }
        $row['entity_id'] = $row['entity_id'] === null ? null : (int) $row['entity_id'];
        $row['cost_cents'] = $row['cost_cents'] === null ? null : (int) $row['cost_cents'];

        return $row;
    }
}
