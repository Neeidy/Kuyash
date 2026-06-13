<?php

declare(strict_types=1);

/**
 * R2 enable-time HARD GATE (Phase 8 ADR-014, realized in Phase 13).
 *
 * Run this against a LIVE Cloudflare R2 bucket BEFORE flipping STORAGE_DRIVER=r2.
 * It proves two things and refuses to pass otherwise:
 *   1. SigV4 round-trip works — put → exists/size → presigned GET (200, body
 *      matches) → delete (and the object is then gone).
 *   2. The bucket is PRIVATE — the SAME object URL WITHOUT the presigned query
 *      string returns 401/403, NOT 200. A public bucket FAILS the gate loudly.
 *
 * Requires real credentials (R2_ACCOUNT_ID / R2_ACCESS_KEY_ID /
 * R2_SECRET_ACCESS_KEY / R2_BUCKET). It writes + deletes ONE throwaway object
 * under a dedicated key and never touches real data. Exit codes:
 *   0 = PASS (safe to enable r2)   1 = FAIL (do NOT enable)   2 = not configured
 *
 *   cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/r2-smoke.php
 */

use Kuyash\Core\Container;
use Kuyash\Http\CurlHttpClient;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$container = require dirname(__DIR__) . '/src/bootstrap.php';
/** @var Container $container */

$sm = $container->get(StorageManager::class);
if (!$sm->has('r2')) {
    fwrite(STDERR, "R2 is NOT configured (need R2_ACCOUNT_ID/R2_ACCESS_KEY_ID/R2_SECRET_ACCESS_KEY/R2_BUCKET).\n");
    fwrite(STDERR, "Set the credentials in .env, then re-run this smoke BEFORE STORAGE_DRIVER=r2.\n");
    exit(2);
}

$r2 = $sm->disk('r2');
$http = new CurlHttpClient();

// a throwaway object under a server-shaped key (cache store, traversal-proof name)
$ws = 999_999;
$name = bin2hex(random_bytes(16)) . '.txt';
$key = StorageKey::make('cache', $ws, $name);
$payload = 'kuyash-r2-smoke-' . bin2hex(random_bytes(8));

$tmp = tempnam(sys_get_temp_dir(), 'r2smoke');
file_put_contents($tmp, $payload);

$pass = 0;
$fail = 0;
$line = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $msg . "\n";
    $ok ? $pass++ : $fail++;
};

echo "== R2 enable-time smoke (live bucket) ==\n";

try {
    // 1) put + exists + size
    $r2->put($key, $tmp, 'text/plain');
    $line($r2->exists($key), 'put → object exists');
    $line($r2->size($key) === strlen($payload), 'size matches uploaded bytes');

    // 2) presigned GET works (SigV4 query signing)
    $presigned = (string) $r2->temporaryUrl($key, 120);
    $line(str_contains($presigned, '?'), 'presigned URL minted');
    $signedGet = $http->get($presigned, [], 30);
    $line($signedGet->status === 200 && $signedGet->body === $payload, 'presigned GET → 200 + body matches');

    // 3) PRIVATE confirmation: the SAME url WITHOUT the signature must be denied
    $anonUrl = explode('?', $presigned)[0];
    $anon = $http->get($anonUrl, [], 30);
    $line(
        in_array($anon->status, [401, 403], true),
        "anonymous (unsigned) GET denied — bucket is PRIVATE (HTTP {$anon->status})",
    );
    if ($anon->status === 200) {
        echo "  !!!!  CRITICAL: the bucket served an unsigned GET — it is PUBLIC. Do NOT enable R2.\n";
    }

    // 4) delete + gone
    $r2->delete($key);
    $line(!$r2->exists($key), 'delete → object gone');
} catch (Throwable $e) {
    // status/transport only — never a key/signature (StorageException is sanitized)
    $line(false, 'smoke threw: ' . $e::class . ': ' . $e->getMessage());
    // best-effort cleanup so a partial run leaves nothing behind
    try {
        $r2->delete($key);
    } catch (Throwable) {
    }
} finally {
    @unlink($tmp);
}

echo "\n{$pass} PASS, {$fail} FAIL\n";
if ($fail > 0) {
    echo "GATE: FAILED — do NOT set STORAGE_DRIVER=r2.\n";
    exit(1);
}
echo "GATE: PASSED — SigV4 round-trip OK and the bucket is private. Safe to enable R2.\n";
exit(0);
