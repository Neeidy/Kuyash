<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Core\Database;

/**
 * The record of everything the showcase seed wrote (DEV/demo tooling — no route
 * reaches this namespace, and nothing in the product reads it).
 *
 * The whole point of the manifest is CERTAINTY at teardown time: a demo row is
 * a demo row because it was recorded here as it was inserted, not because a
 * later heuristic recognised it. Titles can be edited, timestamps drift, and a
 * "delete everything created after X" sweep would take real rows with it.
 *
 * Every write goes through {@see track()} in the SAME transaction as the insert
 * it describes, so a rolled-back seed leaves no manifest entry either.
 */
final class SeedManifest
{
    /** table_name marker for a file the seed placed under media storage. */
    public const FILE = '@file';

    public function __construct(private readonly Database $db)
    {
    }

    /** Record one database row. Re-recording the same row is a no-op. */
    public function track(string $table, int $rowId, string $now): void
    {
        $this->db->run(
            'INSERT OR IGNORE INTO demo_seed_manifest (table_name, row_id, created_at) VALUES (?, ?, ?)',
            [$table, $rowId, $now],
        );
    }

    /** Record one file the seed placed on disk. */
    public function trackFile(string $path, string $now): void
    {
        $this->db->run(
            'INSERT OR IGNORE INTO demo_seed_manifest (table_name, path, created_at) VALUES (?, ?, ?)',
            [self::FILE, $path, $now],
        );
    }

    /**
     * Row ids recorded for one table, ascending.
     *
     * @return list<int>
     */
    public function rowIds(string $table): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['row_id'],
            $this->db->all(
                'SELECT row_id FROM demo_seed_manifest WHERE table_name = ? AND row_id IS NOT NULL ORDER BY row_id ASC',
                [$table],
            ),
        );
    }

    /**
     * Recorded rows for one table as [rowId, recordedAt] pairs, ascending.
     *
     * The timestamp is what lets teardown check that the row still on that id is
     * the row this entry describes — SQLite reuses freed rowids.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function entries(string $table): array
    {
        return array_map(
            static fn (array $r): array => [(int) $r['row_id'], (string) $r['created_at']],
            $this->db->all(
                'SELECT row_id, created_at FROM demo_seed_manifest
                 WHERE table_name = ? AND row_id IS NOT NULL ORDER BY row_id ASC',
                [$table],
            ),
        );
    }

    /**
     * Files recorded, in insertion order.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['path'],
            $this->db->all(
                'SELECT path FROM demo_seed_manifest WHERE table_name = ? ORDER BY id ASC',
                [self::FILE],
            ),
        );
    }

    /**
     * Row counts per table, plus the file count — the dry-run summary.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT table_name, COUNT(*) AS n FROM demo_seed_manifest GROUP BY table_name ORDER BY table_name ASC',
        ) as $row) {
            $out[(string) $row['table_name']] = (int) $row['n'];
        }

        return $out;
    }

    public function total(): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM demo_seed_manifest')['n'] ?? 0);
    }

    /** True when nothing is recorded — i.e. there is nothing to tear down. */
    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /** Forget every entry. Called by teardown AFTER the rows themselves are gone. */
    public function clear(): void
    {
        $this->db->run('DELETE FROM demo_seed_manifest');
    }

    /** Forget the entries for one table (used when a partial delete succeeded). */
    public function forget(string $table, int $rowId): void
    {
        $this->db->run('DELETE FROM demo_seed_manifest WHERE table_name = ? AND row_id = ?', [$table, $rowId]);
    }

    public function forgetFile(string $path): void
    {
        $this->db->run('DELETE FROM demo_seed_manifest WHERE table_name = ? AND path = ?', [self::FILE, $path]);
    }
}
