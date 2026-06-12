<?php

declare(strict_types=1);

/**
 * One-shot user + default workspace seeder (the ONLY way accounts are
 * created in V1 — no self-registration, no web setup surface).
 * Run: cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/create-user.php
 *
 * The password is read from STDIN with echo disabled — NEVER from argv
 * (argv leaks via process lists and shell history).
 */

use Kuyash\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var Database $db */
$db = $container->get(Database::class);

// fail fast with a helpful message when migrations have not run yet
$usersTable = $db->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'");
if ($usersTable === null) {
    fwrite(STDERR, "Database is not migrated. Run: php bin/migrate.php\n");
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);

    return $line === false ? '' : trim($line);
}

function promptHidden(string $label): string
{
    $isTty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($isTty) {
        // restore echo even if we die between -echo and echo (audit nice-to-have)
        register_shutdown_function(static fn () => shell_exec('stty echo'));
        shell_exec('stty -echo');
    }
    $value = prompt($label);
    if ($isTty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $value;
}

// lowercase for consistency with Auth's throttle normalization (audit S1)
$email = strtolower(prompt('Email: '));
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

if ($db->one('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
    fwrite(STDERR, "A user with this email already exists.\n");
    exit(1);
}

$name = prompt('Name (optional): ');

$password = promptHidden('Password (min 12 chars, hidden): ');
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
$confirm = promptHidden('Confirm password: ');
if (!hash_equals($password, $confirm)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$workspaceName = prompt('Workspace name [Default]: ');
if ($workspaceName === '') {
    $workspaceName = 'Default';
}

$hash = password_hash($password, PASSWORD_ARGON2ID);
$now = gmdate('Y-m-d\TH:i:s\Z');

[$userId, $workspaceId] = $db->transaction(static function (Database $db) use ($email, $name, $hash, $workspaceName, $now): array {
    $db->run(
        'INSERT INTO users (email, password_hash, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        [$email, $hash, $name !== '' ? $name : null, $now, $now],
    );
    $userId = $db->lastInsertId();

    $db->run(
        'INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)',
        [$workspaceName, $now, $now],
    );
    $workspaceId = $db->lastInsertId();

    $db->run(
        "INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (?, ?, 'owner', ?)",
        [$workspaceId, $userId, $now],
    );

    return [$userId, $workspaceId];
});

echo "Created user #{$userId} ({$email}) as owner of workspace #{$workspaceId} \"{$workspaceName}\".\n";
exit(0);
