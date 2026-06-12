<?php

declare(strict_types=1);

use Kuyash\Controllers\HealthController;
use Kuyash\Controllers\HomeController;
use Kuyash\Core\Config;
use Kuyash\Core\Response;
use Kuyash\Core\Router;

return static function (Router $router, Config $config): void {
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/health', [HealthController::class, 'check']);

    // dev-only: verifies the central error handler (log + generic 500)
    if ($config->get('app.debug') === true) {
        $router->get('/_dev/boom', static function (): Response {
            throw new RuntimeException('Intentional test exception (dev only)');
        });
    }
};
