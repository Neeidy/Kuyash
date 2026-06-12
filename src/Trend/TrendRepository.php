<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Core\Database;

/**
 * Workspace-scoped reads/writes for the trend cache. Every query filters by
 * workspace_id (tenant isolation at query level). Scoping is a raw int, not a
 * WorkspaceContext: the web path passes $ctx->id() and the worker path passes
 * the workspace_id carried on the claimed job row (the worker has no session).
 *
 * A "batch" is all rows for one (workspace, niche, region) sharing the latest
 * fetched_at. replace() swaps the batch in one short transaction (the provider
 * call has already happened — no external call is ever held inside the tx).
 */
final class TrendRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The latest cached batch for (workspace, niche, region), best-first.
     *
     * @return list<array<string, mixed>>
     */
    public function cached(int $workspaceId, string $niche, string $region): array
    {
        $latest = $this->db->one(
            'SELECT MAX(fetched_at) AS f FROM trends WHERE workspace_id = ? AND niche = ? AND region = ?',
            [$workspaceId, $niche, $region],
        );
        if ($latest === null || $latest['f'] === null) {
            return [];
        }

        return array_map(self::shape(...), $this->db->all(
            'SELECT * FROM trends
             WHERE workspace_id = ? AND niche = ? AND region = ? AND fetched_at = ?
             ORDER BY rank ASC, id ASC',
            [$workspaceId, $niche, $region, $latest['f']],
        ));
    }

    /** @return array<string, mixed>|null null = not found OR another tenant's row */
    public function find(int $workspaceId, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM trends WHERE id = ? AND workspace_id = ?',
            [$id, $workspaceId],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Replace the (workspace, niche, region) batch with fresh results. DELETE +
     * INSERT in one short transaction so a reader never sees a half-written batch.
     *
     * @param list<TrendResult> $results
     */
    public function replace(int $workspaceId, string $niche, string $region, array $results, string $now): void
    {
        $this->db->transaction(function () use ($workspaceId, $niche, $region, $results, $now): void {
            $this->db->run(
                'DELETE FROM trends WHERE workspace_id = ? AND niche = ? AND region = ?',
                [$workspaceId, $niche, $region],
            );

            $rank = 0;
            foreach ($results as $r) {
                $this->db->run(
                    'INSERT INTO trends (workspace_id, niche, region, source, topic, score, format,
                        rank, raw_json, fetched_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $workspaceId,
                        $niche,
                        $region,
                        $r->source,
                        $r->topic,
                        $r->score,
                        $r->format,
                        $rank++,
                        json_encode($r->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $now,
                        $now,
                    ],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $raw = json_decode((string) ($row['raw_json'] ?? '{}'), true);
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['score'] = (int) $row['score'];
        $row['rank'] = (int) $row['rank'];
        $row['raw'] = is_array($raw) ? $raw : [];

        return $row;
    }
}
