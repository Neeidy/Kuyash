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
use Kuyash\Http\CurlBlobClient;
use Kuyash\Http\CurlHttpClient;
use Kuyash\Library\AssetRepository;
use Kuyash\Media\AssemblyEngine;
use Kuyash\Media\AssemblyExecutor;
use Kuyash\Media\AssetCache;
use Kuyash\Media\AssetFetchExecutor;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\FinalRenderExecutor;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\MockStockProvider;
use Kuyash\Media\MockTtsProvider;
use Kuyash\Media\OpenAiTtsProvider;
use Kuyash\Media\PexelsStockProvider;
use Kuyash\Media\RenderRepository;
use Kuyash\Media\StockProvider;
use Kuyash\Media\TtsExecutor;
use Kuyash\Media\TtsProvider;
use Kuyash\Storage\LocalStorageProvider;
use Kuyash\Storage\R2StorageProvider;
use Kuyash\Storage\SigV4Signer;
use Kuyash\Storage\StorageManager;
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

    /* ---------------- Media (Phase 7): ffmpeg, TTS, stock, assembly ---------- */

    $container->bind(MediaPaths::class, static function (Container $c): MediaPaths {
        $cfg = (array) $c->get(Config::class)->get('media');

        return new MediaPaths([
            'asset' => (string) $cfg['asset_root'],
            'cache' => (string) $cfg['cache_root'],
            'render' => (string) $cfg['render_root'],
            'work' => (string) $cfg['work_root'],
        ]);
    });

    // StorageProvider seam (Phase 8): Local is always present (default); R2 is
    // built ONLY when fully credentialed (else the manager fails safe to local).
    // Serving + backfill resolve the provider PER OBJECT from row.storage_disk.
    $container->bind(StorageManager::class, static function (Container $c): StorageManager {
        $media = (array) $c->get(Config::class)->get('media');
        $storage = (array) $c->get(Config::class)->get('storage');

        $providers = ['local' => new LocalStorageProvider([
            'asset' => (string) $media['asset_root'],
            'cache' => (string) $media['cache_root'],
            'render' => (string) $media['render_root'],
        ])];

        $r2 = (array) ($storage['r2'] ?? []);
        $r2Ready = ((string) ($r2['account_id'] ?? '')) !== ''
            && ((string) ($r2['access_key_id'] ?? '')) !== ''
            && ((string) ($r2['secret_access_key'] ?? '')) !== ''
            && ((string) ($r2['bucket'] ?? '')) !== '';
        if ($r2Ready) {
            $endpoint = (string) ($r2['endpoint'] ?? '');
            $host = $endpoint !== ''
                ? (string) (parse_url($endpoint, PHP_URL_HOST) ?? $endpoint)
                : $r2['account_id'] . '.r2.cloudflarestorage.com';
            $providers['r2'] = new R2StorageProvider(
                new CurlBlobClient(),                                  // real transport, real path only
                new SigV4Signer((string) $r2['access_key_id'], (string) $r2['secret_access_key'], (string) ($r2['region'] ?? 'auto'), 's3'),
                $host,
                (string) $r2['bucket'],
                (int) ($r2['presign_ttl'] ?? 300),
                (int) ($r2['max_download_bytes'] ?? 536_870_912),
                (int) ($r2['timeout'] ?? 60),
            );
        }

        $default = (string) ($storage['driver'] ?? 'local');
        if (!isset($providers[$default])) {
            $default = 'local'; // misconfigured driver → never block, serve local
        }

        return new StorageManager($providers, $default);
    });

    $container->bind(Ffmpeg::class, static function (Container $c): Ffmpeg {
        $cfg = (array) $c->get(Config::class)->get('media');

        return new Ffmpeg(
            (string) $cfg['ffmpeg'],
            (string) $cfg['ffprobe'],
            (int) $cfg['ffmpeg_timeout'],
        );
    });

    $container->bind(AssetCache::class, static fn (Container $c): AssetCache => new AssetCache(
        $c->get(Database::class),
        $c->get(MediaPaths::class),
    ));

    $container->bind(RenderRepository::class, static fn (Container $c): RenderRepository => new RenderRepository(
        $c->get(Database::class),
    ));

    // TtsProvider: real OpenAI ONLY when TTS_MOCK=false AND a key is present;
    // otherwise the offline WAV mock. Swap = config only.
    $container->bind(TtsProvider::class, static function (Container $c): TtsProvider {
        $cfg = (array) ($c->get(Config::class)->get('media')['tts'] ?? []);
        $useReal = ($cfg['mock'] ?? true) === false && ((string) ($cfg['api_key'] ?? '')) !== '';

        if ($useReal) {
            return new OpenAiTtsProvider(new CurlHttpClient(), $cfg);
        }

        return new MockTtsProvider((float) ($cfg['words_per_second'] ?? 2.5));
    });

    // StockProvider: real Pexels ONLY when STOCK_MOCK=false AND a key is present;
    // otherwise the offline ffmpeg-lavfi mock. Both produce real clip files.
    $container->bind(StockProvider::class, static function (Container $c): StockProvider {
        $media = (array) $c->get(Config::class)->get('media');
        $cfg = (array) ($media['stock'] ?? []);
        $useReal = ($cfg['mock'] ?? true) === false && ((string) ($cfg['api_key'] ?? '')) !== '';
        $final = (array) $media['final'];

        if ($useReal) {
            return new PexelsStockProvider(new CurlHttpClient(), new CurlBlobClient(), $c->get(Ffmpeg::class), $cfg);
        }

        return new MockStockProvider(
            $c->get(Ffmpeg::class),
            (int) $final['width'],
            (int) $final['height'],
            (int) $media['fps'],
        );
    });

    $container->bind(AssemblyEngine::class, static function (Container $c): AssemblyEngine {
        $cfg = (array) $c->get(Config::class)->get('media');
        $storage = $c->get(StorageManager::class);

        return new AssemblyEngine(
            $c->get(Ffmpeg::class),
            $c->get(MediaPaths::class),
            $c->get(RenderRepository::class),
            $storage->default(),
            (int) $cfg['fps'],
            ['burn_subtitles' => (bool) ($cfg['burn_subtitles'] ?? false)],
            $storage->defaultName(),
        );
    });

    $container->bind(TtsExecutor::class, static function (Container $c): TtsExecutor {
        $cfg = (array) ($c->get(Config::class)->get('media')['tts'] ?? []);

        return new TtsExecutor(
            $c->get(TtsProvider::class),
            $c->get(AssetCache::class),
            (string) ($cfg['voice'] ?? 'alloy'),
        );
    });

    $container->bind(AssetFetchExecutor::class, static function (Container $c): AssetFetchExecutor {
        $media = (array) $c->get(Config::class)->get('media');

        return new AssetFetchExecutor(
            $c->get(Database::class),
            $c->get(StockProvider::class),
            $c->get(Ffmpeg::class),
            $c->get(MediaPaths::class),
            $c->get(AssetCache::class),
            $c->get(StorageManager::class),
            $c->get(QuotaCounter::class),
            (array) $media['final'],
            (int) (($media['stock']['quota_units'] ?? 1)),
            (int) $media['fps'],
        );
    });

    $container->bind(AssemblyExecutor::class, static fn (Container $c): AssemblyExecutor => new AssemblyExecutor(
        $c->get(AssemblyEngine::class),
        (array) $c->get(Config::class)->get('media')['draft'],
    ));

    $container->bind(FinalRenderExecutor::class, static fn (Container $c): FinalRenderExecutor => new FinalRenderExecutor(
        $c->get(AssemblyEngine::class),
        (array) $c->get(Config::class)->get('media')['final'],
    ));

    // MockExecutor serves the remaining mock types; the real executors serve the
    // rest behind their seams — one register() line each (adapter rule).
    $container->bind(ExecutorRegistry::class, static function (Container $c): ExecutorRegistry {
        $registry = new ExecutorRegistry();
        $registry->registerForAll(new MockExecutor());

        $content = $c->get(ContentExecutor::class);
        foreach (ContentExecutor::contentTypes() as $type) {
            $registry->register($type, $content);
        }

        $registry->register('trend_fetch', $c->get(TrendExecutor::class));
        $registry->register('tts', $c->get(TtsExecutor::class));
        $registry->register('asset_fetch', $c->get(AssetFetchExecutor::class));
        $registry->register('assembly', $c->get(AssemblyExecutor::class));
        $registry->register('final_render', $c->get(FinalRenderExecutor::class));

        return $registry;
    });
};
