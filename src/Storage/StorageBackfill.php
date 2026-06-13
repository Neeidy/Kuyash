<?php

declare(strict_types=1);

namespace Kuyash\Storage;

use Closure;
use Kuyash\Core\Database;
use Throwable;

/**
 * Copies local objects to a target disk and flips their per-object storage_disk
 * marker — the gradual, resumable migration path. Idempotent (already-target rows
 * are skipped), tenant-scoped, dry-run-safe. Verifies the object exists on the
 * target (exists + size) BEFORE flipping, and NEVER deletes the local copy
 * (delete-after-verify is a Phase 13 eviction concern). A re-run continues where
 * the last left off because flipped rows leave the 'local' working set.
 *
 * Cache objects stay local in Phase 8, so only `assets` and `renders` (incl.
 * their poster sidecar) are migrated here.
 */
final class StorageBackfill
{
    public function __construct(
        private readonly Database $db,
        private readonly StorageManager $storage,
    ) {
    }

    /**
     * @param int|'all' $workspace
     * @param Closure(string): void $log
     *
     * @return array{copied: int, skipped_already: int, missing: int, errors: int, would_copy: int}
     */
    public function run(string $targetDisk, int|string $workspace, bool $dryRun, int $batch, Closure $log): array
    {
        $local = $this->storage->disk('local');
        if (!$local instanceof LocalStorageProvider) {
            throw new StorageException("Backfill source disk 'local' is not a LocalStorageProvider.");
        }
        $dest = $this->storage->disk($targetDisk); // resolve early — fail fast if r2 unconfigured

        $stats = ['copied' => 0, 'skipped_already' => 0, 'missing' => 0, 'errors' => 0, 'would_copy' => 0];

        foreach ($this->rows('assets', $workspace, $batch) as $row) {
            $objects = [['asset', (string) $row['stored_name'], (string) $row['mime']]];
            $this->migrateRow('assets', (int) $row['id'], (int) $row['workspace_id'], $objects, $local, $dest, $targetDisk, $dryRun, $log, $stats);
        }

        foreach ($this->rows('renders', $workspace, $batch) as $row) {
            $objects = [['render', (string) $row['stored_name'], 'video/mp4']];
            if ($row['poster_name'] !== null && $row['poster_name'] !== '') {
                $objects[] = ['render', (string) $row['poster_name'], 'image/jpeg'];
            }
            $this->migrateRow('renders', (int) $row['id'], (int) $row['workspace_id'], $objects, $local, $dest, $targetDisk, $dryRun, $log, $stats);
        }

        return $stats;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $objects [store, name, contentType]
     * @param array{copied: int, skipped_already: int, missing: int, errors: int, would_copy: int} $stats
     */
    private function migrateRow(
        string $table,
        int $id,
        int $ws,
        array $objects,
        LocalStorageProvider $local,
        StorageProvider $dest,
        string $targetDisk,
        bool $dryRun,
        Closure $log,
        array &$stats,
    ): void {
        // every object backing the row must exist locally before we touch anything
        foreach ($objects as [$store, $name]) {
            $key = StorageKey::make($store, $ws, $name);
            if (!$local->exists($key)) {
                $stats['missing']++;
                $log("MISSING {$table}#{$id} {$key} — local file absent, skipped");

                return;
            }
        }

        if ($dryRun) {
            $stats['would_copy']++;
            $log("DRY-RUN {$table}#{$id} → {$targetDisk} (" . count($objects) . ' object(s))');

            return;
        }

        try {
            foreach ($objects as [$store, $name, $contentType]) {
                $key = StorageKey::make($store, $ws, $name);
                $dest->put($key, $local->path($key), $contentType);
                if (!$dest->exists($key) || $dest->size($key) !== $local->size($key)) {
                    $stats['errors']++;
                    $log("ERROR {$table}#{$id} {$key} — verify failed on {$targetDisk}, marker NOT flipped");

                    return;
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $log("ERROR {$table}#{$id} — {$e->getMessage()}");

            return;
        }

        // flip only after every object verified on the target (local copy kept)
        $this->db->run("UPDATE {$table} SET storage_disk = ? WHERE id = ? AND storage_disk = 'local'", [$targetDisk, $id]);
        $stats['copied']++;
        $log("OK {$table}#{$id} → {$targetDisk}");
    }

    /**
     * @param int|'all' $workspace
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, int|string $workspace, int $batch): array
    {
        // $table is a fixed internal literal ('assets'|'renders'), never user input
        $sql = "SELECT * FROM {$table} WHERE storage_disk = 'local'";
        $params = [];
        if ($workspace !== 'all') {
            $sql .= ' AND workspace_id = ?';
            $params[] = (int) $workspace;
        }
        $sql .= ' ORDER BY id ASC LIMIT ?';
        $params[] = max(1, $batch);

        return $this->db->all($sql, $params);
    }
}
