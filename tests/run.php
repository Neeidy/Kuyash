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
use Kuyash\Controllers\LibraryController;
use Kuyash\Controllers\MediaController;
use Kuyash\Core\Config;
use Kuyash\Core\Container;
use Kuyash\Core\Csrf;
use Kuyash\Core\Database;
use Kuyash\Core\ErrorHandler;
use Kuyash\Core\Flash;
use Kuyash\Core\Response;
use Kuyash\Core\Router;
use Kuyash\Core\Session;
use Kuyash\Core\View;
use Kuyash\Database\Migrator;
use Kuyash\Core\Format;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Library\AssetValidator;
use Kuyash\Library\InvalidUploadException;
use Kuyash\Library\MediaProbe;
use Kuyash\Library\UploadedFile;
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
check('db: recursive_triggers ON (REPLACE cannot skip triggers)', (int) ($db->one('PRAGMA recursive_triggers')['recursive_triggers'] ?? 0) === 1);

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

check('migrate: fresh DB applies all in order', $applied === ['0001_init.sql', '0002_assets.sql', '0003_workflow_engine.sql', '0004_trends.sql']);
check('migrate: second run applies nothing', $migrator->migrate() === []);
check('migrate: tracking rows recorded', count($mdb->all('SELECT filename FROM migrations')) === 4);
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

/* ---------- Phase 3: synthetic ISO BMFF builders (no binary fixtures) ---------- */

function bmffBox(string $type, string $payload): string
{
    return pack('N', 8 + strlen($payload)) . $type . $payload;
}

function bmffFullBox(string $type, int $version, string $rest): string
{
    return bmffBox($type, chr($version) . "\x00\x00\x00" . $rest);
}

function bmffFtyp(string $brand = 'isom'): string
{
    return bmffBox('ftyp', $brand . "\x00\x00\x02\x00" . 'isomiso2avc1mp41');
}

function bmffMvhdV0(int $timescale, int $duration): string
{
    return bmffFullBox('mvhd', 0, pack('N', 0) . pack('N', 0) . pack('N', $timescale) . pack('N', $duration));
}

function bmffMvhdV1(int $timescale, int $duration): string
{
    return bmffFullBox('mvhd', 1, pack('J', 0) . pack('J', 0) . pack('N', $timescale) . pack('J', $duration));
}

/** @param list<int> $matrixABCD cells a,b,c,d as 16.16 fixed (signed) */
function bmffTkhdV0(int $width, int $height, array $matrixABCD = [0x10000, 0, 0, 0x10000]): string
{
    [$a, $b, $c, $d] = $matrixABCD;
    $pre = pack('N', 0) . pack('N', 0) . pack('N', 1) . pack('N', 0) . pack('N', 0)
        . str_repeat("\x00", 8) . pack('n', 0) . pack('n', 0) . pack('n', 0) . pack('n', 0);
    $matrix = pack('N', $a & 0xFFFFFFFF) . pack('N', $b & 0xFFFFFFFF) . pack('N', 0)
        . pack('N', $c & 0xFFFFFFFF) . pack('N', $d & 0xFFFFFFFF) . pack('N', 0)
        . pack('N', 0) . pack('N', 0) . pack('N', 0x40000000);

    return bmffFullBox('tkhd', 0, $pre . $matrix . pack('N', $width << 16) . pack('N', $height << 16));
}

function writeTempMedia(string $bytes, string $suffix = '.mp4'): string
{
    $path = tempDir('media') . '/probe' . $suffix;
    file_put_contents($path, $bytes);

    return $path;
}

function makePng(int $width, int $height): string
{
    $img = imagecreatetruecolor($width, $height);
    $path = tempDir('png') . '/img.png';
    imagepng($img, $path);
    imagedestroy($img);

    return $path;
}

echo "== MediaProbe (synthetic ISO BMFF) ==\n";

$probe = new MediaProbe();
$identity = [0x10000, 0, 0, 0x10000];
$rot90 = [0, 0x10000, -0x10000, 0];

$plain = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 30000) . bmffBox('trak', bmffTkhdV0(1920, 1080))));
$r = $probe->probe($plain, 'video');
check('probe: mvhd v0 duration', $r['duration_s'] === 30.0);
check('probe: tkhd dimensions', $r['width'] === 1920 && $r['height'] === 1080);
check('probe: landscape classifies 16:9', $r['aspect'] === '16:9');

$v1 = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV1(90000, 90000 * 42) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
$r = $probe->probe($v1, 'video');
check('probe: mvhd v1 (64-bit) duration', $r['duration_s'] === 42.0);
check('probe: native portrait classifies 9:16', $r['aspect'] === '9:16');

$rotated = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(600, 6000) . bmffBox('trak', bmffTkhdV0(1920, 1080, $rot90))));
$r = $probe->probe($rotated, 'video');
check('probe: 90° matrix swaps to portrait 9:16', $r['width'] === 1080 && $r['height'] === 1920 && $r['aspect'] === '9:16');

$moovLast = writeTempMedia(bmffFtyp() . bmffBox('mdat', str_repeat('x', 64)) . bmffBox('moov', bmffMvhdV0(1000, 15000) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
check('probe: moov after mdat still parsed', $probe->probe($moovLast, 'video')['duration_s'] === 15.0);

$largeMdat = pack('N', 1) . 'mdat' . pack('J', 16 + 32) . str_repeat('x', 32);
$withLarge = writeTempMedia(bmffFtyp() . $largeMdat . bmffBox('moov', bmffMvhdV0(1000, 5000) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
check('probe: 64-bit largesize box skipped', $probe->probe($withLarge, 'video')['duration_s'] === 5.0);

$audioFirst = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 9000) . bmffBox('trak', bmffTkhdV0(0, 0)) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
$r = $probe->probe($audioFirst, 'video');
check('probe: 0×0 audio trak skipped for dims', $r['width'] === 1080 && $r['height'] === 1920);

$truncated = writeTempMedia(substr(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 30000)), 0, 20));
$r = $probe->probe($truncated, 'video');
check('probe: truncated file → all null', $r['duration_s'] === null && $r['aspect'] === null);

$garbage = writeTempMedia(random_bytes(256));
$r = $probe->probe($garbage, 'video');
check('probe: garbage → all null, no throw', $r['duration_s'] === null && $r['width'] === null);

check('probe: aspect tolerance + labels', MediaProbe::classifyAspect(1082, 1920) === '9:16'
    && MediaProbe::classifyAspect(1000, 1000) === '1:1'
    && MediaProbe::classifyAspect(1080, 1350) === '4:5'
    && MediaProbe::classifyAspect(1234, 777) === 'other');

$photo = makePng(540, 960);
$r = $probe->probe($photo, 'photo');
check('probe: photo via getimagesize → 9:16, no duration', $r['duration_s'] === null && $r['width'] === 540 && $r['aspect'] === '9:16');

echo "== AssetValidator ==\n";

$libConfig = require $basePath . '/config/library.php';
$validator = new AssetValidator($libConfig['allowed'], 1024 * 1024, 512 * 1024); // tiny caps for tests

$upload = static fn (string $path, string $name, int $err = UPLOAD_ERR_OK): UploadedFile => new UploadedFile($name, $path, is_file($path) ? (int) filesize($path) : 0, $err);
$rejects = static function (UploadedFile $f, string $key) use ($validator): bool {
    try {
        $validator->validate($f);

        return false;
    } catch (InvalidUploadException $e) {
        return $e->messageKey === $key;
    }
};

$tinyMp4 = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 1000) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
$meta = $validator->validate($upload($tinyMp4, 'clip.mp4'));
check('validate: happy mp4', $meta === ['kind' => 'video', 'mime' => 'video/mp4', 'ext' => 'mp4']);

$movFile = writeTempMedia(bmffFtyp('qt  ') . bmffBox('moov', bmffMvhdV0(1000, 1000)), '.mov');
$meta = $validator->validate($upload($movFile, 'clip.MOV'));
check('validate: happy mov (case-insensitive ext)', $meta['kind'] === 'video' && $meta['mime'] === 'video/quicktime');

$pngFile = makePng(100, 100);
check('validate: happy png', $validator->validate($upload($pngFile, 'pic.png'))['kind'] === 'photo');

check('validate: PHP error code rejected', $rejects($upload($tinyMp4, 'clip.mp4', UPLOAD_ERR_PARTIAL), 'upload.failed'));
check('validate: ini-size error → too_large', $rejects($upload($tinyMp4, 'clip.mp4', UPLOAD_ERR_INI_SIZE), 'upload.too_large'));
check('validate: empty file rejected', $rejects($upload(writeTempMedia(''), 'clip.mp4'), 'upload.empty'));
check('validate: extension not allowlisted', $rejects($upload($tinyMp4, 'clip.webm'), 'upload.extension_not_allowed'));
check('validate: no extension rejected', $rejects($upload($tinyMp4, 'clip'), 'upload.extension_not_allowed'));

$bigVideo = writeTempMedia(bmffFtyp() . str_repeat('x', 1024 * 1024 + 1));
check('validate: oversize video rejected', $rejects($upload($bigVideo, 'big.mp4'), 'upload.video_too_large'));
$bigPhotoPath = tempDir('big') . '/big.png';
file_put_contents($bigPhotoPath, file_get_contents($pngFile) . str_repeat('x', 512 * 1024));
check('validate: oversize photo rejected', $rejects($upload($bigPhotoPath, 'big.png'), 'upload.photo_too_large'));

check('validate: png bytes named .mp4 → mismatch', $rejects($upload($pngFile, 'sneaky.mp4'), 'upload.content_mismatch'));
check('validate: text bytes named .png → mismatch', $rejects($upload(writeTempMedia('just some text'), 'fake.png'), 'upload.content_mismatch'));

// JPEG magic + garbage: finfo says image/jpeg (2-byte magic) but getimagesize
// fails on the missing SOF segments → exercises the broken-image branch
$brokenJpg = writeTempMedia("\xFF\xD8\xFF\xE0" . random_bytes(64), '.jpg');
check('validate: jpeg magic + garbage → broken image', $rejects($upload($brokenJpg, 'broken.jpg'), 'upload.broken_image'));

// audit fix: a multipart field named file[] makes every $_FILES entry an array
$weird = UploadedFile::fromArray(['name' => ['a', 'b'], 'tmp_name' => ['x'], 'size' => [1], 'error' => [0]]);
check('validate: non-scalar $_FILES degrades to no_file, no crash', $weird->errorCode === UPLOAD_ERR_NO_FILE && $weird->tmpPath === '');

echo "== AssetStorage ==\n";

$storageRoot = tempDir('assets');
$storage = new AssetStorage($storageRoot, static fn (string $from, string $to): bool => rename($from, $to));

$storedName = $storage->newStoredName('mp4');
check('storage: name is 32-hex + validated ext', preg_match('/^[0-9a-f]{32}\.mp4$/', $storedName) === 1);

$src = writeTempMedia('file-bytes');
$storedPath = $storage->store(7, $src, $storedName);
check('storage: stored under workspace dir', str_starts_with($storedPath, $storageRoot . '/7/') && is_file($storedPath));
check('storage: source moved away', !is_file($src));
check('storage: path() resolves the same file', $storage->path(7, $storedName) === $storedPath);
check('storage: delete unlinks', $storage->delete(7, $storedName) && !is_file($storedPath));
check('storage: delete of missing file is false, no throw', $storage->delete(7, $storedName) === false);
check('storage: malformed stored name throws (traversal guard)', throws(static fn () => $storage->path(7, '../../evil.txt'), RuntimeException::class)
    && throws(static fn () => $storage->path(7, 'short.mp4'), RuntimeException::class));

echo "== AssetRepository (tenant isolation) ==\n";

$_SESSION = [];
$ldb = migratedDb($basePath);
$repo = new AssetRepository($ldb);
$lctx = new WorkspaceContext($ldb);
[$libUserA, $libWsA] = seedUser($ldb, 'lib-a@example.com', $argonHash, 'Lib A');
[$libUserB, $libWsB] = seedUser($ldb, 'lib-b@example.com', $argonHash, 'Lib B');

$assetData = static fn (string $title, array $tags = []): array => [
    'kind' => 'video', 'type' => 'own', 'title' => $title,
    'original_filename' => $title . '.mp4', 'stored_name' => bin2hex(random_bytes(16)) . '.mp4',
    'mime' => 'video/mp4', 'size_bytes' => 1234, 'sha256' => hash('sha256', $title),
    'duration_s' => 21.5, 'width' => 1080, 'height' => 1920, 'aspect' => '9:16', 'tags' => $tags,
];

$lctx->set($libWsA);
$assetA1 = $repo->create($lctx, $assetData('Sunset run', ['outdoor', 'b-roll']));
$assetA2 = $repo->create($lctx, $assetData('Studio 100% take', ['studio']));
$lctx->set($libWsB);
$assetB1 = $repo->create($lctx, $assetData('Tenant B private'));

$lctx->set($libWsA);
check('repo: create/find round-trip with tags + derived ai flag', (static function () use ($repo, $lctx, $assetA1): bool {
    $a = $repo->find($lctx, $assetA1);

    return $a !== null && $a['tags'] === ['outdoor', 'b-roll'] && $a['ai_label_required'] === false;
})());
check('repo: list scoped to workspace A', count($repo->listFor($lctx)) === 2);
check('repo: A cannot find B\'s asset', $repo->find($lctx, $assetB1) === null);
check('repo: A deleting B\'s asset is a no-op', $repo->delete($lctx, $assetB1) === false);
check('repo: search matches title', count($repo->listFor($lctx, 'sunset')) === 1);
check('repo: search matches tags', count($repo->listFor($lctx, 'studio')) === 1
    && ($repo->listFor($lctx, 'studio')[0]['title'] ?? '') === 'Studio 100% take');
check('repo: LIKE wildcard escaped', count($repo->listFor($lctx, '100%')) === 1
    && count($repo->listFor($lctx, '%')) === 1);
check('repo: type filter', count($repo->listFor($lctx, null, 'own')) === 2 && $repo->listFor($lctx, null, 'ai') === []);
$lctx->set($libWsB);
check('repo: B still has its asset after A\'s no-op delete', $repo->find($lctx, $assetB1) !== null);
$lctx->set($libWsA);
check('repo: own delete works', $repo->delete($lctx, $assetA2) === true && $repo->find($lctx, $assetA2) === null);
check('repo: bad kind rejected by CHECK', throws(static fn () => $ldb->run(
    "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes, sha256, tags, status, created_at, updated_at)
     VALUES (?, 'gif', 'own', 't', 'o', 's1.gif', 'image/gif', 1, 'h', '[]', 'ready', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')",
    [$libWsA],
), PDOException::class));

echo "== Response::file + Range math ==\n";

$streamPath = tempDir('stream') . '/data.bin';
file_put_contents($streamPath, 'HelloWorldStream');

$capture = static function (Response $r): string {
    ob_start();
    $r->send();

    return (string) ob_get_clean();
};

check('file: streams full content', $capture(Response::file($streamPath, 200, [])) === 'HelloWorldStream');
check('file: offset+length slice', $capture(Response::file($streamPath, 206, [], 5, 5)) === 'World');

$_SERVER['REQUEST_METHOD'] = 'HEAD';
check('file: HEAD skips the body server-side', $capture(Response::file($streamPath, 200, [])) === '');
unset($_SERVER['REQUEST_METHOD']);

check('range: no header → full', MediaController::parseRange('', 1000) === null);
check('range: start-end', MediaController::parseRange('bytes=0-499', 1000) === [0, 499]);
check('range: open end', MediaController::parseRange('bytes=500-', 1000) === [500, 999]);
check('range: suffix', MediaController::parseRange('bytes=-200', 1000) === [800, 999]);
check('range: end clamped to size', MediaController::parseRange('bytes=900-2000', 1000) === [900, 999]);
check('range: start beyond size → invalid (416)', MediaController::parseRange('bytes=1000-', 1000) === 'invalid');
check('range: inverted → invalid', MediaController::parseRange('bytes=600-500', 1000) === 'invalid');
check('range: multi-range → full 200', MediaController::parseRange('bytes=0-1,5-9', 1000) === null);
check('range: malformed → full 200', MediaController::parseRange('bytes=abc', 1000) === null
    && MediaController::parseRange('items=0-1', 1000) === null);

echo "== Flash ==\n";

$_SESSION = [];
$flash = new Flash();
$flash->add('success', 'upload.success');
$flash->add('error', 'upload.empty');
$pulled = $flash->pull();
check('flash: queued messages pulled in order', $pulled === [
    ['type' => 'success', 'key' => 'upload.success'],
    ['type' => 'error', 'key' => 'upload.empty'],
]);
check('flash: pull clears the queue', $flash->pull() === []);

echo "== Format helper ==\n";

check('format: duration', Format::duration(null) === 'unknown' && Format::duration(21.5) === '0:22'
    && Format::duration(21.5, true) === '0:22 (21.5s)' && Format::duration(95.0) === '1:35');
check('format: bytes', Format::bytes(209715200) === '200.0 MB' && Format::bytes(500) === '1 KB');

echo "== Library + Media controllers (unit) ==\n";

$_SESSION = [];
$_GET = [];
$cdb = migratedDb($basePath);
$crepo = new AssetRepository($cdb);
$cctx = new WorkspaceContext($cdb);
[$cUser, $cWs] = seedUser($cdb, 'ctl@example.com', $argonHash, 'Ctl WS');
[, $cWsOther] = seedUser($cdb, 'ctl2@example.com', $argonHash, 'Other WS');
$cstorageRoot = tempDir('cassets');
$cstorage = new AssetStorage($cstorageRoot, static fn (string $f, string $t): bool => rename($f, $t));
$cingest = new AssetIngest($validator, $probe, $cstorage, $crepo, 10, 32);
$libCtl = new LibraryController(
    $view, $crepo, $cingest, $cstorage, $cctx, new Csrf(), new Flash(), $libConfig,
);
$mediaCtl = new MediaController($crepo, $cstorage, $cctx);

$cctx->set($cWs);
check('library ctl: empty state renders', str_contains($libCtl->index()->body(), 'The library is empty'));
check('library ctl: upload copy derived from config', str_contains($libCtl->index()->body(), 'MP4/MOV video (≤200.0 MB)'));
check('library ctl: non-numeric id → 404', $libCtl->show(['id' => 'abc'])->status() === 404);

check('ingest: tags normalized, deduped, capped', $cingest->parseTags('  Alpha, beta ,alpha,,C  ') === ['alpha', 'beta', 'c']);

$ingestSrc = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 30000) . bmffBox('trak', bmffTkhdV0(1080, 1920))));
$ingestId = $cingest->ingest($cctx, new UploadedFile('My Clip.mp4', $ingestSrc, (int) filesize($ingestSrc), UPLOAD_ERR_OK), 'own', '  ', 'one,two');
$ingestRow = $crepo->find($cctx, $ingestId);
check('ingest: full pipeline (validate→probe→hash→store→create)', $ingestRow !== null
    && $ingestRow['title'] === 'My Clip'             // title defaults from filename
    && $ingestRow['aspect'] === '9:16'               // probe ran
    && $ingestRow['sha256'] === hash('sha256', (string) file_get_contents($cstorage->path($cWs, (string) $ingestRow['stored_name'])))
    && $ingestRow['tags'] === ['one', 'two']);

$orphanSrc = writeTempMedia(bmffFtyp() . bmffBox('moov', bmffMvhdV0(1000, 1000)));
$filesBefore = count(glob($cstorageRoot . '/' . $cWs . '/*') ?: []);
check('ingest: DB failure removes stored file (no orphan)', throws(
    static fn () => $cingest->ingest($cctx, new UploadedFile('x.mp4', $orphanSrc, (int) filesize($orphanSrc), UPLOAD_ERR_OK), 'bogus-type', '', ''),
    PDOException::class,
) && count(glob($cstorageRoot . '/' . $cWs . '/*') ?: []) === $filesBefore);

$cleanupId = $ingestId; // remove the happy-path asset so later grid checks see only "Serve me"
$libCtl->delete(['id' => (string) $cleanupId]);

$mediaBytes = 'MEDIA-BYTES-0123456789';
$mediaSrc = writeTempMedia($mediaBytes, '.bin');
$mediaName = $cstorage->newStoredName('mp4');
$cstorage->store($cWs, $mediaSrc, $mediaName);
$mediaId = $crepo->create($cctx, [
    'kind' => 'video', 'type' => 'face', 'title' => 'Serve me',
    'original_filename' => 'serve.mp4', 'stored_name' => $mediaName, 'mime' => 'video/mp4',
    'size_bytes' => strlen($mediaBytes), 'sha256' => hash('sha256', $mediaBytes),
    'duration_s' => null, 'width' => null, 'height' => null, 'aspect' => null, 'tags' => [],
]);

unset($_SERVER['HTTP_RANGE']);
$full = $mediaCtl->serve(['id' => (string) $mediaId]);
check('media ctl: 200 with type from DB + nosniff', $full->status() === 200
    && $full->headers()['Content-Type'] === 'video/mp4'
    && $full->headers()['X-Content-Type-Options'] === 'nosniff'
    && $full->headers()['Content-Length'] === (string) strlen($mediaBytes));
check('media ctl: full body streamed', $capture($full) === $mediaBytes);

$_SERVER['HTTP_RANGE'] = 'bytes=6-11';
$partial = $mediaCtl->serve(['id' => (string) $mediaId]);
check('media ctl: 206 partial with Content-Range', $partial->status() === 206
    && $partial->headers()['Content-Range'] === 'bytes 6-11/' . strlen($mediaBytes)
    && $capture($partial) === substr($mediaBytes, 6, 6));

$_SERVER['HTTP_RANGE'] = 'bytes=999-';
check('media ctl: unsatisfiable range → 416', $mediaCtl->serve(['id' => (string) $mediaId])->status() === 416);
unset($_SERVER['HTTP_RANGE']);

$cctx->set($cWsOther);
check('media ctl: cross-tenant id → 404', $mediaCtl->serve(['id' => (string) $mediaId])->status() === 404);
$cctx->set($cWs);

check('library ctl: grid renders asset card', str_contains($libCtl->index()->body(), 'Serve me'));
check('library ctl: detail page renders', str_contains($libCtl->show(['id' => (string) $mediaId])->body(), 'SHA-256'));
$deleteResponse = $libCtl->delete(['id' => (string) $mediaId]);
check('library ctl: delete removes row + file', $deleteResponse->status() === 303
    && $crepo->find($cctx, $mediaId) === null
    && !is_file($cstorage->path($cWs, $mediaName)));

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

$lib = $appRouter->dispatch('GET', '/library');
check('bootstrap: /library guarded → redirect /login', $lib->status() === 302 && ($lib->headers()['Location'] ?? '') === '/login');
check('bootstrap: /media/{id} guarded → redirect /login', $appRouter->dispatch('GET', '/media/1')->status() === 302);
$uploadGet = $appRouter->dispatch('GET', '/library/upload');
check('bootstrap: GET /library/upload is 405 Allow POST', $uploadGet->status() === 405 && ($uploadGet->headers()['Allow'] ?? '') === 'POST');

check('bootstrap: /workflows guarded → redirect /login', $appRouter->dispatch('GET', '/workflows')->status() === 302);
check('bootstrap: /queue guarded → redirect /login', $appRouter->dispatch('GET', '/queue')->status() === 302);
check('bootstrap: /logs guarded → redirect /login', $appRouter->dispatch('GET', '/logs')->status() === 302);
$approveGet = $appRouter->dispatch('GET', '/queue/job/1/approve');
check('bootstrap: GET approve route is 405 Allow POST', $approveGet->status() === 405 && ($approveGet->headers()['Allow'] ?? '') === 'POST');

$workerApp = require $basePath . '/src/bootstrap-worker.php';
check('bootstrap-worker: returns container', $workerApp instanceof Container);
check('bootstrap-worker: Worker + Maintenance resolvable', $workerApp->get(Kuyash\Workflow\Worker::class) instanceof Kuyash\Workflow\Worker
    && $workerApp->get(Kuyash\Workflow\Maintenance::class) instanceof Kuyash\Workflow\Maintenance);
check('bootstrap-worker: NO session/csrf/view/workspace bindings', !$workerApp->has(Session::class)
    && !$workerApp->has(Csrf::class) && !$workerApp->has(View::class) && !$workerApp->has(WorkspaceContext::class));
check('bootstrap-worker: ErrorHandler resolvable (CLI mode)', $workerApp->get(ErrorHandler::class) instanceof ErrorHandler);

/* ================== Phase 4: Workflow Engine ================== */

use Kuyash\Content\ContentExecutor;
use Kuyash\Content\CostCalculator;
use Kuyash\Content\MockTextProvider;
use Kuyash\Content\OpenAiTextProvider;
use Kuyash\Content\PromptLibrary;
use Kuyash\Content\Sanitizer;
use Kuyash\Content\TextProvider;
use Kuyash\Content\TextProviderException;
use Kuyash\Content\TextResult;
use Kuyash\Content\VariationEngine;
use Kuyash\Controllers\LogsController;
use Kuyash\Controllers\QueueController;
use Kuyash\Controllers\WorkflowController;
use Kuyash\Core\Messages;
use Kuyash\Http\CurlHttpClient;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpResponse;
use Kuyash\Http\HttpTransportException;
use Kuyash\Trend\FormatRecommender;
use Kuyash\Trend\GoogleTrendsProvider;
use Kuyash\Trend\MockTrendProvider;
use Kuyash\Trend\QuotaCounter;
use Kuyash\Trend\TrendConfigRepository;
use Kuyash\Trend\TrendExecutor;
use Kuyash\Trend\TrendFeed;
use Kuyash\Trend\TrendProvider;
use Kuyash\Trend\TrendProviderException;
use Kuyash\Trend\TrendRepository;
use Kuyash\Trend\TrendResult;
use Kuyash\Trend\TrendService;
use Kuyash\Trend\YouTubeTrendsProvider;
use Kuyash\Workflow\Decision;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\ExecutorRegistry;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\JobResult;
use Kuyash\Workflow\Maintenance;
use Kuyash\Workflow\MockExecutor;
use Kuyash\Workflow\Nodes;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\Watchdog;
use Kuyash\Workflow\Worker;
use Kuyash\Workflow\WorkerHeartbeat;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workflow\WorkflowValidator;

/** Executor that always throws — exercises retry/backoff/dead-letter. */
final class AlwaysThrowsExecutor implements JobExecutor
{
    public function execute(array $job, array $prior): JobResult
    {
        throw new RuntimeException('synthetic executor failure');
    }
}

/** Executor that records claim order. */
final class RecordingExecutor implements JobExecutor
{
    /** @var list<int> */
    public array $seen = [];

    public function execute(array $job, array $prior): JobResult
    {
        $this->seen[] = (int) $job['id'];

        return JobResult::ready(['ok' => true], 'mock');
    }
}

function seedReadyVideo(Database $db, int $wsId, string $title = 'Distribute me'): int
{
    $now = gmdate(NOW_ISO);
    $db->run(
        "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name,
            mime, size_bytes, sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at)
         VALUES (?, 'video', 'own', ?, 'clip.mp4', ?, 'video/mp4', 100, 'h', 21.5, 1080, 1920,
            '9:16', '[]', 'ready', ?, ?)",
        [$wsId, $title, bin2hex(random_bytes(16)) . '.mp4', $now, $now],
    );

    return $db->lastInsertId();
}

/** Engine + Worker wired to a shared, controllable clock string. */
/**
 * Mirrors the production binding: the base executor serves every type, and
 * when it is a MockExecutor the 4 content types are overlaid with a real
 * ContentExecutor(MockTextProvider) — so MockExecutor-based e2e tests exercise
 * the actual Phase 5 content seam. Mechanic tests (Recording/AlwaysThrows) pass
 * non-MockExecutor bases and keep their single executor for all types.
 */
function makeRig(Database $db, JobExecutor $executor, string &$now, ?TextProvider $contentProvider = null): array
{
    $clock = static function () use (&$now): string {
        return $now;
    };
    $events = new EventLog($db);
    $engine = new Engine($db, $events, new WorkflowValidator(), $clock);
    $registry = new ExecutorRegistry();
    $registry->registerForAll($executor);

    if ($executor instanceof MockExecutor) {
        $provider = $contentProvider ?? new MockTextProvider(new VariationEngine(), new PromptLibrary());
        $content = new ContentExecutor($provider);
        foreach (['idea_generation', 'script_draft', 'caption_generation', 'hashtag_generation'] as $t) {
            $registry->register($t, $content);
        }

        // Phase 6: trend_fetch is served by TrendExecutor(MockTrendProvider) over
        // the test db + shared clock — mirrors the production binding so e2e
        // full runs exercise the real trend seam (cache TTL uses test time).
        $trendRepo = new TrendRepository($db);
        $trendCfg = new TrendConfigRepository($db, ['niche' => 'general', 'region' => 'US']);
        $trendSvc = new TrendService(
            new MockTrendProvider(),
            $trendRepo,
            new QuotaCounter($db),
            ['cache_ttl_seconds' => 21600, 'limit' => 8, 'quota_units' => []],
            $clock,
        );
        $registry->register('trend_fetch', new TrendExecutor($trendSvc, $trendRepo, $trendCfg));
    }

    $watchdog = new Watchdog($db, $events);
    $worker = new Worker($db, $engine, $registry, $events, $watchdog, 'test-worker:1:abcd', $clock);

    return [$engine, $worker, $events, $watchdog];
}

const FULL_TYPES = ['trend_fetch', 'idea_generation', 'script_draft', 'tts', 'asset_fetch',
    'assembly', 'caption_generation', 'hashtag_generation', 'music_note', 'preview',
    'compliance_check', 'render_review', 'publish'];
const DIST_TYPES = ['asset_fetch', 'caption_generation', 'hashtag_generation', 'music_note',
    'preview', 'compliance_check', 'render_review', 'publish'];

echo "== 0003 schema: tables + CHECKs ==\n";

$p4db = migratedDb($basePath);
[$p4UserA, $p4WsA] = seedUser($p4db, 'wf-a@example.com', $argonHash, 'WF Tenant A');
[$p4UserB, $p4WsB] = seedUser($p4db, 'wf-b@example.com', $argonHash, 'WF Tenant B');
$nowIso = gmdate(NOW_ISO);

check('schema: all 5 workflow tables exist', count($p4db->all(
    "SELECT name FROM sqlite_master WHERE type = 'table'
     AND name IN ('workflows', 'runs', 'jobs', 'events', 'approvals')"
)) === 5);
check('schema: bad workflow template rejected', throws(static fn () => $p4db->run(
    "INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
     VALUES (?, 'X', 'fancy', '[]', ?, ?)",
    [$p4WsA, $nowIso, $nowIso],
), PDOException::class));
check('schema: bad run entity_type + status rejected', (static function () use ($p4db, $p4WsA, $p4UserA, $nowIso): bool {
    $p4db->run(
        "INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
         VALUES (?, 'T', 'full', '[]', ?, ?)",
        [$p4WsA, $nowIso, $nowIso],
    );
    $wfId = $p4db->lastInsertId();
    $badEntity = throws(static fn () => $p4db->run(
        "INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, created_by, created_at, updated_at)
         VALUES (?, ?, 'spaceship', '[]', ?, ?, ?)",
        [$p4WsA, $wfId, $p4UserA, $nowIso, $nowIso],
    ), PDOException::class);
    $badStatus = throws(static fn () => $p4db->run(
        "INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at)
         VALUES (?, ?, 'trend', '[]', 'paused', ?, ?, ?)",
        [$p4WsA, $wfId, $p4UserA, $nowIso, $nowIso],
    ), PDOException::class);
    $p4db->run('DELETE FROM workflows WHERE id = ?', [$wfId]);

    return $badEntity && $badStatus;
})());
check('schema: bad event level/kind rejected', throws(static fn () => $p4db->run(
    "INSERT INTO events (workspace_id, level, kind, key, created_at) VALUES (?, 'debug', 'transition', 'x', ?)",
    [$p4WsA, $nowIso],
), PDOException::class) && throws(static fn () => $p4db->run(
    "INSERT INTO events (workspace_id, level, kind, key, created_at) VALUES (?, 'info', 'gossip', 'x', ?)",
    [$p4WsA, $nowIso],
), PDOException::class));

echo "== 0003 schema: events append-only triggers ==\n";

$p4db->run(
    "INSERT INTO events (workspace_id, level, kind, key, params_json, created_at) VALUES (?, 'info', 'transition', 'probe', '{}', ?)",
    [$p4WsA, $nowIso],
);
$probeEventId = $p4db->lastInsertId();

check('events: UPDATE rejected at SQL level', throws(static fn () => $p4db->run(
    "UPDATE events SET key = 'tampered' WHERE id = ?",
    [$probeEventId],
), PDOException::class));
check('events: DELETE rejected at SQL level', throws(static fn () => $p4db->run(
    'DELETE FROM events WHERE id = ?',
    [$probeEventId],
), PDOException::class));
check('events: row untouched after rejected tampering', ($p4db->one(
    'SELECT key FROM events WHERE id = ?',
    [$probeEventId],
)['key'] ?? '') === 'probe');
// security audit: with recursive_triggers OFF, REPLACE would skip the BEFORE
// DELETE trigger and silently rewrite the audit row — must be rejected
check('events: INSERT OR REPLACE cannot rewrite audit rows', throws(static fn () => $p4db->run(
    "INSERT OR REPLACE INTO events (id, workspace_id, level, kind, key, params_json, created_at)
     VALUES (?, ?, 'info', 'transition', 'replaced', '{}', ?)",
    [$probeEventId, $p4WsA, $nowIso],
), PDOException::class) && ($p4db->one('SELECT key FROM events WHERE id = ?', [$probeEventId])['key'] ?? '') === 'probe');

echo "== Nodes registry + chain expansion ==\n";

check('nodes: full chain is 13 jobs in canonical order', array_column(Nodes::expand(Nodes::FULL), 'type') === FULL_TYPES);
check('nodes: distribution chain is 8 jobs', array_column(Nodes::expand(Nodes::DISTRIBUTION), 'type') === DIST_TYPES);
check('nodes: PUBLISH expands to render_review + publish', Nodes::NODE_JOBS['PUBLISH'] === ['render_review', 'publish']);
check('nodes: steps are 1-based and contiguous', array_column(Nodes::expand(Nodes::FULL), 'step') === range(1, 13));
check('nodes: every job type has defaults', array_keys(Nodes::JOB_DEFAULTS) === Nodes::jobTypes()
    && Nodes::timeoutFor('assembly') === 900 && Nodes::maxRetriesFor('publish') === 3);

echo "== WorkflowValidator ==\n";

$validator4 = new WorkflowValidator();

check('validator: default full template passes', $validator4->validate('full', Nodes::defaultNodes('full')) === []);
check('validator: default distribution template passes', $validator4->validate('distribution', Nodes::defaultNodes('distribution')) === []);
check('validator: unknown template rejected', $validator4->validate('fancy', []) !== []);
check('validator: non-canonical node rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    $nodes[0]['node'] = 'DRIVE';

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: wrong order rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    [$nodes[0], $nodes[1]] = [$nodes[1], $nodes[0]];

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: missing node rejected (no subset logic)', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    array_pop($nodes);

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: unlocked COMPLIANCE rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    foreach ($nodes as &$n) {
        if ($n['node'] === 'COMPLIANCE') {
            $n['locked'] = false;
        }
    }

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: locking a non-locked node rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    $nodes[0]['locked'] = true;

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: invalid VISUALS source rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    foreach ($nodes as &$n) {
        if ($n['node'] === 'VISUALS') {
            $n['settings']['source'] = 'darkweb';
        }
    }

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: nested settings rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    $nodes[0]['settings']['nested'] = ['a' => 1];

    return $validator4->validate('full', $nodes) !== [];
})());
check('validator: oversized settings rejected', (static function () use ($validator4): bool {
    $nodes = Nodes::defaultNodes('full');
    $tooMany = Nodes::defaultNodes('full');
    for ($i = 0; $i < 20; $i++) {
        $tooMany[0]['settings']["k{$i}"] = 'v';
    }
    $nodes[0]['settings']['long'] = str_repeat('x', 301);

    return $validator4->validate('full', $nodes) !== [] && $validator4->validate('full', $tooMany) !== [];
})());

echo "== WorkflowRepository: defaults + tenant isolation ==\n";

$_SESSION = [];
$p4ctx = new WorkspaceContext($p4db);
$p4events = new EventLog($p4db);
$p4workflows = new WorkflowRepository($p4db, $validator4);
$p4runs = new RunRepository($p4db);
$p4jobs = new JobRepository($p4db);

$p4ctx->set($p4WsA);
$p4workflows->ensureDefaults($p4ctx);
$p4workflows->ensureDefaults($p4ctx); // idempotent
$wsAWorkflows = $p4workflows->listFor($p4ctx);

check('workflows: defaults seeded once (idempotent)', count($wsAWorkflows) === 2
    && array_column($wsAWorkflows, 'template') === ['full', 'distribution']);
check('workflows: seeded nodes validate', $validator4->validate('full', $wsAWorkflows[0]['nodes']) === []
    && $validator4->validate('distribution', $wsAWorkflows[1]['nodes']) === []);

$fullWfA = $wsAWorkflows[0]['id'];
$distWfA = $wsAWorkflows[1]['id'];

$p4ctx->set($p4WsB);
check('workflows: tenant B sees none of A\'s workflows', $p4workflows->listFor($p4ctx) === []
    && $p4workflows->find($p4ctx, $fullWfA) === null);
$p4workflows->ensureDefaults($p4ctx);
check('workflows: tenant B gets its own defaults', count($p4workflows->listFor($p4ctx)) === 2);
$p4ctx->set($p4WsA);

echo "== Engine: startRun guards ==\n";

$now4 = '2026-06-12T10:00:00Z';
[$p4engine, $p4worker] = makeRig($p4db, new MockExecutor($p4db), $now4);

check('startRun: unknown workflow → not_found key', (static function () use ($p4engine, $p4ctx, $p4UserA): bool {
    try {
        $p4engine->startRun($p4ctx, 99999, null, $p4UserA);

        return false;
    } catch (WorkflowException $e) {
        return $e->messageKey === 'workflow.not_found';
    }
})());
check('startRun: distribution without asset → asset_required', (static function () use ($p4engine, $p4ctx, $distWfA, $p4UserA): bool {
    try {
        $p4engine->startRun($p4ctx, $distWfA, null, $p4UserA);

        return false;
    } catch (WorkflowException $e) {
        return $e->messageKey === 'run.asset_required';
    }
})());
check('startRun: cross-tenant asset → asset_not_ready', (static function () use ($p4engine, $p4ctx, $p4db, $distWfA, $p4UserA, $p4WsB): bool {
    $foreignAsset = seedReadyVideo($p4db, $p4WsB, 'Tenant B clip');
    try {
        $p4engine->startRun($p4ctx, $distWfA, $foreignAsset, $p4UserA);

        return false;
    } catch (WorkflowException $e) {
        return $e->messageKey === 'run.asset_not_ready';
    }
})());

echo "== E2E: full run with approval stops ==\n";

$fullRunId = $p4engine->startRun($p4ctx, $fullWfA, null, $p4UserA);

check('full run: created running with first job queued', (static function () use ($p4runs, $p4jobs, $p4ctx, $fullRunId): bool {
    $run = $p4runs->find($p4ctx, $fullRunId);
    $jobs = $p4jobs->jobsForRun($p4ctx, $fullRunId);

    return $run !== null && $run['status'] === 'running' && $run['current_node'] === 'TREND'
        && $run['entity_type'] === 'trend' && $run['entity_id'] === null
        && count($jobs) === 1 && $jobs[0]['type'] === 'trend_fetch' && $jobs[0]['status'] === 'queued';
})());

while ($p4worker->tick()) {
}

check('full run: stops at script approval', (static function () use ($p4runs, $p4jobs, $p4ctx, $fullRunId): bool {
    $run = $p4runs->find($p4ctx, $fullRunId);
    $jobs = $p4jobs->jobsForRun($p4ctx, $fullRunId);
    $last = end($jobs);

    return $run['status'] === 'awaiting_approval' && $run['current_node'] === 'SCRIPT'
        && count($jobs) === 3 && $last['type'] === 'script_draft' && $last['status'] === 'awaiting_approval';
})());
check('full run: prior results flow through the content engine', (static function () use ($p4jobs, $p4ctx, $fullRunId): bool {
    $jobs = $p4jobs->jobsForRun($p4ctx, $fullRunId);
    $trend = $jobs[0]['result']['trend'] ?? '';
    $idea = $jobs[1]['result']['idea'] ?? '';
    $hook = $jobs[1]['result']['hook'] ?? '';
    $script = $jobs[2]['result']['script'] ?? '';

    // idea references the trend; script embeds the idea's hook + the topic;
    // content jobs carry a prompt_version stamp + provider 'mock'
    return $trend !== '' && str_contains($idea, $trend)
        && $hook !== '' && str_contains($script, $hook) && str_contains($script, $trend)
        && ($jobs[1]['result']['prompt_version'] ?? '') === 'idea.v1'
        && ($jobs[2]['result']['prompt_version'] ?? '') === 'script.v1'
        && $jobs[1]['provider'] === 'mock' && $jobs[2]['provider'] === 'mock';
})());

$scriptJob = $p4jobs->awaitingApproval($p4ctx)[0];
$approveResult = $p4engine->approve($p4ctx, $scriptJob['id'], $p4UserA, 'wf-a@example.com');

check('full run: approve resumes the chain', $approveResult === Decision::Ok
    && ($p4runs->find($p4ctx, $fullRunId)['status'] ?? '') === 'running');
check('full run: double approve loses calmly', $p4engine->approve($p4ctx, $scriptJob['id'], $p4UserA, 'wf-a@example.com') === Decision::AlreadyDecided);

while ($p4worker->tick()) {
}

check('full run: stops again at render review', (static function () use ($p4runs, $p4jobs, $p4ctx, $fullRunId): bool {
    $run = $p4runs->find($p4ctx, $fullRunId);
    $awaiting = $p4jobs->awaitingApproval($p4ctx);

    return $run['status'] === 'awaiting_approval' && $run['current_node'] === 'PUBLISH'
        && count($awaiting) === 1 && $awaiting[0]['type'] === 'render_review'
        && str_contains((string) ($awaiting[0]['result']['summary'] ?? ''), 'mock-v0');
})());

$reviewJob = $p4jobs->awaitingApproval($p4ctx)[0];
$p4engine->approve($p4ctx, $reviewJob['id'], $p4UserA, 'wf-a@example.com');

while ($p4worker->tick()) {
}

check('full run: completes with published tail', (static function () use ($p4runs, $p4jobs, $p4ctx, $fullRunId): bool {
    $run = $p4runs->find($p4ctx, $fullRunId);
    $jobs = $p4jobs->jobsForRun($p4ctx, $fullRunId);
    $statuses = array_column($jobs, 'status', 'type');

    return $run['status'] === 'completed'
        && count($jobs) === 13
        && $statuses['publish'] === 'published'
        && $statuses['script_draft'] === 'ready'
        && $statuses['render_review'] === 'ready';
})());
check('full run: execution order matches the template exactly', array_column(
    $p4jobs->jobsForRun($p4ctx, $fullRunId),
    'type',
) === FULL_TYPES);
check('full run: approval records are truthful', (static function () use ($p4runs, $p4ctx, $fullRunId, $p4UserA): bool {
    $records = $p4runs->approvalsForRun($p4ctx, $fullRunId);

    return count($records) === 2
        && array_column($records, 'decision') === ['approved', 'approved']
        && array_column($records, 'node') === ['SCRIPT', 'PUBLISH']
        && (int) $records[0]['decided_by'] === $p4UserA
        && $records[0]['decided_by_email'] === 'wf-a@example.com'
        && $records[0]['mode'] === 'manual';
})());
check('full run: extra tick is a no-op', (static function () use ($p4worker, $p4jobs, $p4ctx): bool {
    $before = count($p4jobs->listFor($p4ctx, 500));
    $did = $p4worker->tick();

    return $did === false && count($p4jobs->listFor($p4ctx, 500)) === $before;
})());
check('full run: publish job carries idempotency key', (static function () use ($p4jobs, $p4ctx, $fullRunId): bool {
    $jobs = $p4jobs->jobsForRun($p4ctx, $fullRunId);
    $publish = array_values(array_filter($jobs, static fn (array $j): bool => $j['type'] === 'publish'))[0] ?? null;

    return $publish !== null && $publish['idempotency_key'] === "run:{$fullRunId}:publish";
})());
check('full run: timeline starts with run.started, ends with run.completed', (static function () use ($p4events, $p4ctx, $fullRunId): bool {
    $timeline = $p4events->timelineForRun($p4ctx, $fullRunId);
    $keys = array_column($timeline, 'key');

    return $keys[0] === 'run.started' && end($keys) === 'run.completed'
        && in_array('approval.approved', $keys, true) && in_array('job.claimed', $keys, true);
})());
check('full run: compliance event recorded with mock-v0 policy', (static function () use ($p4events, $p4ctx, $fullRunId): bool {
    $compliance = array_values(array_filter(
        $p4events->timelineForRun($p4ctx, $fullRunId),
        static fn (array $e): bool => $e['kind'] === 'compliance',
    ));

    return count($compliance) === 1 && $compliance[0]['key'] === 'compliance.passed'
        && ($compliance[0]['params']['policy'] ?? '') === 'mock-v0';
})());

echo "== E2E: distribution run with a REAL library asset ==\n";

$distAssetId = seedReadyVideo($p4db, $p4WsA, 'My real clip');
$distRunId = $p4engine->startRun($p4ctx, $distWfA, $distAssetId, $p4UserA);

while ($p4worker->tick()) {
}

check('distribution: asset_fetch resolved the real asset', (static function () use ($p4jobs, $p4ctx, $distRunId, $distAssetId): bool {
    $jobs = $p4jobs->jobsForRun($p4ctx, $distRunId);
    $fetch = $jobs[0];

    return $fetch['type'] === 'asset_fetch'
        && ($fetch['result']['source'] ?? '') === 'library'
        && ($fetch['result']['asset_id'] ?? 0) === $distAssetId
        && ($fetch['result']['title'] ?? '') === 'My real clip'
        && ($fetch['result']['duration_s'] ?? 0.0) === 21.5
        && ($fetch['result']['ai_label_required'] ?? null) === false;
})());

$distReview = $p4jobs->awaitingApproval($p4ctx)[0];
$p4engine->approve($p4ctx, $distReview['id'], $p4UserA, 'wf-a@example.com');
while ($p4worker->tick()) {
}

check('distribution: completes with 8 jobs in template order', (static function () use ($p4runs, $p4jobs, $p4ctx, $distRunId): bool {
    $run = $p4runs->find($p4ctx, $distRunId);
    $jobs = $p4jobs->jobsForRun($p4ctx, $distRunId);

    return $run['status'] === 'completed' && array_column($jobs, 'type') === DIST_TYPES;
})());
check('distribution: entity recorded on run and copied to jobs', (static function () use ($p4runs, $p4jobs, $p4ctx, $distRunId, $distAssetId): bool {
    $run = $p4runs->find($p4ctx, $distRunId);
    $jobs = $p4jobs->jobsForRun($p4ctx, $distRunId);

    return $run['entity_type'] === 'library' && $run['entity_id'] === $distAssetId
        && $jobs[5]['entity_id'] === $distAssetId;
})());

echo "== E2E: reject cancels the run ==\n";

$rejectRunId = $p4engine->startRun($p4ctx, $distWfA, $distAssetId, $p4UserA);
while ($p4worker->tick()) {
}
$rejectJob = $p4jobs->awaitingApproval($p4ctx)[0];
$rejectResult = $p4engine->reject($p4ctx, $rejectJob['id'], $p4UserA, 'wf-a@example.com');
$jobsAfterReject = count($p4jobs->jobsForRun($p4ctx, $rejectRunId));
while ($p4worker->tick()) {
}

check('reject: run cancelled, job cancelled, record truthful', (static function () use ($p4runs, $p4jobs, $p4ctx, $rejectRunId, $rejectJob, $rejectResult): bool {
    $run = $p4runs->find($p4ctx, $rejectRunId);
    $job = $p4jobs->find($p4ctx, $rejectJob['id']);
    $records = $p4runs->approvalsForRun($p4ctx, $rejectRunId);

    return $rejectResult === Decision::Ok && $run['status'] === 'cancelled'
        && $job['status'] === 'cancelled'
        && count($records) === 1 && $records[0]['decision'] === 'rejected';
})());
check('reject: no further jobs were enqueued', count($p4jobs->jobsForRun($p4ctx, $rejectRunId)) === $jobsAfterReject);

echo "== Tenant isolation: runs/jobs/events/decisions ==\n";

$p4ctx->set($p4WsB);
check('isolation: B sees no runs/jobs/events of A', $p4runs->listFor($p4ctx) === []
    && $p4jobs->listFor($p4ctx) === [] && $p4events->listFor($p4ctx) === []
    && $p4runs->find($p4ctx, $fullRunId) === null && $p4jobs->find($p4ctx, $scriptJob['id']) === null);
check('isolation: B cannot approve/retry A\'s job', $p4engine->approve($p4ctx, $scriptJob['id'], $p4UserB, 'wf-b@example.com') === Decision::NotFound
    && $p4engine->retryJob($p4ctx, $scriptJob['id'], $p4UserB, 'wf-b@example.com') === Decision::NotFound);
$p4ctx->set($p4WsA);

echo "== Claim: order, atomicity, future jobs ==\n";

$claimDb = migratedDb($basePath);
[$claimUser, $claimWs] = seedUser($claimDb, 'claim@example.com', $argonHash, 'Claim WS');
$_SESSION = [];
$claimCtx = new WorkspaceContext($claimDb);
$claimCtx->set($claimWs);
$claimNow = '2026-06-12T12:00:00Z';
$recorder = new RecordingExecutor();
[$claimEngine, $claimWorker] = makeRig($claimDb, $recorder, $claimNow);
(new WorkflowRepository($claimDb, $validator4))->ensureDefaults($claimCtx);
$claimWf = (new WorkflowRepository($claimDb, $validator4))->listFor($claimCtx)[1]; // distribution
$claimAsset = seedReadyVideo($claimDb, $claimWs);
$claimRun = $claimEngine->startRun($claimCtx, $claimWf['id'], $claimAsset, $claimUser);
$firstJobId = (int) $claimDb->one('SELECT id FROM jobs WHERE run_id = ?', [$claimRun])['id'];

// Two extra queued jobs far beyond the chain end (steps 90/91): claim
// mechanics only. NOTE: finalizing a step-90 job finds no step-91 in the
// snapshot chain, so the run completes early — fine here (this section only
// asserts claim order/atomicity), but do not extend it assuming a live run.
$rawJob = static function (int $priority, int $step, string $runAfter) use ($claimDb, $claimWs, $claimRun, $claimNow): int {
    $claimDb->run(
        "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json,
            max_retries, priority, run_after, created_at)
         VALUES (?, ?, 'PREVIEW', ?, 'preview', 'queued', '{}', 3, ?, ?, ?)",
        [$claimWs, $claimRun, $step, $priority, $runAfter, $claimNow],
    );

    return $claimDb->lastInsertId();
};
$lowPrioJob = $rawJob(50, 90, $claimNow);
$samePrioJob = $rawJob(100, 91, $claimNow);
$futureJob = $rawJob(10, 92, '2026-06-12T13:00:00Z');

$claimWorker->tick();
$claimWorker->tick();
$claimWorker->tick();

check('claim: priority first, then id among equals', $recorder->seen === [$lowPrioJob, $firstJobId, $samePrioJob]);
check('claim: future run_after is invisible', $claimWorker->tick() === false);
$claimNow = '2026-06-12T13:00:01Z';
check('claim: due job claimed once the clock passes run_after', $claimWorker->tick() === true
    && end($recorder->seen) === $futureJob);
check('claim: raw double-claim returns no second row', (static function () use ($claimDb, $claimWs, $claimRun, $claimNow): bool {
    $claimDb->run(
        "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, max_retries, priority, run_after, created_at)
         VALUES (?, ?, 'PREVIEW', 93, 'preview', 'queued', '{}', 3, 100, ?, ?)",
        [$claimWs, $claimRun, $claimNow, $claimNow],
    );
    $claimSql = "UPDATE jobs SET status = 'processing', worker_id = ?, started_at = ?
        WHERE id = (SELECT id FROM jobs WHERE status = 'queued' AND run_after <= ? ORDER BY priority, id LIMIT 1)
        RETURNING id";
    $first = $claimDb->run($claimSql, ['w1', $claimNow, $claimNow])->fetch();
    $second = $claimDb->run($claimSql, ['w2', $claimNow, $claimNow])->fetch();

    return $first !== false && $second === false;
})());

echo "== Retry: exponential backoff + dead-letter ==\n";

$failDb = migratedDb($basePath);
[$failUser, $failWs] = seedUser($failDb, 'fail@example.com', $argonHash, 'Fail WS');
$_SESSION = [];
$failCtx = new WorkspaceContext($failDb);
$failCtx->set($failWs);
$failNow = '2026-06-12T14:00:00Z';
[$failEngine, $failWorker, $failEvents] = makeRig($failDb, new AlwaysThrowsExecutor(), $failNow);
(new WorkflowRepository($failDb, $validator4))->ensureDefaults($failCtx);
$failWf = (new WorkflowRepository($failDb, $validator4))->listFor($failCtx)[1];
$failAsset = seedReadyVideo($failDb, $failWs);
$failRunId = $failEngine->startRun($failCtx, $failWf['id'], $failAsset, $failUser);
$failJobs = new JobRepository($failDb);
$failRuns = new RunRepository($failDb);

$failWorker->tick(); // attempt 1 → requeue with backoff

check('backoff: first failure requeues into the future', (static function () use ($failJobs, $failCtx, $failRunId): bool {
    $job = $failJobs->jobsForRun($failCtx, $failRunId)[0];

    return $job['status'] === 'queued' && $job['retry_count'] === 1
        && $job['run_after'] === '2026-06-12T14:00:10Z' // now + 2^1 * 5s
        && str_contains((string) $job['error_message'], 'synthetic executor failure');
})());
check('backoff: not claimable before run_after', $failWorker->tick() === false);

$failNow = '2026-06-12T14:00:11Z';
$failWorker->tick(); // attempt 2 → requeue (+20s)
check('backoff: second failure doubles the delay', (static function () use ($failJobs, $failCtx, $failRunId): bool {
    $job = $failJobs->jobsForRun($failCtx, $failRunId)[0];

    return $job['status'] === 'queued' && $job['retry_count'] === 2
        && $job['run_after'] === '2026-06-12T14:00:31Z'; // 14:00:11 + 2^2 * 5s
})());

$failNow = '2026-06-12T14:00:32Z';
$failWorker->tick(); // attempt 3 = max_retries → dead-letter

check('dead-letter: exhausted job failed + run failed', (static function () use ($failJobs, $failRuns, $failCtx, $failRunId): bool {
    $job = $failJobs->jobsForRun($failCtx, $failRunId)[0];
    $run = $failRuns->find($failCtx, $failRunId);

    return $job['status'] === 'failed' && $job['retry_count'] === 3
        && $job['error_message'] !== null && $run['status'] === 'failed';
})());
check('dead-letter: visible in the queue list', (static function () use ($failJobs, $failCtx): bool {
    $listed = $failJobs->listFor($failCtx);

    return count($listed) === 1 && $listed[0]['status'] === 'failed';
})());
check('dead-letter: requeue + failure events recorded', (static function () use ($failEvents, $failCtx, $failRunId): bool {
    $keys = array_column($failEvents->timelineForRun($failCtx, $failRunId), 'key');

    return count(array_keys($keys, 'job.requeued')) === 2
        && in_array('job.failed', $keys, true) && in_array('run.failed', $keys, true);
})());

echo "== Manual retry resets and reruns ==\n";

$deadJob = $failJobs->jobsForRun($failCtx, $failRunId)[0];
$retryResult = $failEngine->retryJob($failCtx, $deadJob['id'], $failUser, 'fail@example.com');

check('manual retry: resets counters and requeues', (static function () use ($failJobs, $failRuns, $failCtx, $failRunId, $retryResult): bool {
    $job = $failJobs->jobsForRun($failCtx, $failRunId)[0];
    $run = $failRuns->find($failCtx, $failRunId);

    return $retryResult === Decision::Ok && $job['status'] === 'queued'
        && $job['retry_count'] === 0 && $job['error_message'] === null
        && $run['status'] === 'running' && $run['current_node'] === 'LIBRARY';
})());
check('manual retry: only failed jobs qualify', $failEngine->retryJob($failCtx, $deadJob['id'], $failUser, 'fail@example.com') === Decision::AlreadyDecided);

// swap to a WORKING executor on the same db: the retried job now succeeds
[, $healWorker] = makeRig($failDb, new MockExecutor($failDb), $failNow);
while ($healWorker->tick()) {
}
check('manual retry: healed run reaches render review', (static function () use ($failJobs, $failRuns, $failCtx, $failRunId): bool {
    $run = $failRuns->find($failCtx, $failRunId);
    $awaiting = $failJobs->awaitingApproval($failCtx);

    return $run['status'] === 'awaiting_approval' && count($awaiting) === 1
        && $awaiting[0]['type'] === 'render_review';
})());

echo "== Watchdog: stale processing jobs ==\n";

$dogDb = migratedDb($basePath);
[$dogUser, $dogWs] = seedUser($dogDb, 'dog@example.com', $argonHash, 'Dog WS');
$_SESSION = [];
$dogCtx = new WorkspaceContext($dogDb);
$dogCtx->set($dogWs);
$dogNow = '2026-06-12T15:00:00Z';
[$dogEngine, $dogWorker, $dogEvents, $dogWatchdog] = makeRig($dogDb, new MockExecutor($dogDb), $dogNow);
(new WorkflowRepository($dogDb, $validator4))->ensureDefaults($dogCtx);
$dogWf = (new WorkflowRepository($dogDb, $validator4))->listFor($dogCtx)[1];
$dogAsset = seedReadyVideo($dogDb, $dogWs);
$dogRunId = $dogEngine->startRun($dogCtx, $dogWf['id'], $dogAsset, $dogUser);
$dogJobId = (int) $dogDb->one('SELECT id FROM jobs WHERE run_id = ?', [$dogRunId])['id'];

// simulate a crashed worker: claimed long ago, never finalized (asset_fetch timeout = 300s)
$dogDb->run(
    "UPDATE jobs SET status = 'processing', worker_id = 'dead:1:ffff', started_at = '2026-06-12T14:00:00Z' WHERE id = ?",
    [$dogJobId],
);
$dogActions = $dogWatchdog->sweep($dogNow);

check('watchdog: stale job requeued with warn event', (static function () use ($dogDb, $dogEvents, $dogCtx, $dogJobId, $dogRunId, $dogActions): bool {
    $job = $dogDb->one('SELECT * FROM jobs WHERE id = ?', [$dogJobId]);
    $keys = array_column($dogEvents->timelineForRun($dogCtx, $dogRunId), 'key');

    return $dogActions === 1 && $job['status'] === 'queued' && (int) $job['retry_count'] === 1
        && $job['started_at'] === null && $job['worker_id'] === null
        && in_array('watchdog.requeued', $keys, true);
})());
check('watchdog: fresh processing job left alone', (static function () use ($dogDb, $dogWatchdog, $dogJobId, $dogNow): bool {
    $dogDb->run(
        "UPDATE jobs SET status = 'processing', started_at = ? WHERE id = ?",
        [$dogNow, $dogJobId],
    );

    return $dogWatchdog->sweep($dogNow) === 0
        && $dogDb->one('SELECT status FROM jobs WHERE id = ?', [$dogJobId])['status'] === 'processing';
})());
check('watchdog: exhausted stale job dead-letters + fails run', (static function () use ($dogDb, $dogWatchdog, $dogEvents, $dogCtx, $dogJobId, $dogRunId, $dogNow): bool {
    $dogDb->run(
        "UPDATE jobs SET retry_count = 2, started_at = '2026-06-12T14:00:00Z' WHERE id = ?",
        [$dogJobId],
    );
    $acted = $dogWatchdog->sweep($dogNow);
    $job = $dogDb->one('SELECT * FROM jobs WHERE id = ?', [$dogJobId]);
    $run = $dogDb->one('SELECT status FROM runs WHERE id = ?', [$dogRunId]);
    $keys = array_column($dogEvents->timelineForRun($dogCtx, $dogRunId), 'key');

    return $acted === 1 && $job['status'] === 'failed'
        && str_contains((string) $job['error_message'], 'watchdog')
        && $run['status'] === 'failed' && in_array('watchdog.failed', $keys, true);
})());
check('watchdog: empty tick runs the sweep', (static function () use ($dogDb, $dogWorker, $dogJobId): bool {
    // re-stale the job; an EMPTY worker tick (no queued jobs) must rescue it
    $dogDb->run(
        "UPDATE jobs SET status = 'processing', retry_count = 0, started_at = '2026-06-12T14:00:00Z' WHERE id = ?",
        [$dogJobId],
    );
    $didWork = $dogWorker->tick();
    $job = $dogDb->one('SELECT status FROM jobs WHERE id = ?', [$dogJobId]);

    return $didWork === false && $job['status'] === 'queued';
})());
// architect review: a finalize arriving AFTER the watchdog reset (or after a
// re-claim by another worker) must lose on worker identity, not just status
check('finalize: stale worker write after requeue/re-claim is a no-op', (static function () use ($dogDb, $dogEngine, $dogJobId, $dogRunId): bool {
    $stale = $dogDb->run(
        "UPDATE jobs SET status = 'processing', worker_id = 'w-old', started_at = '2026-06-12T15:00:00Z'
         WHERE id = ? RETURNING *",
        [$dogJobId],
    )->fetch();
    // watchdog takes the row back while w-old is still executing
    $dogDb->run("UPDATE jobs SET status = 'queued', worker_id = NULL, started_at = NULL WHERE id = ?", [$dogJobId]);
    $jobsBefore = count($dogDb->all('SELECT id FROM jobs WHERE run_id = ?', [$dogRunId]));
    $dogEngine->finalize($stale, JobResult::ready(['late' => true], 'mock'));
    $afterRequeue = $dogDb->one('SELECT status, result_json FROM jobs WHERE id = ?', [$dogJobId]);

    // …and the same stale write against a re-claim by a SECOND worker
    $dogDb->run(
        "UPDATE jobs SET status = 'processing', worker_id = 'w-new', started_at = '2026-06-12T15:01:00Z' WHERE id = ?",
        [$dogJobId],
    );
    $dogEngine->finalize($stale, JobResult::ready(['late' => true], 'mock'));
    $afterReclaim = $dogDb->one('SELECT status, worker_id, result_json FROM jobs WHERE id = ?', [$dogJobId]);
    $jobsAfter = count($dogDb->all('SELECT id FROM jobs WHERE run_id = ?', [$dogRunId]));

    return $afterRequeue['status'] === 'queued' && $afterRequeue['result_json'] === null
        && $afterReclaim['status'] === 'processing' && $afterReclaim['worker_id'] === 'w-new'
        && $afterReclaim['result_json'] === null && $jobsBefore === $jobsAfter;
})());

echo "== Events feed: order, filters, immutability in flow ==\n";

check('events: feed is newest-first with monotonic ids', (static function () use ($p4events, $p4ctx): bool {
    $feed = $p4events->listFor($p4ctx);
    $ids = array_column($feed, 'id');
    $sorted = $ids;
    rsort($sorted);

    return $feed !== [] && $ids === $sorted;
})());
check('events: level filter returns only that level', (static function () use ($p4events, $p4ctx): bool {
    $warns = $p4events->listFor($p4ctx, level: 'warn');

    return $warns !== [] && array_unique(array_column($warns, 'level')) === ['warn'];
})());
check('events: kind filter returns compliance rows', (static function () use ($p4events, $p4ctx): bool {
    $rows = $p4events->listFor($p4ctx, kinds: ['compliance', 'guardrail']);

    return $rows !== [] && array_unique(array_column($rows, 'kind')) === ['compliance'];
})());
check('events: limit respected', count($p4events->listFor($p4ctx, limit: 5)) === 5);

echo "== Maintenance: prune + orphan sweep ==\n";

$maintDb = migratedDb($basePath);
[, $maintWs] = seedUser($maintDb, 'maint@example.com', $argonHash, 'Maint WS');
$maintRoot = tempDir('sweep');
$maintenance = new Maintenance($maintDb, $maintRoot);
$maintNow = gmdate(NOW_ISO);

$maintDb->run('INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (?, ?, 0, ?)', ['old@example.com', '10.9.0.1', '2026-06-01T00:00:00Z']);
$maintDb->run('INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (?, ?, 0, ?)', ['new@example.com', '10.9.0.1', $maintNow]);

check('maintenance: prune removes only stale login rows', $maintenance->pruneLoginAttempts($maintNow) === 1
    && count($maintDb->all('SELECT * FROM login_attempts')) === 1
    && $maintDb->one('SELECT email FROM login_attempts')['email'] === 'new@example.com');

mkdir($maintRoot . '/' . $maintWs, 0750, true);
$knownName = bin2hex(random_bytes(16)) . '.mp4';
$maintDb->run(
    "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
        size_bytes, sha256, tags, status, created_at, updated_at)
     VALUES (?, 'video', 'own', 'Known', 'k.mp4', ?, 'video/mp4', 1, 'h', '[]', 'ready', ?, ?)",
    [$maintWs, $knownName, $maintNow, $maintNow],
);
$knownFile = $maintRoot . '/' . $maintWs . '/' . $knownName;
$orphanOld = $maintRoot . '/' . $maintWs . '/' . bin2hex(random_bytes(16)) . '.mp4';
$orphanFresh = $maintRoot . '/' . $maintWs . '/' . bin2hex(random_bytes(16)) . '.mp4';
file_put_contents($knownFile, 'known');
file_put_contents($orphanOld, 'orphan-old');
file_put_contents($orphanFresh, 'orphan-fresh');
touch($knownFile, time() - 7200);
touch($orphanOld, time() - 7200);

$swept = $maintenance->sweepOrphanAssets($maintNow);

check('maintenance: sweep removes only the old orphan', $swept === [$orphanOld]
    && !is_file($orphanOld) && is_file($knownFile) && is_file($orphanFresh));
check('maintenance: second sweep is a no-op', $maintenance->sweepOrphanAssets($maintNow) === []);

echo "== ErrorHandler: CLI mode ==\n";

$cliHandler = new ErrorHandler(new Config($prodConfigDir), null, tempDir('cli-logs') . '/logs', cliMode: true);
$cliStream = fopen('php://memory', 'r+');
$cliLine = $cliHandler->reportCli(new RuntimeException('CLI-FAILURE-77'), $cliStream);
rewind($cliStream);
$cliStreamOut = (string) stream_get_contents($cliStream);
fclose($cliStream);

check('cli handler: plain-text line, no HTML', str_contains($cliLine, 'RuntimeException')
    && str_contains($cliLine, 'CLI-FAILURE-77') && !str_contains($cliLine, '<'));
check('cli handler: line written to the given stream', str_contains($cliStreamOut, 'CLI-FAILURE-77'));
check('cli handler: web fallback works without a view', (static function () use ($prodConfigDir): bool {
    $noView = new ErrorHandler(new Config($prodConfigDir), null, tempDir('cli-logs-2') . '/logs');
    $response = $noView->renderException(new RuntimeException('x'));

    return $response->status() === 500 && str_contains($response->body(), 'Server Error');
})());

echo "== Messages dictionary ==\n";

check('messages: event template substitution', Messages::event('job.requeued', ['type' => 'tts', 'retry' => 2, 'max' => 3, 'run' => 7])
    === 'tts requeued, retry 2/3 (run #7)');
check('messages: unknown key falls back to the key', Messages::text('no.such.key') === 'no.such.key'
    && Messages::event('no.such.event', []) === 'no.such.event');
check('messages: missing param left as placeholder', Messages::event('job.created', ['run' => 1])
    === '{type} queued (run #1)');
check('messages: status labels humanize enums', Messages::status('awaiting_approval') === 'awaiting approval'
    && Messages::status('published') === 'published' && Messages::status('weird') === 'weird');

echo "== Controllers: queue + workflows + logs (unit) ==\n";

$_SESSION = [];
$_SESSION['auth_user_id'] = $p4UserA;
$p4ctx->set($p4WsA);

// park one run at render review so the approval card has real content
$ctlRunId = $p4engine->startRun($p4ctx, $distWfA, $distAssetId, $p4UserA);
while ($p4worker->tick()) {
}
$p4auth = new Auth($p4db, new LoginThrottle($p4db), $p4ctx);
$deadHeartbeat = new WorkerHeartbeat(tempDir('hb') . '/none.heartbeat'); // never beaten → "not running"
$queueCtl = new QueueController($view, $p4jobs, $p4runs, $p4engine, $p4ctx, $p4auth, new Csrf(), new Flash(), $deadHeartbeat);
$wfCtl = new WorkflowController(
    $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine,
    new AssetRepository($p4db), $p4ctx, $p4auth, new Csrf(), new Flash(),
);
$logsCtl = new LogsController($view, $p4events, $p4ctx, new Csrf(), new Flash());

check('workflows ctl: index lists both defaults', (static function () use ($wfCtl): bool {
    $body = $wfCtl->index()->body();

    return str_contains($body, 'Full pipeline') && str_contains($body, 'Distribution');
})());
check('workflows ctl: show renders locked COMPLIANCE track', (static function () use ($wfCtl, $fullWfA): bool {
    $body = $wfCtl->show(['id' => (string) $fullWfA])->body();

    return str_contains($body, 'COMPLIANCE') && str_contains($body, 'node--locked')
        && str_contains($body, 'MUSIC NOTE / STYLE');
})());
check('workflows ctl: distribution show offers the asset select', (static function () use ($wfCtl, $distWfA): bool {
    $body = $wfCtl->show(['id' => (string) $distWfA])->body();

    return str_contains($body, 'asset_id') && str_contains($body, 'My real clip');
})());
check('workflows ctl: run trigger starts a run (303 → /queue)', (static function () use ($wfCtl, $distWfA, $distAssetId, $p4runs, $p4ctx): bool {
    $before = count($p4runs->listFor($p4ctx, 100));
    $_POST = ['asset_id' => (string) $distAssetId];
    $response = $wfCtl->run(['id' => (string) $distWfA]);
    $_POST = [];

    return $response->status() === 303 && ($response->headers()['Location'] ?? '') === '/queue'
        && count($p4runs->listFor($p4ctx, 100)) === $before + 1;
})());
check('workflows ctl: distribution run without asset flashes the key', (static function () use ($wfCtl, $distWfA): bool {
    $_POST = [];
    $response = $wfCtl->run(['id' => (string) $distWfA]);

    return $response->status() === 303
        && str_contains(($response->headers()['Location'] ?? ''), '/workflows/');
})());
check('queue ctl: renders awaiting approvals + jobs + runs', (static function () use ($queueCtl): bool {
    $body = $queueCtl->index()->body();

    return str_contains($body, 'Approvals') && str_contains($body, 'render_review')
        && str_contains($body, 'never faked');
})());
check('queue ctl: warns when the worker is not running', (static function () use ($queueCtl): bool {
    // $deadHeartbeat was never beaten → isAlive false → band shown
    return str_contains($queueCtl->index()->body(), 'background worker is not running');
})());
check('queue ctl: non-numeric job id → 404', $queueCtl->approve(['id' => 'abc'])->status() === 404
    && $queueCtl->retry(['id' => '../1'])->status() === 404);
check('runs ctl: run detail shows truthful approval + timeline', (static function () use ($wfCtl, $fullRunId): bool {
    $body = $wfCtl->showRun(['id' => (string) $fullRunId])->body();

    return str_contains($body, 'Approved by you') && str_contains($body, 'wf-a@example.com')
        && str_contains($body, 'append-only') && str_contains($body, 'compliance pass');
})());
check('logs ctl: feed renders with filter chips', (static function () use ($logsCtl): bool {
    $_GET = ['f' => 'warn'];
    $body = $logsCtl->index()->body();
    $_GET = [];

    return str_contains($body, 'event feed') && str_contains($body, 'is-active');
})());

// cross-tenant decisions through the CONTROLLER: 404, nothing leaked
$_SESSION = [];
$_SESSION['auth_user_id'] = $p4UserB;
$p4ctx->set($p4WsB);
$authB = new Auth($p4db, new LoginThrottle($p4db), $p4ctx);
$queueCtlB = new QueueController($view, $p4jobs, $p4runs, $p4engine, $p4ctx, $authB, new Csrf(), new Flash(), $deadHeartbeat);
$wfCtlB = new WorkflowController(
    $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine,
    new AssetRepository($p4db), $p4ctx, $authB, new Csrf(), new Flash(),
);

check('isolation ctl: B approving A\'s job → 404', $queueCtlB->approve(['id' => (string) $scriptJob['id']])->status() === 404);
check('isolation ctl: B retrying A\'s job → 404', $queueCtlB->retry(['id' => (string) $scriptJob['id']])->status() === 404);
check('isolation ctl: B viewing A\'s run → 404', $wfCtlB->showRun(['id' => (string) $fullRunId])->status() === 404);
check('isolation ctl: B viewing A\'s workflow → 404', $wfCtlB->show(['id' => (string) $fullWfA])->status() === 404);
$_SESSION = [];

/* ================== Phase 5: Script & Caption Engine ================== */

/** Queue-driven fake transport: each post() returns the next queued response or throws. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $calls = [];

    /** @param list<HttpResponse|HttpTransportException> $queue */
    public function __construct(private array $queue)
    {
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        return $this->serve('POST', $url, $headers, $body);
    }

    public function get(string $url, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->serve('GET', $url, $headers, '');
    }

    /** @param array<string, string> $headers */
    private function serve(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next instanceof HttpTransportException) {
            throw $next;
        }
        if ($next instanceof HttpResponse) {
            return $next;
        }

        throw new RuntimeException('FakeHttpClient queue exhausted');
    }
}

/** Provider stub that reports a real cost (proves cost-on-awaiting persistence). */
final class StubCostTextProvider implements TextProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function generate(string $kind, array $context, int $seed): TextResult
    {
        $data = match ($kind) {
            'idea' => ['idea' => 'stub idea', 'hook' => 'stub hook', 'format' => '15-45s vertical'],
            'script' => ['script' => 'hook line. body line. cta line.', 'word_count' => 6, 'estimated_duration_s' => 2.4],
            'caption' => ['captions' => ['instagram' => 'ig', 'tiktok' => 'tt', 'youtube' => 'yt']],
            'hashtag' => ['hashtags' => ['#a', '#b', '#c']],
            default => [],
        };

        return new TextResult($data, 'openai', 'stub.v1', 'gpt-4o-mini', $kind === 'script' ? 7 : 2);
    }
}

/** Build a realistic OpenAI 200 body: choices[0].message.content is a JSON string. */
function openAiBody(array $contentObject, int $inTokens = 100, int $outTokens = 50): HttpResponse
{
    $payload = [
        'choices' => [['message' => ['content' => json_encode($contentObject, JSON_THROW_ON_ERROR)]]],
        'usage' => ['prompt_tokens' => $inTokens, 'completion_tokens' => $outTokens],
    ];

    return new HttpResponse(200, json_encode($payload, JSON_THROW_ON_ERROR));
}

const OPENAI_CFG = [
    'api_key' => 'sk-test-SECRET-DO-NOT-LEAK',
    'org_id' => '',
    'model' => 'gpt-4o-mini',
    'timeout' => 5,
    'temperature' => 0.7,
    'endpoint' => 'https://api.openai.test/v1/chat/completions',
    'prices' => ['gpt-4o-mini' => ['in' => 15.0, 'out' => 60.0]],
];

echo "== Content: Sanitizer ==\n";

check('sanitizer: strips control chars', Sanitizer::clean("a\x00b\x07c") === 'abc');
check('sanitizer: folds whitespace', Sanitizer::clean("a\n\t  b   c") === 'a b c');
check('sanitizer: clamps length', mb_strlen(Sanitizer::clean(str_repeat('x', 500), 100)) === 100);
check('sanitizer: trims edges', Sanitizer::clean('  hi  ') === 'hi');

echo "== Content: VariationEngine ==\n";

$ve = new VariationEngine();
check('variation: deterministic for same seed', $ve->variant(12345) === $ve->variant(12345));
check('variation: topic substituted into hook', str_contains($ve->hook(3, 'desk stretches'), 'desk stretches'));
check('variation: independent dimensions present', (static function () use ($ve): bool {
    $v = $ve->variant(7);

    return isset($v['hook'], $v['pacing'], $v['opener'], $v['cta']) && $v['hook'] !== '';
})());
check('variation: seeds produce diverse hooks (slop control)', (static function () use ($ve): bool {
    $hooks = [];
    for ($s = 0; $s < VariationEngine::hookPoolSize() * 2; $s++) {
        $hooks[] = $ve->hook($s);
    }
    $distinct = count(array_unique($hooks));

    return $distinct >= VariationEngine::hookPoolSize(); // every hook reachable
})());
check('variation: two runs lower similarity vs identical', (static function () use ($ve): bool {
    $a = $ve->hook(1, 'topic x');
    $b = $ve->hook(2, 'topic x'); // different hook scaffold, same topic
    $selfSim = VariationEngine::similarity($a, $a);
    $crossSim = VariationEngine::similarity($a, $b);

    return $a !== $b && $selfSim === 1.0 && $crossSim < 1.0;
})());
check('variation: similarity metric basics', VariationEngine::similarity('a b c', 'a b c') === 1.0
    && VariationEngine::similarity('a b', 'c d') === 0.0
    && VariationEngine::similarity('a b c d', 'a b x y') === (2 / 6));

echo "== Content: PromptLibrary ==\n";

$pl = new PromptLibrary();
check('prompts: versioned keys per kind', $pl->version('idea') === 'idea.v1'
    && $pl->version('script') === 'script.v1' && $pl->version('caption') === 'caption.v1'
    && $pl->version('hashtag') === 'hashtag.v1');
check('prompts: three platforms', PromptLibrary::platforms() === ['instagram', 'tiktok', 'youtube']);
check('prompts: messages carry system + user with sanitized topic', (static function () use ($pl, $ve): bool {
    $msgs = $pl->messages('script', ['topic' => "evil\x00topic", 'hook' => 'H', 'idea' => 'I'], $ve->variant(1));

    return count($msgs) === 2 && $msgs[0]['role'] === 'system' && $msgs[1]['role'] === 'user'
        && str_contains($msgs[1]['content'], 'eviltopic') && !str_contains($msgs[1]['content'], "\x00")
        && str_contains($msgs[1]['content'], 'STRICT JSON') === false // strictness lives in system msg
        && str_contains($msgs[0]['content'], 'STRICT JSON');
})());

echo "== Content: MockTextProvider ==\n";

$mtp = new MockTextProvider($ve, $pl);

check('mock provider: idea references trend + has hook/format/version', (static function () use ($mtp): bool {
    $r = $mtp->generate('idea', ['topic' => 'desk stretches'], 100);

    return str_contains($r->data['idea'], 'desk stretches') && ($r->data['hook'] ?? '') !== ''
        && $r->data['format'] === '15-45s vertical' && $r->provider === 'mock'
        && $r->costCents === null && $r->promptVersion === 'idea.v1';
})());
check('mock provider: script carries computed word_count + duration', (static function () use ($mtp): bool {
    $r = $mtp->generate('script', ['topic' => 'desk stretches', 'hook' => 'My hook', 'idea' => 'My idea'], 100);
    $words = count(preg_split('/\s+/', trim((string) $r->data['script']), -1, PREG_SPLIT_NO_EMPTY) ?: []);

    return str_contains($r->data['script'], 'My hook') && str_contains($r->data['script'], 'desk stretches')
        && $r->data['word_count'] === $words && $r->data['word_count'] > 0
        && $r->data['estimated_duration_s'] > 0;
})());
check('mock provider: captions are 3 DISTINCT platform variants', (static function () use ($mtp): bool {
    $r = $mtp->generate('caption', ['topic' => 'desk stretches'], 100);
    $caps = $r->data['captions'];

    return array_keys($caps) === ['instagram', 'tiktok', 'youtube']
        && count(array_unique($caps)) === 3;
})());
check('mock provider: hashtags >= 3, deduped, #-prefixed', (static function () use ($mtp): bool {
    $r = $mtp->generate('hashtag', ['topic' => 'budget travel hacks'], 100);
    $tags = $r->data['hashtags'];

    return count($tags) >= 3 && $tags === array_values(array_unique($tags))
        && array_filter($tags, static fn ($t) => !str_starts_with($t, '#')) === [];
})());
check('mock provider: deterministic for same seed', $mtp->generate('script', ['topic' => 't'], 42)->data
    === $mtp->generate('script', ['topic' => 't'], 42)->data);

echo "== Content: CostCalculator ==\n";

check('cost: tokens × price → cents + usd', (static function (): bool {
    $c = CostCalculator::compute('gpt-4o-mini', 2_000_000, 1_000_000, ['gpt-4o-mini' => ['in' => 15.0, 'out' => 60.0]]);

    return $c['cents'] === 90 && $c['usd'] === 0.9; // 2*15 + 1*60 = 90 cents
})());
check('cost: tiny call rounds cents to 0 but keeps usd', (static function (): bool {
    $c = CostCalculator::compute('gpt-4o-mini', 100, 50, ['gpt-4o-mini' => ['in' => 15.0, 'out' => 60.0]]);

    return $c['cents'] === 0 && $c['usd'] > 0;
})());
check('cost: unknown model → zero', CostCalculator::compute('mystery', 100, 100, [])['cents'] === 0);

echo "== Content: OpenAiTextProvider (fake transport, ZERO network) ==\n";

$makeOpenAi = static function (array $queue): array {
    $fake = new FakeHttpClient($queue);
    $provider = new OpenAiTextProvider($fake, new PromptLibrary(), new VariationEngine(), OPENAI_CFG);

    return [$provider, $fake];
};

check('openai: happy idea → shaped result + provider/model/version', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['idea' => 'X', 'hook' => 'Y', 'format' => 'z'], 1000, 500)]);
    $r = $p->generate('idea', ['topic' => 'desk stretches'], 5);

    return $r->data['idea'] === 'X' && $r->data['hook'] === 'Y' && $r->data['format'] === '15-45s vertical'
        && $r->provider === 'openai' && $r->model === 'gpt-4o-mini' && $r->promptVersion === 'idea.v1'
        && !isset($r->data['prompt_version']); // provider returns content only; executor stamps the version
})());
check('openai: sends Bearer key in header, never leaks it on error', (static function () use ($makeOpenAi): bool {
    [$p, $fake] = $makeOpenAi([new HttpResponse(429, '{"error":{"message":"slow down, key sk-test-SECRET-DO-NOT-LEAK"}}')]);
    $leaked = false;
    $threw = false;
    try {
        $p->generate('idea', ['topic' => 't'], 1);
    } catch (TextProviderException $e) {
        $threw = true;
        $leaked = str_contains($e->getMessage(), 'sk-test-SECRET-DO-NOT-LEAK');
    }
    $sentKey = str_contains($fake->calls[0]['headers']['Authorization'] ?? '', 'sk-test-SECRET-DO-NOT-LEAK');

    return $threw && $sentKey && !$leaked; // we DO send it, we NEVER echo it
})());
check('openai: 429 → rate-limit error', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([new HttpResponse(429, '{}')]);

    return throws(static fn () => $p->generate('idea', ['topic' => 't'], 1), TextProviderException::class);
})());
check('openai: non-2xx → failed (status only, no body)', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([new HttpResponse(500, '{"secret":"sk-test-SECRET-DO-NOT-LEAK"}')]);
    try {
        $p->generate('idea', ['topic' => 't'], 1);

        return false;
    } catch (TextProviderException $e) {
        return str_contains($e->getMessage(), '500') && !str_contains($e->getMessage(), 'sk-test-SECRET-DO-NOT-LEAK');
    }
})());
check('openai: transport throw → wrapped, sanitized', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([new HttpTransportException('Operation timed out')]);
    try {
        $p->generate('script', ['topic' => 't'], 1);

        return false;
    } catch (TextProviderException $e) {
        return str_contains($e->getMessage(), 'timed out');
    }
})());
check('openai: malformed top-level JSON → failed', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([new HttpResponse(200, 'not json at all')]);

    return throws(static fn () => $p->generate('idea', ['topic' => 't'], 1), TextProviderException::class);
})());
check('openai: message content not valid JSON → failed', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([new HttpResponse(200, json_encode([
        'choices' => [['message' => ['content' => 'plain text, not json']]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]))]);

    return throws(static fn () => $p->generate('idea', ['topic' => 't'], 1), TextProviderException::class);
})());
check('openai: caption missing a platform → failed', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['instagram' => 'a', 'tiktok' => 'b'])]); // youtube missing

    return throws(static fn () => $p->generate('caption', ['topic' => 't'], 1), TextProviderException::class);
})());
check('openai: caption happy → 3 platforms cleaned', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['instagram' => 'IG', 'tiktok' => 'TT', 'youtube' => 'YT'])]);
    $r = $p->generate('caption', ['topic' => 't'], 1);

    return array_keys($r->data['captions']) === ['instagram', 'tiktok', 'youtube']
        && $r->data['captions']['youtube'] === 'YT';
})());
check('openai: hashtags happy → #-normalized + capped', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['hashtags' => ['fitness', '#desk', ' stretch ']])]);
    $r = $p->generate('hashtag', ['topic' => 't'], 1);

    return $r->data['hashtags'] === ['#fitness', '#desk', '#stretch'];
})());
check('openai: empty hashtags → failed', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['hashtags' => []])]);

    return throws(static fn () => $p->generate('hashtag', ['topic' => 't'], 1), TextProviderException::class);
})());
check('openai: script recomputes word_count + carries cost from usage', (static function () use ($makeOpenAi): bool {
    [$p] = $makeOpenAi([openAiBody(['script' => 'one two three four five', 'word_count' => 999], 2_000_000, 1_000_000)]);
    $r = $p->generate('script', ['topic' => 't', 'hook' => 'h'], 1);

    return $r->data['word_count'] === 5 // recomputed, model's 999 ignored
        && $r->costCents === 90 && ($r->data['cost_usd'] ?? 0) === 0.9 && $r->provider === 'openai';
})());

echo "== Content: ContentExecutor ==\n";

$mockExec = new ContentExecutor(new MockTextProvider($ve, $pl));

check('executor: idea → ready with prompt_version merged', (static function () use ($mockExec): bool {
    $r = $mockExec->execute(
        ['type' => 'idea_generation', 'run_id' => 1, 'step' => 2],
        ['trend_fetch' => ['trend' => 'desk stretches']],
    );

    return $r->status === JobResult::STATUS_READY && str_contains($r->result['idea'], 'desk stretches')
        && $r->result['prompt_version'] === 'idea.v1' && $r->provider === 'mock';
})());
check('executor: script_draft → awaiting_approval', (static function () use ($mockExec): bool {
    $r = $mockExec->execute(
        ['type' => 'script_draft', 'run_id' => 1, 'step' => 3],
        ['trend_fetch' => ['trend' => 't'], 'idea_generation' => ['hook' => 'H', 'idea' => 'I']],
    );

    return $r->status === JobResult::STATUS_AWAITING_APPROVAL && isset($r->result['script']);
})());
check('executor: distribution falls back to asset title as topic', (static function () use ($mockExec): bool {
    $r = $mockExec->execute(
        ['type' => 'caption_generation', 'run_id' => 9, 'step' => 2],
        ['asset_fetch' => ['title' => 'My library clip']],
    );

    return $r->status === JobResult::STATUS_READY
        && str_contains(implode(' ', $r->result['captions']), 'My library clip');
})());
check('executor: unsupported type → failed', (static function () use ($mockExec): bool {
    $r = $mockExec->execute(['type' => 'tts', 'run_id' => 1, 'step' => 1], []);

    return $r->status === JobResult::STATUS_FAILED;
})());
check('executor: provider exception → failed(openai), message preserved', (static function () use ($ve, $pl): bool {
    $throwing = new OpenAiTextProvider(
        new FakeHttpClient([new HttpResponse(503, '{}')]),
        $pl,
        $ve,
        OPENAI_CFG,
    );
    $exec = new ContentExecutor($throwing);
    $r = $exec->execute(['type' => 'idea_generation', 'run_id' => 1, 'step' => 1], []);

    return $r->status === JobResult::STATUS_FAILED && $r->provider === 'openai'
        && str_contains((string) $r->errorMessage, '503');
})());
check('executor: failure provider tag comes from the provider (vendor-blind)', (static function (): bool {
    // a hypothetical second provider must NOT be mislabeled 'openai'
    $vendor = new class implements TextProvider {
        public function name(): string
        {
            return 'claude';
        }

        public function generate(string $kind, array $context, int $seed): TextResult
        {
            throw new TextProviderException('boom');
        }
    };
    $r = (new ContentExecutor($vendor))->execute(['type' => 'idea_generation', 'run_id' => 1, 'step' => 1], []);

    return $r->status === JobResult::STATUS_FAILED && $r->provider === 'claude';
})());
check('mock provider: unknown kind throws (no silent empty)', throws(
    static fn () => (new MockTextProvider($ve, $pl))->generate('mystery', ['topic' => 't'], 1),
    TextProviderException::class,
));

echo "== Content: provider selection via the real binding ==\n";

$buildProvider = static function (string $mock, string $key) use ($basePath): TextProvider {
    $_ENV['OPENAI_MOCK'] = $mock; // explicit $_ENV wins over .env (loadEnvFile only fills gaps)
    $_ENV['OPENAI_API_KEY'] = $key;
    $container = require $basePath . '/src/bootstrap.php';

    return $container->get(TextProvider::class);
};
$envBackupMock = $_ENV['OPENAI_MOCK'] ?? null;
$envBackupKey = $_ENV['OPENAI_API_KEY'] ?? null;

check('selection: mock=true → MockTextProvider', $buildProvider('true', '') instanceof MockTextProvider);
check('selection: mock=false + key → OpenAiTextProvider', $buildProvider('false', 'sk-live-xyz') instanceof OpenAiTextProvider);
check('selection: mock=false + empty key → MockTextProvider (fail-safe)', $buildProvider('false', '') instanceof MockTextProvider);

if ($envBackupMock === null) { unset($_ENV['OPENAI_MOCK']); } else { $_ENV['OPENAI_MOCK'] = $envBackupMock; }
if ($envBackupKey === null) { unset($_ENV['OPENAI_API_KEY']); } else { $_ENV['OPENAI_API_KEY'] = $envBackupKey; }

echo "== Content: end-to-end full run produces rich content ==\n";

$ceDb = migratedDb($basePath);
[$ceUser, $ceWs] = seedUser($ceDb, 'content@example.com', $argonHash, 'Content WS');
$_SESSION = [];
$ceCtx = new WorkspaceContext($ceDb);
$ceCtx->set($ceWs);
$ceNow = '2026-06-12T16:00:00Z';
[$ceEngine, $ceWorker] = makeRig($ceDb, new MockExecutor($ceDb), $ceNow);
(new WorkflowRepository($ceDb, $validator4))->ensureDefaults($ceCtx);
$ceFullWf = (new WorkflowRepository($ceDb, $validator4))->listFor($ceCtx)[0]; // full
$ceJobs = new JobRepository($ceDb);
$ceRunId = $ceEngine->startRun($ceCtx, $ceFullWf['id'], null, $ceUser);

while ($ceWorker->tick()) {
}
// stops at script approval → approve → continue to render review → approve → complete
$ceEngine->approve($ceCtx, $ceJobs->awaitingApproval($ceCtx)[0]['id'], $ceUser, 'content@example.com');
while ($ceWorker->tick()) {
}
$ceEngine->approve($ceCtx, $ceJobs->awaitingApproval($ceCtx)[0]['id'], $ceUser, 'content@example.com');
while ($ceWorker->tick()) {
}

check('e2e content: run completes with rich idea/script/caption/hashtag', (static function () use ($ceJobs, $ceCtx, $ceRunId): bool {
    $jobs = $ceJobs->jobsForRun($ceCtx, $ceRunId);
    $byType = [];
    foreach ($jobs as $j) {
        $byType[$j['type']] = $j['result'];
    }
    $captions = $byType['caption_generation']['captions'] ?? [];
    $hashtags = $byType['hashtag_generation']['hashtags'] ?? [];

    return ($byType['idea_generation']['hook'] ?? '') !== ''
        && ($byType['script_draft']['word_count'] ?? 0) > 0
        && count($captions) === 3 && count(array_unique($captions)) === 3
        && count($hashtags) >= 3
        && ($byType['idea_generation']['prompt_version'] ?? '') === 'idea.v1';
})());

echo "== Content: cost recorded on the paused script (honest spend) ==\n";

$costDb = migratedDb($basePath);
[$costUser, $costWs] = seedUser($costDb, 'cost@example.com', $argonHash, 'Cost WS');
$_SESSION = [];
$costCtx = new WorkspaceContext($costDb);
$costCtx->set($costWs);
$costNow = '2026-06-12T17:00:00Z';
[$costEngine, $costWorker] = makeRig($costDb, new MockExecutor($costDb), $costNow, new StubCostTextProvider());
(new WorkflowRepository($costDb, $validator4))->ensureDefaults($costCtx);
$costWf = (new WorkflowRepository($costDb, $validator4))->listFor($costCtx)[0];
$costJobs = new JobRepository($costDb);
$costRunId = $costEngine->startRun($costCtx, $costWf['id'], null, $costUser);
while ($costWorker->tick()) {
}

check('cost: paused script_draft carries real cost_cents + provider', (static function () use ($costJobs, $costCtx, $costRunId): bool {
    $awaiting = $costJobs->awaitingApproval($costCtx);
    $script = $awaiting[0] ?? null;

    return $script !== null && $script['type'] === 'script_draft'
        && $script['status'] === 'awaiting_approval'
        && $script['cost_cents'] === 7 && $script['provider'] === 'openai';
})());
check('cost: ready idea job carries cost too', (static function () use ($costJobs, $costCtx, $costRunId): bool {
    $jobs = $costJobs->jobsForRun($costCtx, $costRunId);
    $idea = array_values(array_filter($jobs, static fn ($j) => $j['type'] === 'idea_generation'))[0] ?? null;

    return $idea !== null && $idea['cost_cents'] === 2 && $idea['provider'] === 'openai';
})());

echo "== WorkerHeartbeat ==\n";

$hbPath = tempDir('hb') . '/worker.heartbeat';
$hb = new WorkerHeartbeat($hbPath);

check('heartbeat: missing file → not alive, null age', !$hb->isAlive('2026-06-12T18:00:00Z')
    && $hb->lastBeat() === null && $hb->ageSeconds('2026-06-12T18:00:00Z') === null);
$hb->beat('2026-06-12T18:00:00Z');
check('heartbeat: fresh beat → alive', $hb->isAlive('2026-06-12T18:00:10Z')
    && $hb->lastBeat() === '2026-06-12T18:00:00Z' && $hb->ageSeconds('2026-06-12T18:00:10Z') === 10);
check('heartbeat: beat older than threshold → not alive', !$hb->isAlive('2026-06-12T18:01:00Z')); // 60s > 30s
check('heartbeat: exactly at threshold → still alive', $hb->isAlive('2026-06-12T18:00:30Z'));
check('heartbeat: beat() creates the directory if missing', (static function (): bool {
    $nested = tempDir('hb-nested') . '/deep/dir/worker.heartbeat';
    $h = new WorkerHeartbeat($nested);
    $h->beat('2026-06-12T18:00:00Z');

    return is_file($nested) && $h->lastBeat() === '2026-06-12T18:00:00Z';
})());

/* ================== Phase 6: Trend Radar ================== */

/** Controllable TrendProvider: counts calls, can return canned results or throw. */
final class FakeTrendProvider implements TrendProvider
{
    public int $calls = 0;
    public ?TrendProviderException $throw = null;

    /** @param list<TrendResult> $result */
    public function __construct(public array $result = [], private string $providerName = 'youtube')
    {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function fetch(string $niche, string $region, int $limit): array
    {
        $this->calls++;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return array_slice($this->result, 0, $limit);
    }
}

function trendResult(string $topic, int $score, string $source = 'youtube'): TrendResult
{
    return new TrendResult($topic, $score, $source, 'general', 'US', FormatRecommender::recommend('general', $topic), ['k' => 'v']);
}

/** A realistic YouTube search.list 200 body. */
function youtubeBody(array $titles): HttpResponse
{
    $items = [];
    foreach ($titles as $i => $title) {
        $items[] = ['id' => ['videoId' => 'vid' . $i], 'snippet' => ['title' => $title, 'channelTitle' => 'Channel ' . $i]];
    }

    return new HttpResponse(200, json_encode(['items' => $items], JSON_THROW_ON_ERROR));
}

/** A realistic Google dailytrends body, including the ")]}'," anti-hijack prefix. */
function googleTrendsBody(array $queries): HttpResponse
{
    $searches = [];
    foreach ($queries as $q) {
        $searches[] = ['title' => ['query' => $q], 'formattedTraffic' => '200K+'];
    }
    $json = json_encode(['default' => ['trendingSearchesDays' => [['trendingSearches' => $searches]]]], JSON_THROW_ON_ERROR);

    return new HttpResponse(200, ")]}',\n" . $json);
}

echo "== Trend: FormatRecommender ==\n";

check('format: face niche → face', FormatRecommender::recommend('fitness', 'anything') === 'face');
check('format: face signal in topic → face', FormatRecommender::recommend('tech', 'how to setup wifi') === 'face'
    && FormatRecommender::recommend('tech', 'morning routine reset') === 'face');
check('format: otherwise faceless', FormatRecommender::recommend('tech', 'phone battery myths') === 'faceless');

echo "== Trend: MockTrendProvider ==\n";

$mockTrend = new MockTrendProvider();
$mockGeneral = $mockTrend->fetch('general', 'US', 5);
check('mock trend: name + count + best-first', $mockTrend->name() === 'mock'
    && count($mockGeneral) === 5
    && $mockGeneral[0]->score >= $mockGeneral[1]->score && $mockGeneral[1]->score >= $mockGeneral[2]->score);
check('mock trend: deterministic per (niche,region)', (static function () use ($mockTrend): bool {
    $a = $mockTrend->fetch('cooking', 'US', 4);
    $b = $mockTrend->fetch('cooking', 'US', 4);

    return $a[0]->topic === $b[0]->topic && $a[0]->score === $b[0]->score && $a[0]->niche === 'cooking';
})());
check('mock trend: every result carries a format + score range', (static function () use ($mockGeneral): bool {
    foreach ($mockGeneral as $t) {
        if (!in_array($t->format, ['face', 'faceless'], true) || $t->score < 55 || $t->score > 99) {
            return false;
        }
    }

    return true;
})());
check('mock trend: unknown niche falls back to general pool', $mockTrend->fetch('nope', 'US', 3) !== []);

echo "== Trend: TrendRepository (cache + tenant isolation) ==\n";

$trDb = migratedDb($basePath);
[$trUserA, $trWsA] = seedUser($trDb, 'trend-a@example.com', $argonHash, 'Trend WS A');
[$trUserB, $trWsB] = seedUser($trDb, 'trend-b@example.com', $argonHash, 'Trend WS B');
$trRepo = new TrendRepository($trDb);

$trRepo->replace($trWsA, 'general', 'US', [trendResult('alpha', 90), trendResult('beta', 80)], '2026-06-12T10:00:00Z');
check('trend repo: cached returns the batch best-first', (static function () use ($trRepo, $trWsA): bool {
    $rows = $trRepo->cached($trWsA, 'general', 'US');

    return count($rows) === 2 && $rows[0]['topic'] === 'alpha' && $rows[0]['rank'] === 0
        && $rows[0]['raw'] === ['k' => 'v'] && $rows[0]['score'] === 90;
})());
$firstId = $trRepo->cached($trWsA, 'general', 'US')[0]['id'];
check('trend repo: find scoped to workspace', $trRepo->find($trWsA, $firstId) !== null
    && $trRepo->find($trWsB, $firstId) === null);
check('trend repo: another tenant sees nothing', $trRepo->cached($trWsB, 'general', 'US') === []);
$trRepo->replace($trWsA, 'general', 'US', [trendResult('gamma', 95)], '2026-06-12T16:00:00Z');
check('trend repo: replace swaps the batch (latest fetched_at only)', (static function () use ($trRepo, $trWsA): bool {
    $rows = $trRepo->cached($trWsA, 'general', 'US');

    return count($rows) === 1 && $rows[0]['topic'] === 'gamma';
})());

echo "== Trend: QuotaCounter ==\n";

$qDb = migratedDb($basePath);
[$qUser, $qWs] = seedUser($qDb, 'quota@example.com', $argonHash, 'Quota WS');
[$qUser2, $qWs2] = seedUser($qDb, 'quota2@example.com', $argonHash, 'Quota WS2');
$quotaC = new QuotaCounter($qDb);
$quotaC->record($qWs, 'youtube', 100, '2026-06-12T10:00:00Z');
$quotaC->record($qWs, 'youtube', 100, '2026-06-12T11:00:00Z');
$quotaC->record($qWs, 'youtube', 100, '2026-06-13T10:00:00Z');
check('quota: same provider+day accumulates', $quotaC->usageFor($qWs, 'youtube', '2026-06-12') === 200);
check('quota: different day is separate', $quotaC->usageFor($qWs, 'youtube', '2026-06-13') === 100);
check('quota: zero/negative units are ignored', (static function () use ($quotaC, $qWs): bool {
    $quotaC->record($qWs, 'google_trends', 0, '2026-06-12T12:00:00Z');

    return $quotaC->usageFor($qWs, 'google_trends', '2026-06-12') === 0;
})());
check('quota: scoped per workspace', $quotaC->usageFor($qWs2, 'youtube', '2026-06-12') === 0);
check('quota: totalsForDay highest-first', (static function () use ($quotaC, $qWs): bool {
    $quotaC->record($qWs, 'google_trends', 5, '2026-06-12T13:00:00Z');
    $totals = $quotaC->totalsForDay($qWs, '2026-06-12');

    return count($totals) === 2 && $totals[0]['provider'] === 'youtube' && $totals[0]['units'] === 200;
})());

echo "== Trend: TrendService (read-through cache, TTL, degradation, quota) ==\n";

$svcDb = migratedDb($basePath);
[$svcUser, $svcWs] = seedUser($svcDb, 'svc@example.com', $argonHash, 'Svc WS');
$svcRepo = new TrendRepository($svcDb);
$svcQuota = new QuotaCounter($svcDb);
$svcNow = '2026-06-12T10:00:00Z';
$svcClock = static function () use (&$svcNow): string { return $svcNow; };
$svcProvider = new FakeTrendProvider([trendResult('alpha', 90), trendResult('beta', 80)], 'youtube');
$svcCfg = ['cache_ttl_seconds' => 100, 'limit' => 8, 'quota_units' => ['youtube' => 100]];
$svc = new TrendService($svcProvider, $svcRepo, $svcQuota, $svcCfg, $svcClock);

$feed1 = $svc->feed($svcWs, 'general', 'US');
check('service: first feed fetches + caches (fresh)', $feed1->freshness === TrendFeed::FRESH
    && count($feed1->items) === 2 && $svcProvider->calls === 1 && $feed1->source === 'youtube');
check('service: real provider call recorded against quota', $svcQuota->usageFor($svcWs, 'youtube', '2026-06-12') === 100);

$svcNow = '2026-06-12T10:01:00Z'; // +60s, within TTL=100
$feed2 = $svc->feed($svcWs, 'general', 'US');
check('service: within TTL serves cache (no provider call)', $feed2->freshness === TrendFeed::FRESH
    && $svcProvider->calls === 1);

$svcNow = '2026-06-12T10:05:00Z'; // +5min, past TTL
$feed3 = $svc->feed($svcWs, 'general', 'US');
check('service: past TTL refreshes', $feed3->freshness === TrendFeed::FRESH && $svcProvider->calls === 2);

$feed4 = $svc->feed($svcWs, 'general', 'US', true); // force within TTL
check('service: force bypasses TTL', $svcProvider->calls === 3);

$svcProvider->throw = new TrendProviderException('YouTube quota exceeded or forbidden (HTTP 403)');
$svcNow = '2026-06-12T11:00:00Z'; // past TTL → tries provider → fails → stale cache
$feed5 = $svc->feed($svcWs, 'general', 'US');
check('service: provider failure with cache → stale + reason', $feed5->freshness === TrendFeed::STALE
    && count($feed5->items) === 2 && $feed5->error !== null && str_contains($feed5->error, '403'));
check('service: failed fetch is NOT charged to quota', $svcQuota->usageFor($svcWs, 'youtube', '2026-06-12') === 300); // 3 successes only

$coldDb = migratedDb($basePath);
[$coldUser, $coldWs] = seedUser($coldDb, 'cold@example.com', $argonHash, 'Cold WS');
$coldProvider = new FakeTrendProvider([], 'youtube');
$coldProvider->throw = new TrendProviderException('down');
$coldSvc = new TrendService($coldProvider, new TrendRepository($coldDb), new QuotaCounter($coldDb), $svcCfg, $svcClock);
$coldFeed = $coldSvc->feed($coldWs, 'general', 'US');
check('service: provider failure with cold cache → empty + reason', $coldFeed->isEmpty()
    && $coldFeed->freshness === TrendFeed::EMPTY && $coldFeed->error === 'down');

$mockSvcProvider = new FakeTrendProvider([trendResult('m', 70, 'mock')], 'mock');
$mockSvc = new TrendService($mockSvcProvider, $svcRepo, $svcQuota, $svcCfg, $svcClock);
$mockSvc->feed($svcWs, 'tech', 'US');
check('service: mock provider is never charged to quota', $svcQuota->usageFor($svcWs, 'mock', '2026-06-12') === 0);

echo "== Trend: YouTubeTrendsProvider (fake transport, ZERO network) ==\n";

$ytCfg = ['api_key' => 'yt-SECRET-KEY-DO-NOT-LEAK', 'endpoint' => 'https://yt.test/search', 'timeout' => 5];
$ytFake = new FakeHttpClient([youtubeBody(['How to cook eggs', 'Funny cats compilation'])]);
$yt = new YouTubeTrendsProvider($ytFake, $ytCfg);
$ytResults = $yt->fetch('cooking', 'US', 8);
check('youtube: parses items into best-first TrendResults', count($ytResults) === 2
    && $ytResults[0]->topic === 'How to cook eggs' && $ytResults[0]->source === 'youtube'
    && $ytResults[0]->score === 100 && $ytResults[1]->score === 93
    && $ytResults[0]->format === 'face' && $ytResults[0]->raw['channel'] === 'Channel 0');
check('youtube: request carries region + query, GET method', (static function () use ($ytFake): bool {
    $call = $ytFake->calls[0];

    return $call['method'] === 'GET' && str_contains($call['url'], 'regionCode=US')
        && str_contains($call['url'], 'q=cooking');
})());
check('youtube: sanitizes vendor text (control chars stripped)', (static function () use ($ytCfg): bool {
    $fake = new FakeHttpClient([youtubeBody(["Bad\x00title\nsecond line"])]);
    $r = (new YouTubeTrendsProvider($fake, $ytCfg))->fetch('general', 'US', 8);

    return !str_contains($r[0]->topic, "\n") && !str_contains($r[0]->topic, "\x00");
})());
check('youtube: 403 → exception, key never leaked', (static function () use ($ytCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(403, '{"error":"quota"}')]);
    try {
        (new YouTubeTrendsProvider($fake, $ytCfg))->fetch('general', 'US', 8);

        return false;
    } catch (TrendProviderException $e) {
        return str_contains($e->getMessage(), '403') && !str_contains($e->getMessage(), 'SECRET');
    }
})());
check('youtube: transport error → wrapped exception', (static function () use ($ytCfg): bool {
    $fake = new FakeHttpClient([new HttpTransportException('Operation timed out')]);

    return throws(static fn () => (new YouTubeTrendsProvider($fake, $ytCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());
check('youtube: malformed JSON → exception', (static function () use ($ytCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, 'not json')]);

    return throws(static fn () => (new YouTubeTrendsProvider($fake, $ytCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());
check('youtube: no items → exception (never an empty silent result)', (static function () use ($ytCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, '{}')]);

    return throws(static fn () => (new YouTubeTrendsProvider($fake, $ytCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());
// Redaction proof — uses the REAL CurlHttpClient against loopback port 1
// (instant "connection refused", NO external network): the message carries only
// curl's transport description. The host may appear, but the SECRET key and the
// path/query that carries it must NEVER reach the thrown message.
check('http: real transport error redacts the key + query string', (static function (): bool {
    $secret = 'KEY-SUPER-SECRET-XYZ';
    try {
        (new CurlHttpClient())->get('http://127.0.0.1:1/search?key=' . $secret, ['Accept' => 'application/json'], 2);

        return false;
    } catch (HttpTransportException $e) {
        $msg = $e->getMessage();

        return !str_contains($msg, $secret) && !str_contains($msg, 'key=') && !str_contains($msg, '/search');
    }
})());
check('youtube: real-transport failure stays key-safe end-to-end', (static function (): bool {
    $cfg = ['api_key' => 'yt-SECRET-REALPATH', 'endpoint' => 'http://127.0.0.1:1/search', 'timeout' => 2];
    try {
        (new YouTubeTrendsProvider(new CurlHttpClient(), $cfg))->fetch('general', 'US', 8);

        return false;
    } catch (TrendProviderException $e) {
        return !str_contains($e->getMessage(), 'SECRET');
    }
})());

echo "== Trend: GoogleTrendsProvider (fake transport, ZERO network) ==\n";

$gtCfg = ['endpoint' => 'https://trends.test/dailytrends', 'timeout' => 5];
$gtFake = new FakeHttpClient([googleTrendsBody(['election results', 'new movie release'])]);
$gtResults = (new GoogleTrendsProvider($gtFake, $gtCfg))->fetch('general', 'US', 8);
check('google: strips )]}\' prefix + parses trending searches', count($gtResults) === 2
    && $gtResults[0]->topic === 'election results' && $gtResults[0]->source === 'google_trends'
    && $gtResults[0]->score === 100 && $gtResults[1]->score === 95
    && $gtResults[0]->raw['traffic'] === '200K+');
check('google: non-2xx → exception', (static function () use ($gtCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(429, 'rate limited')]);

    return throws(static fn () => (new GoogleTrendsProvider($fake, $gtCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());
check('google: no brace / garbage body → exception', (static function () use ($gtCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, 'garbage-no-json')]);

    return throws(static fn () => (new GoogleTrendsProvider($fake, $gtCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());
check('google: no trending days → exception', (static function () use ($gtCfg): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, ")]}',\n{}")]);

    return throws(static fn () => (new GoogleTrendsProvider($fake, $gtCfg))->fetch('general', 'US', 8), TrendProviderException::class);
})());

echo "== Trend: provider selection via the real binding ==\n";

$buildTrendProvider = static function (string $mock, string $provider, string $key) use ($basePath): TrendProvider {
    $_ENV['TREND_MOCK'] = $mock;
    $_ENV['TREND_PROVIDER'] = $provider;
    $_ENV['YOUTUBE_API_KEY'] = $key;
    $container = require $basePath . '/src/bootstrap.php';

    return $container->get(TrendProvider::class);
};
$tEnvBackup = [$_ENV['TREND_MOCK'] ?? null, $_ENV['TREND_PROVIDER'] ?? null, $_ENV['YOUTUBE_API_KEY'] ?? null];

check('trend selection: mock=true → MockTrendProvider', $buildTrendProvider('true', 'youtube', 'k') instanceof MockTrendProvider);
check('trend selection: mock=false + youtube + key → YouTubeTrendsProvider', $buildTrendProvider('false', 'youtube', 'yt-key') instanceof YouTubeTrendsProvider);
check('trend selection: mock=false + youtube + no key → mock (fail-safe)', $buildTrendProvider('false', 'youtube', '') instanceof MockTrendProvider);
check('trend selection: mock=false + google_trends → GoogleTrendsProvider', $buildTrendProvider('false', 'google_trends', '') instanceof GoogleTrendsProvider);

foreach (['TREND_MOCK', 'TREND_PROVIDER', 'YOUTUBE_API_KEY'] as $i => $k) {
    if ($tEnvBackup[$i] === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $tEnvBackup[$i]; }
}

echo "== Trend: TrendExecutor (niche + create-from-trend paths) ==\n";

$teDb = migratedDb($basePath);
[$teUser, $teWs] = seedUser($teDb, 'te@example.com', $argonHash, 'TE WS');
$teRepo = new TrendRepository($teDb);
$teCfg = new TrendConfigRepository($teDb, ['niche' => 'general', 'region' => 'US']);
$teClock = static fn (): string => '2026-06-12T10:00:00Z';
$teSvc = new TrendService(new MockTrendProvider(), $teRepo, new QuotaCounter($teDb), ['cache_ttl_seconds' => 100, 'limit' => 8, 'quota_units' => []], $teClock);
$teExec = new TrendExecutor($teSvc, $teRepo, $teCfg);

$teNicheResult = $teExec->execute(['workspace_id' => $teWs, 'entity_type' => 'trend', 'entity_id' => null, 'run_id' => 1, 'step' => 1], []);
check('executor: niche path emits top trend with a topic + format', $teNicheResult->status === JobResult::STATUS_READY
    && ($teNicheResult->result['trend'] ?? '') !== ''
    && in_array($teNicheResult->result['format'] ?? '', ['face', 'faceless'], true)
    && ($teNicheResult->result['origin'] ?? '') === 'niche');

$teRepo->replace($teWs, 'general', 'US', [trendResult('Pinned topic', 88, 'mock')], '2026-06-12T10:00:00Z');
$pinnedId = $teRepo->cached($teWs, 'general', 'US')[0]['id'];
$teSelected = $teExec->execute(['workspace_id' => $teWs, 'entity_type' => 'trend', 'entity_id' => $pinnedId, 'run_id' => 2, 'step' => 1], []);
check('executor: create-from-trend emits the SELECTED trend', $teSelected->status === JobResult::STATUS_READY
    && $teSelected->result['trend'] === 'Pinned topic' && ($teSelected->result['origin'] ?? '') === 'selected');
$teGone = $teExec->execute(['workspace_id' => $teWs, 'entity_type' => 'trend', 'entity_id' => 999999, 'run_id' => 3, 'step' => 1], []);
check('executor: selected trend missing → honest failure', $teGone->status === JobResult::STATUS_FAILED
    && str_contains((string) $teGone->errorMessage, 'no longer available'));

echo "== Trend: Engine create-from-trend (entity wiring + isolation) ==\n";

$cfDb = migratedDb($basePath);
[$cfUser, $cfWs] = seedUser($cfDb, 'cf@example.com', $argonHash, 'CF WS');
[$cfUser2, $cfWs2] = seedUser($cfDb, 'cf2@example.com', $argonHash, 'CF WS2');
$_SESSION = [];
$cfCtx = new WorkspaceContext($cfDb);
$cfCtx->set($cfWs);
$cfNow = '2026-06-12T10:00:00Z';
[$cfEngine, $cfWorker] = makeRig($cfDb, new MockExecutor($cfDb), $cfNow);
(new WorkflowRepository($cfDb, $validator4))->ensureDefaults($cfCtx);
$cfFullWf = null;
foreach ((new WorkflowRepository($cfDb, $validator4))->listFor($cfCtx) as $wf) {
    if ($wf['template'] === 'full') { $cfFullWf = $wf; break; }
}
$cfRepo = new TrendRepository($cfDb);
$cfRepo->replace($cfWs, 'general', 'US', [trendResult('Engine pinned topic', 91, 'mock')], $cfNow);
$cfTrendId = $cfRepo->cached($cfWs, 'general', 'US')[0]['id'];

$cfRunId = $cfEngine->startRun($cfCtx, $cfFullWf['id'], null, $cfUser, $cfTrendId);
check('engine cf: run pinned to the trend (entity_id set)', (static function () use ($cfDb, $cfRunId, $cfWs, $cfTrendId): bool {
    $run = $cfDb->one('SELECT entity_type, entity_id FROM runs WHERE id = ? AND workspace_id = ?', [$cfRunId, $cfWs]);

    return $run !== null && $run['entity_type'] === 'trend' && (int) $run['entity_id'] === $cfTrendId;
})());
check('engine cf: unknown trend id → WorkflowException(trend.not_found)', (static function () use ($cfEngine, $cfCtx, $cfFullWf, $cfUser): bool {
    try {
        $cfEngine->startRun($cfCtx, $cfFullWf['id'], null, $cfUser, 987654);

        return false;
    } catch (WorkflowException $e) {
        return $e->messageKey === 'trend.not_found';
    }
})());
check('engine cf: another tenant\'s trend id is rejected', (static function () use ($cfEngine, $cfCtx, $cfFullWf, $cfUser, $cfDb, $cfWs2, $cfNow): bool {
    // seed a trend owned by WS2, try to use it from WS1's context
    (new TrendRepository($cfDb))->replace($cfWs2, 'general', 'US', [trendResult('foreign', 50, 'mock')], $cfNow);
    $foreignId = (new TrendRepository($cfDb))->cached($cfWs2, 'general', 'US')[0]['id'];
    try {
        $cfEngine->startRun($cfCtx, $cfFullWf['id'], null, $cfUser, $foreignId);

        return false;
    } catch (WorkflowException $e) {
        return $e->messageKey === 'trend.not_found';
    }
})());

$cfJobs = new JobRepository($cfDb);
while ($cfWorker->tick()) {
}
check('engine cf: worker emits the pinned topic into trend_fetch', (static function () use ($cfJobs, $cfCtx, $cfRunId): bool {
    $jobs = $cfJobs->jobsForRun($cfCtx, $cfRunId);
    $trend = $jobs[0]['result']['trend'] ?? '';
    $idea = $jobs[1]['result']['idea'] ?? '';

    return $trend === 'Engine pinned topic' && str_contains($idea, 'Engine pinned topic');
})());

echo "\n" . $pass . ' PASS, ' . count($failures) . " FAIL\n";

if ($failures !== []) {
    echo "Failed:\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

exit(0);
