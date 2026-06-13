<?php

declare(strict_types=1);

/**
 * Web bindings: session, auth, CSRF, views, controllers, router.
 * Everything here may assume an HTTP request context. The worker container
 * never loads this file.
 */

use Kuyash\Auth\Auth;
use Kuyash\Auth\LoginThrottle;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Controllers\LibraryController;
use Kuyash\Controllers\LogsController;
use Kuyash\Controllers\MediaController;
use Kuyash\Controllers\QueueController;
use Kuyash\Controllers\RenderController;
use Kuyash\Controllers\TrendController;
use Kuyash\Controllers\WorkflowController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Csrf;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Flash;
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
use Kuyash\Storage\StorageManager;
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

    $container->bind(WorkspaceSettings::class, static fn (Container $c): WorkspaceSettings => new WorkspaceSettings(
        $c->get(Database::class),
    ));

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
    ));

    $container->bind(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
        $c->get(View::class),
        $c->get(Auth::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(WorkerHeartbeat::class),
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

    $container->bind(WorkflowController::class, static fn (Container $c): WorkflowController => new WorkflowController(
        $c->get(View::class),
        $c->get(WorkflowRepository::class),
        $c->get(RunRepository::class),
        $c->get(JobRepository::class),
        $c->get(EventLog::class),
        $c->get(Engine::class),
        $c->get(AssetRepository::class),
        $c->get(WorkspaceContext::class),
        $c->get(Auth::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
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
    ));

    $container->bind(LogsController::class, static fn (Container $c): LogsController => new LogsController(
        $c->get(View::class),
        $c->get(EventLog::class),
        $c->get(WorkspaceContext::class),
        $c->get(Csrf::class),
        $c->get(Flash::class),
    ));

    $container->bind(Router::class, static function (Container $c): Router {
        $router = new Router($c, $c->get(View::class));
        $registerRoutes = require __DIR__ . '/../routes.php';
        $registerRoutes($router, $c->get(Config::class), $c);

        return $router;
    });
};
