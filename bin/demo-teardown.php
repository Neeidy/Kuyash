<?php

declare(strict_types=1);

/**
 * Undo for bin/demo-seed.php (DEV-ONLY, not product code).
 *
 * Reads `demo_seed_manifest` and deletes exactly the rows and files the seed
 * recorded, in foreign-key-safe order. Real data — the connected account, the
 * real ledger, the operator's own publishing times, every run they actually
 * made — is never matched, because nothing here matches on content. A row is
 * deleted because the manifest says the seed created it, and for no other
 * reason.
 *
 * Two supersets are swept alongside the manifest, both inside the demo's own
 * footprint and both explained in Kuyash\Demo\ShowcaseTeardown: calendar cells
 * the product created for a demo publishing time, and run-scoped children of a
 * demo run.
 *
 * Usage:
 *   php bin/demo-teardown.php --dry-run     # counts only, touches nothing
 *   php bin/demo-teardown.php --yes         # backup, then delete
 *   php bin/demo-teardown.php --yes --no-backup
 */

use Kuyash\Core\Config;
use Kuyash\Core\Database;
use Kuyash\Core\SqliteBackup;
use Kuyash\Demo\ShowcaseTeardown;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
$confirmed = in_array('--yes', $args, true);
if (!$dry && !$confirmed) {
    fwrite(STDERR, "demo-teardown: deletes the demo content bin/demo-seed.php created.\n"
        . "  php bin/demo-teardown.php --dry-run   # show what would go, change nothing\n"
        . "  php bin/demo-teardown.php --yes       # take a backup, then delete\n");
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $container->get(Database::class);

if ($db->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'demo_seed_manifest'") === null) {
    fwrite(STDERR, "demo-teardown: the demo manifest table is missing. Run: php bin/migrate.php\n");
    exit(1);
}

$teardown = new ShowcaseTeardown($db);
$plan = $teardown->dryRun();

if ($plan['total'] === 0) {
    fwrite(STDOUT, "demo-teardown: nothing recorded — no demo content to remove.\n");
    exit(0);
}

fwrite(STDOUT, "demo-teardown: {$plan['total']} manifest entries.\n");
foreach ($plan['rows'] as $table => $n) {
    fwrite(STDOUT, sprintf("  %-22s %d row(s)\n", $table, $n));
}
fwrite(STDOUT, sprintf("  %-22s %d file(s)%s\n", 'media files', count($plan['files']),
    $plan['missing_files'] === [] ? '' : ' (' . count($plan['missing_files']) . ' already gone)'));

if ($plan['blockers'] !== []) {
    fwrite(STDERR, "\nBLOCKED — the append-only audit log pins some of this in place:\n  - "
        . implode("\n  - ", $plan['blockers']) . "\n"
        . "  Those runs stay. Everything else below can still be removed.\n");
}

if ($dry) {
    fwrite(STDOUT, "\nDry run — nothing was changed.\n");
    exit(0);
}

// ── backup BEFORE deleting ──────────────────────────────────────────────────
// VACUUM INTO is WAL-aware and integrity-checked: the snapshot folds in whatever
// the worker has committed to the write-ahead log, which a plain file copy would
// leave behind.
if (!in_array('--no-backup', $args, true)) {
    $path = (string) $container->get(Config::class)->get('database.path');
    $target = dirname($path) . '/' . pathinfo($path, PATHINFO_FILENAME)
        . '.pre-demo-teardown.' . gmdate('Ymd\THis\Z') . '.bak.sqlite';
    if (file_exists($target)) {
        fwrite(STDERR, "demo-teardown: backup target already exists ({$target}) — aborting.\n");
        exit(1);
    }
    $integrity = (new SqliteBackup($db))->snapshotTo($target);
    if ($integrity !== 'ok') {
        fwrite(STDERR, "demo-teardown: backup failed integrity_check ({$integrity}) — nothing was deleted.\n");
        exit(1);
    }
    fwrite(STDOUT, "\nbackup: {$target} (integrity={$integrity})\n");
}

$result = $teardown->run();

fwrite(STDOUT, "\nremoved:\n");
foreach ($result['rows'] as $table => $n) {
    fwrite(STDOUT, sprintf("  %-22s %d row(s)\n", $table, $n));
}
fwrite(STDOUT, sprintf("  %-22s %d file(s)%s\n", 'media files', $result['files'],
    $result['files_missing'] === 0 ? '' : " ({$result['files_missing']} already gone)"));

if ($result['kept'] !== []) {
    // Kept ON PURPOSE, and still tracked: a pinned run and everything hanging
    // off it stays whole, and re-running once the blocker is resolved finishes
    // the job. A row can also be kept because the id it was recorded under now
    // carries a NEWER row — SQLite reuses freed rowids, and teardown refuses to
    // delete a row it cannot prove is its own.
    fwrite(STDERR, "\nkept (still tracked, re-run to finish):\n  - " . implode("\n  - ", $result['kept']) . "\n");
}

fwrite(STDOUT, "demo-teardown: done. Real data was not matched on and was not touched.\n");
exit($result['kept'] === [] ? 0 : 2);
