<?php

declare(strict_types=1);

/**
 * Route health check (DEV-ONLY, not product code).
 *
 * WHY IT EXISTS: a hand-rolled `curl -o /dev/null -w '%{http_code}'` sweep
 * reported 200 for a /dashboard that was, in fact, an unhandled PDOException —
 * broken for every workspace that had a publishing time. I could not afterwards
 * account for that reading, which is the whole point: an ad-hoc sweep is easy to
 * get subtly wrong (a stale cookie jar, an unnoticed redirect, a login that
 * quietly failed) and it reports "all green" either way. This exists so the
 * check is the same every time and states what it actually measured.
 *
 * It does three things a one-liner tends not to:
 *   • logs in — every screen worth checking is behind auth, and an unauthenticated
 *     sweep measures the login page while looking like it measured the app;
 *   • names the workspace it landed in — this codebase has no workspace switch,
 *     so a run only ever proves one tenant, and the bug that prompted this lived
 *     in one workspace while the others were fine;
 *   • reads the BODY, not just the status. ErrorHandler DOES answer 500 for a
 *     thrown exception, so the status is normally truthful — but a fatal raised
 *     after output has begun cannot be re-statused, and that is a 200 carrying a
 *     stack trace.
 *
 * Usage:
 *   HEALTH_EMAIL=you@example.com HEALTH_PASSWORD=... \
 *   php bin/health.php [baseUrl] [extra/route ...]
 *
 * Exits 0 when every route is clean, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8082', '/');

// The base URL comes from argv and this script POSTs a password to it. A
// "run the health check against staging" line copy-pasted with a lookalike host
// would hand that password over, and the host could answer with a page carrying
// a CSRF field and a 303 so the run still reported everything clean. So: only
// http/https, and plaintext only to loopback.
$parts = parse_url($base);
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
$host = strtolower((string) ($parts['host'] ?? ''));
if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
    fwrite(STDERR, "health: the base must be an http(s) URL, e.g. http://127.0.0.1:8082\n");
    exit(1);
}
$loopback = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
if ($scheme === 'http' && !$loopback) {
    fwrite(STDERR, "health: refusing to send credentials in the clear to {$host}. Use https.\n");
    exit(1);
}
// Credentials come from the environment and are never defaulted here: a local
// dev password is still a password, and a second copy of one living in a
// committed script is how they spread.
$email = getenv('HEALTH_EMAIL') ?: '';
$password = getenv('HEALTH_PASSWORD') ?: '';
if ($email === '' || $password === '') {
    fwrite(STDERR, "health: set HEALTH_EMAIL and HEALTH_PASSWORD.\n"
        . "  HEALTH_EMAIL=you@example.com HEALTH_PASSWORD=... php bin/health.php [baseUrl] [extra/route ...]\n");
    exit(1);
}

/** The nav set — every screen an operator can reach from the sidebar. */
$routes = [
    '/dashboard', '/trends', '/library', '/workflows', '/queue', '/plan',
    '/accounts', '/logs', '/digest', '/usage', '/settings', '/quick',
];
foreach (array_slice($argv, 2) as $extra) {
    $routes[] = '/' . ltrim($extra, '/');
}

/**
 * Substrings that mean the page failed even if the status did not say so.
 * Deliberately specific: a screen may legitimately contain the word "error",
 * but none of these.
 */
const FAILURE_MARKERS = [
    'Unhandled ',            // the debug error page's own heading
    'SQLSTATE[',             // any PDO failure that reached the output
    'Fatal error:',
    'Uncaught ',
    'Warning: ',
    'Deprecated: ',
    'no such table',
    'no such column',
];

$jar = tempnam(sys_get_temp_dir(), 'kuyash-health-');
if ($jar === false) {
    fwrite(STDERR, "health: could not create a cookie jar\n");
    exit(1);
}

/** @return array{status: int, body: string} */
$get = static function (string $url) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS_STR => 'http,https',
        CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
};

$post = static function (string $url, array $fields) use ($jar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS_STR => 'http,https',
        CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
};

// ── log in ──────────────────────────────────────────────────────────────────
$login = $get($base . '/login');
if ($login['status'] !== 200) {
    fwrite(STDERR, "health: {$base}/login answered {$login['status']} — is the dev server up?\n");
    @unlink($jar);
    exit(1);
}
preg_match('/name="_csrf" value="([^"]+)"/', $login['body'], $m);
$token = $m[1] ?? '';
if ($token === '') {
    fwrite(STDERR, "health: no CSRF token on the login form\n");
    @unlink($jar);
    exit(1);
}

$auth = $post($base . '/login', ['_csrf' => $token, 'email' => $email, 'password' => $password]);
if ($auth['status'] !== 303) {
    // 200 means the form came back — wrong credentials, or the throttle fired.
    fwrite(STDERR, "health: login as {$email} answered {$auth['status']} (expected 303). Wrong password, or rate-limited.\n");
    @unlink($jar);
    exit(1);
}

/**
 * A readable slice of the page around the first failure marker.
 *
 * With APP_DEBUG on this can carry PDO text and host paths, so treat the output
 * as you would a log: it is for the person running the check, not for pasting
 * into an issue unread.
 */
$excerpt = static function (string $body, string $marker): string {
    $at = strpos($body, $marker);
    if ($at === false) {
        return '';
    }
    $from = max(0, $at - 40);
    $slice = substr($body, $from, 200);
    // the debug page is HTML; collapse it so one failure is one readable line
    $slice = trim((string) preg_replace('/\s+/', ' ', strip_tags($slice)));

    return '      ' . $slice;
};

// ── check every route, on status AND content ────────────────────────────────
$failures = 0;
$workspace = null;
foreach ($routes as $route) {
    $res = $get($base . $route);

    // The workspace the session landed in. There is no switch route, so one run
    // proves ONE tenant — saying which is the difference between "the app is
    // healthy" and what was actually measured.
    if ($workspace === null && preg_match('/class="mode-chip__name">([^<]+)</', $res['body'], $w) === 1) {
        $workspace = html_entity_decode($w[1], ENT_QUOTES);
    }

    $hits = [];
    foreach (FAILURE_MARKERS as $marker) {
        if (str_contains($res['body'], $marker)) {
            $hits[] = trim($marker);
        }
    }
    $ok = $res['status'] === 200 && $hits === [];
    $failures += $ok ? 0 : 1;

    // Say WHY it failed, with enough of the page to act on. "body contains:
    // SQLSTATE[" alone just sends the reader back to curl by hand.
    $why = '';
    if (!$ok && $hits !== []) {
        $why = ' — body contains: ' . implode(', ', $hits) . "\n" . $excerpt($res['body'], $hits[0]);
    } elseif ($res['status'] >= 300 && $res['status'] < 400) {
        $why = ' — redirected (not signed in, or the session was dropped)';
    } elseif (!$ok) {
        $why = ' — expected 200';
    }

    printf("  %-16s %3s  %s%s\n", $route, (string) $res['status'], $ok ? 'clean' : 'FAILED', $why);
}

@unlink($jar);

printf(
    "\n%d route(s) checked as %s in workspace %s — %d clean, %d failed\n",
    count($routes),
    $email,
    $workspace ?? '(unknown)',
    count($routes) - $failures,
    $failures,
);
exit($failures === 0 ? 0 : 1);
