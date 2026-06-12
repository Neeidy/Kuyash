<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Closure;
use Kuyash\Core\Database;
use Throwable;

/**
 * Content-addressed intermediate cache (TTS audio, stock clips). remember()
 * returns the cached file on a HIT (no producer call, recorded as a saving) or
 * runs the producer and stores the result on a MISS — workspace-scoped, keyed by
 * a sha256 of the inputs.
 *
 * The producer (ffmpeg / a network fetch) runs OUTSIDE any transaction (sqlite
 * rule). The single INSERT races safely on the UNIQUE(workspace_id, cache_key):
 * a loser deletes its just-produced file and reuses the winner's row.
 */
final class AssetCache
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly Database $db,
        private readonly MediaPaths $paths,
    ) {
    }

    /**
     * @param Closure(string): array<string, mixed> $producer receives the absolute
     *        target path, writes the file, returns its metadata
     */
    public function remember(int $workspaceId, string $kind, string $cacheKey, string $ext, Closure $producer): CacheEntry
    {
        $hit = $this->lookup($workspaceId, $cacheKey);
        if ($hit !== null) {
            $this->db->run(
                'UPDATE asset_cache SET hits = hits + 1 WHERE workspace_id = ? AND cache_key = ?',
                [$workspaceId, $cacheKey],
            );

            return new CacheEntry(
                $this->paths->ref('cache', $workspaceId, (string) $hit['stored_name']),
                (string) $hit['stored_name'],
                $this->decodeMeta($hit['meta_json']),
                true,
            );
        }

        // MISS — produce outside any transaction
        $name = $this->paths->newName($ext);
        $path = $this->paths->pathFor('cache', $workspaceId, $name);
        $meta = $producer($path);
        // encode BEFORE the insert so a non-encodable meta surfaces, never gets
        // mistaken for a unique-key race
        $metaJson = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        try {
            $this->db->run(
                'INSERT INTO asset_cache (workspace_id, cache_key, kind, stored_name, meta_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$workspaceId, $cacheKey, $kind, $name, $metaJson, gmdate(self::ISO)],
            );
        } catch (Throwable $e) {
            // ONLY a UNIQUE(cache_key) conflict is a benign race — a concurrent
            // worker produced the same content first. Any other error rethrows.
            if (!$this->isUniqueViolation($e)) {
                @unlink($path);
                throw $e;
            }
            @unlink($path); // drop our duplicate file, reuse the winner's row
            $winner = $this->lookup($workspaceId, $cacheKey);
            if ($winner !== null) {
                return new CacheEntry(
                    $this->paths->ref('cache', $workspaceId, (string) $winner['stored_name']),
                    (string) $winner['stored_name'],
                    $this->decodeMeta($winner['meta_json']),
                    true,
                );
            }
            throw $this->wrap();
        }

        return new CacheEntry($this->paths->ref('cache', $workspaceId, $name), $name, $meta, false);
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        // SQLSTATE 23000 = integrity constraint; message names the UNIQUE index
        return ($e instanceof \PDOException && (string) $e->getCode() === '23000')
            || str_contains($e->getMessage(), 'UNIQUE constraint');
    }

    public function hitCountFor(int $workspaceId): int
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(hits), 0) AS h FROM asset_cache WHERE workspace_id = ?',
            [$workspaceId],
        );

        return (int) ($row['h'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function lookup(int $workspaceId, string $cacheKey): ?array
    {
        return $this->db->one(
            'SELECT stored_name, meta_json FROM asset_cache WHERE workspace_id = ? AND cache_key = ?',
            [$workspaceId, $cacheKey],
        );
    }

    /** @return array<string, mixed> */
    private function decodeMeta(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function wrap(): \RuntimeException
    {
        return new \RuntimeException('asset cache write failed and no existing entry was found');
    }
}
