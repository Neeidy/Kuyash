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

// $email is needed inside: the seeded edits and their audit lines name a person,
// and a `static` closure that does not import it silently binds null — which
// renders as the literal "{user}" on /logs and in every run timeline.
$db->transaction(static function (Database $db) use ($workspaceId, $userId, $email, $now, $ago, $hasPlayable, $playableMp4, $playablePoster): void {
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
    // created_at matches its own "run started" event and its first job row —
    // the header and the timeline must not name two different moments.
    $runDone = $makeRun($db, $workspaceId, $distId, $distNodes, 'library', 'completed', 'PUBLISH', $userId, $ago(155), $ago(120));

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
    // ComplianceCheckExecutor: a REQUIRED label makes the status
    // pass_with_ai_label — plain 'pass' with a required label cannot happen.
    $reviewResult = ['ai_label_required' => true, 'compliance' => ['status' => 'pass_with_ai_label']];
    if ($hasPlayable) {
        $db->run(
            'INSERT INTO renders (workspace_id, run_id, kind, stored_name, poster_name, mime, width, height, duration_s, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runAwaiting, 'draft', $playableMp4, $playablePoster, 'video/mp4', 540, 960, 3.0, $ago(6)],
        );
        $reviewResult['draft_render_id'] = $db->lastInsertId();
    }
    // Phase 25: a run waiting at the publish gate HAS captions and tags — they
    // are written at steps 7/8, long before the review at step 12. Without them
    // the post-text editor has nothing to edit and never appears in a
    // screenshot. Real shape (the same keys ContentExecutor emits), deterministic,
    // media-free, and NOT marked as edited — a demo must not claim a person
    // touched something nobody touched.
    $insertJob($db, $workspaceId, $runAwaiting, 'CAPTION', 7, 'caption_generation', 'ready', json_encode([
        'captions' => [
            'instagram' => "One pan, five pantry staples, zero cleanup. Save this for your next grocery run.",
            'tiktok' => "Stop buying takeout on weeknights — one pan and five things you already own.",
            'youtube' => "One-pan weeknight dinner: five pantry staples, no cleanup, under 20 minutes.",
        ],
        'prompt_version' => 'caption.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(8));
    $insertJob($db, $workspaceId, $runAwaiting, 'HASHTAGS', 8, 'hashtag_generation', 'ready', json_encode([
        'hashtags' => ['#onepan', '#weeknightdinner', '#easyrecipes', '#pantrystaples', '#nocleanup'],
        'prompt_version' => 'hashtag.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(8));

    // A run sitting at the publish gate has already PASSED compliance (step 9),
    // and that job — not the review card — is where ai_label_required actually
    // lives for the publisher and for the text editor. Seeding it keeps the demo
    // the same shape as production instead of a near-miss.
    $insertJob($db, $workspaceId, $runAwaiting, 'COMPLIANCE', 9, 'compliance_check', 'ready', json_encode([
        'status' => 'pass_with_ai_label',
        'policy' => 'kuyash-v1',
        'checks' => [
            'ai_label' => ['required' => true, 'reasons' => ['synthetic_voice']],
            'format' => ['status' => 'pass', 'duration_s' => 3.0, 'aspect' => '9:16', 'reasons' => []],
            'slop' => ['status' => 'pass', 'score' => 0.12, 'warn_at' => 0.55, 'block_at' => 0.8, 'history_runs' => 2],
        ],
        'reasons' => [],
        'ai_label_required' => true,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(7));

    $insertJob(
        $db,
        $workspaceId,
        $runAwaiting,
        // The engine files render_review under PUBLISH (Nodes::NODE_JOBS), and
        // that is the node approvedBefore()/approvedAlready() filter on — seeding
        // 'PREVIEW' meant an approval taken here could never raise the
        // "edited after approval" record the phase exists to make honest.
        'PUBLISH',
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

    // A couple of finished jobs on the completed run (flat list texture). Its
    // caption/tag rows carry REAL text so the finished run's post-text editor
    // renders in its READ-ONLY state — the state a screenshot could not reach
    // while every seeded run was still editable.
    // …and it was edited before it went out, with a SIMILARITY warning — the one
    // branch the compliance chip exists for, showing a real score. The awaiting
    // run below carries a non-similarity warning, so the two chip wordings are
    // photographed side by side and neither is guessed at.
    $doneCaptions = [
        'instagram' => "Three things I wish I knew before my first sourdough loaf.",
        'tiktok' => "Sourdough beginners: these three mistakes cost me a month.",
        'youtube' => "Three sourdough mistakes every beginner makes (and how to skip them).",
    ];
    $doneCaptionsAi = [
        'instagram' => "Three things to know before your first sourdough loaf.",
        'tiktok' => "Sourdough beginners: three mistakes that cost me a month.",
        'youtube' => "Three sourdough mistakes beginners make (and how to skip them).",
    ];
    $doneTags = [
        '#sourdough', '#baking', '#beginnerbaker', '#breadmaking', '#starter',
        '#homebaking', '#slowfood', '#crumb', '#openrumb', '#firstloaf',
        '#bakingtips', '#wildyeast', '#fermentation', '#breadhead',
    ];
    $doneEdit = [
        'by' => $userId,
        'by_email' => $email,
        'at' => $ago(130),
        'hash' => \Kuyash\Content\ContentRevision::hash($doneCaptions, $doneTags),
        'verdict' => [
            'status' => 'warn',
            'policy' => 'kuyash-v1',
            'reasons' => [],
            // 0.61 sits between SLOP_WARN (0.55) and SLOP_BLOCK (0.80): warned,
            // saved, published — exactly what the thresholds allow.
            'slop' => ['score' => 0.61, 'history_runs' => 4],
            'warnings' => [['key' => 'content.similar', 'params' => ['score' => '0.61']]],
        ],
    ];
    // Every node of the DISTRIBUTION track, in canonical step order. Without the
    // steps that carry no interesting payload, a COMPLETED, PUBLISHED run showed
    // its first node as "pending" — a run cannot publish without starting.
    // The shape AssetFetchExecutor actually emits — and the one the rows below
    // depend on: without duration_s the compliance row could not have measured
    // "28.0s, 9:16", and without a visual_ref final_render would have refused.
    $insertJob($db, $workspaceId, $runDone, 'LIBRARY', 1, 'asset_fetch', 'ready', $j([
        'source' => 'library', 'visual_kind' => 'video',
        'visual_ref' => 'asset:1:' . str_repeat('3', 32) . '.mp4',
        'asset_id' => 1, 'title' => 'Sourdough loaf — cutting board',
        'ai_label_required' => false, 'duration_s' => 28.0,
    ]), $ago(155));
    $insertJob($db, $workspaceId, $runDone, 'CAPTION', 2, 'caption_generation', 'ready', json_encode([
        'captions' => $doneCaptions,
        'captions_ai' => $doneCaptionsAi,
        'edit' => $doneEdit,
        'prompt_version' => 'caption.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(150));
    // 14 tags against the tightest limit of 15: the "getting close" counter
    // state, on a read-only run where no warning callout could contradict it.
    // (The awaiting run below carries 16 — genuinely over — so both states are
    // photographed and both are states the gate can actually reach.)
    $insertJob($db, $workspaceId, $runDone, 'HASHTAGS', 3, 'hashtag_generation', 'ready', json_encode([
        'hashtags' => $doneTags,
        'hashtags_ai' => $doneTags,
        'edit' => $doneEdit,
        'prompt_version' => 'hashtag.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(150));
    // A published run passed compliance on the way — showing it as a completed
    // publish with a PENDING compliance node depicts an outcome that cannot
    // happen, on the one screen a compliance reviewer reads.
    $insertJob($db, $workspaceId, $runDone, 'MUSIC NOTE / STYLE', 4, 'music_note', 'ready', $j([
        'mood' => 'calm',
        'note' => 'suggestion only — platform-native sounds cannot be published via API',
    ]), $ago(145));
    $insertJob($db, $workspaceId, $runDone, 'PREVIEW', 5, 'preview', 'ready', $j([
        'note' => 'preview is the in-pipeline checkpoint; the reviewable render is the draft',
    ]), $ago(143));
    $insertJob($db, $workspaceId, $runDone, 'COMPLIANCE', 6, 'compliance_check', 'ready', json_encode([
        'status' => 'pass',
        'policy' => 'kuyash-v1',
        'checks' => [
            'ai_label' => ['required' => false, 'reasons' => []],
            'format' => ['status' => 'pass', 'duration_s' => 28.0, 'aspect' => '9:16', 'reasons' => []],
            'slop' => ['status' => 'pass', 'score' => 0.14, 'warn_at' => 0.55, 'block_at' => 0.8, 'history_runs' => 1],
        ],
        'reasons' => [],
        'ai_label_required' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(140));
    // The window the edit happened in: approved at the publish gate, then
    // final_render, then publish. Without this row nothing ever OPENED the
    // window ContentRevision::lockReason() requires, so the seeded edit would
    // depict a state production cannot reach.
    $insertJob($db, $workspaceId, $runDone, 'PUBLISH', 7, 'render_review', 'ready', json_encode([
        'ai_label_required' => false,
        'compliance' => ['status' => 'pass'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(135));
    // render_id is filled in below, once the renders row it points at exists.
    $insertJob($db, $workspaceId, $runDone, 'PUBLISH', 8, 'final_render', 'ready', $j(['final' => true, 'ai_label_required' => false]), $ago(125));
    $insertJob($db, $workspaceId, $runDone, 'PUBLISH', 9, 'publish', 'published', '{}', $ago(120));

    // …and the human approval that row records. `manual` means a real user and
    // no policy stamp — the 0007 CHECK enforces exactly that, and it is the one
    // record a compliance reader looks for on a published run.
    $reviewJob = $db->one(
        "SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' ORDER BY id DESC LIMIT 1",
        [$runDone],
    );
    if ($reviewJob !== null) {
        $db->run(
            "INSERT INTO approvals (workspace_id, run_id, job_id, node, decision, mode, decided_by, decided_at)
             VALUES (?, ?, ?, 'PUBLISH', 'approved', 'manual', ?, ?)",
            [$workspaceId, $runDone, (int) $reviewJob['id'], $userId, $ago(128)],
        );
    }

    // Run D — a SECOND post waiting for approval. The queue renders one text
    // editor per waiting post, and a single-card seed could never show that two
    // editors on one screen keep their own counts and their own tag field.
    $runAwaiting2 = $makeRun($db, $workspaceId, $distId, $distNodes, 'library', 'awaiting_approval', 'PUBLISH', $userId, $ago(26), $ago(9));
    // …and this one has been EDITED, by the fixture's own user. Everything in
    // this workspace is fabricated (it is a test fixture, named as one), so the
    // rule that matters is "depict nothing that could not happen" — and an
    // edited post is the phase's central state. Without it, the chip, the
    // "put back what Kuyash wrote" button, the saved-warning callout and the
    // near-the-limit tag counter are four things that ship unphotographed.
    $editedCaptions = [
        'instagram' => "The five-minute morning stretch I actually do — before coffee, before my phone.",
        'tiktok' => "Five minutes, no equipment, and my back stopped complaining by week two.",
        'youtube' => "A five-minute morning stretch routine — no equipment, no floor mat, no excuses.",
    ];
    $aiCaptions = [
        'instagram' => "The five-minute morning stretch I do before anything else.",
        'tiktok' => "Five minutes, no equipment, and my back stopped complaining.",
        'youtube' => "A five-minute morning stretch routine — no equipment needed.",
    ];
    // 16 tags against the tightest configured limit (15). ContentGate raises
    // content.too_many_tags only when the count is OVER, so seeding 14 produced
    // a warning the product cannot produce — a callout saying "too many" above a
    // counter that read "14 of about 15" in the not-yet style, contradicting it.
    // At 16 the callout, the counter and its over-the-limit styling are all true
    // and all visible at once.
    $editedTags = [
        '#morningroutine', '#stretching', '#mobility', '#fiveminutes', '#backpain',
        '#desklife', '#posture', '#nogym', '#beforework', '#dailyhabit',
        '#lowimpact', '#flexibility', '#warmup', '#stretcheveryday',
        '#morningmobility', '#deskbreak',
    ];
    $aiTags = ['#morningroutine', '#stretching', '#mobility', '#fiveminutes'];
    $editBlock = [
        'by' => $userId,
        'by_email' => $email,
        'at' => $ago(9),
        'hash' => \Kuyash\Content\ContentRevision::hash($editedCaptions, $editedTags),
        'verdict' => [
            'status' => 'warn',
            'policy' => 'kuyash-v1',
            // production shape: ContentGate always reports a score, warned or
            // not. 0.18 is well under SLOP_WARN, so this edit is warned about
            // its tag count and NOT about similarity — the two chips differ.
            'slop' => ['score' => 0.18, 'history_runs' => 3],
            'reasons' => [],
            'warnings' => [
                ['key' => 'content.too_many_tags', 'params' => ['platform' => 'youtube', 'n' => 16, 'limit' => 15]],
            ],
        ],
    ];
    $insertJob($db, $workspaceId, $runAwaiting2, 'LIBRARY', 1, 'asset_fetch', 'ready', $j([
        'source' => 'library', 'visual_kind' => 'video',
        'visual_ref' => 'asset:1:' . str_repeat('4', 32) . '.mp4',
        'asset_id' => 2, 'title' => 'Morning stretch — window light',
        'ai_label_required' => false, 'duration_s' => 22.0,
    ]), $ago(25));
    $insertJob($db, $workspaceId, $runAwaiting2, 'CAPTION', 2, 'caption_generation', 'ready', json_encode([
        'captions' => $editedCaptions,
        'captions_ai' => $aiCaptions,
        'edit' => $editBlock,
        'prompt_version' => 'caption.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(23));
    $insertJob($db, $workspaceId, $runAwaiting2, 'HASHTAGS', 3, 'hashtag_generation', 'ready', json_encode([
        'hashtags' => $editedTags,
        'hashtags_ai' => $aiTags,
        'edit' => $editBlock,
        'prompt_version' => 'hashtag.v1',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(22));
    // No AI voice on a library clip, so no AI notice is due here — the second
    // card honestly shows the editor WITHOUT the locked disclosure line.
    $insertJob($db, $workspaceId, $runAwaiting2, 'MUSIC NOTE / STYLE', 4, 'music_note', 'ready', $j([
        'mood' => 'upbeat',
        'note' => 'suggestion only — platform-native sounds cannot be published via API',
    ]), $ago(20));
    $insertJob($db, $workspaceId, $runAwaiting2, 'PREVIEW', 5, 'preview', 'ready', $j([
        'note' => 'preview is the in-pipeline checkpoint; the reviewable render is the draft',
    ]), $ago(18));
    $insertJob($db, $workspaceId, $runAwaiting2, 'COMPLIANCE', 6, 'compliance_check', 'ready', json_encode([
        'status' => 'pass',
        'policy' => 'kuyash-v1',
        'checks' => [
            'ai_label' => ['required' => false, 'reasons' => []],
            'format' => ['status' => 'pass', 'duration_s' => 22.0, 'aspect' => '9:16', 'reasons' => []],
            'slop' => ['status' => 'pass', 'score' => 0.09, 'warn_at' => 0.55, 'block_at' => 0.8, 'history_runs' => 3],
        ],
        'reasons' => [],
        'ai_label_required' => false,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago(16));
    $insertJob(
        $db,
        $workspaceId,
        $runAwaiting2,
        'PUBLISH',
        7,
        'render_review',
        'awaiting_approval',
        json_encode(['ai_label_required' => false, 'compliance' => ['status' => 'pass']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $ago(14),
    );

    // Renders: count-only rows for the dashboard KPI (never served on these
    // pages — no job links a draft_render_id, so nothing requests the file).
    $finalRenderId = null;
    foreach (['draft', 'final'] as $i => $kind) {
        $db->run(
            'INSERT INTO renders (workspace_id, run_id, kind, stored_name, mime, width, height, duration_s, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runDone, $kind, str_repeat((string) ($i + 1), 32) . '.mp4', 'video/mp4', 1080, 1920, 28.0, $ago(125)],
        );
        if ($kind === 'final') {
            $finalRenderId = $db->lastInsertId();
        }
    }
    // …and the final_render job points at the row it produced. A `ready`
    // final_render always carries a real render id (FinalRenderExecutor returns
    // JobResult::failed otherwise), so a null there depicted a successful
    // full-res render that rendered nothing.
    if ($finalRenderId !== null) {
        $db->run(
            "UPDATE jobs SET result_json = ? WHERE run_id = ? AND type = 'final_render'",
            [$j(['render_id' => $finalRenderId, 'final' => true, 'ai_label_required' => false]), $runDone],
        );
    }

    // Event log (drives /logs): REAL dictionary keys + full params so the feed
    // renders fully interpolated and humanized — no raw event key, no literal
    // {run}/{workflow}, no internal job-type enum (Phase 21 zero-jargon /logs).
    // Each row carries its OWN run: a screen that asserts a compliance verdict
    // beside an empty audit trail contradicts the rule the verdict comes from,
    // and both edited runs now assert one.
    $events = [
        // OLDEST FIRST. EventLog reads `ORDER BY id DESC` on purpose (created_at
        // has only second resolution), so the insertion order here IS what the
        // feed's "newest first" promise resolves to.
        //
        // NOT daily_cap_reached: AutoApprovalGate returns PATH_MANUAL_MODE before
        // any cap event can be written, so a Manual workspace — which this one is
        // — cannot emit it at all. The kill switch is the guardrail a person can
        // trip in any mode, and it belongs to the workspace, not to a run.
        [null, 'warn', 'guardrail', 'guardrail.kill_switch_on', ['user' => $email], 200],
        [null, 'info', 'guardrail', 'guardrail.kill_switch_off', ['user' => $email], 190],
        // one clock with the job rows above: started → caption → compliance →
        // edited (inside the approval window) → approved → published
        [$runDone, 'info', 'transition', 'run.started', ['run' => $runDone, 'workflow' => 'Distribution'], 155],
        [$runDone, 'info', 'transition', 'job.finished', ['type' => 'caption_generation', 'run' => $runDone], 150],
        [$runDone, 'info', 'compliance', 'compliance.passed', ['run' => $runDone], 140],
        [$runDone, 'info', 'transition', 'content.edited', ['run' => $runDone, 'user' => $email], 130],
        [$runDone, 'warn', 'compliance', 'content.edit_warned', [
            'run' => $runDone, 'reason' => 'This is fairly close to something you posted recently (similarity 0.61).', 'slop' => 0.61,
        ], 130],
        // the approval and the publish the other cards on this screen assert —
        // an append-only timeline that omits them reads as an incomplete record
        [$runDone, 'info', 'transition', 'approval.approved', ['node' => 'PUBLISH', 'user' => $email, 'run' => $runDone], 128],
        [$runDone, 'info', 'transition', 'job.published', ['type' => 'publish', 'run' => $runDone], 120],

        [$runAwaiting2, 'info', 'transition', 'content.edited', ['run' => $runAwaiting2, 'user' => $email], 9],
        [$runAwaiting2, 'info', 'compliance', 'content.edit_checked', [
            'run' => $runAwaiting2, 'result' => 'warn', 'slop' => 0.18, 'policy' => 'kuyash-v1',
        ], 9],
    ];
    foreach ($events as [$runFor, $level, $kind, $key, $params, $minsAgo]) {
        $db->run(
            'INSERT INTO events (workspace_id, run_id, level, kind, key, params_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $runFor, $level, $kind, $key, json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $ago($minsAgo)],
        );
    }

    // Credit ledger: one manual grant → a non-zero balance on usage/dashboard.
    $db->run(
        "INSERT INTO credit_transactions (workspace_id, type, amount_cents, reason, created_at) VALUES (?, 'grant', ?, ?, ?)",
        [$workspaceId, 5000, 'Visual-test seed grant', $ago(200)],
    );

    // Social accounts (populate /accounts; no reference asset → no media dep).
    // The last one is PROVIDER-BACKED (a real follower count). Without it the
    // fixture could only ever exercise the sample branch of the account card, so
    // the rule that matters most — a real channel is never given a fabricated
    // figure or a fabricated preview frame — was true in the code and
    // undemonstrated in every screenshot.
    $accounts = [
        ['instagram', '@visual.kitchen', 'connected', 'ok', null],
        ['tiktok', '@visual.kitchen', 'connected', 'ok', null],
        ['youtube', 'Visual Kitchen', 'reauth_needed', 'degraded', null],
        ['instagram', '@visual.real', 'connected', 'ok', 7],
    ];
    foreach ($accounts as [$platform, $handle, $status, $health, $followers]) {
        $db->run(
            'INSERT INTO accounts (workspace_id, platform, handle, status, health, followers_count,
                                   followers_synced_at, connected_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$workspaceId, $platform, $handle, $status, $health, $followers,
             $followers === null ? null : $now, $now, $now, $now],
        );
    }

    // Weekly publishing plan (Phase 23) — real slot rows, not fabricated metrics:
    // a slot is operator configuration, so seeding it populates the settings
    // screen and the approval picker without asserting anything about results.
    $db->run("UPDATE workspaces SET timezone = 'Europe/Istanbul' WHERE id = ?", [$workspaceId]);
    // Phase 24: two of the three times are filled by the operator, one by Kuyash,
    // so the calendar shows BOTH modes. Still pure configuration — no invented
    // metric, and (below) no fabricated "published" day.
    foreach ([[1, '09:00', 'manual'], [3, '18:30', 'auto'], [5, '12:00', 'manual']] as [$weekday, $time, $mode]) {
        $db->run(
            'INSERT INTO publish_slots (workspace_id, account_id, weekday, time_hhmm, mode, enabled, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, 1, ?, ?)',
            [$workspaceId, $weekday, $time, $mode, $now, $now],
        );
    }

    // Calendar cells for the next two weeks. Seeded through the REAL materializer
    // so the screenshots show what the product actually produces, and left in
    // 'open' state only: a demo must never render a day as "Published" for a post
    // that never happened (the Phase 22 honesty rule, applied to the calendar).
    $seedOcc = new Kuyash\Publish\OccurrenceRepository($db);
    (new Kuyash\Publish\OccurrenceMaterializer($seedOcc, new Kuyash\Publish\SlotResolver()))->materialize(
        $workspaceId,
        'Europe/Istanbul',
        (new Kuyash\Publish\SlotRepository($db))->listForWorkspace($workspaceId),
        $now,
    );

    // Attach the workspace's existing awaiting-approval run to the first calendar
    // day, so the screenshots cover the PLANNED approval card (a stated day plus
    // the "publish now instead" override) rather than only the plain picker.
    // Real rows, real wiring — no fabricated outcome: the day reads "Waiting for
    // you", which is exactly what it is.
    $seedAwaiting = $db->one(
        "SELECT run_id FROM jobs WHERE workspace_id = ? AND status = 'awaiting_approval'
           AND type = 'render_review' ORDER BY id ASC LIMIT 1",
        [$workspaceId],
    );
    $seedCell = $db->one(
        'SELECT id, publish_at FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at ASC LIMIT 1',
        [$workspaceId],
    );
    if ($seedAwaiting !== null && $seedCell !== null) {
        $seedOcc->reserve($workspaceId, (int) $seedCell['id'], null, $now);
        $seedOcc->attachRun($workspaceId, (int) $seedCell['id'], (int) $seedAwaiting['run_id'], $now);
        $db->run(
            'UPDATE runs SET publish_after = ? WHERE id = ? AND workspace_id = ?',
            [(string) $seedCell['publish_at'], (int) $seedAwaiting['run_id'], $workspaceId],
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
