<?php

declare(strict_types=1);

/**
 * Forward-only migration runner.
 * Run: php bin/migrate.php
 */

use Kuyash\Core\Database;
use Kuyash\Database\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var Database $db */
$db = $container->get(Database::class);

$applied = (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->migrate();

if ($applied === []) {
    echo "Nothing to apply — database is up to date.\n";
} else {
    echo "Applied:\n  - " . implode("\n  - ", $applied) . "\n";
}

// pragma evidence (acceptance criteria: WAL + busy_timeout + FKs)
$journal = $db->one('PRAGMA journal_mode')['journal_mode'] ?? '?';
$busy = $db->one('PRAGMA busy_timeout')['timeout'] ?? '?';
$fk = $db->one('PRAGMA foreign_keys')['foreign_keys'] ?? '?';
echo "journal_mode={$journal} busy_timeout={$busy} foreign_keys={$fk}\n";

exit(0);
