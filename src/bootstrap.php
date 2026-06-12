<?php

declare(strict_types=1);

/**
 * Application bootstrap: autoloader + env + explicit service bindings.
 * Returns the configured Container. Used by public/index.php and tests.
 */

use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Router;
use Kuyash\Core\View;

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

$container->bind(HomeController::class, static fn (Container $c): HomeController => new HomeController(
    $c->get(View::class),
    $c->get(Config::class),
));

$container->bind(HealthController::class, static fn (Container $c): HealthController => new HealthController(
    $c->get(Config::class),
));

$container->bind(Router::class, static function (Container $c): Router {
    $router = new Router($c, $c->get(View::class));
    $registerRoutes = require __DIR__ . '/routes.php';
    $registerRoutes($router, $c->get(Config::class));

    return $router;
});

return $container;
