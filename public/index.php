<?php

declare(strict_types=1);

use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Router;

// PHP built-in dev server (php -S … public/index.php): serve real files directly.
if (PHP_SAPI === 'cli-server') {
    $requested = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($requested !== __DIR__ . '/' && is_file($requested)) {
        return false;
    }
}

// Bootstrap runs before the central handler exists; a failure here (broken
// config, missing dir) must still produce a generic 500, never a raw trace.
try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';

    /** @var ErrorHandler $errorHandler */
    $errorHandler = $container->get(ErrorHandler::class);
    $errorHandler->register();
} catch (Throwable $e) {
    error_log('Kuyash bootstrap failure: ' . $e::class . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Server Error</h1><p>Something went wrong. The error has been logged.</p>';

    return;
}

try {
    $response = $container->get(Router::class)->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/',
    );
} catch (Throwable $e) {
    $response = $errorHandler->renderException($e);
}

$response->send();
