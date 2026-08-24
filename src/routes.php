<?php

declare(strict_types=1);

use Kuyash\Auth\Auth;
use Kuyash\Controllers\AccountsController;
use Kuyash\Controllers\AuthController;
use Kuyash\Controllers\DashboardController;
use Kuyash\Controllers\DigestController;
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
use Kuyash\Controllers\SettingsController;
use Kuyash\Controllers\TrendController;
use Kuyash\Controllers\UsageController;
use Kuyash\Controllers\WorkflowController;
use Kuyash\Publish\WebhookController;
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

    // Live layer (Phase 19): immediate-close SSE snapshot, auth + tenant scoped.
    $router->get('/live', $protected([LiveController::class, 'stream']));

    // UI language switch (Phase 14): persists users.locale + session, CSRF-gated.
    $router->post('/locale', $protected([LocaleController::class, 'set']));

    $router->get('/trends', $protected([TrendController::class, 'index']));
    $router->post('/trends/niche', $protected([TrendController::class, 'setNiche']));
    $router->post('/trends/refresh', $protected([TrendController::class, 'refresh']));
    $router->post('/trends/create', $protected([TrendController::class, 'create']));

    $router->get('/library', $protected([LibraryController::class, 'index']));
    $router->post('/library/upload', $protected([LibraryController::class, 'upload']));
    $router->get('/library/asset/{id}', $protected([LibraryController::class, 'show']));
    $router->post('/library/asset/{id}/delete', $protected([LibraryController::class, 'delete']));
    $router->post('/library/asset/{id}/avatar', $protected([LibraryController::class, 'setAvatar']));
    $router->post('/library/avatar/clear', $protected([LibraryController::class, 'clearAvatar']));
    $router->get('/media/{id}', $protected([MediaController::class, 'serve']));
    $router->get('/render/{id}', $protected([RenderController::class, 'serve']));
    $router->get('/render/{id}/poster', $protected([RenderController::class, 'poster']));

    // Quick Create (Phase 12): photo + prompt → AI image-to-video → distribute.
    $router->get('/quick', $protected([QuickCreateController::class, 'index']));
    $router->post('/quick', $protected([QuickCreateController::class, 'create']));

    $router->get('/workflows', $protected([WorkflowController::class, 'index']));
    $router->get('/workflows/{id}', $protected([WorkflowController::class, 'show']));
    $router->post('/workflows/{id}/run', $protected([WorkflowController::class, 'run']));
    $router->get('/runs/{id}', $protected([WorkflowController::class, 'showRun']));

    $router->get('/queue', $protected([QueueController::class, 'index']));
    $router->post('/queue/job/{id}/approve', $protected([QueueController::class, 'approve']));
    $router->post('/queue/job/{id}/reject', $protected([QueueController::class, 'reject']));
    $router->post('/queue/job/{id}/retry', $protected([QueueController::class, 'retry']));

    // Accounts (Phase 10): mock two-leg OAuth connect, disconnect, per-account
    // default reference. The GET callback is guarded by a session `state` nonce.
    $router->get('/accounts', $protected([AccountsController::class, 'index']));
    $router->get('/accounts/connect/{platform}', $protected([AccountsController::class, 'connectStart']));
    $router->get('/accounts/callback', $protected([AccountsController::class, 'connectCallback']));
    $router->post('/accounts/sync', $protected([AccountsController::class, 'sync']));
    $router->post('/accounts/{id}/disconnect', $protected([AccountsController::class, 'disconnect']));
    $router->post('/accounts/{id}/reference', $protected([AccountsController::class, 'setReference']));

    // Inbound Zernio webhook — NOT auth-protected (external callback) and
    // CSRF-EXEMPT (allowlisted before the CSRF gate in public/index.php); it is
    // authenticated instead by HMAC signature verification in the controller.
    $router->post('/webhooks/zernio', [WebhookController::class, 'receive']);

    $router->get('/logs', $protected([LogsController::class, 'index']));

    // Usage, costs & credits (Phase 11): live single-workspace spend vs budget cap
    $router->get('/usage', $protected([UsageController::class, 'index']));

    $router->get('/settings', $protected([SettingsController::class, 'index']));
    $router->post('/settings', $protected([SettingsController::class, 'save']));
    $router->post('/settings/name', $protected([SettingsController::class, 'saveName']));
    $router->post('/settings/ai-disclosure', $protected([SettingsController::class, 'saveAiDisclosure']));
    $router->post('/settings/kill-switch', $protected([SettingsController::class, 'killSwitch']));
    // Phase 23 — the weekly publishing plan. Its own screen (not a Settings
    // card): "when do my videos go out" is opened on purpose, not set once.
    // Templates only; publishing still runs approval → publish_after → gate.
    $router->get('/plan', $protected([PlanController::class, 'index']));
    $router->post('/plan/timezone', $protected([PlanController::class, 'saveTimezone']));
    $router->post('/plan/slots', $protected([PlanController::class, 'addSlot']));
    $router->post('/plan/slots/{id}/remove', $protected([PlanController::class, 'removeSlot']));
    $router->post('/plan/slots/{id}/toggle', $protected([PlanController::class, 'toggleSlot']));
    // Phase 24 — the plan becomes a CALENDAR: dated cells content can be put on,
    // plus a mode per publishing time (you fill it / Kuyash fills it). Nothing
    // here publishes: assigning starts work that still stops at the approval gate.
    $router->post('/plan/slots/{id}/mode', $protected([PlanController::class, 'setSlotMode']));
    $router->post('/plan/settings', $protected([PlanController::class, 'savePlanSettings']));
    $router->post('/plan/pause', $protected([PlanController::class, 'togglePause']));
    $router->post('/plan/day/{id}/assign', $protected([PlanController::class, 'assign']));
    $router->post('/plan/day/{id}/clear', $protected([PlanController::class, 'unassign']));
    $router->get('/digest', $protected([DigestController::class, 'index']));

    // dev-only: verifies the central error handler (log + generic 500)
    if ($config->get('app.debug') === true) {
        $router->get('/_dev/boom', static function (): Response {
            throw new RuntimeException('Intentional test exception (dev only)');
        });
    }
};
