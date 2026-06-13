<?php

declare(strict_types=1);

use Kuyash\Auth\Auth;
use Kuyash\Core\Config;
use Kuyash\Core\Csrf;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\I18n;
use Kuyash\Core\Response;
use Kuyash\Core\Router;
use Kuyash\Core\Session;
use Kuyash\Core\View;

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
    // fixed order (security-critical): session before CSRF (the token lives in
    // it), CSRF gate before dispatch (a future route can never forget the check)
    $container->get(Session::class)->start();

    // Activate the UI locale for this request (Phase 14): the logged-in user's
    // session-cached locale, else the configured default. I18n clamps unknown
    // values to EN. Set once here so every controller renders in one language.
    I18n::setLocale(I18n::resolve(
        $container->get(Auth::class)->sessionLocale(),
        (string) $container->get(Config::class)->get('app.locale', I18n::DEFAULT),
    ));

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'POST') {
        // CSRF-exempt allowlist: external callbacks with no session/token. The
        // ONLY entry is the Zernio webhook, which is authenticated instead by
        // HMAC signature verification inside WebhookController (Phase 10). Kept
        // here, narrowly, so a future route can never silently skip the gate.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim((string) $path, '/');
        $csrfExempt = ['/webhooks/zernio'];

        if (!in_array($path, $csrfExempt, true)) {
            $token = $_POST[Csrf::FIELD] ?? null;
            if (!$container->get(Csrf::class)->validate(is_string($token) ? $token : null)) {
                Response::html(
                    $container->get(View::class)->render('errors/403', ['title' => '403 — Forbidden']),
                    403,
                )->send();

                return;
            }
        }
    }

    $response = $container->get(Router::class)->dispatch(
        $method,
        $_SERVER['REQUEST_URI'] ?? '/',
    );
} catch (Throwable $e) {
    $response = $errorHandler->renderException($e);
}

$response->send();
