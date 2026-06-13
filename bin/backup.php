<?php

declare(strict_types=1);

/**
 * WAL-aware backup of the SQLite database + local media (Phase 13 hardening).
 *
 * Run:  cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/backup.php
 *       … bin/backup.php --db-only          # skip the (large) media tree
 *       … bin/backup.php --out=/path/to/dir # custom destination root
 *
 * Produces a timestamped directory under storage/backups/<UTC>/ containing:
 *   - database.sqlite  (a consistent VACUUM INTO snapshot, integrity-checked)
 *   - media/{asset,cache,render}/…  (local objects; omitted with --db-only)
 *   - manifest.json    (timestamps, sizes, source paths)
 *
 * R2 NOTE: when STORAGE_DRIVER=r2, durable objects live in Cloudflare R2, which
 * has its own redundancy — this backup does NOT re-download them. Restoring a DB
 * whose rows point at R2 keys just works against the same bucket. The local
 * media tree backed up here is the cache/scratch + any local-disk objects.
 */

use Kuyash\Core\Config;
use Kuyash\Core\Database;
use Kuyash\Core\SqliteBackup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$basePath = dirname(__DIR__);

$argv = $_SERVER['argv'] ?? [];
$dbOnly = in_array('--db-only', $argv, true);
$outRoot = $basePath . '/storage/backups';
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--out=')) {
        $outRoot = substr((string) $arg, 6);
    }
}

/** @var Config $config */
$config = $container->get(Config::class);
/** @var Database $db */
$db = $container->get(Database::class);

$stamp = gmdate('Ymd\THis\Z');
$dest = rtrim($outRoot, '/') . '/' . $stamp;
if (!is_dir($dest) && !mkdir($dest, 0750, true) && !is_dir($dest)) {
    fwrite(STDERR, "Cannot create backup directory: {$dest}\n");
    exit(1);
}

// 1) consistent DB snapshot (WAL-aware) + integrity check ---------------------
$dbTarget = $dest . '/database.sqlite';
$integrity = (new SqliteBackup($db))->snapshotTo($dbTarget);
if ($integrity !== 'ok') {
    fwrite(STDERR, "ABORT: snapshot failed integrity_check ({$integrity}). Backup is NOT trustworthy.\n");
    exit(1);
}
$dbBytes = (int) (@filesize($dbTarget) ?: 0);
echo "DB snapshot: {$dbTarget} ({$dbBytes} bytes) integrity={$integrity}\n";

// 2) local media tree (skippable) ---------------------------------------------
$mediaFiles = 0;
$mediaBytes = 0;
if (!$dbOnly) {
    $media = (array) $config->get('media');
    $roots = [
        'asset' => (string) ($media['asset_root'] ?? ''),
        'cache' => (string) ($media['cache_root'] ?? ''),
        'render' => (string) ($media['render_root'] ?? ''),
    ];
    foreach ($roots as $name => $src) {
        if ($src === '' || !is_dir($src)) {
            continue;
        }
        [$f, $b] = copyTree($src, $dest . '/media/' . $name);
        $mediaFiles += $f;
        $mediaBytes += $b;
    }
    echo "Media: {$mediaFiles} files ({$mediaBytes} bytes)\n";
} else {
    echo "Media: skipped (--db-only)\n";
}

// 3) manifest -----------------------------------------------------------------
$manifest = [
    'created_at' => $stamp,
    'db_bytes' => $dbBytes,
    'db_integrity' => $integrity,
    'media_files' => $mediaFiles,
    'media_bytes' => $mediaBytes,
    'db_only' => $dbOnly,
    'source_db' => (string) $config->get('database.path'),
    'storage_driver' => (string) $config->get('storage.driver'),
];
file_put_contents($dest . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nBackup complete → {$dest}\n";
echo "Restore with: php bin/restore.php {$dest} --force\n";
exit(0);

/**
 * Recursive file copy (no shell, no rm). Returns [fileCount, byteCount].
 *
 * @return array{0: int, 1: int}
 */
function copyTree(string $src, string $dst): array
{
    if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
        throw new RuntimeException("Cannot create media backup dir: {$dst}");
    }
    $files = 0;
    $bytes = 0;
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
        $bytes += (int) $item->getSize();
    }

    return [$files, $bytes];
}
