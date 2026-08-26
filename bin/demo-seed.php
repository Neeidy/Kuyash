<?php

declare(strict_types=1);

/**
 * Showcase top-up for the DEV database (DEV-ONLY, not product code).
 *
 * Fills the screens a case study is captured from so they read as a working
 * product rather than as empty states — WITHOUT inventing anything.
 *
 * The honesty rules this obeys, because a screenshot is a claim:
 *   • every fabricated value is labelled. Demo library clips carry "[SAMPLE]" in
 *     the title; the demo social account is a mock one, and the account card
 *     already marks any figure it stands behind with a "sample" chip because the
 *     provider never reported it (`followers_count IS NULL`).
 *   • the REAL account (@ai.neeidy) is never given a made-up number. Its figures
 *     are whatever the provider actually reported, or an honest dash.
 *   • no outcome is fabricated. Runs are STARTED and left to the worker, so the
 *     queue card, the compliance verdict and the approval record are all real
 *     output of the real pipeline. Nothing is written to make a capability look
 *     like it works when it does not.
 *   • idempotent: clips are keyed by name, the demo channel is only revived
 *     if it is disconnected, and a run is started only when none is in flight.
 *
 * Usage (refuses without the confirmation, so it cannot run by accident):
 *   php bin/demo-seed.php --yes
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (!in_array('--yes', array_slice($argv, 1), true)) {
    fwrite(STDERR, "demo-seed: this writes to the database in config/database.\n"
        . "  Re-run with --yes if that is what you want.\n");
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $container->get(Kuyash\Core\Database::class);
$paths = $container->get(Kuyash\Media\MediaPaths::class);

$now = gmdate('Y-m-d\TH:i:s\Z');
$ws = (int) ($db->one('SELECT id FROM workspaces ORDER BY id LIMIT 1')['id'] ?? 0);
$wsArg = getenv('DEMO_WORKSPACE');
if ($wsArg !== false && ctype_digit($wsArg)) {
    $ws = (int) $wsArg;
}
if ($ws === 0 || $db->one('SELECT id FROM workspaces WHERE id = ?', [$ws]) === null) {
    // Checked BEFORE anything is written: an id that does not exist used to
    // create a media directory and two files, then die on the foreign key.
    fwrite(STDERR, "demo-seed: no such workspace (#{$ws}).\n");
    exit(1);
}
$owner = $db->one(
    "SELECT user_id FROM workspace_users WHERE workspace_id = ? ORDER BY (role = 'owner') DESC, id ASC LIMIT 1",
    [$ws],
);
$userId = (int) ($owner['user_id'] ?? 0);
fwrite(STDOUT, "demo-seed: workspace #{$ws}\n");

// ── 1. Library: labelled demo clips ─────────────────────────────────────────
// One clip inside the 15-45s format band and one below it, so the screens can
// show a usable video AND the honest block a too-short one produces.
//
// EVERY NUMBER HERE IS MEASURED, NEVER ASSERTED. duration_s is not a caption:
// AssetFetchExecutor copies it into the job result and ComplianceCheckExecutor
// checks the format band against it — for a distribution run there is no render
// row yet, so the asset's own figure IS the one the verdict is computed from.
// An earlier version of this script declared "22.0" for a copy of a 3-second
// file, which would have produced an audit record reading "format passed,
// duration 22.0s" for a 3-second video. That is the one thing a tool whose
// whole job is screenshots must never do.
$fixture = dirname(__DIR__) . '/tools/visual/fixtures/preview.mp4';
$probe = new Kuyash\Library\MediaProbe();

/** Build a clip of about $seconds by looping the fixture; null when ffmpeg cannot. */
$build = static function (string $source, string $target, int $seconds): ?string {
    $cmd = sprintf(
        'ffmpeg -y -stream_loop -1 -i %s -t %d -c:v libx264 -pix_fmt yuv420p -an %s 2>/dev/null',
        escapeshellarg($source),
        $seconds,
        escapeshellarg($target),
    );
    exec($cmd, $out, $code);

    return ($code === 0 && is_file($target)) ? $target : null;
};

$clips = [
    ['name' => str_repeat('a', 32) . '.mp4', 'label' => 'short (below the format band)', 'seconds' => null],
    ['name' => str_repeat('b', 32) . '.mp4', 'label' => 'inside the format band', 'seconds' => 22],
];
foreach ($clips as $clip) {
    if ($db->one('SELECT id FROM assets WHERE workspace_id = ? AND stored_name = ?', [$ws, $clip['name']]) !== null) {
        fwrite(STDOUT, "  library: {$clip['label']} clip already present\n");
        continue;
    }
    if (!is_file($fixture)) {
        fwrite(STDOUT, "  library: no fixture clip on disk — skipped\n");
        break;
    }

    // Produce the file FIRST, in a temp location, so nothing is written into
    // media storage for a row that turns out not to be insertable.
    $tmp = sys_get_temp_dir() . '/kuyash-demo-' . bin2hex(random_bytes(6)) . '.mp4';
    $made = $clip['seconds'] === null ? (copy($fixture, $tmp) ? $tmp : null) : $build($fixture, $tmp, $clip['seconds']);
    if ($made === null) {
        @unlink($tmp);
        fwrite(STDOUT, "  library: could not build the {$clip['label']} clip (is ffmpeg installed?) — skipped\n");
        continue;
    }

    // …then MEASURE it. Whatever the file actually is, is what gets stored.
    $m = $probe->probe($made, 'video');
    $dest = $paths->pathFor('asset', $ws, $clip['name']);
    @mkdir(dirname($dest), 0775, true);
    if (is_link($dest) || file_exists($dest)) {
        @unlink($tmp);
        fwrite(STDOUT, "  library: {$clip['name']} already occupies its path — skipped\n");
        continue;
    }
    // Bytes FIRST, row second. The other order can leave a row marked `ready`
    // pointing at nothing — a library screen listing a clip that is not there —
    // and /tmp is a different device here, so rename() is a copy that can fail.
    $size = (int) filesize($made);
    $sha = hash_file('sha256', $made);
    if (!@rename($made, $dest) && !(@copy($made, $dest) && @unlink($made))) {
        @unlink($made);
        fwrite(STDOUT, "  library: could not place the {$clip['label']} clip on disk — skipped\n");
        continue;
    }
    $db->run(
        'INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes,
                             sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at, storage_disk)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$ws, 'video', 'own',
         // duration FIRST: a calendar cell shows ~13 characters, and a shared
         // "[SAMPLE] Demo clip — " prefix made every option read identically.
         sprintf('[SAMPLE] %ss demo clip — vertical test footage', $m['duration_s'] === null ? '?' : (string) (int) round($m['duration_s'])),
         'preview.mp4', $clip['name'], 'video/mp4', $size, $sha,
         $m['duration_s'], $m['width'], $m['height'], $m['aspect'], '[]', 'ready', $now, $now, 'local'],
    );
    fwrite(STDOUT, sprintf(
        "  library: seeded the %s clip — measured %ss, %sx%s\n",
        $clip['label'],
        $m['duration_s'] === null ? '?' : (string) round((float) $m['duration_s'], 1),
        (string) $m['width'], (string) $m['height'],
    ));
}

// ── 2. Accounts: revive the MOCK demo channel ───────────────────────────────
// Only a mock one. A real account's connection state is a fact about the real
// world and is never written here. followers_count stays NULL, which is exactly
// what makes the card mark its figures as a sample instead of as measured.
$demo = $db->one(
    "SELECT id, status FROM accounts WHERE workspace_id = ? AND handle = '@smoke_tt'",
    [$ws],
);
if ($demo === null) {
    fwrite(STDOUT, "  accounts: no mock demo channel to revive\n");
} elseif ((string) $demo['status'] === 'connected') {
    fwrite(STDOUT, "  accounts: mock demo channel already connected\n");
} else {
    $db->run(
        "UPDATE accounts SET status = 'connected', health = 'ok', connected_at = ?, updated_at = ?
         WHERE id = ? AND workspace_id = ?",
        [$now, $now, (int) $demo['id'], $ws],
    );
    fwrite(STDOUT, "  accounts: mock demo channel reconnected (figures stay sample-marked)\n");
}

// ── 3. Queue: one post actually waiting for approval ────────────────────────
// STARTED, not written. Whatever the card ends up showing — the compliance
// verdict, the slop score, the approval record — is the real pipeline's output.
//
// And ONLY where a human gate exists. In a workspace set to auto-approval an
// empty queue is the truth: nothing is waiting because nothing needs a person.
// Starting runs to fill that screen would both misrepresent the workspace's own
// configuration and spawn a fresh run on every invocation — which is exactly
// what the first version of this script did.
$mode = (string) ($db->one('SELECT approval_mode FROM workspaces WHERE id = ?', [$ws])['approval_mode'] ?? 'manual');
// Guarded on a live RUN, not on the approval gate. "Is anything awaiting
// approval" is a state the worker has to walk the run to — so with the worker
// stopped, which is the normal state on a demo machine, every invocation
// started another run. Each one makes real caption/hashtag calls when
// OPENAI_MOCK=false, and the default workspace here has no budget cap, so the
// preflight gate would not stop the bleeding either.
$waiting = $db->one(
    "SELECT COUNT(*) AS n FROM runs
     WHERE workspace_id = ? AND status NOT IN ('completed', 'failed', 'cancelled')",
    [$ws],
);
if ($mode !== 'manual') {
    fwrite(STDOUT, "  queue: workspace approves automatically, so an empty queue is the honest state — nothing started\n");
} elseif ((int) ($waiting['n'] ?? 0) > 0) {
    fwrite(STDOUT, "  queue: a run is already in flight — not starting another\n");
} else {
    $asset = $db->one(
        "SELECT id FROM assets WHERE workspace_id = ? AND kind = 'video' AND status = 'ready' AND duration_s >= 15
         ORDER BY id DESC LIMIT 1",
        [$ws],
    );
    $wf = $db->one("SELECT id FROM workflows WHERE workspace_id = ? AND template = 'distribution' LIMIT 1", [$ws]);
    if ($asset === null || $wf === null || $userId === 0) {
        fwrite(STDOUT, "  queue: no usable clip, workflow or owner — skipped\n");
    } else {
        $engine = $container->get(Kuyash\Workflow\Engine::class);
        $runId = $engine->startRunFor($ws, (int) $wf['id'], (int) $asset['id'], $userId);
        fwrite(STDOUT, "  queue: started run #{$runId} — the worker will carry it to its approval gate\n");
    }
}

fwrite(STDOUT, "demo-seed: done. Nothing here is presented as measured that was not.\n");
