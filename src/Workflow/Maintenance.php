<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * Worker chores (Phase 2/3 follow-ups, finally owned by a long-lived process):
 * - pruneLoginAttempts: the throttle window is minutes; rows older than the
 *   retention are dead weight (a success-only stream never pruned before).
 * - sweepOrphanAssets: delete-after-commit can leave files behind; remove
 *   files that are BOTH older than one hour AND unknown to the assets table.
 *   The age guard makes an in-flight upload race impossible (a fresh tmp/
 *   stored file is never touched); the table is read first, file operations
 *   run outside any transaction.
 *
 * bin/worker.php runs this at startup and every ~5 minutes; tests call the
 * methods directly.
 */
final class Maintenance
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly \Kuyash\Core\Database $db,
        private readonly string $assetStorageRoot,
    ) {
    }

    /** @return array{pruned_login_attempts: int, swept_orphans: int} */
    public function run(string $nowIso): array
    {
        return [
            'pruned_login_attempts' => $this->pruneLoginAttempts($nowIso),
            'swept_orphans' => count($this->sweepOrphanAssets($nowIso)),
        ];
    }

    /** Delete login_attempts older than $keepDays. Returns rows removed. */
    public function pruneLoginAttempts(string $nowIso, int $keepDays = 7): int
    {
        $cutoff = gmdate(self::ISO, (int) strtotime($nowIso) - $keepDays * 86400);

        return $this->db->run(
            'DELETE FROM login_attempts WHERE attempted_at < ?',
            [$cutoff],
        )->rowCount();
    }

    /**
     * Remove stored asset files with no matching assets row, but ONLY when
     * older than $minAgeSeconds (default 1h) — fresh files may belong to an
     * upload whose row lands a moment later.
     *
     * @return list<string> absolute paths removed
     */
    public function sweepOrphanAssets(string $nowIso, int $minAgeSeconds = 3600): array
    {
        if (!is_dir($this->assetStorageRoot)) {
            return [];
        }

        // table first (source of truth), files second — never the reverse
        $known = [];
        foreach ($this->db->all('SELECT workspace_id, stored_name FROM assets') as $row) {
            $known[$row['workspace_id'] . '/' . $row['stored_name']] = true;
        }

        $cutoffTs = (int) strtotime($nowIso) - $minAgeSeconds;
        $removed = [];

        foreach (glob($this->assetStorageRoot . '/*/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $rel = basename(dirname($file)) . '/' . basename($file);
            if (isset($known[$rel])) {
                continue;
            }
            $mtime = filemtime($file);
            if ($mtime === false || $mtime > $cutoffTs) {
                continue; // fresh — possibly an in-flight upload
            }
            if (@unlink($file)) {
                $removed[] = $file;
            } else {
                error_log("Kuyash: orphan sweep could not delete {$file}");
            }
        }

        return $removed;
    }
}
