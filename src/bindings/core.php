<?php

declare(strict_types=1);

/**
 * Core bindings: services shared by BOTH the web app and the worker process.
 * Nothing here may depend on a session — the worker has none (its tenant
 * scope comes from the workspace_id on each claimed job row).
 */

use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Database;
use Kuyash\Library\AssetRepository;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\ExecutorRegistry;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\MockExecutor;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workflow\WorkflowValidator;

return static function (Container $container, string $basePath): void {
    $container->bind(Config::class, static fn (): Config => new Config($basePath . '/config'));

    $container->bind(Database::class, static fn (Container $c): Database => new Database(
        (string) $c->get(Config::class)->get('database.path'),
    ));

    $container->bind(AssetRepository::class, static fn (Container $c): AssetRepository => new AssetRepository(
        $c->get(Database::class),
    ));

    $container->bind(EventLog::class, static fn (Container $c): EventLog => new EventLog(
        $c->get(Database::class),
    ));

    $container->bind(WorkflowValidator::class, static fn (): WorkflowValidator => new WorkflowValidator());

    $container->bind(WorkflowRepository::class, static fn (Container $c): WorkflowRepository => new WorkflowRepository(
        $c->get(Database::class),
        $c->get(WorkflowValidator::class),
    ));

    $container->bind(RunRepository::class, static fn (Container $c): RunRepository => new RunRepository(
        $c->get(Database::class),
    ));

    $container->bind(JobRepository::class, static fn (Container $c): JobRepository => new JobRepository(
        $c->get(Database::class),
    ));

    $container->bind(Engine::class, static fn (Container $c): Engine => new Engine(
        $c->get(Database::class),
        $c->get(EventLog::class),
        $c->get(WorkflowValidator::class),
    ));

    // Phase 4: the single MockExecutor serves all 13 job types. Later phases
    // swap individual types to real adapters with one register() line each.
    $container->bind(ExecutorRegistry::class, static function (Container $c): ExecutorRegistry {
        $registry = new ExecutorRegistry();
        $registry->registerForAll(new MockExecutor($c->get(Database::class)));

        return $registry;
    });
};
