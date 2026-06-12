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

check('migrate: fresh DB applies all in order', $applied === ['0001_init.sql', '0002_assets.sql']);
check('migrate: second run applies nothing', $migrator->migrate() === []);
check('migrate: tracking rows recorded', count($mdb->all('SELECT filename FROM migrations')) === 2);
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

echo "\n" . $pass . ' PASS, ' . count($failures) . " FAIL\n";

if ($failures !== []) {
    echo "Failed:\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

exit(0);
