<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    'name' => Config::env('APP_NAME', 'Kuyash'),
    'env' => Config::env('APP_ENV', 'prod'),
    // secure default: debug stays off unless .env explicitly enables it
    'debug' => Config::env('APP_DEBUG', false) === true,
    'url' => Config::env('APP_URL', 'http://localhost:8080'),
    'version' => '0.1.0-phase1',
];
