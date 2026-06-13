<?php

declare(strict_types=1);

namespace Kuyash\Core;

use RuntimeException;

/**
 * WAL-aware SQLite backup (Phase 13 hardening). The ONLY safe way to copy a live
 * SQLite database is NOT `cp kuyash.sqlite` — under WAL the latest committed rows
 * live in the -wal sidecar, so a raw file copy can capture a torn/stale state.
 *
 * `VACUUM ... INTO` produces a single, consistent, defragmented snapshot of all
 * committed data (WAL included) WITHOUT an exclusive lock, so it is safe to run
 * while the app + worker are live. We checkpoint first only as hygiene (keeps the
 * live -wal small); the snapshot's correctness does not depend on it.
 *
 * The thin bin/backup.php + bin/restore.php CLI wrappers call this; the snapshot
 * + integrity logic lives here so it is unit-tested (the CLI plumbing is not).
 */
final class SqliteBackup
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Write a consistent snapshot of the live DB to $targetPath and return the
     * snapshot's PRAGMA integrity_check result ('ok' when sound). Refuses to
     * overwrite an existing file (a backup must never clobber another backup).
     */
    public function snapshotTo(string $targetPath): string
    {
        if (is_file($targetPath)) {
            throw new RuntimeException("Backup target already exists: {$targetPath}");
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Backup directory cannot be created: {$dir}");
        }

        // hygiene: fold committed WAL frames into the main db so the live sidecar
        // stays small (the snapshot below is consistent either way)
        $this->db->run('PRAGMA wal_checkpoint(TRUNCATE)');

        // consistent, lock-free copy. The path is operator-supplied (CLI), still
        // bound as a parameter — never string-concatenated into the statement.
        $this->db->run('VACUUM main INTO ?', [$targetPath]);

        return $this->integrityCheck($targetPath);
    }

    /** Open a database file and run PRAGMA integrity_check (returns 'ok' when sound). */
    public function integrityCheck(string $dbPath): string
    {
        $row = (new Database($dbPath))->one('PRAGMA integrity_check');

        return (string) ($row['integrity_check'] ?? 'unknown');
    }
}
