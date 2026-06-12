<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    'name' => 'kuyash_session',
    // server-side idle lifetime (gc_maxlifetime); cookie itself is a session cookie
    'lifetime' => 7200,
    'save_path' => dirname(__DIR__) . '/storage/sessions',
    // secure-by-default: the cookie requires HTTPS everywhere except explicit
    // local dev (mirrors the APP_DEBUG posture — prod can never opt out by omission)
    'secure' => Config::env('APP_ENV', 'prod') !== 'dev',
];
