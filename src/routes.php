<?php

declare(strict_types=1);

use Kuyash\Auth\Auth;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Controllers\LibraryController;
use Kuyash\Controllers\LogsController;
use Kuyash\Controllers\MediaController;
use Kuyash\Controllers\QueueController;
use Kuyash\Controllers\TrendController;
use Kuyash\Controllers\WorkflowController;
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

    $router->get('/trends', $protected([TrendController::class, 'index']));
    $router->post('/trends/niche', $protected([TrendController::class, 'setNiche']));
    $router->post('/trends/refresh', $protected([TrendController::class, 'refresh']));
    $router->post('/trends/create', $protected([TrendController::class, 'create']));

    $router->get('/library', $protected([LibraryController::class, 'index']));
    $router->post('/library/upload', $protected([LibraryController::class, 'upload']));
    $router->get('/library/asset/{id}', $protected([LibraryController::class, 'show']));
    $router->post('/library/asset/{id}/delete', $protected([LibraryController::class, 'delete']));
    $router->get('/media/{id}', $protected([MediaController::class, 'serve']));

    $router->get('/workflows', $protected([WorkflowController::class, 'index']));
    $router->get('/workflows/{id}', $protected([WorkflowController::class, 'show']));
    $router->post('/workflows/{id}/run', $protected([WorkflowController::class, 'run']));
    $router->get('/runs/{id}', $protected([WorkflowController::class, 'showRun']));

    $router->get('/queue', $protected([QueueController::class, 'index']));
    $router->post('/queue/job/{id}/approve', $protected([QueueController::class, 'approve']));
    $router->post('/queue/job/{id}/reject', $protected([QueueController::class, 'reject']));
    $router->post('/queue/job/{id}/retry', $protected([QueueController::class, 'retry']));

    $router->get('/logs', $protected([LogsController::class, 'index']));

    // dev-only: verifies the central error handler (log + generic 500)
    if ($config->get('app.debug') === true) {
        $router->get('/_dev/boom', static function (): Response {
            throw new RuntimeException('Intentional test exception (dev only)');
        });
    }
};
