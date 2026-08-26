<?php

declare(strict_types=1);

/**
 * Extract a still frame for every library video that has no poster yet
 * (DEV/OPS tooling, not product code).
 *
 * Posters are content-addressed by the asset's sha256, so this is idempotent and
 * cheap to re-run: an asset that already has one is a stat, not a decode. New
 * uploads get theirs at ingest; this exists for the library that predates the
 * feature.
 *
 * Read-only with respect to the database — it writes files under the cache
 * store and touches no row.
 *
 * Usage:
 *   php bin/backfill-posters.php            # every workspace
 *   php bin/backfill-posters.php --dry-run
 *   DEMO_WORKSPACE=2 php bin/backfill-posters.php
 */

use Kuyash\Core\Database;
use Kuyash\Media\AssetPoster;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $container->get(Database::class);
$posters = $container->get(AssetPoster::class);

$wsArg = getenv('DEMO_WORKSPACE');
$scope = ($wsArg !== false && ctype_digit($wsArg)) ? (int) $wsArg : null;

$rows = $scope === null
    ? $db->all("SELECT id, workspace_id, kind, stored_name, sha256, title, storage_disk FROM assets WHERE kind = 'video' ORDER BY id ASC")
    : $db->all(
        "SELECT id, workspace_id, kind, stored_name, sha256, title, storage_disk FROM assets
         WHERE kind = 'video' AND workspace_id = ? ORDER BY id ASC",
        [$scope],
    );

$made = 0;
$had = 0;
$failed = 0;
foreach ($rows as $asset) {
    if ($posters->exists($asset)) {
        $had++;
        continue;
    }
    if ($dry) {
        fwrite(STDOUT, sprintf("  would extract: asset #%d — %s\n", (int) $asset['id'], (string) $asset['title']));
        $made++;
        continue;
    }
    if ($posters->ensure($asset) === null) {
        // ensure() never throws; a null is a clip ffmpeg could not sample, an
        // object that is gone, or no ffmpeg at all. Named, not swallowed.
        fwrite(STDERR, sprintf("  no poster: asset #%d — %s\n", (int) $asset['id'], (string) $asset['title']));
        $failed++;
        continue;
    }
    $made++;
}

fwrite(STDOUT, sprintf(
    "backfill-posters: %d video(s) — %d already had one, %d %s, %d could not be made.\n",
    count($rows),
    $had,
    $made,
    $dry ? 'would be extracted' : 'extracted',
    $failed,
));
exit($failed > 0 ? 1 : 0);
