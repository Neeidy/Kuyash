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
 *      string is REFUSED (HTTP 401/403, or 400 for a fully-unsigned request) AND
 *      the object bytes are withheld. The body is checked, not just the status: a
 *      response that returns the object content (any status) FAILS the gate loudly.
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

    // 3) PRIVATE confirmation: the SAME url WITHOUT the signature must be denied.
    //
    // The security-critical fact is whether the unsigned request got the object
    // BYTES back — NOT the exact status code. A fully unsigned request to R2 can
    // legitimately be refused with 400 (malformed/absent auth) instead of 401/403;
    // all three are "denied" SO LONG AS the body was withheld. So we check the body
    // first (the real leak signal) and only then accept an explicit deny status.
    $anonUrl = explode('?', $presigned)[0];
    $anon = $http->get($anonUrl, [], 30);
    $anonBody = (string) ($anon->body ?? '');

    // LEAK = the object content actually came back to an unsigned caller. str_contains
    // (not ===) so a wrapped/partial leak can't slip past on a body-length quirk.
    $bodyLeaked = $payload !== '' && str_contains($anonBody, $payload);
    // PRIVATE = an explicit access-refusal status AND no object bytes returned.
    // A 200 (or any other status) or a body leak fails the gate — we never blindly
    // trust a status code without confirming the body did not escape.
    $isPrivate = !$bodyLeaked && in_array($anon->status, [400, 401, 403], true);

    // surface the unsigned response for inspection: a private bucket answers with a
    // short error document (XML/JSON), never the payload. Truncated + single-line.
    $peek = trim(preg_replace('/\s+/', ' ', substr($anonBody, 0, 180)) ?? '');
    echo "  ····  unsigned GET → HTTP {$anon->status}; body[" . strlen($anonBody) . "B]: "
        . ($peek === '' ? '(empty)' : $peek) . "\n";

    $line(
        $isPrivate,
        "anonymous (unsigned) GET denied — bucket is PRIVATE (HTTP {$anon->status}, object bytes withheld)",
    );
    if ($bodyLeaked) {
        echo "  !!!!  CRITICAL: an unsigned GET returned the OBJECT BYTES — the bucket is PUBLIC. Do NOT enable R2.\n";
    } elseif ($anon->status === 200) {
        echo "  !!!!  CRITICAL: an unsigned GET returned HTTP 200 — treat the bucket as PUBLIC until proven otherwise. Do NOT enable R2.\n";
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
