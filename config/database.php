<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // single SQLite file; override with DB_PATH for tests/alternate setups
    'path' => Config::env('DB_PATH', dirname(__DIR__) . '/storage/database/kuyash.sqlite'),
];
