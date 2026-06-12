<?php

declare(strict_types=1);

/**
 * Worker bootstrap: autoloader + env + core/worker bindings.
 * Returns the configured Container. No Session, Csrf, View or
 * WorkspaceContext is bound here — see src/bindings/worker.php.
 */

use Kuyash\Core\Config;
use Kuyash\Core\Container;

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
(require __DIR__ . '/bindings/core.php')($container, $basePath);
(require __DIR__ . '/bindings/worker.php')($container, $basePath);

return $container;
