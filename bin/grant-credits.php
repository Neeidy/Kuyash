<?php

declare(strict_types=1);

/**
 * Manual credit grant / adjustment (Phase 11). There is no Stripe and no prepaid
 * economy in V1 — budget is added by hand here (or by a seed). Credits are a
 * money-denominated display layer over real cents; the enforced control is the
 * monthly budget cap on /settings, not this balance.
 *
 *   cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/grant-credits.php <workspace_id> <amount_usd> [reason...]
 *
 * A positive amount is a 'grant'; a negative amount is a signed 'adjust'
 * (correction / claw-back). Amounts are plain dollars, e.g. 25 or 25.50.
 * Arguments are NOT secrets, so argv is fine here (unlike bin/create-user.php).
 */

use Kuyash\Core\Database;
use Kuyash\Usage\CreditLedger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';

/** @var Database $db */
$db = $container->get(Database::class);

$usage = "Usage: php bin/grant-credits.php <workspace_id> <amount_usd> [reason...]\n"
    . "  amount_usd: dollars to grant (positive) or adjust (negative), e.g. 25 or -1.50\n";

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, $usage);
    exit(1);
}

$wsRaw = (string) $args[0];
if (!ctype_digit($wsRaw)) {
    fwrite(STDERR, "workspace_id must be a positive integer.\n" . $usage);
    exit(1);
}
$workspaceId = (int) $wsRaw;

$amountRaw = (string) $args[1];
if (!is_numeric($amountRaw)) {
    fwrite(STDERR, "amount_usd must be a number (dollars).\n" . $usage);
    exit(1);
}
$cents = (int) round((float) $amountRaw * 100);
if ($cents === 0) {
    fwrite(STDERR, "amount_usd must be non-zero.\n");
    exit(1);
}

$reason = trim(implode(' ', array_slice($args, 2)));
if ($reason === '') {
    $reason = $cents > 0 ? 'manual grant' : 'manual adjust';
}

// fail fast if migrations have not run, or the workspace does not exist
$ws = $db->one("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'credit_transactions'");
if ($ws === null) {
    fwrite(STDERR, "Database is not migrated. Run: php bin/migrate.php\n");
    exit(1);
}
$workspace = $db->one('SELECT id, name FROM workspaces WHERE id = ?', [$workspaceId]);
if ($workspace === null) {
    fwrite(STDERR, "Workspace #{$workspaceId} does not exist.\n");
    exit(1);
}

/** @var CreditLedger $ledger */
$ledger = $container->get(CreditLedger::class);

if ($cents > 0) {
    $ledger->grant($workspaceId, $cents, $reason);
    $verb = 'Granted';
} else {
    $ledger->adjust($workspaceId, $cents, $reason);
    $verb = 'Adjusted';
}

$balance = $ledger->balanceCents($workspaceId);
$fmt = static fn (int $c): string => ($c < 0 ? '-$' : '$') . number_format(abs($c) / 100, 2);
$amountFmt = $fmt($cents);
$balanceFmt = $fmt($balance);

echo "{$verb} {$amountFmt} on workspace #{$workspaceId} \"{$workspace['name']}\" — reason: {$reason}.\n";
echo "New credit balance: {$balanceFmt}.\n";
exit(0);
