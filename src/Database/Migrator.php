<?php

declare(strict_types=1);

namespace Kuyash\Database;

use Kuyash\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Forward-only migration runner over plain .sql files.
 * Files run in lexicographic order (use numeric prefixes: 0001_…, 0002_…);
 * each file executes inside its own transaction and is recorded in the
 * `migrations` table, so re-running is a no-op (idempotent).
 * No rollbacks by design: the recovery path for a single-file SQLite DB
 * is a file copy taken before migrating.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * Apply all pending migrations.
     *
     * @return list<string> filenames applied by this run (empty = nothing to apply)
     */
    public function migrate(): array
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER PRIMARY KEY,
                filename   TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = array_column($this->db->all('SELECT filename FROM migrations'), 'filename');

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $newlyApplied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Migration unreadable: {$name}");
            }

            // SQLite's supported "change a CHECK / rebuild a table" recipe needs
            // foreign-key enforcement OFF, and that pragma is a NO-OP inside a
            // transaction — so it must be toggled here, around the per-file tx,
            // never from within the .sql. Migrations are trusted, reviewed DDL;
            // disabling enforcement during the rewrite lets a parent table be
            // dropped+renamed (e.g. widening workflows.template) without tripping
            // child FKs on the implicit row-delete. Integrity is NOT taken on
            // faith: foreign_key_check runs after every file and any dangling
            // reference a migration leaves behind is a hard failure.
            $pdo->exec('PRAGMA foreign_keys=OFF');
            $pdo->beginTransaction();
            try {
                // raw exec is sanctioned here only: trusted, reviewed .sql files
                // from the repo — never user input (see security rule on DDL)
                $pdo->exec($sql);
                $this->db->run(
                    'INSERT INTO migrations (filename, applied_at) VALUES (?, ?)',
                    [$name, gmdate('Y-m-d\TH:i:s\Z')],
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $pdo->exec('PRAGMA foreign_keys=ON'); // restore before bailing out
                throw new RuntimeException("Migration failed in {$name}: {$e->getMessage()}", 0, $e);
            }

            // verify the file introduced no orphaned references, THEN restore
            // enforcement for the next file / normal request handling
            $violations = $this->db->all('PRAGMA foreign_key_check');
            $pdo->exec('PRAGMA foreign_keys=ON');
            if ($violations !== []) {
                throw new RuntimeException(
                    "Migration {$name} left {$violations[0]['table']} foreign-key violations — "
                    . 'aborting (the DB has already committed this file; restore from the pre-migrate copy).',
                );
            }

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }
}
