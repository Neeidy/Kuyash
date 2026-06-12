<?php

declare(strict_types=1);

use Kuyash\Auth\Auth;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Response;
use Kuyash\Core\Router;

return static function (Router $router, Config $config, Container $container): void {
    // Route guard, not a middleware stack: wraps a handler so unauthenticated
    // requests bounce to /login. Protected routes stay visible in one place.
    $protected = static function (array|Closure $handler) use ($container): Closure {
        return static function (array $params) use ($container, $handler): Response {
            if (!$container->get(Auth::class)->check()) {
                return Response::redirect('/login');
            }
            if ($handler instanceof Closure) {
                return $handler($params);
            }
            [$class, $method] = $handler;

            return $container->get($class)->{$method}($params);
        };
    };

    $router->get('/', [HomeController::class, 'index']);
    $router->get('/health', [HealthController::class, 'check']);

    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'attemptLogin']);
    $router->post('/logout', $protected([AuthController::class, 'logout']));

    $router->get('/dashboard', $protected([DashboardController::class, 'index']));

    // dev-only: verifies the central error handler (log + generic 500)
    if ($config->get('app.debug') === true) {
        $router->get('/_dev/boom', static function (): Response {
            throw new RuntimeException('Intentional test exception (dev only)');
        });
    }
};
