<?php

declare(strict_types=1);

/**
 * Worker bindings: the queue process. Deliberately NO Session, Csrf, View or
 * WorkspaceContext — the worker is sessionless; tenant scope travels on each
 * claimed job row (workspace_id re-applied in every write).
 */

use Kuyash\Analytics\DailySnapshot;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Publish\PublishProvider;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\ExecutorRegistry;
use Kuyash\Workflow\Maintenance;
use Kuyash\Workflow\Watchdog;
use Kuyash\Workflow\Worker;

return static function (Container $container, string $basePath): void {
    $container->bind(ErrorHandler::class, static fn (Container $c): ErrorHandler => new ErrorHandler(
        $c->get(Config::class),
        null,                              // no View — plain-text output only
        $basePath . '/storage/logs',
        cliMode: true,
    ));

    $container->bind(Watchdog::class, static fn (Container $c): Watchdog => new Watchdog(
        $c->get(Database::class),
        $c->get(EventLog::class),
    ));

    $container->bind(Maintenance::class, static fn (Container $c): Maintenance => new Maintenance(
        $c->get(Database::class),
        (string) $c->get(Config::class)->get('library.storage_root'),
    ));

    // read-only audience/engagement poll — zero spend, at most one row per
    // account per UTC day (see DailySnapshot)
    $container->bind(DailySnapshot::class, static fn (Container $c): DailySnapshot => new DailySnapshot(
        $c->get(Database::class),
        $c->get(PublishProvider::class),
    ));

    // opaque id: pid + nonce — hostnames would leak into the tenant-visible
    // event feed via job.claimed params (security audit)
    $container->bind(Worker::class, static fn (Container $c): Worker => new Worker(
        $c->get(Database::class),
        $c->get(Engine::class),
        $c->get(ExecutorRegistry::class),
        $c->get(EventLog::class),
        $c->get(Watchdog::class),
        'w' . getmypid() . '-' . bin2hex(random_bytes(2)),
    ));
};
