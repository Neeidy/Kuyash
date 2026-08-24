<?php

declare(strict_types=1);

/**
 * Queue worker process. Thin: the loop, flags, signals and maintenance
 * cadence live here; all queue logic is in Worker::tick() (testable).
 *
 * Run: cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/worker.php
 *
 * Flags:
 *   --once          drain the queue (process due jobs until empty), then exit
 *   --max-jobs=N    exit after processing N jobs
 *   --sleep-ms=500  idle sleep between empty ticks (default 500)
 *
 * SIGTERM/SIGINT set a stop flag (pcntl, when loaded) — the worker finishes
 * the in-flight job and exits cleanly. Without pcntl: use --once/--max-jobs;
 * a killed worker's stale job is requeued by the watchdog anyway.
 */

use Kuyash\Analytics\DailySnapshot;
use Kuyash\Core\ErrorHandler;
use Kuyash\Publish\PlanRunner;
use Kuyash\Publish\Reconciler;
use Kuyash\Publish\WebhookInbox;
use Kuyash\Workflow\Maintenance;
use Kuyash\Workflow\Worker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap-worker.php';

/** @var ErrorHandler $errorHandler */
$errorHandler = $container->get(ErrorHandler::class);
$errorHandler->register();

$once = in_array('--once', $argv, true);
$maxJobs = 0;
$sleepMs = 500;
foreach ($argv as $arg) {
    if (preg_match('/^--max-jobs=(\d+)$/', $arg, $m) === 1) {
        $maxJobs = (int) $m[1];
    }
    if (preg_match('/^--sleep-ms=(\d+)$/', $arg, $m) === 1) {
        $sleepMs = max(50, (int) $m[1]);
    }
}

$stop = false;
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
        fwrite(STDERR, "worker: stop signal received — finishing current job\n");
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

/** @var Worker $worker */
$worker = $container->get(Worker::class);
/** @var Maintenance $maintenance */
$maintenance = $container->get(Maintenance::class);
/** @var WebhookInbox $webhookInbox */
$webhookInbox = $container->get(WebhookInbox::class);
/** @var Reconciler $reconciler */
$reconciler = $container->get(Reconciler::class);
/** @var DailySnapshot $snapshot */
$snapshot = $container->get(DailySnapshot::class);
/** @var Kuyash\Workflow\WorkerHeartbeat $heartbeat */
$heartbeat = $container->get(Kuyash\Workflow\WorkerHeartbeat::class);
/** @var PlanRunner $plan */
$plan = $container->get(PlanRunner::class);

$chores = $maintenance->run(gmdate('Y-m-d\TH:i:s\Z'));
// publish maintenance once on start too, so a `--once` drain also processes any
// pending webhooks and reconciles stale in-flight posts
$webhookInbox->processPending(gmdate('Y-m-d\TH:i:s\Z'));
$reconciler->sweep(gmdate('Y-m-d\TH:i:s\Z'));
// read-only metrics poll; self-limits to one row per account per UTC day, so
// running it on every start (and on the chore cadence) costs one cheap query
$snapshot->capture(gmdate('Y-m-d\TH:i:s\Z'));
// Phase 24 — the weekly plan, run BEFORE the first job is ever claimed. Order is
// load-bearing: a worker that was down over a planned time must CLOSE those
// stale publishes, not wake up and fire a day's worth of them at once.
// A failing plan tick must NEVER stop the queue: this runs before the first
// claim, and an uncaught throw here (SQLite lock contention with a /plan page
// view is enough) would exit the worker and silently halt ALL publishing.
$planCounts = ['materialized' => 0, 'swept' => 0, 'started' => 0];
try {
    $planCounts = $plan->tick(gmdate('Y-m-d\TH:i:s\Z'));
} catch (Throwable $e) {
    error_log('Kuyash: plan tick failed on startup — ' . $e->getMessage());
}
$heartbeat->beat(gmdate('Y-m-d\TH:i:s\Z')); // mark alive immediately on start
fwrite(STDOUT, sprintf(
    "worker: started (maintenance: %d login rows pruned, %d orphan files swept; plan: %d slot(s) added, %d closed, %d started)\n",
    $chores['pruned_login_attempts'],
    $chores['swept_orphans'],
    $planCounts['materialized'],
    $planCounts['swept'],
    $planCounts['started'],
));

$processed = 0;
$lastChores = time();
$lastBeat = time();

while (!$stop) {
    // heartbeat at most every 5s (the web UI warns when it goes >30s stale)
    if (time() - $lastBeat >= 5) {
        $heartbeat->beat(gmdate('Y-m-d\TH:i:s\Z'));
        $lastBeat = time();
    }

    // chores run on the ~5min cadence regardless of load — checking only on
    // idle ticks would starve maintenance under a continuously full queue
    if (time() - $lastChores >= 300) {
        $maintenance->run(gmdate('Y-m-d\TH:i:s\Z'));
        // reconcile in-flight publishes whose webhook never arrived (15-min
        // staleness threshold, so a 5-min sweep cadence is ample)
        $reconciler->sweep(gmdate('Y-m-d\TH:i:s\Z'));
        // daily audience/engagement snapshot: the UNIQUE(day) guard makes this
        // a no-op query for the rest of the day once today's row exists
        $snapshot->capture(gmdate('Y-m-d\TH:i:s\Z'));
        // weekly plan: fill the calendar, close times that passed, and start
        // content for automatic times inside their lead window. Guarded for the
        // same reason as the startup call — the queue keeps running regardless.
        try {
            $plan->tick(gmdate('Y-m-d\TH:i:s\Z'));
        } catch (Throwable $e) {
            error_log('Kuyash: plan tick failed — ' . $e->getMessage());
        }
        $lastChores = time();
    }

    // process any verified webhook deliveries promptly (cheap indexed query)
    $webhookInbox->processPending(gmdate('Y-m-d\TH:i:s\Z'));

    $didWork = $worker->tick();

    if ($didWork) {
        $processed++;
        if ($maxJobs > 0 && $processed >= $maxJobs) {
            break;
        }
        continue;
    }

    if ($once) {
        break;
    }

    usleep($sleepMs * 1000);
}

fwrite(STDOUT, "worker: exiting ({$processed} job(s) processed)\n");
exit(0);
