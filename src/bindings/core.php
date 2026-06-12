<?php

declare(strict_types=1);

/**
 * Core bindings: services shared by BOTH the web app and the worker process.
 * Nothing here may depend on a session — the worker has none (its tenant
 * scope comes from the workspace_id on each claimed job row).
 */

use Kuyash\Content\ContentExecutor;
use Kuyash\Content\MockTextProvider;
use Kuyash\Content\OpenAiTextProvider;
use Kuyash\Content\PromptLibrary;
use Kuyash\Content\TextProvider;
use Kuyash\Content\VariationEngine;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Database;
use Kuyash\Http\CurlHttpClient;
use Kuyash\Library\AssetRepository;
use Kuyash\Trend\GoogleTrendsProvider;
use Kuyash\Trend\MockTrendProvider;
use Kuyash\Trend\QuotaCounter;
use Kuyash\Trend\TrendConfigRepository;
use Kuyash\Trend\TrendExecutor;
use Kuyash\Trend\TrendProvider;
use Kuyash\Trend\TrendRepository;
use Kuyash\Trend\TrendService;
use Kuyash\Trend\YouTubeTrendsProvider;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\ExecutorRegistry;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\MockExecutor;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkerHeartbeat;
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

    // Worker liveness signal — written by the worker, read by the web UI
    // (shared so both containers resolve the same file path).
    $container->bind(WorkerHeartbeat::class, static fn (): WorkerHeartbeat => new WorkerHeartbeat(
        $basePath . '/storage/worker.heartbeat',
    ));

    // TextProvider (Phase 5): real OpenAI ONLY when OPENAI_MOCK=false AND a key
    // is present; otherwise the deterministic offline mock. Swap = config only.
    $container->bind(TextProvider::class, static function (Container $c): TextProvider {
        $cfg = (array) $c->get(Config::class)->get('openai');
        $useReal = ($cfg['mock'] ?? true) === false && ($cfg['api_key'] ?? '') !== '';

        if ($useReal) {
            return new OpenAiTextProvider(
                new CurlHttpClient(),       // constructed only on the real path
                new PromptLibrary(),
                new VariationEngine(),
                $cfg,
            );
        }

        return new MockTextProvider(new VariationEngine(), new PromptLibrary());
    });

    $container->bind(ContentExecutor::class, static fn (Container $c): ContentExecutor => new ContentExecutor(
        $c->get(TextProvider::class),
    ));

    $container->bind(TrendRepository::class, static fn (Container $c): TrendRepository => new TrendRepository(
        $c->get(Database::class),
    ));

    $container->bind(QuotaCounter::class, static fn (Container $c): QuotaCounter => new QuotaCounter(
        $c->get(Database::class),
    ));

    $container->bind(TrendConfigRepository::class, static function (Container $c): TrendConfigRepository {
        $cfg = (array) $c->get(Config::class)->get('trends');

        return new TrendConfigRepository($c->get(Database::class), [
            'niche' => (string) ($cfg['default_niche'] ?? 'general'),
            'region' => (string) ($cfg['default_region'] ?? 'US'),
        ]);
    });

    // TrendProvider (Phase 6): a real provider ONLY when TREND_MOCK=false AND the
    // chosen provider is configured (youtube needs a key; google_trends does not).
    // Anything else → the deterministic offline mock. Swap = config only.
    $container->bind(TrendProvider::class, static function (Container $c): TrendProvider {
        $cfg = (array) $c->get(Config::class)->get('trends');
        $useReal = ($cfg['mock'] ?? true) === false;

        if ($useReal) {
            $which = (string) ($cfg['provider'] ?? 'youtube');
            if ($which === 'youtube' && ((string) (($cfg['youtube']['api_key'] ?? '')) !== '')) {
                return new YouTubeTrendsProvider(new CurlHttpClient(), (array) $cfg['youtube']);
            }
            if ($which === 'google_trends') {
                return new GoogleTrendsProvider(new CurlHttpClient(), (array) $cfg['google_trends']);
            }
            // misconfigured real provider → fail safe to mock (never block trends)
        }

        return new MockTrendProvider();
    });

    $container->bind(TrendService::class, static fn (Container $c): TrendService => new TrendService(
        $c->get(TrendProvider::class),
        $c->get(TrendRepository::class),
        $c->get(QuotaCounter::class),
        (array) $c->get(Config::class)->get('trends'),
    ));

    $container->bind(TrendExecutor::class, static fn (Container $c): TrendExecutor => new TrendExecutor(
        $c->get(TrendService::class),
        $c->get(TrendRepository::class),
        $c->get(TrendConfigRepository::class),
    ));

    // MockExecutor serves the remaining mock types; ContentExecutor (behind a
    // TextProvider) serves the 4 content types and TrendExecutor (behind a
    // TrendProvider) serves trend_fetch — one register() line each (adapter
    // rule). Later phases swap more types the same way.
    $container->bind(ExecutorRegistry::class, static function (Container $c): ExecutorRegistry {
        $registry = new ExecutorRegistry();
        $registry->registerForAll(new MockExecutor($c->get(Database::class)));

        $content = $c->get(ContentExecutor::class);
        foreach (ContentExecutor::contentTypes() as $type) {
            $registry->register($type, $content);
        }

        $registry->register('trend_fetch', $c->get(TrendExecutor::class));

        return $registry;
    });
};
