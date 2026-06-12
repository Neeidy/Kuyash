<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Core\Database;

/**
 * Workspace-scoped CRUD for render artifacts (draft/final MP4s + posters).
 * Every query filters by workspace_id (tenant isolation). Scoping is a raw int:
 * executors pass the job's workspace_id, the web layer passes the session ctx id.
 */
final class RenderRepository
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{kind: string, stored_name: string, poster_name: ?string,
     *   width: ?int, height: ?int, duration_s: ?float, size_bytes: ?int} $data
     */
    public function create(int $workspaceId, int $runId, ?int $jobId, array $data): int
    {
        $this->db->run(
            'INSERT INTO renders (workspace_id, run_id, job_id, kind, stored_name, poster_name,
                mime, width, height, duration_s, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, \'video/mp4\', ?, ?, ?, ?, ?)',
            [
                $workspaceId,
                $runId,
                $jobId,
                $data['kind'],
                $data['stored_name'],
                $data['poster_name'],
                $data['width'],
                $data['height'],
                $data['duration_s'],
                $data['size_bytes'],
                gmdate(self::ISO),
            ],
        );

        return $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null null = not found OR another tenant's render */
    public function find(int $workspaceId, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM renders WHERE id = ? AND workspace_id = ?',
            [$id, $workspaceId],
        );

        return $row === null ? null : self::shape($row);
    }

    /** Newest render of a kind for a run (the draft shown at render_review). */
    public function latestForRun(int $workspaceId, int $runId, ?string $kind = null): ?array
    {
        $sql = 'SELECT * FROM renders WHERE workspace_id = ? AND run_id = ?';
        $params = [$workspaceId, $runId];
        if ($kind !== null) {
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $this->db->one($sql, $params);

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param list<int> $runIds
     *
     * @return array<int, array<string, mixed>> latest draft render per run id
     */
    public function latestDraftsByRun(int $workspaceId, array $runIds): array
    {
        $runIds = array_values(array_filter($runIds, static fn ($v): bool => is_int($v) && $v > 0));
        if ($runIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($runIds), '?'));
        $rows = $this->db->all(
            "SELECT * FROM renders
             WHERE workspace_id = ? AND run_id IN ({$in})
             ORDER BY run_id, id DESC",
            array_merge([$workspaceId], $runIds),
        );

        $byRun = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $byRun[$runId] ??= self::shape($row); // first seen = newest (id DESC)
        }

        return $byRun;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['run_id'] = (int) $row['run_id'];
        $row['width'] = $row['width'] === null ? null : (int) $row['width'];
        $row['height'] = $row['height'] === null ? null : (int) $row['height'];
        $row['duration_s'] = $row['duration_s'] === null ? null : (float) $row['duration_s'];
        $row['size_bytes'] = $row['size_bytes'] === null ? null : (int) $row['size_bytes'];

        return $row;
    }
}
