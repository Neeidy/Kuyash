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

use Kuyash\Core\ErrorHandler;
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
/** @var Kuyash\Workflow\WorkerHeartbeat $heartbeat */
$heartbeat = $container->get(Kuyash\Workflow\WorkerHeartbeat::class);

$chores = $maintenance->run(gmdate('Y-m-d\TH:i:s\Z'));
$heartbeat->beat(gmdate('Y-m-d\TH:i:s\Z')); // mark alive immediately on start
fwrite(STDOUT, sprintf(
    "worker: started (maintenance: %d login rows pruned, %d orphan files swept)\n",
    $chores['pruned_login_attempts'],
    $chores['swept_orphans'],
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
        $lastChores = time();
    }

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
