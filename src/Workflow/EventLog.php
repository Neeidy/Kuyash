<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Append-only event log. record() is called INSIDE the same short transaction
 * as the state transition it describes (no transition without an event, no
 * event without a transition) — it joins the caller's open transaction because
 * both run on the same PDO connection. SQL triggers reject UPDATE/DELETE.
 *
 * record() takes a plain workspace id because the worker has no session and
 * therefore no WorkspaceContext — it writes with the workspace_id carried by
 * the claimed job row. Web-facing reads take the context (isolation pattern).
 *
 * Rows store key + params_json, not prose: the UI resolves keys through
 * Core\Messages, which keeps the future TR i18n pass mechanical.
 */
final class EventLog
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $params */
    public function record(
        int $workspaceId,
        string $level,
        string $kind,
        string $key,
        array $params = [],
        ?int $runId = null,
        ?int $jobId = null,
    ): void {
        $this->db->run(
            'INSERT INTO events (workspace_id, run_id, job_id, level, kind, key, params_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $workspaceId,
                $runId,
                $jobId,
                $level,
                $kind,
                $key,
                json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );
    }

    /**
     * Newest-first feed for /logs. Filters: level (info|warn|error) and/or
     * kinds (subset of transition|compliance|guardrail) — invalid values mean
     * no filter. Filtering happens in SQL so LIMIT counts matching rows.
     *
     * @param list<string>|null $kinds
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(
        WorkspaceContext $ctx,
        ?string $level = null,
        ?array $kinds = null,
        int $limit = 200,
    ): array {
        $sql = 'SELECT * FROM events WHERE workspace_id = ?';
        $params = [$ctx->id()];

        if (in_array($level, ['info', 'warn', 'error'], true)) {
            $sql .= ' AND level = ?';
            $params[] = $level;
        }

        $validKinds = array_values(array_intersect(
            $kinds ?? [],
            ['transition', 'compliance', 'guardrail'],
        ));
        if ($validKinds !== []) {
            $sql .= ' AND kind IN (' . implode(', ', array_fill(0, count($validKinds), '?')) . ')';
            $params = array_merge($params, $validKinds);
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));

        return array_map(self::shape(...), $this->db->all($sql, $params));
    }

    /**
     * Chronological timeline for one run (ORDER BY id — created_at has only
     * second precision and mock transitions land within the same second).
     *
     * @return list<array<string, mixed>>
     */
    public function timelineForRun(WorkspaceContext $ctx, int $runId): array
    {
        return array_map(self::shape(...), $this->db->all(
            'SELECT * FROM events WHERE workspace_id = ? AND run_id = ? ORDER BY id ASC',
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
        $params = json_decode((string) $row['params_json'], true);
        $row['params'] = is_array($params) ? $params : [];
        $row['id'] = (int) $row['id'];
        $row['run_id'] = $row['run_id'] === null ? null : (int) $row['run_id'];
        $row['job_id'] = $row['job_id'] === null ? null : (int) $row['job_id'];

        return $row;
    }
}
