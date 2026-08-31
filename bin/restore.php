<?php

declare(strict_types=1);

/**
 * Restore a backup produced by bin/backup.php (Phase 13 hardening).
 *
 * Run:  php bin/restore.php storage/backups/<UTC>          # DRY-RUN
 *       php bin/restore.php storage/backups/<UTC> --force  # apply
 *
 * Safety:
 *   - Without --force it is a DRY-RUN: it validates the backup (integrity_check)
 *     and prints exactly what it would replace, touching nothing.
 *   - The backup's DB is integrity-checked BEFORE the live DB is touched — a bad
 *     backup never replaces a good database.
 *   - The current DB (+ its -wal/-shm sidecars) is MOVED ASIDE to
 *     <db>.pre-restore-<UTC>, never deleted — the operation is reversible.
 *   - Media is copied over the configured roots (existing files overwritten,
 *     none deleted).
 */

use Kuyash\Core\Config;
use Kuyash\Core\Database;
use Kuyash\Core\SqliteBackup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$force = in_array('--force', $argv, true);
$backupDir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with((string) $arg, '--')) {
        $backupDir = rtrim((string) $arg, '/');
        break;
    }
}

if ($backupDir === null || !is_dir($backupDir)) {
    fwrite(STDERR, "Usage: php bin/restore.php <backup-dir> [--force]\n");
    exit(1);
}

$backupDbFile = $backupDir . '/database.sqlite';
if (!is_file($backupDbFile)) {
    fwrite(STDERR, "No database.sqlite in {$backupDir}\n");
    exit(1);
}

/** @var Config $config */
$config = $container->get(Config::class);
$liveDb = (string) $config->get('database.path');
$media = (array) $config->get('media');

// validate the backup BEFORE touching the live DB
$integrity = (new SqliteBackup($container->get(Database::class)))->integrityCheck($backupDbFile);
if ($integrity !== 'ok') {
    fwrite(STDERR, "ABORT: backup failed integrity_check ({$integrity}). Live DB untouched.\n");
    exit(1);
}
echo "Backup integrity: {$integrity}\n";
echo "Will restore:\n  DB   {$backupDbFile}\n       → {$liveDb}\n";

if (!$force) {
    echo "\nDRY-RUN — nothing changed. Re-run with --force to apply.\n";
    exit(0);
}

// move the current DB + sidecars aside (reversible; never deleted)
$stamp = gmdate('Ymd\THis\Z');
foreach (['', '-wal', '-shm'] as $suffix) {
    $f = $liveDb . $suffix;
    if (is_file($f) && !@rename($f, $f . '.pre-restore-' . $stamp)) {
        fwrite(STDERR, "ABORT: could not move aside {$f}\n");
        exit(1);
    }
}

$dir = dirname($liveDb);
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
    fwrite(STDERR, "ABORT: cannot create DB directory {$dir}\n");
    exit(1);
}
if (!@copy($backupDbFile, $liveDb)) {
    fwrite(STDERR, "ABORT: failed to copy snapshot into place.\n");
    exit(1);
}
echo "DB restored → {$liveDb}\n";

// media (best-effort, overwrite; none deleted)
$restored = 0;
foreach (['asset' => 'asset_root', 'cache' => 'cache_root', 'render' => 'render_root'] as $name => $cfgKey) {
    $src = $backupDir . '/media/' . $name;
    $dst = (string) ($media[$cfgKey] ?? '');
    if (!is_dir($src) || $dst === '') {
        continue;
    }
    $restored += restoreTree($src, $dst);
}
echo "Media restored: {$restored} files\n";

// verify the restored live DB
$liveIntegrity = (new SqliteBackup(new Database($liveDb)))->integrityCheck($liveDb);
echo "Restored DB integrity: {$liveIntegrity}\n";
echo $liveIntegrity === 'ok' ? "\nRestore complete.\n" : "\nWARNING: restored DB failed integrity_check.\n";
exit($liveIntegrity === 'ok' ? 0 : 1);

/** Recursive copy (overwrite, no delete). Returns file count. */
function restoreTree(string $src, string $dst): int
{
    if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
        throw new RuntimeException("Cannot create media dir: {$dst}");
    }
    $files = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        $target = $dst . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0750, true);
            }
            continue;
        }
        if (!@copy($item->getPathname(), $target)) {
            throw new RuntimeException("Copy failed: {$item->getPathname()}");
        }
        $files++;
    }

    return $files;
}
