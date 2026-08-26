<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Core\Database;

/**
 * Undo for {@see ShowcaseSeed} (DEV/demo tooling — `bin/demo-teardown.php` is
 * its only caller).
 *
 * It deletes exactly the rows the manifest records, in an order that satisfies
 * every foreign key, and nothing else. There is no "delete rows created after X",
 * no title matching, no workspace wipe: a heuristic that guessed which rows were
 * demo rows would eventually guess wrong on real data, which is the failure this
 * whole design exists to prevent.
 *
 * TWO DELIBERATE EXCEPTIONS, both of them supersets that stay inside the demo's
 * own footprint:
 *
 *  • CALENDAR CELLS OF A DEMO PUBLISHING TIME. The materializer runs on every
 *    worker tick and every plan page view, so the product itself creates further
 *    cells for a demo slot after the seed has finished. They are demo rows by
 *    construction — a cell cannot exist without its slot — and leaving them
 *    behind would make the slot undeletable.
 *  • RUN-SCOPED CHILDREN. posts/jobs/approvals/renders/usage rows that point at a
 *    demo run are swept even if the manifest somehow missed one, because the run
 *    could not be deleted otherwise and a half-deleted run is worse than either
 *    outcome.
 *
 * ONE THING IT CANNOT DO: `events` is append-only by trigger. The seed therefore
 * never writes one — but if the worker ever appended an event for a demo run,
 * that run becomes undeletable, so {@see blockers()} reports it up front instead
 * of failing halfway through.
 */
final class ShowcaseTeardown
{
    /**
     * Delete order. Children before parents, all the way down — every entry is
     * here because some other entry has a foreign key into it.
     */
    //
    // `credit_transactions` and `approvals` stay listed even though the seed no
    // longer writes either (a balance and an approval chip are aggregates and
    // identities — neither can carry the [SAMPLE] marker, so neither is
    // fabricated). The manifest never names them, so the manifest-driven pass is
    // a no-op; the run-scoped sweep below still needs them, because a run the
    // OPERATOR approves during a capture has real rows that must be removed with
    // it — unless the audit log pinned that run, in which case blockedRunIds()
    // has already excluded it and its real approval survives.
    private const ORDER = [
        'credit_transactions',   // → runs, jobs
        'usage_events',          // → runs, jobs
        'approvals',             // → runs, jobs
        'posts',                 // → runs, jobs, accounts
        'renders',               // → runs, jobs
        'slot_occurrences',      // → publish_slots, assets, runs
        'publish_slots',         // → accounts
        'jobs',                  // → runs
        'runs',                  // → workflows, users, assets
        'account_metrics',       // → accounts
        'accounts',              // → assets
        'assets',
        'trends',
        'trend_config',
        'workflows',
        // last: approvals and runs both point at a user, so the demo account can
        // only go once nothing names it any more
        'users',
    ];

    /**
     * The timestamp column each table stamps a row with at creation.
     *
     * WHY THIS EXISTS: the manifest records (table, rowid), and SQLite REUSES a
     * freed rowid unless the column is AUTOINCREMENT — which none of these are.
     * So: delete a [SAMPLE] clip from the Library screen, upload a real one, and
     * the real one can land on the freed id. Teardown would then read the
     * manifest, see that id, and delete the operator's own file.
     *
     * The guard needs no schema support. Every row the seed writes is stamped at
     * or before the moment the seed ran, and every row created AFTERWARDS is
     * stamped after it — so a row whose own timestamp is newer than its manifest
     * entry is, by construction, not the row that entry describes.
     */
    private const STAMP = [
        'credit_transactions' => 'created_at',
        'usage_events' => 'created_at',
        'approvals' => 'decided_at',
        'posts' => 'created_at',
        'renders' => 'created_at',
        'slot_occurrences' => 'created_at',
        'publish_slots' => 'created_at',
        'jobs' => 'created_at',
        'runs' => 'created_at',
        'account_metrics' => 'created_at',
        'accounts' => 'created_at',
        'assets' => 'created_at',
        'trends' => 'created_at',
        'trend_config' => 'updated_at',
        'workflows' => 'created_at',
        'users' => 'created_at',
    ];

    /** Which column ties a row to its run, for the pinned-run skip in run(). */
    private const RUN_SCOPE = [
        'credit_transactions' => 'ref_run_id',
        'usage_events' => 'run_id',
        'approvals' => 'run_id',
        'posts' => 'run_id',
        'renders' => 'run_id',
        'slot_occurrences' => 'run_id',
        'jobs' => 'run_id',
    ];

    /**
     * Extra conditions that keep a PARENT row alive when a child survived.
     *
     * A pinned run keeps its jobs, its calendar day and the clip it was made
     * from — so the publishing time that day belongs to, and the asset that clip
     * is, have to stay too. Without these the delete either fails on a foreign
     * key (taking the whole teardown down with it) or, where no key exists,
     * leaves the surviving run pointing at content that is gone.
     */
    private const GUARD = [
        'publish_slots' => ['NOT EXISTS (SELECT 1 FROM slot_occurrences o WHERE o.slot_id = publish_slots.id)'],
        'accounts' => [
            'NOT EXISTS (SELECT 1 FROM posts p WHERE p.account_id = accounts.id)',
            'NOT EXISTS (SELECT 1 FROM publish_slots s WHERE s.account_id = accounts.id)',
            'NOT EXISTS (SELECT 1 FROM account_metrics am WHERE am.account_id = accounts.id)',
        ],
        'assets' => [
            'NOT EXISTS (SELECT 1 FROM slot_occurrences o WHERE o.asset_id = assets.id)',
            'NOT EXISTS (SELECT 1 FROM accounts a WHERE a.default_reference_asset_id = assets.id)',
            'NOT EXISTS (SELECT 1 FROM runs r WHERE r.reference_asset_id = assets.id)',
            // runs.entity_id carries no foreign key, so this one is not about a
            // constraint — it is about not leaving a surviving run pointing at a
            // library clip that no longer exists.
            "NOT EXISTS (SELECT 1 FROM runs r WHERE r.entity_type = 'library' AND r.entity_id = assets.id)",
        ],
        'workflows' => ['NOT EXISTS (SELECT 1 FROM runs r WHERE r.workflow_id = workflows.id)'],
        'users' => [
            'NOT EXISTS (SELECT 1 FROM approvals a WHERE a.decided_by = users.id)',
            'NOT EXISTS (SELECT 1 FROM runs r WHERE r.created_by = users.id)',
            'NOT EXISTS (SELECT 1 FROM workspace_users wu WHERE wu.user_id = users.id)',
            'NOT EXISTS (SELECT 1 FROM jobs j WHERE j.user_id = users.id)',
        ],
    ];

    private SeedManifest $manifest;

    public function __construct(private readonly Database $db)
    {
        $this->manifest = new SeedManifest($this->db);
    }

    public function manifest(): SeedManifest
    {
        return $this->manifest;
    }

    /**
     * What a teardown WOULD delete, and what stands in its way. Read-only.
     *
     * @return array{rows: array<string, int>, files: list<string>, missing_files: list<string>, blockers: list<string>, total: int}
     */
    public function dryRun(): array
    {
        $rows = [];
        foreach (self::ORDER as $table) {
            $ids = $this->manifest->rowIds($table);
            $extra = $table === 'slot_occurrences' ? $this->orphanedCells() : [];
            $n = count(array_unique(array_merge($ids, $extra)));
            if ($n > 0) {
                $rows[$table] = $n;
            }
        }

        $files = [];
        $missing = [];
        foreach ($this->manifest->files() as $path) {
            is_file($path) ? $files[] = $path : $missing[] = $path;
        }

        return [
            'rows' => $rows,
            'files' => $files,
            'missing_files' => $missing,
            'blockers' => $this->blockers(),
            'total' => $this->manifest->total(),
        ];
    }

    /**
     * Rows that the append-only audit log has pinned in place.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        $runIds = $this->manifest->rowIds('runs');
        if ($runIds === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT run_id, COUNT(*) AS n FROM events WHERE run_id IN (' . self::placeholders($runIds) . ')
             GROUP BY run_id ORDER BY run_id ASC',
            $runIds,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = sprintf(
                'run #%d has %d event(s): events are append-only, so this run cannot be deleted',
                (int) $row['run_id'],
                (int) $row['n'],
            );
        }

        return $out;
    }

    /**
     * Delete everything the manifest records, EXCEPT what the append-only audit
     * log has pinned in place.
     *
     * PARTIAL BY DESIGN. The first version ran one all-or-nothing transaction:
     * the CLI printed "those runs stay, everything else can still be removed"
     * and then the blocked `DELETE FROM runs` threw and nothing at all came out.
     * A pinned run and everything hanging off it is now left whole — a
     * half-stripped run with its jobs gone is worse than either outcome — and
     * its manifest entries are KEPT, so a later teardown finishes the job once
     * the blocker is gone.
     *
     * @return array{rows: array<string, int>, files: int, files_missing: int, kept: list<string>}
     */
    public function run(): array
    {
        $deletedFiles = 0;
        $missingFiles = 0;
        $filePaths = $this->manifest->files();
        $blocked = $this->blockedRunIds();

        // Files belonging to a pinned run stay with it.
        if ($blocked !== []) {
            $keepFiles = $this->filesOfRuns($blocked);
            $filePaths = array_values(array_diff($filePaths, $keepFiles));
        }

        [$rows, $kept] = $this->db->transaction(function (Database $db) use ($blocked): array {
            $deleted = [];
            $notBlocked = self::notIn('run_id', $blocked);

            // Cells the PRODUCT created for a demo publishing time, before the
            // slot they belong to is removed (see the class comment).
            foreach ($this->orphanedCells() as $id) {
                $deleted['slot_occurrences'] = ($deleted['slot_occurrences'] ?? 0)
                    + $db->run(
                        "DELETE FROM slot_occurrences WHERE id = ? AND (run_id IS NULL OR {$notBlocked})",
                        array_merge([$id], $blocked),
                    )->rowCount();
            }

            // Run-scoped children the manifest may not list, for the same reason.
            $runIds = array_values(array_diff($this->manifest->rowIds('runs'), $blocked));
            if ($runIds !== []) {
                $in = self::placeholders($runIds);
                foreach ([
                    'credit_transactions' => 'ref_run_id',
                    'usage_events' => 'run_id',
                    'approvals' => 'run_id',
                    'posts' => 'run_id',
                    'renders' => 'run_id',
                ] as $table => $column) {
                    $deleted[$table] = ($deleted[$table] ?? 0)
                        + $db->run("DELETE FROM {$table} WHERE {$column} IN ({$in})", $runIds)->rowCount();
                }
                // A cell of a REAL publishing time must never be deleted just
                // because a demo run was parked on it — it only has to stop
                // pointing at a run that is about to disappear.
                $db->run(
                    "UPDATE slot_occurrences SET run_id = NULL, status = 'open', asset_id = NULL
                     WHERE run_id IN ({$in})",
                    $runIds,
                );
                $deleted['jobs'] = ($deleted['jobs'] ?? 0)
                    + $db->run("DELETE FROM jobs WHERE run_id IN ({$in})", $runIds)->rowCount();
            }

            foreach (self::ORDER as $table) {
                $key = $table === 'trend_config' ? 'workspace_id' : 'id';
                $stamp = self::STAMP[$table] ?? null;
                $scope = self::RUN_SCOPE[$table] ?? null;
                foreach ($this->manifest->entries($table) as [$id, $recordedAt]) {
                    $sql = "DELETE FROM {$table} WHERE {$key} = ?";
                    $params = [$id];
                    if ($stamp !== null) {
                        // Identity check before the delete: see the STAMP note.
                        $sql .= " AND {$stamp} <= ?";
                        $params[] = $recordedAt;
                    }
                    if ($scope !== null && $blocked !== []) {
                        // belongs to a pinned run → leave it whole
                        $sql .= ' AND ' . self::notIn($scope, $blocked);
                        $params = array_merge($params, $blocked);
                    } elseif ($table === 'runs' && $blocked !== []) {
                        $sql .= ' AND ' . self::notIn('id', $blocked);
                        $params = array_merge($params, $blocked);
                    }
                    foreach (self::GUARD[$table] ?? [] as $guard) {
                        // a parent whose child survived survives with it
                        $sql .= ' AND ' . $guard;
                    }
                    $deleted[$table] = ($deleted[$table] ?? 0) + $db->run($sql, $params)->rowCount();
                }
            }

            // Forget only what is actually gone. Anything still standing keeps
            // its entry so a later teardown can finish it.
            $kept = [];
            foreach (self::ORDER as $table) {
                $key = $table === 'trend_config' ? 'workspace_id' : 'id';
                foreach ($this->manifest->rowIds($table) as $id) {
                    if ($db->one("SELECT 1 AS x FROM {$table} WHERE {$key} = ?", [$id]) === null) {
                        $this->manifest->forget($table, $id);
                        continue;
                    }
                    $kept[] = "{$table} #{$id}";
                }
            }

            return [array_filter($deleted, static fn (int $n): bool => $n > 0), $kept];
        });

        foreach ($filePaths as $path) {
            if (!is_file($path)) {
                $missingFiles++;
                $this->manifest->forgetFile($path);
                continue;
            }
            if (@unlink($path)) {
                $deletedFiles++;
                $this->manifest->forgetFile($path);
            }
        }

        return ['rows' => $rows, 'files' => $deletedFiles, 'files_missing' => $missingFiles, 'kept' => $kept];
    }

    /**
     * Demo runs the append-only audit log has pinned in place.
     *
     * @return list<int>
     */
    private function blockedRunIds(): array
    {
        $runIds = $this->manifest->rowIds('runs');
        if ($runIds === []) {
            return [];
        }

        return array_map(
            static fn (array $r): int => (int) $r['run_id'],
            $this->db->all(
                'SELECT DISTINCT run_id FROM events WHERE run_id IN (' . self::placeholders($runIds) . ')
                 ORDER BY run_id ASC',
                $runIds,
            ),
        );
    }

    /**
     * Media paths belonging to the given runs' assets and renders — the files a
     * pinned run still needs in order to render at all.
     *
     * @param list<int> $runIds
     *
     * @return list<string>
     */
    private function filesOfRuns(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }
        $in = self::placeholders($runIds);
        $names = array_merge(
            array_map(
                static fn (array $r): string => (string) $r['stored_name'],
                $this->db->all("SELECT stored_name FROM renders WHERE run_id IN ({$in})", $runIds),
            ),
            array_map(
                static fn (array $r): string => (string) $r['poster_name'],
                $this->db->all(
                    "SELECT poster_name FROM renders WHERE run_id IN ({$in}) AND poster_name IS NOT NULL",
                    $runIds,
                ),
            ),
            array_map(
                static fn (array $r): string => (string) $r['stored_name'],
                $this->db->all(
                    "SELECT a.stored_name FROM assets a
                     JOIN runs r ON r.entity_id = a.id AND r.workspace_id = a.workspace_id
                     WHERE r.id IN ({$in}) AND r.entity_type = 'library'",
                    $runIds,
                ),
            ),
        );

        $keep = [];
        foreach ($this->manifest->files() as $path) {
            if (in_array(basename($path), $names, true)) {
                $keep[] = $path;
            }
        }

        return $keep;
    }

    /** `col NOT IN (…)` that is simply TRUE when the list is empty. */
    private static function notIn(string $column, array $ids): string
    {
        return $ids === [] ? '1 = 1' : "({$column} IS NULL OR {$column} NOT IN (" . self::placeholders($ids) . '))';
    }

    /**
     * Calendar cells belonging to a demo publishing time — including the ones
     * the materializer created after the seed ran.
     *
     * @return list<int>
     */
    private function orphanedCells(): array
    {
        $slotIds = $this->manifest->rowIds('publish_slots');
        if ($slotIds === []) {
            return [];
        }

        return array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->db->all(
                'SELECT id FROM slot_occurrences WHERE slot_id IN (' . self::placeholders($slotIds) . ') ORDER BY id ASC',
                $slotIds,
            ),
        );
    }

    /** @param list<int> $ids */
    private static function placeholders(array $ids): string
    {
        return $ids === [] ? 'NULL' : implode(', ', array_fill(0, count($ids), '?'));
    }
}
