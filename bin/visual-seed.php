<?php

declare(strict_types=1);

/**
 * Phase 15.9 — Visual-test seed (DEV-ONLY, not product code).
 *
 * Populates an ISOLATED visual-test database with deterministic mock content so
 * the headless-screenshot harness (tools/visual/shot.mjs) renders populated
 * screens instead of bare empty states. Idempotent: safe to run repeatedly.
 *
 * Run against the visual DB (NEVER the real dev DB) — gate.sh sets this:
 *   DB_PATH=storage/database/kuyash-visual.sqlite \
 *   APP_ENV=dev OPENAI_MOCK=true STORAGE_DRIVER=local \
 *   /opt/homebrew/opt/php@8.3/bin/php bin/visual-seed.php
 *
 * Deterministic credentials come from the environment (shared with the harness
 * so login matches), with dev-only defaults. The password is read but NEVER
 * printed — the seeded DB is gitignored and local-only.
 *
 * MOSTLY MEDIA-FREE: the only on-disk media is ONE playable preview render
 * (Phase 21 D6) — a tiny committed mock clip + poster copied into render storage
 * so the inline approval player actually plays. Its poster file EXISTS (the gate's
 * <img> loads it → still no 404); the <video> is preload="none" so it loads only
 * on click. Everything else stays media-free, so library/quick/digest still show
 * their empty states (which the visual gate wants screenshotted anyway).
 */

use Kuyash\Core\Database;
use Kuyash\Media\MediaPaths;
use Kuyash\Workflow\Nodes;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// Safety guard: only ever seed an ISOLATED visual DB. Without this, running the
// seeder bare (no DB_PATH) would fall back to the real dev DB (config/database
// default) and inject mock content into it. Require DB_PATH to point at a
// "visual" sqlite — exactly what tools/visual/gate.sh exports.
$dbPath = getenv('DB_PATH') ?: '';
if (!str_contains($dbPath, 'visual')) {
    fwrite(STDERR, "Refusing to seed: DB_PATH must point at an isolated visual DB (got: " . ($dbPath ?: '<unset>') . ").\n");
    fwrite(STDERR, "Run via tools/visual/gate.sh, or set DB_PATH=storage/database/kuyash-visual.sqlite.\n");
    exit(2);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var Database $db */
$db = $container->get(Database::class);

// Fail fast (and loud) if migrations have not run on this DB yet.
if ($db->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'") === null) {
    fwrite(STDERR, "Visual DB is not migrated. Run: DB_PATH=... php bin/migrate.php\n");
    exit(1);
}

$email = getenv('VISUAL_TEST_EMAIL') ?: 'visual@kuyash.local';
$password = getenv('VISUAL_TEST_PASSWORD') ?: 'visual-dev-only-password';
$now = gmdate('Y-m-d\TH:i:s\Z');

/** Minute-offset ISO timestamp helper for a believable recent timeline. */
$ago = static function (int $minutes) use ($now): string {
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($now) - $minutes * 60);
};

// --- 1. User + workspace + owner membership (find-or-create) ---------------

$existing = $db->one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing !== null) {
    $userId = (int) $existing['id'];
    $membership = $db->one('SELECT workspace_id FROM workspace_users WHERE user_id = ? LIMIT 1', [$userId]);
    $workspaceId = (int) ($membership['workspace_id'] ?? 0);
} else {
    [$userId, $workspaceId] = $db->transaction(static function (Database $db) use ($email, $password, $now): array {
        $db->run(
            'INSERT INTO users (email, password_hash, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$email, password_hash($password, PASSWORD_ARGON2ID), 'Visual Test', $now, $now],
        );
        $userId = $db->lastInsertId();

        $db->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', ['Visual Test Workspace', $now, $now]);
        $workspaceId = $db->lastInsertId();

        $db->run(
            "INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (?, ?, 'owner', ?)",
            [$workspaceId, $userId, $now],
        );

        return [$userId, $workspaceId];
    });
}

if ($workspaceId === 0) {
    fwrite(STDERR, "Could not resolve a workspace for the visual user.\n");
    exit(1);
}

// Content seeding is idempotent: if this workspace already has runs, it's seeded.
if ($db->one('SELECT id FROM runs WHERE workspace_id = ? LIMIT 1', [$workspaceId]) !== null) {
    fwrite(STDOUT, "Visual DB already seeded (user #{$userId}, workspace #{$workspaceId}). No changes.\n");
    exit(0);
}

// Phase 21 (D6): one PLAYABLE preview render so the inline approval player is not
// a dead placeholder. A tiny committed mock clip + poster are copied into render
// storage and the awaiting render_review job links its draft_render_id. The poster
// file therefore EXISTS (the visual gate's <img> loads it → still 0 × 404); the
// <video> is preload="none", so it loads only on a real click — where it plays.
$paths = $container->get(MediaPaths::class);
$fixtureDir = dirname(__DIR__) . '/tools/visual/fixtures';
$playableMp4 = str_repeat('c', 32) . '.mp4';      // deterministic, NAME_RE-valid
$playablePoster = str_repeat('d', 32) . '.jpg';
$hasPlayable = is_file($fixtureDir . '/preview.mp4') && is_file($fixtureDir . '/preview.jpg');
if ($hasPlayable) {
    copy($fixtureDir . '/preview.mp4', $paths->pathFor('render', $workspaceId, $playableMp4));
    copy($fixtureDir . '/preview.jpg', $paths->pathFor('render', $workspaceId, $playablePoster));
}

// --- 2. Content (one short transaction; mock, deterministic) ----------------

$db->transaction(static function (Database $db) use ($workspaceId, $userId, $now, $ago, $hasPlayable, $playableMp4, $playablePoster): void {
    // Trend Radar: niche config + a batch of cached signals.
    $db->run(
        'INSERT INTO trend_config (workspace_id, niche, region, updated_at) VALUES (?, ?, ?, ?)',
        [$workspaceId, 'home cooking', 'US', $now],
    );
    $topics = [
        ['One-pan weeknight dinners', 92, 'faceless'],
        ['30-second pantry hacks', 88, 'faceless'],
        ['Budget meal-prep for the week', 81, 'face'],
        ['Air-fryer myths, busted', 76, 'faceless'],
        ['Five-ingredient desserts', 70, 'faceless'],
    ];
    foreach ($topics as $rank => [$topic, $score, $format]) {
        $db->run(
            'INSERT INTO trends (workspace_id, niche, region, source, topic, score, format, rank, raw_json, fetched_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, 'home cooking', 'US', 'mock', $topic, $score, $format, $rank, '{}', $ago(45), $ago(45)],
        );
    }

    // Default workflows (correct node structure straight from the canonical registry).
    $workflowIds = [];
    $names = [
        Nodes::TEMPLATE_FULL => 'Full pipeline',
        Nodes::TEMPLATE_DISTRIBUTION => 'Distribution',
        Nodes::TEMPLATE_QUICK_CREATE => 'Quick Create',
    ];
    foreach ($names as $template => $name) {
        $db->run(
            'INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $workspaceId,
                $name,
                $template,
                json_encode(Nodes::defaultNodes($template), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ],
        );
        $workflowIds[$template] = $db->lastInsertId();
    }
    $fullId = $workflowIds[Nodes::TEMPLATE_FULL];
    $distId = $workflowIds[Nodes::TEMPLATE_DISTRIBUTION];
    $fullNodes = json_encode(Nodes::defaultNodes(Nodes::TEMPLATE_FULL), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $distNodes = json_encode(Nodes::defaultNodes(Nodes::TEMPLATE_DISTRIBUTION), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $makeRun = static function (Database $db, int $ws, int $wf, string $nodes, string $entityType, string $status, ?string $node, int $userId, string $created, string $updated): int {
        $db->run(
            'INSERT INTO runs (workspace_id, workflow_id, entity_type, entity_id, nodes_json, status, current_node, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ws, $wf, $entityType, null, $nodes, $status, $node, $userId, $created, $updated],
        );

        return $db->lastInsertId();
    };

    // Run A — running (active, mid-pipeline).
    $runRunning = $makeRun($db, $workspaceId, $fullId, $fullNodes, 'trend', 'running', 'VOICE', $userId, $ago(18), $ago(4));
    // Run B — awaiting approval (active + awaiting; drives the showcase strip).
    $runAwaiting = $makeRun($db, $workspaceId, $fullId, $fullNodes, 'trend', 'awaiting_approval', 'SCRIPT', $userId, $ago(30), $ago(8));
    // Run C — completed.
    $runDone = $makeRun($db, $workspaceId, $distId, $distNodes, 'library', 'completed', 'PUBLISH', $userId, $ago(180), $ago(120));

    // Jobs for the awaiting run: a script approval + a preview/render approval.
    // result_json is valid JSON with NO draft_render_id / library_asset_id, so
    // the approval cards render WITHOUT a media element (no missing-file 404).
    $insertJob = static function (Database $db, int $ws, int $run, string $node, int $step, string $type, string $status, string $result, string $created): void {
        $db->run(
            'INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, run_after, priority, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ws, $run, $node, $step, $type, $status, '{}', $result, $created, 100, $created],
        );
    };
    $insertJob(
        $db,
        $workspaceId,
        $runAwaiting,
        'SCRIPT',
        3,
        'script_draft',
        'awaiting_approval',
        json_encode([
            'ai_label_required' => false,
            'summary' => 'A 30-second one-pan dinner with a punchy hook and three quick steps.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $ago(8),
    );
    // PREVIEW approval — compliance passed; with a PLAYABLE draft render when the
    // fixture exists (so the inline player actually plays a real mock clip).
    $reviewResult = ['ai_label_required' => true, 'compliance' => ['status' => 'pass']];
    if ($hasPlayable) {
        $db->run(
            'INSERT INTO renders (workspace_id, run_id, kind, stored_name, poster_name, mime, width, height, duration_s, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runAwaiting, 'draft', $playableMp4, $playablePoster, 'video/mp4', 540, 960, 3.0, $ago(6)],
        );
        $reviewResult['draft_render_id'] = $db->lastInsertId();
    }
    $insertJob(
        $db,
        $workspaceId,
        $runAwaiting,
        'PREVIEW',
        10,
        'render_review',
        'awaiting_approval',
        json_encode($reviewResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $ago(6),
    );
    // Run A's per-node progression — gives the dashboard "production line"
    // node-graph a real done → done → done → active → waiting shape (the early
    // nodes finished, VOICE is processing now). Media-free (no renders linked).
    //
    // Phase 21 §4: each finished job carries a REALISTIC result_json (same shape
    // the executors emit) so the node drawer shows real, per-node-DISTINCT output
    // (trend title/score, idea hook, script body, voice/duration) instead of one
    // generic blurb. Deterministic + media-free (no file refs, no draft render).
    $j = static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $seedScript = "Stop buying takeout on busy weeknights.\n\n"
        . "Here is the quick version about one-pan weeknight dinners. Beat one sets it up, "
        . "beat two shows the turn, beat three lands the takeaway (brisk pacing).\n\nSave this for your next grocery run.";
    $insertJob($db, $workspaceId, $runRunning, 'TREND', 1, 'trend_fetch', 'ready', $j([
        'trend' => 'One-pan weeknight dinners', 'niche' => 'home cooking', 'region' => 'US',
        'score' => 92, 'format' => 'faceless', 'source' => 'mock', 'origin' => 'niche',
    ]), $ago(16));
    $insertJob($db, $workspaceId, $runRunning, 'IDEA', 2, 'idea_generation', 'ready', $j([
        'idea' => 'Angle on "One-pan weeknight dinners": one sheet pan, five pantry staples, zero cleanup.',
        'hook' => 'Stop buying takeout on busy weeknights.', 'format' => '15-45s vertical',
    ]), $ago(14));
    $insertJob($db, $workspaceId, $runRunning, 'SCRIPT', 3, 'script_draft', 'ready', $j([
        'script' => $seedScript, 'word_count' => 44, 'estimated_duration_s' => 17.6,
    ]), $ago(12));
    // VOICE is still PROCESSING — a mid-flight job has not written its result yet,
    // so result_json stays empty and the drawer honestly shows "no output yet"
    // (the third state alongside done→real-output and wait→not-started).
    $insertJob($db, $workspaceId, $runRunning, 'VOICE', 4, 'tts', 'processing', '{}', $ago(4));

    // A couple of finished jobs on the completed run (flat list texture).
    $insertJob($db, $workspaceId, $runDone, 'CAPTION', 2, 'caption_generation', 'ready', '{}', $ago(150));
    $insertJob($db, $workspaceId, $runDone, 'PUBLISH', 7, 'publish', 'published', '{}', $ago(120));

    // Renders: count-only rows for the dashboard KPI (never served on these
    // pages — no job links a draft_render_id, so nothing requests the file).
    foreach (['draft', 'final'] as $i => $kind) {
        $db->run(
            'INSERT INTO renders (workspace_id, run_id, kind, stored_name, mime, width, height, duration_s, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runDone, $kind, str_repeat((string) ($i + 1), 32) . '.mp4', 'video/mp4', 1080, 1920, 28.0, $ago(125)],
        );
    }

    // Event log (drives /logs): REAL dictionary keys + full params so the feed
    // renders fully interpolated and humanized — no raw event key, no literal
    // {run}/{workflow}, no internal job-type enum (Phase 21 zero-jargon /logs).
    $events = [
        ['info', 'transition', 'run.started', ['run' => $runDone, 'workflow' => 'Distribution'], 75],
        ['info', 'transition', 'job.finished', ['type' => 'caption_generation', 'run' => $runDone], 60],
        ['info', 'compliance', 'compliance.passed', ['run' => $runDone], 40],
        ['warn', 'guardrail', 'guardrail.daily_cap_reached', ['used' => 2, 'cap' => 3, 'run' => $runDone], 20],
    ];
    foreach ($events as [$level, $kind, $key, $params, $minsAgo]) {
        $db->run(
            'INSERT INTO events (workspace_id, run_id, level, kind, key, params_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runDone, $level, $kind, $key, json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago($minsAgo)],
        );
    }

    // Credit ledger: one manual grant → a non-zero balance on usage/dashboard.
    $db->run(
        "INSERT INTO credit_transactions (workspace_id, type, amount_cents, reason, created_at) VALUES (?, 'grant', ?, ?, ?)",
        [$workspaceId, 5000, 'Visual-test seed grant', $ago(200)],
    );

    // Social accounts (populate /accounts; no reference asset → no media dep).
    $accounts = [
        ['instagram', '@visual.kitchen', 'connected', 'ok'],
        ['tiktok', '@visual.kitchen', 'connected', 'ok'],
        ['youtube', 'Visual Kitchen', 'reauth_needed', 'degraded'],
    ];
    foreach ($accounts as [$platform, $handle, $status, $health]) {
        $db->run(
            'INSERT INTO accounts (workspace_id, platform, handle, status, health, connected_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $platform, $handle, $status, $health, $now, $now, $now],
        );
    }

    // Weekly publishing plan (Phase 23) — real slot rows, not fabricated metrics:
    // a slot is operator configuration, so seeding it populates the settings
    // screen and the approval picker without asserting anything about results.
    $db->run("UPDATE workspaces SET timezone = 'Europe/Istanbul' WHERE id = ?", [$workspaceId]);
    foreach ([[1, '09:00'], [3, '18:30'], [5, '12:00']] as [$weekday, $time]) {
        $db->run(
            'INSERT INTO publish_slots (workspace_id, account_id, weekday, time_hhmm, enabled, created_at, updated_at)
             VALUES (?, NULL, ?, ?, 1, ?, ?)',
            [$workspaceId, $weekday, $time, $now, $now],
        );
    }

    // Library assets — VIDEO kind only (the library INDEX renders a styled tile +
    // hover play affordance for video, with NO <img>/<video> load → still 0 × 404;
    // the detail page is out of the gate's route set). Populates the v3 asset grid.
    $libAssets = [
        ['own', 'Morning routine reset', 24.0, ['routine', 'morning']],
        ['own', 'One-pan dinner b-roll', 31.0, ['cooking', 'dinner']],
        ['face', 'Talking-head intro clip', 18.0, ['face', 'intro']],
    ];
    foreach ($libAssets as $idx => [$type, $title, $dur, $tags]) {
        $db->run(
            'INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
                size_bytes, sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at)
             VALUES (?, \'video\', ?, ?, ?, ?, \'video/mp4\', ?, ?, ?, 1080, 1920, \'9:16\', ?, \'ready\', ?, ?)',
            [
                $workspaceId, $type, $title, $title . '.mp4',
                str_pad((string) ($idx + 1), 32, '0', STR_PAD_LEFT) . '.mp4',
                4_200_000 + $idx * 100_000, str_repeat('0', 64), $dur,
                json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(90 - $idx * 5), $ago(90 - $idx * 5),
            ],
        );
    }
});

fwrite(STDOUT, "Seeded visual DB: user #{$userId} ({$email}) → workspace #{$workspaceId} with mock trends, workflows, runs, approvals, ledger and accounts.\n");
exit(0);
