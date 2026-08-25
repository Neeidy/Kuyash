<?php

declare(strict_types=1);

/**
 * Web bindings: session, auth, CSRF, views, controllers, router.
 * Everything here may assume an HTTP request context. The worker container
 * never loads this file.
 */

use Kuyash\Auth\Auth;
use Kuyash\Auth\LoginThrottle;
use Kuyash\Compliance\AutoApprovalGate;
use Kuyash\Compliance\DigestReport;
use Kuyash\Compliance\QualityScore;
use Kuyash\Controllers\AccountsController;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\DigestController;
use Kuyash\Controllers\SettingsController;
use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Controllers\LibraryController;
use Kuyash\Controllers\LiveController;
use Kuyash\Controllers\LocaleController;
use Kuyash\Controllers\LogsController;
use Kuyash\Controllers\MediaController;
use Kuyash\Controllers\PlanController;
use Kuyash\Controllers\QueueController;
use Kuyash\Controllers\QuickCreateController;
use Kuyash\Controllers\RenderController;
use Kuyash\Controllers\TrendController;
use Kuyash\Controllers\UsageController;
use Kuyash\Controllers\WorkflowController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Csrf;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Flash;
use Kuyash\Core\RateLimiter;
use Kuyash\Core\Router;
use Kuyash\Core\Session;
use Kuyash\Core\View;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Library\AssetValidator;
use Kuyash\Library\MediaProbe;
use Kuyash\Media\AssetCache;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\RenderRepository;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\PostRepository;
use Kuyash\Publish\PublishCounter;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
use Kuyash\Publish\WebhookController;
use Kuyash\Publish\WebhookInbox;
use Kuyash\Storage\StorageManager;
use Kuyash\Usage\CostEstimator;
use Kuyash\Usage\CreditLedger;
use Kuyash\Usage\UsageRepository;
use Kuyash\Workflow\Cockpit;
use Kuyash\Trend\QuotaCounter;
use Kuyash\Trend\TrendConfigRepository;
use Kuyash\Trend\TrendService;
use Kuyash\Workflow\Engine;
use Kuyash\Workspace\WorkspaceSettings;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkerHeartbeat;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceContext;

return static function (Container $container, string $basePath): void {
    $container->bind(View::class, static fn (): View => new View($basePath . '/templates'));

    $container->bind(ErrorHandler::class, static fn (Container $c): ErrorHandler => new ErrorHandler(
        $c->get(Config::class),
        $c->get(View::class),
        $basePath . '/storage/logs',
    ));

    $container->bind(Session::class, static function (Container $c): Session {
        $config = $c->get(Config::class);

        return new Session(
            (string) $config->get('session.save_path'),
            (string) $config->get('session.name', 'kuyash_session'),
            (int) $config->get('session.lifetime', 7200),
            $config->get('session.secure') !== false, // secure unless explicitly disabled (dev)
        );
    });

    $container->bind(Csrf::class, static fn (): Csrf => new Csrf());

    $container->bind(Flash::class, static fn (): Flash => new Flash());

    $container->bind(LoginThrottle::class, static fn (Container $c): LoginThrottle => new LoginThrottle(
        $c->get(Database::class),
    ));

    $container->bind(WorkspaceContext::class, static fn (Container $c): WorkspaceContext => new WorkspaceContext(
        $c->get(Database::class),
    ));
    // WorkspaceSettings is bound in core.php since Phase 9 (the worker-side
    // AutoApprovalGate reads it).

    $container->bind(Auth::class, static fn (Container $c): Auth => new Auth(
        $c->get(Database::class),
        $c->get(LoginThrottle::class),
        $c->get(WorkspaceContext::class),
    ));

    $container->bind(HomeController::class, static fn (Container $c): HomeController => new HomeController(
        $c->get(Auth::class),
    ));

    $container->bind(HealthController::class, static fn (): HealthController => new HealthController());

    $container->bind(AuthController::class, static fn (Container $c): AuthController => new AuthController(
        $c->get(View::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
    ));

    $container->bind(Cockpit::class, static fn (Container $c): Cockpit => new Cockpit(
        $c->get(Database::class),
        $c->get(AssetCache::class),
        $c->get(CreditLedger::class),
        $c->get(UsageRepository::class),
        $c->get(AccountRepository::class),
        $c->get(JobRepository::class),
        $c->get(\Kuyash\Publish\PlanBoard::class),
        $c->get(WorkspaceSettings::class),
    ));

    $container->bind(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
        $c->get(View::class),
        $c->get(Auth::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(WorkerHeartbeat::class),
        $c->get(Cockpit::class),
        $c->get(\Kuyash\Content\TextEditorView::class),
    ));

    $container->bind(LiveController::class, static fn (Container $c): LiveController => new LiveController(
        $c->get(Auth::class),
        $c->get(WorkspaceContext::class),
        $c->get(Cockpit::class),
    ));

    $container->bind(AssetValidator::class, static function (Container $c): AssetValidator {
        $config = $c->get(Config::class);

        return new AssetValidator(
            (array) $config->get('library.allowed'),
            (int) $config->get('library.max_video_bytes'),
            (int) $config->get('library.max_photo_bytes'),
        );
    });

    $container->bind(MediaProbe::class, static fn (): MediaProbe => new MediaProbe());

    $container->bind(AssetStorage::class, static fn (Container $c): AssetStorage => new AssetStorage(
        (string) $c->get(Config::class)->get('library.storage_root'),
    ));

    $container->bind(AssetIngest::class, static function (Container $c): AssetIngest {
        $config = $c->get(Config::class);

        return new AssetIngest(
            $c->get(AssetValidator::class),
            $c->get(MediaProbe::class),
            $c->get(AssetStorage::class),
            $c->get(AssetRepository::class),
            $c->get(StorageManager::class),
            (int) $config->get('library.max_tags'),
            (int) $config->get('library.max_tag_length'),
        );
    });

    $container->bind(LibraryController::class, static fn (Container $c): LibraryController => new LibraryController(
        $c->get(View::class),
        $c->get(AssetRepository::class),
        $c->get(AssetIngest::class),
        $c->get(AssetStorage::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        $c->get(WorkspaceSettings::class),
        (array) $c->get(Config::class)->get('library'),
        $c->get(\Kuyash\Publish\OccurrenceRepository::class),
    ));

    $container->bind(MediaController::class, static fn (Container $c): MediaController => new MediaController(
        $c->get(AssetRepository::class),
        $c->get(AssetStorage::class),
        $c->get(StorageManager::class),
        $c->get(WorkspaceContext::class),
        (int) $c->get(Config::class)->get('storage.r2.presign_ttl', 300),
    ));

    $container->bind(RenderController::class, static fn (Container $c): RenderController => new RenderController(
        $c->get(RenderRepository::class),
        $c->get(MediaPaths::class),
        $c->get(StorageManager::class),
        $c->get(WorkspaceContext::class),
        (int) $c->get(Config::class)->get('storage.r2.presign_ttl', 300),
    ));

    $container->bind(\Kuyash\Controllers\ContentController::class, static fn (Container $c): \Kuyash\Controllers\ContentController => new \Kuyash\Controllers\ContentController(
        $c->get(\Kuyash\Content\ContentRevision::class),
        $c->get(\Kuyash\Compliance\ContentGate::class),
        $c->get(\Kuyash\Publish\AccountRepository::class),
        $c->get(Database::class),
        $c->get(WorkspaceContext::class),
        $c->get(\Kuyash\Auth\Auth::class),
        $c->get(Flash::class),
        $c->get(\Kuyash\Workflow\EventLog::class),
        $c->get(WorkspaceSettings::class),
        $c->get(\Kuyash\Content\DraftStash::class),
        // one live compliance re-score per save; 30 saves a minute per IP is far
        // above a person editing text and low enough that a loop cannot churn it
        new RateLimiter($c->get(Database::class), 30, 60),
    ));

    $container->bind(WorkflowController::class, static fn (Container $c): WorkflowController => new WorkflowController(
        $c->get(View::class),
        $c->get(WorkflowRepository::class),
        $c->get(RunRepository::class),
        $c->get(JobRepository::class),
        $c->get(EventLog::class),
        $c->get(Engine::class),
        $c->get(AssetRepository::class),
        $c->get(PostRepository::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        $c->get(\Kuyash\Content\TextEditorView::class),
    ));

    $container->bind(AccountsController::class, static fn (Container $c): AccountsController => new AccountsController(
        $c->get(View::class),
        $c->get(AccountRepository::class),
        $c->get(PostRepository::class),
        $c->get(AssetRepository::class),
        $c->get(PublishCounter::class),
        $c->get(WorkspaceSettings::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        $c->get(\Kuyash\Publish\PublishProvider::class),
        // one live provider call per click, against an undocumented vendor
        // rate limit: 20 syncs / 60s per IP
        new RateLimiter($c->get(Database::class), 20, 60),
    ));

    $container->bind(WebhookController::class, static fn (Container $c): WebhookController => new WebhookController(
        $c->get(WebhookInbox::class),
        (string) $c->get(Config::class)->get('zernio.webhook_secret', ''),
        // per-IP throttle: 120 deliveries / 60s — generous (a real webhook never
        // bursts near it); tune down once Zernio's live rate is known.
        new RateLimiter($c->get(Database::class), 120, 60),
    ));

    $container->bind(TrendController::class, static fn (Container $c): TrendController => new TrendController(
        $c->get(View::class),
        $c->get(TrendService::class),
        $c->get(TrendConfigRepository::class),
        $c->get(QuotaCounter::class),
        $c->get(WorkflowRepository::class),
        $c->get(Engine::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(PlanController::class, static fn (Container $c): PlanController => new PlanController(
        $c->get(View::class),
        $c->get(SlotRepository::class),
        $c->get(SlotResolver::class),
        $c->get(WorkspaceSettings::class),
        $c->get(PostRepository::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        $c->get(\Kuyash\Publish\OccurrenceRepository::class),
        $c->get(\Kuyash\Publish\OccurrenceMaterializer::class),
        $c->get(\Kuyash\Publish\PlanBoard::class),
        $c->get(\Kuyash\Library\AssetRepository::class),
        $c->get(\Kuyash\Workflow\WorkflowRepository::class),
        $c->get(\Kuyash\Workflow\Engine::class),
        $c->get(\Kuyash\Workflow\EventLog::class),
        $c->get(\Kuyash\Auth\Auth::class),
        $c->get(\Kuyash\Publish\AccountRepository::class),
        // per-IP throttle on plan writes: 60 changes / 60s. Far above any human
        // editing a weekly plan, low enough that a stuck script cannot churn it.
        new RateLimiter($c->get(Database::class), 60, 60),
        $c->get(\Kuyash\Usage\CostEstimator::class),
    ));

    $container->bind(QueueController::class, static fn (Container $c): QueueController => new QueueController(
        $c->get(View::class),
        $c->get(JobRepository::class),
        $c->get(RunRepository::class),
        $c->get(Engine::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        $c->get(WorkerHeartbeat::class),
        $c->get(SlotRepository::class),
        $c->get(SlotResolver::class),
        $c->get(WorkspaceSettings::class),
        $c->get(\Kuyash\Publish\OccurrenceRepository::class),
        $c->get(Database::class),
        $c->get(\Kuyash\Content\TextEditorView::class),
    ));

    $container->bind(SettingsController::class, static fn (Container $c): SettingsController => new SettingsController(
        $c->get(View::class),
        $c->get(WorkspaceSettings::class),
        $c->get(QualityScore::class),
        $c->get(AutoApprovalGate::class),
        $c->get(EventLog::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(DigestController::class, static fn (Container $c): DigestController => new DigestController(
        $c->get(View::class),
        $c->get(DigestReport::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(LogsController::class, static fn (Container $c): LogsController => new LogsController(
        $c->get(View::class),
        $c->get(EventLog::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(UsageController::class, static fn (Container $c): UsageController => new UsageController(
        $c->get(View::class),
        $c->get(UsageRepository::class),
        $c->get(CreditLedger::class),
        $c->get(WorkspaceSettings::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(QuickCreateController::class, static fn (Container $c): QuickCreateController => new QuickCreateController(
        $c->get(View::class),
        $c->get(AssetRepository::class),
        $c->get(AssetIngest::class),
        $c->get(WorkflowRepository::class),
        $c->get(Engine::class),
        $c->get(CostEstimator::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
        (array) $c->get(Config::class)->get('library'),
    ));

    $container->bind(LocaleController::class, static fn (Container $c): LocaleController => new LocaleController(
        $c->get(Database::class),
        $c->get(Auth::class),
        $c->get(Flash::class),
    ));

    $container->bind(Router::class, static function (Container $c): Router {
        $router = new Router($c, $c->get(View::class));
        $registerRoutes = require __DIR__ . '/../routes.php';
        $registerRoutes($router, $c->get(Config::class), $c);

        return $router;
    });
};
