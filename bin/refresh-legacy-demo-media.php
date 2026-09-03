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
 *   php bin/refresh-legacy-demo-media.php --yes --pinned --id 7 --fixture 03
 *   php bin/refresh-legacy-demo-media.php --yes --pinned --id 3,4 --resync
 *
 * Narrowing and resyncing:
 *   --id 3,4      only these asset ids. Without it every candidate is rebuilt,
 *                 and the fixture each one gets is a function of its id — fine
 *                 for a library that was seeded in one go, wrong the moment you
 *                 want ONE item fixed without reshuffling the footage under the
 *                 titles of its neighbours.
 *   --fixture 03  the fixture to rebuild from, instead of the id-derived one, so
 *                 a clip can be made to match the title it already carries.
 *                 Single --id only: one name cannot mean two files.
 *   --resync      do not touch the bytes; re-measure the file that is already
 *                 there, write the row to match it, and rebuild the poster.
 *                 For when the ROW is what drifted: restore a database snapshot
 *                 taken before a refresh and the rows describe footage that is
 *                 no longer on disk, while the poster — content-addressed by the
 *                 row's stale sha256 — keeps serving a frame cut from bytes the
 *                 product no longer has. The video plays the new clip under a
 *                 still of the old one.
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
//
// --pinned lifts that last condition. The manifest is normally excluded because
// those rows belong to the seed and a re-seed replaces them properly. That stops
// being true once an append-only guardrail event names their run: the run cannot
// be deleted, the seed cannot reuse the name, and the item is stuck with the
// footage it was born with. Refreshing its BYTES is then the only way to fix
// what it shows — and it changes no row identity, no duration, and no audit
// record. Deleting the event to tidy the library is the thing this avoids.
$pinnedToo = in_array('--pinned', $argv, true);
$resync = in_array('--resync', $args, true);

$optVal = static function (string $name) use ($args): ?string {
    $i = array_search($name, $args, true);

    return $i !== false && isset($args[$i + 1]) ? (string) $args[$i + 1] : null;
};
$onlyIds = [];
if (($raw = $optVal('--id')) !== null) {
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            fwrite(STDERR, "refresh-legacy-demo-media: --id takes asset ids, got \"{$part}\".\n");
            exit(1);
        }
        $onlyIds[] = (int) $part;
    }
}
$forcedFixture = $optVal('--fixture');
if ($forcedFixture !== null) {
    if (count($onlyIds) !== 1) {
        // One fixture, many ids would put identical footage under several
        // different titles — the thing this flag exists to prevent.
        fwrite(STDERR, "refresh-legacy-demo-media: --fixture needs exactly one --id.\n");
        exit(1);
    }
    if (preg_match('/^[0-9]{2}$/', $forcedFixture) !== 1) {
        fwrite(STDERR, "refresh-legacy-demo-media: --fixture takes a two-digit fixture name, e.g. 03.\n");
        exit(1);
    }
}
if ($resync && $forcedFixture !== null) {
    fwrite(STDERR, "refresh-legacy-demo-media: --resync replaces no bytes, so --fixture means nothing with it.\n");
    exit(1);
}
$manifestClause = $pinnedToo ? '' : "
       AND NOT EXISTS (
           SELECT 1 FROM demo_seed_manifest m WHERE m.table_name = 'assets' AND m.row_id = a.id
       )";
$idClause = '';
$bindings = [ShowcaseSeed::MARK . '%'];
if ($onlyIds !== []) {
    $idClause = ' AND a.id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
    $bindings = array_merge($bindings, $onlyIds);
}
$candidates = $db->all(
    "SELECT a.id, a.workspace_id, a.title, a.stored_name, a.duration_s, a.sha256, a.storage_disk
     FROM assets a
     WHERE a.kind = 'video' AND a.status = 'ready' AND a.title LIKE ?{$manifestClause}{$idClause}
     ORDER BY a.id ASC",
    $bindings,
);
// An id that matched nothing is a typo or a row that is not a demo clip; either
// way the operator asked for work that will not happen, so it is said out loud
// rather than folded into a count that reads like success.
if ($onlyIds !== []) {
    $found = array_map(static fn (array $r): int => (int) $r['id'], $candidates);
    $missing = array_values(array_diff($onlyIds, $found));
    if ($missing !== []) {
        fwrite(STDERR, 'refresh-legacy-demo-media: not a refreshable demo clip (marked, ready, video'
            . ($pinnedToo ? '' : ', unowned by the manifest') . '): #' . implode(', #', $missing) . "\n");
        exit(1);
    }
}

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
        fwrite(STDOUT, sprintf("  would %s %s (keeping %.1fs)\n", $resync ? 'resync' : 'refresh', $label, $seconds));
        $done++;
        continue;
    }

    $local = $paths->pathFor('asset', (int) $asset['workspace_id'], (string) $asset['stored_name']);

    // ── resync: the bytes stay, the row is made to describe them ────────────
    if ($resync) {
        if (!is_file($local)) {
            fwrite(STDERR, "  skip {$label} — nothing on disk to resync to\n");
            $skipped++;
            continue;
        }
        $m = $probe->probe($local, 'video');
        if ($m['duration_s'] === null || abs((float) $m['duration_s'] - $seconds) > 0.75) {
            // Same guard as a rebuild, for the same reason: a compliance record
            // on a run using this asset states the duration it measured.
            fwrite(STDERR, sprintf(
                "  skip %s — the file on disk measures %ss, the row records %.1fs; that is a different clip, not a drifted row\n",
                $label,
                $m['duration_s'] === null ? '?' : (string) round((float) $m['duration_s'], 2),
                $seconds,
            ));
            $skipped++;
            continue;
        }
        $db->run(
            'UPDATE assets SET sha256 = ?, size_bytes = ?, duration_s = ?, width = ?, height = ?, aspect = ?, updated_at = ?
             WHERE id = ?',
            [
                hash_file('sha256', $local), filesize($local), $m['duration_s'],
                $m['width'], $m['height'], $m['aspect'], $now, $id,
            ],
        );
        $fresh = $db->one('SELECT id, workspace_id, kind, stored_name, sha256, storage_disk FROM assets WHERE id = ?', [$id]);
        $posters->ensure($fresh ?? []);
        fwrite(STDOUT, sprintf("  resynced %s — %ss, %sx%s\n", $label,
            (string) round((float) $m['duration_s'], 1), (string) $m['width'], (string) $m['height']));
        $done++;
        continue;
    }

    $scratch = sys_get_temp_dir() . '/legacy-src-' . bin2hex(random_bytes(6)) . '.mp4';
    $rebuilt = sys_get_temp_dir() . '/legacy-out-' . bin2hex(random_bytes(6)) . '.mp4';

    try {
        /* Same source the showcase seed uses, and for the same reason: a demo
           library has to look the same every time it is installed. Asking the
           provider for "vertical lifestyle" returned whatever was trending that
           minute — which is how the seeded library ended up with a black glitch
           frame and a clip that was not 9:16. DEMO_MEDIA=live keeps the old
           behaviour for anyone who wants fresh footage. */
        if (getenv('DEMO_MEDIA') === 'live') {
            $container->get(StockProvider::class)->fetchClip('vertical lifestyle', $seconds, $scratch);
        } else {
            $fixtures = glob(dirname(__DIR__) . '/tools/visual/fixtures/stock/[0-9][0-9].mp4') ?: [];
            if ($fixtures === []) {
                throw new RuntimeException('no committed stock fixtures to rebuild from');
            }
            sort($fixtures);
            // deterministic per asset, so re-running this changes nothing —
            // unless the operator named the fixture, in which case the point is
            // to put THIS footage under THIS title.
            $source = $fixtures[$id % count($fixtures)];
            if ($forcedFixture !== null) {
                $named = dirname(__DIR__) . '/tools/visual/fixtures/stock/' . $forcedFixture . '.mp4';
                if (!is_file($named)) {
                    throw new RuntimeException("no such fixture: {$forcedFixture}.mp4");
                }
                $source = $named;
            }
            if (!copy($source, $scratch)) {
                throw new RuntimeException('could not stage the fixture clip');
            }
        }
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
