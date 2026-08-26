<?php

declare(strict_types=1);

/**
 * Replace the bytes of LEGACY demo clips with real stock footage
 * (DEV/demo tooling, not product code).
 *
 * WHY IT IS SEPARATE FROM THE SEED. `bin/demo-seed.php` only ever INSERTs, which
 * is what makes it undoable from a manifest. This script MUTATES rows that
 * already exist, so it deliberately does not live there: an earlier demo-seed
 * (before the manifest existed) left `[SAMPLE]`-titled assets behind that
 * teardown cannot remove and the seed will not touch. Their bytes are synthetic
 * test footage, so every preview built from them is a flat wash — including the
 * approval card of a run that is still waiting in the queue.
 *
 * WHAT IT WILL NOT DO:
 *   • touch anything that is NOT titled with the demo marker — the operator's
 *     own footage is never a candidate;
 *   • touch anything the manifest already owns (the seed handles those);
 *   • change a clip's DURATION. A compliance record on an existing run states the
 *     duration it measured; replacing 22.0s of footage with 14s of footage would
 *     make that record describe a file that no longer exists. The replacement is
 *     trimmed to the recorded length and re-measured, and the row is abandoned if
 *     the measurement does not match.
 *
 * It backs up the bytes it replaces, and re-measures everything it writes.
 *
 * Usage:
 *   php bin/refresh-legacy-demo-media.php --dry-run
 *   php bin/refresh-legacy-demo-media.php --yes
 */

use Kuyash\Core\Database;
use Kuyash\Demo\ShowcaseSeed;
use Kuyash\Library\MediaProbe;
use Kuyash\Media\AssetPoster;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\StockProvider;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv, 1);
$dry = in_array('--dry-run', $args, true);
if (!$dry && !in_array('--yes', $args, true)) {
    fwrite(STDERR, "refresh-legacy-demo-media: this REPLACES the bytes of existing demo clips.\n"
        . "  php bin/refresh-legacy-demo-media.php --dry-run   # list candidates, change nothing\n"
        . "  php bin/refresh-legacy-demo-media.php --yes\n");
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $container->get(Database::class);
$paths = $container->get(MediaPaths::class);
$ffmpeg = $container->get(Ffmpeg::class);
$probe = new MediaProbe();
$posters = $container->get(AssetPoster::class);
$now = gmdate('Y-m-d\TH:i:s\Z');

// Marked as demo, ready, video — and NOT owned by the manifest.
$candidates = $db->all(
    "SELECT a.id, a.workspace_id, a.title, a.stored_name, a.duration_s, a.sha256, a.storage_disk
     FROM assets a
     WHERE a.kind = 'video' AND a.status = 'ready' AND a.title LIKE ?
       AND NOT EXISTS (
           SELECT 1 FROM demo_seed_manifest m WHERE m.table_name = 'assets' AND m.row_id = a.id
       )
     ORDER BY a.id ASC",
    [ShowcaseSeed::MARK . '%'],
);

if ($candidates === []) {
    fwrite(STDOUT, "refresh-legacy-demo-media: no legacy demo clips to refresh.\n");
    exit(0);
}

fwrite(STDOUT, 'refresh-legacy-demo-media: ' . count($candidates) . " candidate(s).\n");
$done = 0;
$skipped = 0;

foreach ($candidates as $asset) {
    $id = (int) $asset['id'];
    $seconds = $asset['duration_s'] === null ? null : (float) $asset['duration_s'];
    $label = sprintf('#%d %s', $id, (string) $asset['title']);

    if ($seconds === null || $seconds < 1.0) {
        fwrite(STDERR, "  skip {$label} — no recorded duration to preserve\n");
        $skipped++;
        continue;
    }
    if ($dry) {
        fwrite(STDOUT, sprintf("  would refresh %s (keeping %.1fs)\n", $label, $seconds));
        $done++;
        continue;
    }

    $local = $paths->pathFor('asset', (int) $asset['workspace_id'], (string) $asset['stored_name']);
    $scratch = sys_get_temp_dir() . '/legacy-src-' . bin2hex(random_bytes(6)) . '.mp4';
    $rebuilt = sys_get_temp_dir() . '/legacy-out-' . bin2hex(random_bytes(6)) . '.mp4';

    try {
        $container->get(StockProvider::class)->fetchClip('vertical lifestyle', $seconds, $scratch);
        $ffmpeg->run([
            '-stream_loop', '-1', '-i', $scratch, '-t', (string) $seconds,
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-an', $rebuilt,
        ]);
    } catch (Throwable $e) {
        fwrite(STDERR, "  skip {$label} — {$e->getMessage()}\n");
        @unlink($scratch);
        @unlink($rebuilt);
        $skipped++;
        continue;
    }
    @unlink($scratch);

    // MEASURE before committing to it: the compliance record on any run using
    // this asset states a duration, and it must keep describing the real file.
    $m = $probe->probe($rebuilt, 'video');
    if ($m['duration_s'] === null || abs((float) $m['duration_s'] - $seconds) > 0.75) {
        fwrite(STDERR, sprintf(
            "  skip %s — rebuilt clip measured %ss, recorded %.1fs; leaving the original\n",
            $label,
            $m['duration_s'] === null ? '?' : (string) round((float) $m['duration_s'], 2),
            $seconds,
        ));
        @unlink($rebuilt);
        $skipped++;
        continue;
    }

    // Bytes are backed up beside themselves before anything replaces them.
    if (is_file($local) && !@copy($local, $local . '.pre-refresh.bak')) {
        fwrite(STDERR, "  skip {$label} — could not back up the original bytes\n");
        @unlink($rebuilt);
        $skipped++;
        continue;
    }
    if (!@rename($rebuilt, $local) && !(@copy($rebuilt, $local) && @unlink($rebuilt))) {
        fwrite(STDERR, "  skip {$label} — could not place the new bytes\n");
        @unlink($rebuilt);
        $skipped++;
        continue;
    }

    // The row must describe the bytes that are now there, or it lies about its
    // own content. duration_s is written back from the measurement too.
    $db->run(
        'UPDATE assets SET sha256 = ?, size_bytes = ?, duration_s = ?, width = ?, height = ?, aspect = ?, updated_at = ?
         WHERE id = ?',
        [
            hash_file('sha256', $local), filesize($local), $m['duration_s'],
            $m['width'], $m['height'], $m['aspect'], $now, $id,
        ],
    );

    // the poster is keyed on sha256, so the old one no longer applies
    $fresh = $db->one('SELECT id, workspace_id, kind, stored_name, sha256, storage_disk FROM assets WHERE id = ?', [$id]);
    $posters->ensure($fresh ?? []);

    fwrite(STDOUT, sprintf("  refreshed %s — %ss, %sx%s\n", $label,
        (string) round((float) $m['duration_s'], 1), (string) $m['width'], (string) $m['height']));
    $done++;
}

fwrite(STDOUT, sprintf(
    "refresh-legacy-demo-media: %d %s, %d skipped.\n",
    $done,
    $dry ? 'would be refreshed' : 'refreshed',
    $skipped,
));
exit($skipped > 0 ? 1 : 0);
