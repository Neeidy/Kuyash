<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
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
        // Optional: lets a HIT whose local file was evicted to the durable disk
        // (R2) self-heal by restoring it. Null (test rigs) → re-produce instead.
        private readonly ?StorageManager $storage = null,
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
            $name = (string) $hit['stored_name'];
            $meta = $this->decodeMeta($hit['meta_json']);
            $cached = true;

            // A HIT row can outlive its local file: migrate-storage moves the
            // object to the durable disk (R2) and drops the local copy, or local
            // scratch gets cleaned. The consumer (ffmpeg) needs a REAL local file,
            // so verify it and self-heal before returning the ref.
            $canonical = $this->paths->pathFor('cache', $workspaceId, $name);
            if (!is_file($canonical)) {
                $meta = $this->rematerialize(
                    $workspaceId,
                    $cacheKey,
                    $name,
                    (string) ($hit['storage_disk'] ?? 'local'),
                    $canonical,
                    $meta,
                    $producer,
                    $cached,
                );
            }

            $this->db->run(
                'UPDATE asset_cache SET hits = hits + 1 WHERE workspace_id = ? AND cache_key = ?',
                [$workspaceId, $cacheKey],
            );

            return new CacheEntry(
                $this->paths->ref('cache', $workspaceId, $name),
                $name,
                $meta,
                $cached,
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

    /**
     * Heal a HIT whose local file vanished. First try to restore it from the
     * durable disk where migrate-storage parked it (R2). If it is unrecoverable
     * there — local-only disk, never uploaded, or no StorageManager wired — re-run
     * the producer IN PLACE (same stored_name → no new row, no UNIQUE race). A
     * re-produce is a fresh production, not a cache saving, so $cached flips to
     * false and the caller charges quota for the real re-fetch.
     *
     * @param Closure(string): array<string, mixed> $producer
     * @param array<string, mixed>                  $meta current (HIT) metadata
     * @return array<string, mixed> meta to report — unchanged on restore, fresh on re-produce
     */
    private function rematerialize(
        int $workspaceId,
        string $cacheKey,
        string $name,
        string $disk,
        string $canonical,
        array $meta,
        Closure $producer,
        bool &$cached,
    ): array {
        if ($this->storage !== null && $disk !== 'local' && $this->storage->has($disk)) {
            $key = StorageKey::make('cache', $workspaceId, $name);
            $provider = $this->storage->disk($disk);
            if ($provider->exists($key)) {
                $provider->getToLocal($key, $canonical);
                if (is_file($canonical)) {
                    return $meta; // restored from the durable disk — still a HIT
                }
            }
        }

        // last resort: re-produce into the SAME stored_name and refresh the meta
        $fresh = $producer($canonical);
        $metaJson = json_encode($fresh, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->db->run(
            'UPDATE asset_cache SET meta_json = ? WHERE workspace_id = ? AND cache_key = ?',
            [$metaJson, $workspaceId, $cacheKey],
        );
        $cached = false;

        return $fresh;
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
            'SELECT stored_name, meta_json, storage_disk FROM asset_cache WHERE workspace_id = ? AND cache_key = ?',
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
