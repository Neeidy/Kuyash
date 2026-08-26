<?php

declare(strict_types=1);

/**
 * Showcase seed for the DEV database (DEV-ONLY, not product code).
 *
 * Fills the screens a case study is captured from so they read as a working
 * product rather than as a wall of empty states — and stays undoable, inert and
 * honest while doing it. The rules, and why each one is a rule, are documented
 * on Kuyash\Demo\ShowcaseSeed; the short version:
 *
 *   • UNDOABLE — every row and file is recorded in `demo_seed_manifest` as it is
 *     written, and `bin/demo-teardown.php` removes exactly that set. The seed
 *     only INSERTs: an UPDATE to a real row could not be undone from a manifest,
 *     so the workspace's own settings are read and never touched.
 *   • INERT — no `queued` or `processing` job is written anywhere, demo posts are
 *     already-terminal MOCK rows, and every calendar cell is placed in a state
 *     the plan runner provably skips. Running the worker after this changes
 *     nothing: no run starts, nothing is spent, nothing is published.
 *   • HONEST — demo channels are non-connected mock rows (so the account card
 *     marks every figure it derives as a sample), every seeded title, caption and
 *     hashtag starts with "[SAMPLE]", the real connected account is never given a
 *     number, and no capability that does not work is depicted as working.
 *
 * It is IDEMPOTENT by tearing itself down first: a second run removes the
 * previous demo set and writes a fresh one, so it can never accumulate.
 *
 * Usage (refuses without the confirmation, so it cannot run by accident):
 *   php bin/demo-seed.php --yes
 *   DEMO_WORKSPACE=2 php bin/demo-seed.php --yes
 */

use Kuyash\Core\Config;
use Kuyash\Core\Database;
use Kuyash\Demo\FixtureMediaFactory;
use Kuyash\Demo\StockMediaFactory;
use Kuyash\Demo\SeedManifest;
use Kuyash\Demo\ShowcaseSeed;
use Kuyash\Demo\ShowcaseTeardown;
use Kuyash\Library\MediaProbe;
use Kuyash\Media\AssetPoster;
use Kuyash\Media\MediaPaths;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$args = array_slice($argv, 1);
if (!in_array('--yes', $args, true)) {
    fwrite(STDERR, "demo-seed: this writes demo content into the database in config/database.\n"
        . "  Everything it writes is tracked and removable with: php bin/demo-teardown.php --yes\n"
        . "  Re-run with --yes if that is what you want.\n");
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
$db = $container->get(Database::class);
$now = gmdate('Y-m-d\TH:i:s\Z');

if ($db->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'demo_seed_manifest'") === null) {
    fwrite(STDERR, "demo-seed: the demo manifest table is missing. Run: php bin/migrate.php\n");
    exit(1);
}

// ── which workspace ─────────────────────────────────────────────────────────
// The showcase belongs where the operator actually works, which is the workspace
// that has a channel attached — not necessarily workspace #1. Stated out loud
// either way, because writing demo content into the wrong tenant is exactly the
// mistake a confirmation flag is supposed to prevent.
$wsArg = getenv('DEMO_WORKSPACE');
if ($wsArg !== false && ctype_digit($wsArg)) {
    $ws = (int) $wsArg;
} else {
    $pick = $db->one(
        "SELECT w.id FROM workspaces w
         WHERE EXISTS (SELECT 1 FROM accounts a WHERE a.workspace_id = w.id AND a.status = 'connected')
         ORDER BY w.id ASC LIMIT 1",
    ) ?? $db->one('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    $ws = (int) ($pick['id'] ?? 0);
}
if ($ws === 0 || $db->one('SELECT id, name FROM workspaces WHERE id = ?', [$ws]) === null) {
    fwrite(STDERR, "demo-seed: no such workspace (#{$ws}).\n");
    exit(1);
}
$wsRow = $db->one('SELECT name, approval_mode FROM workspaces WHERE id = ?', [$ws]);
$wsName = (string) $wsRow['name'];
fwrite(STDOUT, "demo-seed: workspace #{$ws} ({$wsName})\n");

// ── the one guardrail this seed CANNOT hold harmless ────────────────────────
// Everything it writes is inert to the WORKER: no job it writes is claimable,
// no calendar cell it writes can be produced or swept, nothing it writes lands
// in the month the budget cap is enforced against, and no demo post touches a
// connected channel's per-account daily cap.
//
// What it cannot avoid is being READ AS EVIDENCE. Two guardrails score the
// workspace's recent history by ROW ORDER, not by date:
//   • QualityScore walks the last 20 compliance checks. Eight of them become
//     demo checks, all clean, which pulls the average similarity down — measured
//     on the dev workspace: 0.275 with the demo against 0.345 without, lifting
//     the score from 84 to 87, in the permissive direction.
//   • SlopScorer compares new content against the last 10 runs. Eight of them
//     become demo runs, EVICTING the operator's real history from the window —
//     and slop takes the maximum similarity over it, so eviction can only make a
//     real near-duplicate score cleaner.
// In Manual mode a person is still the gate and this is cosmetic. In AUTO mode
// those two numbers help decide what publishes with nobody looking — so the
// seed refuses that workspace unless the operator says otherwise in as many
// words.
if ((string) ($wsRow['approval_mode'] ?? 'manual') === 'auto' && !in_array('--auto-mode-ok', $args, true)) {
    fwrite(STDERR, "demo-seed: workspace #{$ws} approves automatically.\n"
        . "  Demo runs are read as evidence by two guardrails that score recent history:\n"
        . "  the quality score (last 20 compliance checks) and slop similarity (last 10 runs).\n"
        . "  In Auto mode those help decide what publishes with nobody looking.\n"
        . "  Switch the workspace to Manual for the capture, pick another with DEMO_WORKSPACE=N,\n"
        . "  or re-run with --auto-mode-ok if you accept that.\n");
    exit(1);
}

// ── the hazard that outlives the seed: a live publish path ──────────────────
// The seed writes no claimable job, but it does put five REAL approval gates on
// the queue screen. Approving one is a human act the seed cannot make inert:
// PublishGateExecutor returns early for human-approved runs, so neither the
// daily cap nor the kill switch applies, and ZernioPublishExecutor fans out to
// every CONNECTED channel — the operator's real one, not the demo rows. The
// caption that would go out begins with "[SAMPLE]".
//
// This is checked BEFORE anything is written rather than warned about after, in
// the trailing lines of a summary that reads like success.
$mockPublish = $container->get(Config::class)->get('zernio.mock') === true;
if (!$mockPublish && !in_array('--live-publish-ok', $args, true)) {
    fwrite(STDERR, "demo-seed: publishing is LIVE (ZERNIO_MOCK=false).\n"
        . "  This seed puts five real approval gates on the queue. Approving one during a\n"
        . "  capture publishes a [SAMPLE] caption to your connected channels for real —\n"
        . "  and a human-approved run bypasses both the daily cap and the kill switch.\n"
        . "  Set ZERNIO_MOCK=true for the capture session, or re-run with\n"
        . "  --live-publish-ok if you accept that.\n");
    exit(1);
}

// ── idempotency: remove the previous demo set first ─────────────────────────
$manifest = new SeedManifest($db);
if (!$manifest->isEmpty()) {
    $teardown = new ShowcaseTeardown($db);
    $blockers = $teardown->blockers();
    if ($blockers !== []) {
        fwrite(STDERR, "demo-seed: a previous demo set cannot be removed cleanly:\n  - "
            . implode("\n  - ", $blockers) . "\n"
            . "  Resolve that first (bin/demo-teardown.php --dry-run explains it), then re-run.\n");
        exit(1);
    }
    $undone = $teardown->run();
    fwrite(STDOUT, '  reset: removed the previous demo set ('
        . array_sum($undone['rows']) . " row(s), {$undone['files']} file(s))\n");
}

// ── where the demo footage comes from ───────────────────────────────────────
// REAL stock, not synthetic. A poster can only show something if the clip shows
// something: the previous fixture was a gradient, so every "preview" in the
// product rendered as a flat wash and the poster work was invisible. The clips
// are labelled, tracked and removed by teardown like everything else.
//
// DEMO_MEDIA=fixture forces the committed fixtures instead — that is what the
// visual gate uses, so the gate stays deterministic and offline while still
// exercising real footage.
$mediaProbe = new MediaProbe();
$ffmpegBin = $container->get(Kuyash\Media\Ffmpeg::class);
$useFixtures = getenv('DEMO_MEDIA') === 'fixture';

if ($useFixtures) {
    $mediaFactory = new FixtureMediaFactory(dirname(__DIR__) . '/tools/visual/fixtures/stock', $mediaProbe, $ffmpegBin);
    fwrite(STDOUT, "  media: committed stock fixtures (DEMO_MEDIA=fixture)\n");
} else {
    $scratch = dirname(__DIR__) . '/storage/work';
    @mkdir($scratch, 0750, true);
    $mediaFactory = new StockMediaFactory(
        $container->get(Kuyash\Media\StockProvider::class),
        $ffmpegBin,
        $mediaProbe,
        $scratch,
        ShowcaseSeed::stockQueries(),
    );
    fwrite(STDOUT, '  media: live stock provider (' . $container->get(Kuyash\Media\StockProvider::class)->name() . ")\n");
}

// ── seed ────────────────────────────────────────────────────────────────────
$seed = new ShowcaseSeed(
    $db,
    $container->get(MediaPaths::class),
    $mediaFactory,
    // the same poster service the product uses at ingest, so a demo clip
    // previews exactly the way an uploaded one does
    $container->get(AssetPoster::class),
);

$report = $seed->run($ws, $now);

foreach ($report['notes'] as $note) {
    // notes go to STDERR: they are the only place a partial seed says so, and
    // STDOUT here is a tidy summary that reads like success either way
    fwrite(STDERR, "  note: {$note}\n");
}
$total = 0;
foreach ($report['counts'] as $table => $n) {
    $total += $n;
    fwrite(STDOUT, sprintf("  %-22s %d\n", $table, $n));
}
fwrite(STDOUT, "demo-seed: {$total} tracked entries.\n");
fwrite(STDOUT, "  Undo with: php bin/demo-teardown.php --yes\n");

// Said again at the end, on STDERR: the publishing hazard is a precondition
// above, but this one only bites later.
fwrite(STDERR, "\n  TEAR IT DOWN WHEN THE CAPTURE IS OVER. The seed is inert on the day it runs,\n"
    . "  but its calendar days age: once one passes its time the worker closes it and appends\n"
    . "  a guardrail line to the audit log, which is append-only — that pins the run it names,\n"
    . "  and teardown can no longer remove it (it says so and keeps the rest).\n");
fwrite(STDERR, "\n  THE APPROVAL QUEUE IS REAL. Those cards are live approval gates"
    . ($mockPublish
        ? ", on the mock provider.\n  The seed's own approval records name a DEMO account, never you.\n"
        : " and publishing is LIVE.\n  You passed --live-publish-ok; approving one publishes for real.\n"));
exit(0);
