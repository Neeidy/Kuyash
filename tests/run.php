<?php

declare(strict_types=1);

/**
 * Phase 1+2 smoke tests — plain PHP asserts, no test framework (no-package rule).
 * Run: cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php
 * Exit code 0 = all PASS, 1 = at least one failure.
 *
 * DB-backed groups use :memory: SQLite + the real Migrator (instant, isolated);
 * the WAL pragma is asserted on a temp-file DB because WAL is a no-op on :memory:.
 */

use Kuyash\Auth\Auth;
use Kuyash\Auth\LoginResult;
use Kuyash\Auth\LoginThrottle;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Csrf;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Response;
use Kuyash\Core\Router;
use Kuyash\Core\Session;
use Kuyash\Core\View;
use Kuyash\Database\Migrator;
use Kuyash\Workspace\WorkspaceContext;

$basePath = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefix = 'Kuyash\\';
    if (str_starts_with($class, $prefix)) {
        $file = $basePath . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$pass = 0;
$failures = [];

function check(string $name, bool $cond): void
{
    global $pass, $failures;
    if ($cond) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $failures[] = $name;
        echo "  FAIL  {$name}\n";
    }
}

/** Tiny fixture helper: throwaway dir under the system temp path. */
function tempDir(string $suffix): string
{
    $dir = sys_get_temp_dir() . '/kuyash-test-' . $suffix . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0750, true);

    return $dir;
}

function throws(callable $fn, string $exceptionClass = Throwable::class): bool
{
    try {
        $fn();

        return false;
    } catch (Throwable $e) {
        return $e instanceof $exceptionClass;
    }
}

/** Fresh in-memory DB with the real migrations applied. */
function migratedDb(string $basePath): Database
{
    $db = new Database(':memory:');
    (new Migrator($db, $basePath . '/database/migrations'))->migrate();

    return $db;
}

const NOW_ISO = 'Y-m-d\TH:i:s\Z';

/** Seed one user + workspace + owner membership; returns [userId, workspaceId]. */
function seedUser(Database $db, string $email, string $hash, string $wsName): array
{
    $now = gmdate(NOW_ISO);
    $db->run('INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)', [$email, $hash, $now, $now]);
    $userId = $db->lastInsertId();
    $db->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', [$wsName, $now, $now]);
    $wsId = $db->lastInsertId();
    $db->run("INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (?, ?, 'owner', ?)", [$wsId, $userId, $now]);

    return [$userId, $wsId];
}

echo "== Config / .env parser ==\n";

$envFile = tempDir('env') . '/.env';
file_put_contents($envFile, <<<ENV
# comment line
TEST_PLAIN=hello
TEST_QUOTED="with spaces"
TEST_SINGLE='single'
TEST_BOOL=true
TEST_OFF=false
not_a_key=skipped
TEST_EXISTING=from-file
ENV);

$_ENV['TEST_EXISTING'] = 'pre-set';
Config::loadEnvFile($envFile);

check('env: plain value parsed', ($_ENV['TEST_PLAIN'] ?? null) === 'hello');
check('env: double quotes stripped', ($_ENV['TEST_QUOTED'] ?? null) === 'with spaces');
check('env: single quotes stripped', ($_ENV['TEST_SINGLE'] ?? null) === 'single');
check('env: lowercase key rejected', !array_key_exists('not_a_key', $_ENV));
check('env: existing value never overridden', $_ENV['TEST_EXISTING'] === 'pre-set');
check('env: missing file is a no-op', (static function (): bool {
    Config::loadEnvFile('/nonexistent/.env');

    return true;
})());
check('env(): bool coercion true', Config::env('TEST_BOOL') === true);
check('env(): bool coercion false', Config::env('TEST_OFF') === false);
check('env(): default for missing key', Config::env('TEST_MISSING', 'dflt') === 'dflt');

$configDir = tempDir('cfg');
file_put_contents($configDir . '/test.php', "<?php return ['nested' => ['value' => 42], 'flag' => true];");
$config = new Config($configDir);

check('config: dot-notation lookup', $config->get('test.nested.value') === 42);
check('config: top-level lookup', $config->get('test.flag') === true);
check('config: default on missing key', $config->get('test.missing.deep', 'dflt') === 'dflt');

echo "== Container ==\n";

$container = new Container();
$container->bind('thing', static fn (): object => new stdClass());

check('container: has() after bind', $container->has('thing'));
check('container: same instance cached', $container->get('thing') === $container->get('thing'));
check('container: unknown id throws', throws(static fn () => $container->get('missing'), RuntimeException::class));

echo "== View ==\n";

check('view: e() escapes html', View::e('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;');

$view = new View($basePath . '/templates');
$loginHtml = $view->render('auth/login', ['title' => 'T', 'csrfField' => '', 'error' => null, 'email' => '<Kuyash>']);

check('view: layout wraps content', str_contains($loginHtml, '<!DOCTYPE html>'));
check('view: data escaped in template', str_contains($loginHtml, '&lt;Kuyash&gt;') && !str_contains($loginHtml, 'value="<Kuyash>"'));
check('view: missing template throws', throws(static fn () => $view->render('does-not-exist'), RuntimeException::class));

echo "== Router ==\n";

$routerContainer = new Container();
$router = new Router($routerContainer, $view);
$router->get('/x', static fn (): Response => Response::html('static-ok'));
$router->get('/items/{id}', static fn (array $p): Response => Response::html('item:' . $p['id']));
$router->post('/x', static fn (): Response => Response::html('posted'));

check('router: static route 200', $router->dispatch('GET', '/x')->status() === 200);
check('router: param extracted', $router->dispatch('GET', '/items/42')->body() === 'item:42');
check('router: query string ignored', $router->dispatch('GET', '/x?a=1')->body() === 'static-ok');
check('router: method respected', $router->dispatch('POST', '/x')->body() === 'posted');
check('router: unknown path is 404', $router->dispatch('GET', '/nope')->status() === 404);
check('router: 404 renders template', str_contains($router->dispatch('GET', '/nope')->body(), '404'));
check('router: non-Response handler throws', (static function () use ($router): bool {
    $router->get('/bad', static fn (): string => 'not a response');

    return throws(static fn () => $router->dispatch('GET', '/bad'), RuntimeException::class);
})());

echo "== Router: 405 + HEAD ==\n";

$put = $router->dispatch('PUT', '/x');
check('router: wrong method is 405 not 404', $put->status() === 405);
check('router: Allow lists real verbs + HEAD', ($put->headers()['Allow'] ?? '') === 'GET, HEAD, POST');
$postOnGetOnly = $router->dispatch('POST', '/items/7');
check('router: 405 on param route, Allow GET+HEAD', $postOnGetOnly->status() === 405 && ($postOnGetOnly->headers()['Allow'] ?? '') === 'GET, HEAD');
check('router: HEAD falls back to GET route', $router->dispatch('HEAD', '/x')->status() === 200);
check('router: HEAD on unknown path is 404', $router->dispatch('HEAD', '/nope')->status() === 404);
check('router: unknown path stays 404 for POST', $router->dispatch('POST', '/nope')->status() === 404);

echo "== ErrorHandler ==\n";

$prodConfigDir = tempDir('cfg-prod');
file_put_contents($prodConfigDir . '/app.php', "<?php return ['debug' => false];");
$logDir = tempDir('logs') . '/logs';
$handler = new ErrorHandler(new Config($prodConfigDir), $view, $logDir);
$response = $handler->renderException(new RuntimeException('SECRET-DETAIL-123'));
$logFiles = glob($logDir . '/app-*.log') ?: [];
$logBody = $logFiles !== [] ? (string) file_get_contents($logFiles[0]) : '';

check('error: 500 status', $response->status() === 500);
check('error: message NOT leaked when debug=false', !str_contains($response->body(), 'SECRET-DETAIL-123'));
check('error: generic page shown', str_contains($response->body(), 'Server Error'));
check('error: log file written', $logFiles !== []);
check('error: log contains real message', str_contains($logBody, 'SECRET-DETAIL-123'));

$devConfigDir = tempDir('cfg-dev');
file_put_contents($devConfigDir . '/app.php', "<?php return ['debug' => true];");
$devHandler = new ErrorHandler(new Config($devConfigDir), $view, tempDir('logs-dev'));
$devResponse = $devHandler->renderException(new RuntimeException('VISIBLE-IN-DEBUG'));

check('error: message shown when debug=true', str_contains($devResponse->body(), 'VISIBLE-IN-DEBUG'));

// audit B1: trace args must never reach logs (passwords travel as args)
$ignoreArgsBackup = ini_get('zend.exception_ignore_args');
ini_set('zend.exception_ignore_args', '0');
ErrorHandler::hardenTraceLogging();
$probe = (static fn (string $secretArg): RuntimeException => new RuntimeException('probe'))('SuperSecretArg99');
check('error: trace args stripped after hardening', !str_contains($probe->getTraceAsString(), 'SuperSecretArg99'));
ini_set('zend.exception_ignore_args', (string) $ignoreArgsBackup);

echo "== Database (PDO wrapper + pragmas) ==\n";

$db = new Database(':memory:');

check('db: foreign_keys pragma ON', (int) ($db->one('PRAGMA foreign_keys')['foreign_keys'] ?? 0) === 1);
check('db: busy_timeout 5000', (int) ($db->one('PRAGMA busy_timeout')['timeout'] ?? 0) === 5000);

$fileDb = new Database(tempDir('db') . '/t.sqlite');
check('db: WAL journal mode on file DB', strtolower((string) ($fileDb->one('PRAGMA journal_mode')['journal_mode'] ?? '')) === 'wal');

$db->run('CREATE TABLE tx_probe (v TEXT NOT NULL)');
$db->run('INSERT INTO tx_probe (v) VALUES (?)', ['one']);
check('db: prepared round-trip', ($db->one('SELECT v FROM tx_probe WHERE v = ?', ['one'])['v'] ?? null) === 'one');

$db->transaction(static fn (Database $d) => $d->run('INSERT INTO tx_probe (v) VALUES (?)', ['committed']));
check('db: transaction commits', (int) $db->one('SELECT COUNT(*) AS n FROM tx_probe')['n'] === 2);

check('db: transaction rolls back on throw', throws(static fn () => $db->transaction(static function (Database $d): void {
    $d->run('INSERT INTO tx_probe (v) VALUES (?)', ['doomed']);
    throw new RuntimeException('boom');
}), RuntimeException::class) && (int) $db->one('SELECT COUNT(*) AS n FROM tx_probe')['n'] === 2);

echo "== Migrator ==\n";

$mdb = new Database(':memory:');
$migrator = new Migrator($mdb, $basePath . '/database/migrations');
$applied = $migrator->migrate();

check('migrate: fresh DB applies 0001_init', $applied === ['0001_init.sql']);
check('migrate: second run applies nothing', $migrator->migrate() === []);
check('migrate: tracking row recorded', ($mdb->one('SELECT filename FROM migrations')['filename'] ?? null) === '0001_init.sql');
check('migrate: schema tables exist', count($mdb->all(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('users','workspaces','workspace_users','login_attempts')"
)) === 4);

echo "== Schema constraints ==\n";

$sdb = migratedDb($basePath);
$argonHash = password_hash('CorrectHorseBattery1', PASSWORD_ARGON2ID);
[$userA, $wsA] = seedUser($sdb, 'a@example.com', $argonHash, 'Workspace A');

check('schema: duplicate email rejected (NOCASE)', throws(static fn () => $sdb->run(
    'INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)',
    ['A@EXAMPLE.COM', 'x', gmdate(NOW_ISO), gmdate(NOW_ISO)],
), PDOException::class));
check('schema: FK violation rejected', throws(static fn () => $sdb->run(
    "INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (999, ?, 'member', ?)",
    [$userA, gmdate(NOW_ISO)],
), PDOException::class));
check('schema: role CHECK rejected', throws(static fn () => $sdb->run(
    "INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (?, ?, 'admin', ?)",
    [$wsA, $userA, gmdate(NOW_ISO)],
), PDOException::class));
check('schema: duplicate membership rejected', throws(static fn () => $sdb->run(
    "INSERT INTO workspace_users (workspace_id, user_id, role, created_at) VALUES (?, ?, 'member', ?)",
    [$wsA, $userA, gmdate(NOW_ISO)],
), PDOException::class));

echo "== LoginThrottle ==\n";

$tdb = migratedDb($basePath);
$throttle = new LoginThrottle($tdb);

for ($i = 0; $i < 5; $i++) {
    $throttle->record('victim@example.com', '10.0.0.1', false);
}
check('throttle: 5 failures lock the email', $throttle->isLocked('victim@example.com', '10.0.0.99'));
check('throttle: other email unaffected', !$throttle->isLocked('other@example.com', '10.0.0.99'));

$throttle->record('victim@example.com', '10.0.0.1', true);
check('throttle: success clears the lock', !$throttle->isLocked('victim@example.com', '10.0.0.99'));

for ($i = 0; $i < 5; $i++) {
    $throttle->record('stale@example.com', '10.0.0.2', false);
}
$tdb->run('UPDATE login_attempts SET attempted_at = ? WHERE email = ?', ['2000-01-01T00:00:00Z', 'stale@example.com']);
check('throttle: expired window unlocks', !$throttle->isLocked('stale@example.com', '10.0.0.99'));

for ($i = 0; $i < 20; $i++) {
    $throttle->record("bot{$i}@example.com", '203.0.113.7', false);
}
check('throttle: 20 failures lock the IP for any email', $throttle->isLocked('fresh@example.com', '203.0.113.7'));

echo "== CSRF ==\n";

$_SESSION = [];
$csrf = new Csrf();
$token = $csrf->token();

check('csrf: 64-char hex token', preg_match('/^[0-9a-f]{64}$/', $token) === 1);
check('csrf: token stable within session', $csrf->token() === $token);
check('csrf: valid token accepted', $csrf->validate($token));
check('csrf: tampered token rejected', !$csrf->validate(strrev($token)));
check('csrf: missing token rejected', !$csrf->validate(null) && !$csrf->validate(''));
check('csrf: field renders hidden input', str_contains($csrf->field(), 'name="_csrf"') && str_contains($csrf->field(), $token));
$_SESSION = [];
check('csrf: no session token → reject', !$csrf->validate($token));

echo "== Session hardening ==\n";

$session = new Session('/tmp/kuyash-sess', 'kuyash_session', 7200, true);
$p = $session->cookieParams();

check('session: httponly + samesite Lax', $p['httponly'] === true && $p['samesite'] === 'Lax');
check('session: strict mode + cookies only', $p['use_strict_mode'] === true && $p['use_only_cookies'] === true);
check('session: secure flag honored', $p['secure'] === true);
check('session: session cookie + 7200 gc', $p['cookie_lifetime'] === 0 && $p['gc_maxlifetime'] === 7200);
check('session: dev constructor disables secure', (new Session('/tmp/x', secure: false))->cookieParams()['secure'] === false);

// config/session.php derivation: secure unless APP_ENV=dev (prod-by-default)
$envBackup = $_ENV['APP_ENV'] ?? null;
unset($_ENV['APP_ENV']);
$cfgProd = require $basePath . '/config/session.php';
$_ENV['APP_ENV'] = 'dev';
$cfgDev = require $basePath . '/config/session.php';
if ($envBackup === null) {
    unset($_ENV['APP_ENV']);
} else {
    $_ENV['APP_ENV'] = $envBackup;
}

check('session config: secure by default (no APP_ENV)', $cfgProd['secure'] === true);
check('session config: dev opts out of secure', $cfgDev['secure'] === false);

echo "== WorkspaceContext (tenant isolation) ==\n";

$_SESSION = [];
$wdb = migratedDb($basePath);
$ctx = new WorkspaceContext($wdb);
[$isoUserA, $isoWsA] = seedUser($wdb, 'iso-a@example.com', $argonHash, 'Tenant A');
[$isoUserB, $isoWsB] = seedUser($wdb, 'iso-b@example.com', $argonHash, 'Tenant B');

check('workspace: id() throws when unset (fail-closed)', throws(static fn () => $ctx->id(), RuntimeException::class));
check('workspace: resolve returns own workspace', ($ctx->resolveForUser($isoUserA)['id'] ?? null) === $isoWsA);
check('workspace: A cannot read B\'s workspace', $ctx->workspaceForUser($isoWsB, $isoUserA) === null);
check('workspace: member-scoped read returns own row', ($ctx->workspaceForUser($isoWsA, $isoUserA)['name'] ?? null) === 'Tenant A');
$ctx->set($isoWsA);
check('workspace: set() then id() round-trips', $ctx->id() === $isoWsA);
$ctx->clear();
check('workspace: clear() restores fail-closed', throws(static fn () => $ctx->id(), RuntimeException::class));

echo "== Auth ==\n";

$_SESSION = [];
$adb = migratedDb($basePath);
$athrottle = new LoginThrottle($adb);
$actx = new WorkspaceContext($adb);
$auth = new Auth($adb, $athrottle, $actx);
[$authUser, $authWs] = seedUser($adb, 'owner@example.com', password_hash('CorrectHorseBattery1', PASSWORD_ARGON2ID), 'Main');

check('auth: stored hash is argon2id', str_starts_with(
    (string) $adb->one('SELECT password_hash FROM users WHERE id = ?', [$authUser])['password_hash'],
    '$argon2id$',
));
check('auth: wrong password → Invalid', $auth->attempt('owner@example.com', 'nope-nope-nope', '10.1.0.1') === LoginResult::Invalid);
check('auth: unknown email → Invalid, no exception', $auth->attempt('ghost@example.com', 'whatever-pass', '10.1.0.1') === LoginResult::Invalid);
check('auth: not authenticated before login', !$auth->check());

$result = $auth->attempt('owner@example.com', 'CorrectHorseBattery1', '10.1.0.1');
check('auth: correct password → Ok', $result === LoginResult::Ok);
check('auth: session carries user id', ($_SESSION['auth_user_id'] ?? null) === $authUser);
check('auth: workspace resolved into session', $actx->id() === $authWs);
check('auth: check()/user() after login', $auth->check() && ($auth->user()['email'] ?? null) === 'owner@example.com');

$auth->logout();
check('auth: logout clears session', !$auth->check() && $_SESSION === []);

// legacy-hash upgrade path: bcrypt verifies, then gets rehashed to argon2id
[$legacyUser] = seedUser($adb, 'legacy@example.com', password_hash('LegacyPassword99x', PASSWORD_BCRYPT), 'Legacy WS');
check('auth: bcrypt login still works', $auth->attempt('legacy@example.com', 'LegacyPassword99x', '10.1.0.2') === LoginResult::Ok);
check('auth: hash upgraded to argon2id on login', str_starts_with(
    (string) $adb->one('SELECT password_hash FROM users WHERE id = ?', [$legacyUser])['password_hash'],
    '$argon2id$',
));
$auth->logout();

// fail-closed: a user with no workspace membership cannot log in
$now = gmdate(NOW_ISO);
$adb->run('INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)', ['orphan@example.com', password_hash('OrphanPassword99', PASSWORD_ARGON2ID), $now, $now]);
check('auth: user without workspace → Invalid (fail-closed)', $auth->attempt('orphan@example.com', 'OrphanPassword99', '10.1.0.3') === LoginResult::Invalid);

// lockout: 5 failures, then even the CORRECT password is refused
for ($i = 0; $i < 5; $i++) {
    $auth->attempt('owner@example.com', 'wrong-password', '10.1.0.4');
}
check('auth: locked after 5 failures even with correct password', $auth->attempt('owner@example.com', 'CorrectHorseBattery1', '10.1.0.4') === LoginResult::Locked);

// audit S1: case variants share one throttle bucket — no fresh attempts via OWNER@
check('auth: lock not bypassable by email case', $auth->attempt('OWNER@example.com', 'CorrectHorseBattery1', '10.1.0.4') === LoginResult::Locked);

// and on a fresh account: mixed-case failures count toward the lowercase bucket
[$caseUser] = seedUser($adb, 'case@example.com', password_hash('CasePassword1234', PASSWORD_ARGON2ID), 'Case WS');
for ($i = 0; $i < 5; $i++) {
    $auth->attempt($i % 2 === 0 ? 'CASE@example.com' : 'Case@Example.COM', 'wrong-password', '10.1.0.5');
}
check('auth: mixed-case failures fill one bucket', $auth->attempt('case@example.com', 'CasePassword1234', '10.1.0.5') === LoginResult::Locked);

echo "== Bootstrap (integration) ==\n";

$_SESSION = [];
$app = require $basePath . '/src/bootstrap.php';

check('bootstrap: returns container', $app instanceof Container);

$appRouter = $app->get(Router::class);

$root = $appRouter->dispatch('GET', '/');
check('bootstrap: / redirects to /login when logged out', $root->status() === 302 && ($root->headers()['Location'] ?? '') === '/login');

$loginPage = $appRouter->dispatch('GET', '/login');
check('bootstrap: /login is 200 with csrf field', $loginPage->status() === 200 && str_contains($loginPage->body(), 'name="_csrf"'));
check('bootstrap: /login form posts back to /login', str_contains($loginPage->body(), 'action="/login"'));

$dash = $appRouter->dispatch('GET', '/dashboard');
check('bootstrap: /dashboard guarded → redirect /login', $dash->status() === 302 && ($dash->headers()['Location'] ?? '') === '/login');

$logoutGet = $appRouter->dispatch('GET', '/logout');
check('bootstrap: GET /logout is 405 Allow POST', $logoutGet->status() === 405 && ($logoutGet->headers()['Allow'] ?? '') === 'POST');

$healthResponse = $appRouter->dispatch('GET', '/health');
check('bootstrap: /health minimal public payload', $healthResponse->status() === 200 && $healthResponse->body() === '{"status":"ok"}');
check('bootstrap: /health content-type json', str_contains($healthResponse->headers()['Content-Type'] ?? '', 'application/json'));
check('bootstrap: HEAD /health falls back to GET', $appRouter->dispatch('HEAD', '/health')->status() === 200);

echo "\n" . $pass . ' PASS, ' . count($failures) . " FAIL\n";

if ($failures !== []) {
    echo "Failed:\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

exit(0);
