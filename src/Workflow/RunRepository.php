<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Workspace-scoped run reads for the UI. Every query filters by workspace_id.
 * Run state transitions live in Engine (guarded UPDATEs inside short
 * transactions) — this class never writes.
 */
final class RunRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Newest-first runs with their workflow names.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, int $limit = 50): array
    {
        // created_at DESC first so the (workspace_id, created_at DESC) index
        // serves the page; id DESC breaks same-second ties deterministically
        return array_map(self::shape(...), $this->db->all(
            'SELECT r.*, w.name AS workflow_name, w.template AS workflow_template
             FROM runs r
             JOIN workflows w ON w.id = r.workflow_id
             WHERE r.workspace_id = ?
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ' . max(1, min(200, $limit)),
            [$ctx->id()],
        ));
    }

    /** @return array<string, mixed>|null null = not found OR other tenant's run */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT r.*, w.name AS workflow_name, w.template AS workflow_template
             FROM runs r
             JOIN workflows w ON w.id = r.workflow_id
             WHERE r.id = ? AND r.workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Truthful approval records for a run, with the deciding user's email —
     * the UI renders "Approved by you · {email} · {time}" from the STORED
     * record, never a re-derived claim. LEFT JOIN: auto records (Phase 9)
     * have decided_by NULL by design — they belong to the compliance agent,
     * never a person.
     *
     * @return list<array<string, mixed>>
     */
    public function approvalsForRun(WorkspaceContext $ctx, int $runId): array
    {
        return $this->db->all(
            // the NAME too: it is where a marked account says what it is, and
            // the run page rendered only the email — so a [SAMPLE] account
            // reached the one screen that shows it with its marker stripped
            'SELECT a.*, u.email AS decided_by_email, u.name AS decided_by_name
             FROM approvals a
             LEFT JOIN users u ON u.id = a.decided_by
             WHERE a.workspace_id = ? AND a.run_id = ?
             ORDER BY a.id ASC',
            [$ctx->id(), $runId],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $nodes = json_decode((string) $row['nodes_json'], true);
        $row['nodes'] = is_array($nodes) ? $nodes : [];
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['workflow_id'] = (int) $row['workflow_id'];
        $row['entity_id'] = $row['entity_id'] === null ? null : (int) $row['entity_id'];

        return $row;
    }
}
