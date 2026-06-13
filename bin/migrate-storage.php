<?php

declare(strict_types=1);

/**
 * Storage backfill: copy local objects to a target disk and flip their per-object
 * storage_disk marker. Gradual, resumable, idempotent, tenant-scoped. Verifies
 * each copy on the target before flipping; NEVER deletes the local copy.
 *
 * Run:
 *   cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/migrate-storage.php --disk=r2 --workspace=all --dry-run
 *   cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/migrate-storage.php --disk=r2 --workspace=3 --batch=200
 *
 * Flags:
 *   --disk=r2            target disk (default r2)
 *   --workspace=<id|all> scope (default all)
 *   --batch=N            rows per table per run (default 100); re-run to continue
 *   --dry-run            report what WOULD copy, mutate nothing
 */

use Kuyash\Core\Database;
use Kuyash\Storage\StorageBackfill;
use Kuyash\Storage\StorageManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap-worker.php';

$disk = 'r2';
$workspace = 'all';
$batch = 100;
$dryRun = false;
foreach ($argv as $arg) {
    if (preg_match('/^--disk=([a-z0-9_]+)$/', $arg, $m) === 1) {
        $disk = $m[1];
    } elseif (preg_match('/^--workspace=(all|\d+)$/', $arg, $m) === 1) {
        $workspace = $m[1] === 'all' ? 'all' : (int) $m[1];
    } elseif (preg_match('/^--batch=(\d+)$/', $arg, $m) === 1) {
        $batch = max(1, (int) $m[1]);
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

/** @var StorageManager $storage */
$storage = $container->get(StorageManager::class);
if (!$storage->has($disk)) {
    fwrite(STDERR, "Target disk '{$disk}' is not configured. Set its credentials in .env first.\n");
    exit(1);
}

/** @var Database $db */
$db = $container->get(Database::class);

$wsLabel = is_int($workspace) ? "workspace {$workspace}" : 'all workspaces';
fwrite(STDOUT, ($dryRun ? '[DRY-RUN] ' : '') . "Backfilling local → {$disk} ({$wsLabel}, batch {$batch})\n");

$stats = (new StorageBackfill($db, $storage))->run(
    $disk,
    $workspace,
    $dryRun,
    $batch,
    static fn (string $line): int => fwrite(STDOUT, '  ' . $line . "\n"),
);

fwrite(STDOUT, sprintf(
    "Done — copied=%d would_copy=%d missing=%d errors=%d (local copies kept)\n",
    $stats['copied'],
    $stats['would_copy'],
    $stats['missing'],
    $stats['errors'],
));

exit($stats['errors'] > 0 ? 1 : 0);
