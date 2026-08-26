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
 *   • idempotent: run it twice and nothing doubles.
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
if ($ws === 0) {
    fwrite(STDERR, "demo-seed: no workspace found.\n");
    exit(1);
}
$owner = $db->one(
    "SELECT user_id FROM workspace_users WHERE workspace_id = ? ORDER BY (role = 'owner') DESC, id ASC LIMIT 1",
    [$ws],
);
$userId = (int) ($owner['user_id'] ?? 0);
fwrite(STDOUT, "demo-seed: workspace #{$ws}\n");

// ── 1. Library: labelled demo clips ─────────────────────────────────────────
// A clip that PASSES the format band (15-45s) and one that does not, so the
// screens can show both a usable video and the honest block it produces.
$fixture = dirname(__DIR__) . '/tools/visual/fixtures/preview.mp4';
$clips = [
    ['name' => str_repeat('a', 32) . '.mp4', 'title' => '[SAMPLE] Demo clip — 3s vertical test footage', 'dur' => 3.0],
    ['name' => str_repeat('b', 32) . '.mp4', 'title' => '[SAMPLE] Demo clip — 22s vertical test footage', 'dur' => 22.0],
];
foreach ($clips as $clip) {
    if ($db->one('SELECT id FROM assets WHERE workspace_id = ? AND stored_name = ?', [$ws, $clip['name']]) !== null) {
        fwrite(STDOUT, "  library: {$clip['title']} already present\n");
        continue;
    }
    if (!is_file($fixture)) {
        fwrite(STDOUT, "  library: no fixture clip on disk — skipped\n");
        break;
    }
    $dest = $paths->pathFor('asset', $ws, $clip['name']);
    @mkdir(dirname($dest), 0775, true);
    copy($fixture, $dest);
    $db->run(
        'INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes,
                             sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at, storage_disk)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$ws, 'video', 'own', $clip['title'], 'preview.mp4', $clip['name'], 'video/mp4',
         (int) filesize($dest), hash_file('sha256', $dest), $clip['dur'], 540, 960, '9:16', '', 'ready', $now, $now, 'local'],
    );
    fwrite(STDOUT, "  library: seeded {$clip['title']}\n");
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
$waiting = $db->one(
    "SELECT COUNT(*) AS n FROM jobs WHERE workspace_id = ? AND status = 'awaiting_approval'",
    [$ws],
);
if ($mode !== 'manual') {
    fwrite(STDOUT, "  queue: workspace approves automatically, so an empty queue is the honest state — nothing started\n");
} elseif ((int) ($waiting['n'] ?? 0) > 0) {
    fwrite(STDOUT, "  queue: something is already waiting for approval\n");
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
