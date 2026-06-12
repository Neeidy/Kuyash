<?php

declare(strict_types=1);

/**
 * Application bootstrap: autoloader + env + explicit service bindings.
 * Returns the configured Container. Used by public/index.php, bin/ scripts
 * and tests.
 */

use Kuyash\Auth\Auth;
use Kuyash\Auth\LoginThrottle;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Csrf;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Router;
use Kuyash\Core\Session;
use Kuyash\Core\View;
use Kuyash\Workspace\WorkspaceContext;

// --- PSR-4-style autoloader (no Composer): Kuyash\ → src/ ---
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kuyash\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$basePath = dirname(__DIR__);

Config::loadEnvFile($basePath . '/.env');

$container = new Container();

$container->bind(Config::class, static fn (): Config => new Config($basePath . '/config'));

$container->bind(View::class, static fn (): View => new View($basePath . '/templates'));

$container->bind(ErrorHandler::class, static fn (Container $c): ErrorHandler => new ErrorHandler(
    $c->get(Config::class),
    $c->get(View::class),
    $basePath . '/storage/logs',
));

$container->bind(Database::class, static fn (Container $c): Database => new Database(
    (string) $c->get(Config::class)->get('database.path'),
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

$container->bind(LoginThrottle::class, static fn (Container $c): LoginThrottle => new LoginThrottle(
    $c->get(Database::class),
));

$container->bind(WorkspaceContext::class, static fn (Container $c): WorkspaceContext => new WorkspaceContext(
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

$container->bind(DashboardController::class, static fn (Container $c): DashboardController => new DashboardController(
    $c->get(View::class),
    $c->get(Auth::class),
    $c->get(WorkspaceContext::class),
    $c->get(Csrf::class),
));

$container->bind(Router::class, static function (Container $c): Router {
    $router = new Router($c, $c->get(View::class));
    $registerRoutes = require __DIR__ . '/routes.php';
    $registerRoutes($router, $c->get(Config::class), $c);

    return $router;
});

return $container;
