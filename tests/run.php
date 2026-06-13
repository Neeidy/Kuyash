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
use Kuyash\Compliance\AutoApprovalGate;
use Kuyash\Compliance\ComplianceCheckExecutor;
use Kuyash\Compliance\CompliancePolicy;
use Kuyash\Compliance\DigestReport;
use Kuyash\Compliance\GateDecision;
use Kuyash\Compliance\PublishGateExecutor;
use Kuyash\Compliance\QualityScore;
use Kuyash\Compliance\SlopScorer;
use Kuyash\Usage\BudgetExceededException;
use Kuyash\Usage\CostEstimator;
use Kuyash\Usage\CreditLedger;
use Kuyash\Usage\PreflightGate;
use Kuyash\Usage\UsageRecorder;
use Kuyash\Usage\UsageRepository;
use Kuyash\Controllers\DigestController;
use Kuyash\Controllers\SettingsController;
use Kuyash\Controllers\UsageController;
use Kuyash\Database\Migrator;
use Kuyash\Core\Format;
use Kuyash\Workspace\WorkspaceSettings;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Library\AssetValidator;
use Kuyash\Library\InvalidUploadException;
use Kuyash\Library\MediaProbe;
use Kuyash\Library\UploadedFile;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\MockPublishProvider;
use Kuyash\Publish\PostRepository;
use Kuyash\Publish\PublishCounter;
use Kuyash\Publish\PublishOutcome;
use Kuyash\Publish\PublishRequest;
use Kuyash\Publish\Reconciler;
use Kuyash\Publish\WebhookController;
use Kuyash\Publish\WebhookInbox;
use Kuyash\Publish\ZernioPublishExecutor;
use Kuyash\Publish\ZernioPublishProvider;
use Kuyash\Controllers\AccountsController;
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

/**
 * A 'local'-only StorageManager over the given asset root (cache/render default
 * to siblings). put()/getToLocal() are in-place no-ops when objects already live
 * under these roots — which is the case for AssetStorage/MediaPaths fixtures, so
 * the seam adds zero behavior to the existing tests.
 */
function localStorageManager(string $assetRoot, ?string $cacheRoot = null, ?string $renderRoot = null): \Kuyash\Storage\StorageManager
{
    // FQN: this helper is defined above the file's `use` block, so the aliases
    // are not yet in scope for its body.
    return new \Kuyash\Storage\StorageManager(['local' => new \Kuyash\Storage\LocalStorageProvider([
        'asset' => $assetRoot,
        'cache' => $cacheRoot ?? $assetRoot . '/_cache',
        'render' => $renderRoot ?? $assetRoot . '/_render',
    ])], 'local');
}

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

check('migrate: fresh DB applies all in order', $applied === ['0001_init.sql', '0002_assets.sql', '0003_workflow_engine.sql', '0004_trends.sql', '0005_media.sql', '0006_storage_location.sql', '0007_compliance.sql', '0008_accounts.sql', '0009_usage_ledger.sql', '0010_ai_video.sql', '0011_rate_limits.sql']);
check('migrate: second run applies nothing', $migrator->migrate() === []);
check('migrate: tracking rows recorded', count($mdb->all('SELECT filename FROM migrations')) === 11);
check('migrate: schema tables exist', count($mdb->all(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('users','workspaces','workspace_users','login_attempts')"
)) === 4);

echo "== SqliteBackup: WAL-aware snapshot round-trip (Phase 13) ==\n";

$bkDir = tempDir('backup');
$bkSrcPath = $bkDir . '/live.sqlite';
$bkSrc = new Database($bkSrcPath);
(new Migrator($bkSrc, $basePath . '/database/migrations'))->migrate();
[$bkU, $bkWs] = seedUser($bkSrc, 'bk@example.com', 'x', 'BK WS');
// write a row AFTER connect so the latest committed state lives in the -wal sidecar
// (a raw cp would miss it; VACUUM INTO must capture it)
$bkSrc->run("INSERT INTO events (workspace_id, level, kind, key, params_json, created_at) VALUES (?, 'info', 'transition', 'backup.probe', '{}', ?)", [$bkWs, gmdate(NOW_ISO)]);
$bkTarget = $bkDir . '/snap.sqlite';
$bkIntegrity = (new \Kuyash\Core\SqliteBackup($bkSrc))->snapshotTo($bkTarget);
check('backup: VACUUM INTO snapshot passes integrity_check', $bkIntegrity === 'ok' && is_file($bkTarget));
check('backup: snapshot has row-count parity with the live source (WAL content captured)', (static function () use ($bkSrc, $bkTarget): bool {
    $snap = new Database($bkTarget);
    foreach (['users', 'workspaces', 'workspace_users', 'events', 'migrations'] as $t) {
        if ((int) $bkSrc->one("SELECT COUNT(*) AS n FROM {$t}")['n'] !== (int) $snap->one("SELECT COUNT(*) AS n FROM {$t}")['n']) {
            return false;
        }
    }

    return (string) $snap->one("SELECT key FROM events WHERE key = 'backup.probe'")['key'] === 'backup.probe';
})());
check('backup: refuses to overwrite an existing target (no clobber)', throws(static fn () => (new \Kuyash\Core\SqliteBackup($bkSrc))->snapshotTo($bkTarget), RuntimeException::class));
foreach (['', '-wal', '-shm'] as $sfx) {
    @unlink($bkSrcPath . $sfx);
}
@unlink($bkTarget);
@rmdir($bkDir);

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
$cdisks = localStorageManager($cstorageRoot);
$cingest = new AssetIngest($validator, $probe, $cstorage, $crepo, $cdisks, 10, 32);
$libCtl = new LibraryController(
    $view, $crepo, $cingest, $cstorage, $cctx, new Csrf(), new Flash(),
    new Kuyash\Workspace\WorkspaceSettings($cdb), $libConfig,
);
$mediaCtl = new MediaController($crepo, $cstorage, $cdisks, $cctx, 300);

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
use Kuyash\Controllers\RenderController;
use Kuyash\Controllers\WorkflowController;
use Kuyash\Core\Messages;
use Kuyash\Http\BlobClient;
use Kuyash\Http\BlobResult;
use Kuyash\Http\CurlHttpClient;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpResponse;
use Kuyash\Http\HttpTransportException;
use Kuyash\Media\AssemblyEngine;
use Kuyash\Media\AssemblyExecutor;
use Kuyash\Media\AssetCache;
use Kuyash\Media\AssetFetchExecutor;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\FinalRenderExecutor;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\MockStockProvider;
use Kuyash\Media\MockTtsProvider;
use Kuyash\Media\OpenAiTtsProvider;
use Kuyash\Media\PexelsStockProvider;
use Kuyash\Media\RenderRepository;
use Kuyash\Media\StockProvider;
use Kuyash\Media\SubtitleBuilder;
use Kuyash\Media\TtsExecutor;
use Kuyash\Media\TtsProvider;
use Kuyash\Media\WavWriter;
use Kuyash\Storage\LocalStorageProvider;
use Kuyash\Storage\R2StorageProvider;
use Kuyash\Storage\SigV4Signer;
use Kuyash\Storage\StorageBackfill;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
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

/** Executor that RETURNS a non-retryable failure — exercises fast-fail dead-letter (Phase 13). */
final class PermanentlyFailsExecutor implements JobExecutor
{
    public function execute(array $job, array $prior): JobResult
    {
        return JobResult::failedPermanent('auth rejected (HTTP 401)', 'test');
    }
}

/** Executor that THROWS a PermanentFailure — the Worker must classify it as non-retryable. */
final class ThrowsPermanentExecutor implements JobExecutor
{
    public function execute(array $job, array $prior): JobResult
    {
        throw new \Kuyash\Core\PermanentFailureException('provider auth failed (HTTP 403)');
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

/* ---------------- Phase 7 media test infrastructure ---------------- */

// Real ffmpeg when present; a stub otherwise so the engine e2e stays portable.
// Produced files go under a per-run temp media root, cleaned at suite end.
$ffmpegBin = (string) (getenv('FFMPEG_BIN') ?: '/opt/homebrew/bin/ffmpeg');
$ffprobeBin = (string) (getenv('FFPROBE_BIN') ?: '/opt/homebrew/bin/ffprobe');
$TEST_MEDIA_ROOT = $basePath . '/storage/_test_media/' . bin2hex(random_bytes(4));
$mediaReady = (new Ffmpeg($ffmpegBin, $ffprobeBin, 60))->available();

/** Lightweight media stand-ins so the chain completes without ffmpeg (CI safety net). */
final class StubMediaExecutor implements JobExecutor
{
    public function __construct(private readonly Database $db)
    {
    }

    public function execute(array $job, array $prior): JobResult
    {
        return match ((string) $job['type']) {
            'tts' => JobResult::ready(['provider' => 'mock', 'voice' => 'alloy', 'audio_ref' => 'cache:' . $job['workspace_id'] . ':stub', 'duration_s' => 20.0, 'cached' => false], 'mock'),
            'asset_fetch' => $this->assetFetch($job),
            // Phase 12: Quick Create AI clip (no ffmpeg) — always AI-labeled, in-band duration
            'ai_video' => JobResult::ready(['source' => 'ai', 'provider' => 'mock', 'visual_kind' => 'video', 'visual_ref' => 'cache:' . $job['workspace_id'] . ':stub', 'draft_render_id' => null, 'duration_s' => 16.0, 'ai_label_required' => true, 'cached' => false], 'mock'),
            'assembly' => JobResult::ready(['draft' => true, 'render_id' => null, 'ai_label_required' => (bool) ($prior['asset_fetch']['ai_label_required'] ?? false)], 'mock'),
            'final_render' => JobResult::ready(['final' => true, 'render_id' => null, 'ai_label_required' => (bool) ($prior['ai_video']['ai_label_required'] ?? $prior['asset_fetch']['ai_label_required'] ?? false)], 'mock'),
            default => JobResult::failed('stub media: ' . $job['type'], 'mock'),
        };
    }

    private function assetFetch(array $job): JobResult
    {
        if (($job['entity_type'] ?? null) === 'library' && $job['entity_id'] !== null) {
            $a = $this->db->one("SELECT id, title, duration_s, type FROM assets WHERE id = ? AND workspace_id = ? AND status = 'ready'", [$job['entity_id'], $job['workspace_id']]);
            if ($a === null) {
                return JobResult::failed('library asset is no longer available', 'library');
            }

            return JobResult::ready(['source' => 'library', 'visual_kind' => 'video', 'asset_id' => (int) $a['id'], 'title' => (string) $a['title'], 'duration_s' => $a['duration_s'] === null ? null : (float) $a['duration_s'], 'ai_label_required' => $a['type'] === 'ai'], 'library');
        }

        return JobResult::ready(['source' => 'stock', 'visual_kind' => 'clip', 'visual_ref' => 'cache:' . $job['workspace_id'] . ':stub', 'ai_label_required' => false], 'mock');
    }
}

/** Build the real Media executors over the temp media root (used when ffmpeg is present). */
function makeMediaExecutors(Database $db): array
{
    global $ffmpegBin, $ffprobeBin, $TEST_MEDIA_ROOT;
    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/assets", 'cache' => "$TEST_MEDIA_ROOT/cache", 'render' => "$TEST_MEDIA_ROOT/renders", 'work' => "$TEST_MEDIA_ROOT/work"]);
    $ff = new Ffmpeg($ffmpegBin, $ffprobeBin, 120);
    $cache = new AssetCache($db, $paths);
    $renders = new RenderRepository($db);
    $disks = localStorageManager("$TEST_MEDIA_ROOT/assets", "$TEST_MEDIA_ROOT/cache", "$TEST_MEDIA_ROOT/renders");
    $engine = new AssemblyEngine($ff, $paths, $renders, $disks->default(), 24, ['burn_subtitles' => false], 'local');
    $draftGeo = ['width' => 540, 'height' => 960, 'preset' => 'ultrafast'];
    $finalGeo = ['width' => 1080, 'height' => 1920, 'preset' => 'ultrafast'];

    return [
        'tts' => new TtsExecutor(new MockTtsProvider(2.5), $cache, 'alloy'),
        'asset_fetch' => new AssetFetchExecutor($db, new MockStockProvider($ff, 1080, 1920, 24), $ff, $paths, $cache, $disks, new QuotaCounter($db), $finalGeo, 1, 24),
        // Phase 12: Quick Create image-to-video over the mock provider (real ffmpeg zoompan)
        'ai_video' => new \Kuyash\Media\AiVideoExecutor($db, new \Kuyash\Media\MockVideoGenProvider($ff, 1080, 1920, 24), $cache, $engine, $paths, $disks, $draftGeo, 16.0, 30.0),
        'assembly' => new AssemblyExecutor($engine, $draftGeo),
        'final_render' => new FinalRenderExecutor($engine, $finalGeo),
        'paths' => $paths, 'renders' => $renders, 'cache' => $cache, 'ffmpeg' => $ff, 'engine' => $engine,
    ];
}

function seedReadyVideo(Database $db, int $wsId, string $title = 'Distribute me'): int
{
    global $mediaReady, $ffmpegBin, $TEST_MEDIA_ROOT;
    $now = gmdate(NOW_ISO);
    $stored = bin2hex(random_bytes(16)) . '.mp4';
    $db->run(
        "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name,
            mime, size_bytes, sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at)
         VALUES (?, 'video', 'own', ?, 'clip.mp4', ?, 'video/mp4', 100, 'h', 21.5, 1080, 1920,
            '9:16', '[]', 'ready', ?, ?)",
        [$wsId, $title, $stored, $now, $now],
    );
    $id = $db->lastInsertId();

    // write a REAL 1s 9:16 mp4 so the real asset_fetch/final_render can read it
    if ($mediaReady) {
        $dir = "$TEST_MEDIA_ROOT/assets/$wsId";
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $p = proc_open([$ffmpegBin, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=green:s=1080x1920:d=1:r=24', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', "$dir/$stored"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
    }

    return $id;
}

/** Engine + Worker wired to a shared, controllable clock string. */
/**
 * Mirrors the production binding: the base executor serves every type, and
 * when it is a MockExecutor the 4 content types are overlaid with a real
 * ContentExecutor(MockTextProvider) — so MockExecutor-based e2e tests exercise
 * the actual Phase 5 content seam. Mechanic tests (Recording/AlwaysThrows) pass
 * non-MockExecutor bases and keep their single executor for all types.
 */
function makeRig(Database $db, JobExecutor $executor, string &$now, ?TextProvider $contentProvider = null, bool $autoCompliance = false): array
{
    $clock = static function () use (&$now): string {
        return $now;
    };
    $events = new EventLog($db);

    // Phase 9: when $autoCompliance, the Engine carries the AutoApprovalGate so
    // render_review consults Auto mode + guardrails (all sharing the test clock).
    $autoGate = $autoCompliance
        ? new \Kuyash\Compliance\AutoApprovalGate(
            $db,
            $events,
            new \Kuyash\Workspace\WorkspaceSettings($db),
            new \Kuyash\Compliance\QualityScore($db, $clock),
            new UsageRepository($db),
        )
        : null;
    $engine = new Engine($db, $events, new WorkflowValidator(), $clock, $autoGate);
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

        // Phase 9: real compliance scoring (replaces MockExecutor's removed
        // compliance_check arm) — mirrors production so MockExecutor-based e2e
        // exercises the actual kuyash-v1 policy.
        $registry->register('compliance_check', new \Kuyash\Compliance\ComplianceCheckExecutor(
            $db,
            new \Kuyash\Compliance\SlopScorer($db),
        ));

        // Phase 7: real Media executors (mock providers → REAL ffmpeg) when the
        // binary is present; stubs otherwise so the engine e2e stays portable.
        global $mediaReady;
        if ($mediaReady) {
            $media = makeMediaExecutors($db);
            foreach (['tts', 'asset_fetch', 'ai_video', 'assembly', 'final_render'] as $t) {
                $registry->register($t, $media[$t]);
            }
        } else {
            $stub = new StubMediaExecutor($db);
            foreach (['tts', 'asset_fetch', 'ai_video', 'assembly', 'final_render'] as $t) {
                $registry->register($t, $stub);
            }
        }

        // Phase 10: real per-account publish (mock-first provider) wrapped by the
        // Phase-9 guardrail gate — registered for EVERY MockExecutor rig so e2e
        // exercises the real publish path. Manual runs pass the gate straight
        // through; auto runs are kill-switch + per-account-cap gated.
        $pubAccounts = new \Kuyash\Publish\AccountRepository($db);
        $pubPosts = new \Kuyash\Publish\PostRepository($db);
        $pubExec = new \Kuyash\Publish\ZernioPublishExecutor(
            $db, new \Kuyash\Publish\MockPublishProvider(), $pubAccounts, $pubPosts, $events, $clock,
        );
        $registry->register('publish', new \Kuyash\Compliance\PublishGateExecutor(
            $db, $pubExec, new \Kuyash\Publish\PublishCounter($db), $pubAccounts, $clock,
        ));
    }

    $watchdog = new Watchdog($db, $events);
    $worker = new Worker($db, $engine, $registry, $events, $watchdog, 'test-worker:1:abcd', $clock);

    return [$engine, $worker, $events, $watchdog];
}

const FULL_TYPES = ['trend_fetch', 'idea_generation', 'script_draft', 'tts', 'asset_fetch',
    'assembly', 'caption_generation', 'hashtag_generation', 'music_note', 'preview',
    'compliance_check', 'render_review', 'final_render', 'publish'];
const DIST_TYPES = ['asset_fetch', 'caption_generation', 'hashtag_generation', 'music_note',
    'preview', 'compliance_check', 'render_review', 'final_render', 'publish'];

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

check('nodes: full chain is 14 jobs in canonical order', array_column(Nodes::expand(Nodes::FULL), 'type') === FULL_TYPES);
check('nodes: distribution chain is 9 jobs', array_column(Nodes::expand(Nodes::DISTRIBUTION), 'type') === DIST_TYPES);
check('nodes: PUBLISH expands to render_review + final_render + publish', Nodes::NODE_JOBS['PUBLISH'] === ['render_review', 'final_render', 'publish']);
check('nodes: steps are 1-based and contiguous', array_column(Nodes::expand(Nodes::FULL), 'step') === range(1, 14));
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
[$p4engine, $p4worker] = makeRig($p4db, new MockExecutor(), $now4);

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
        && str_contains((string) ($awaiting[0]['result']['summary'] ?? ''), 'kuyash-v1');
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
        && count($jobs) === 14
        && $statuses['publish'] === 'published'
        && $statuses['script_draft'] === 'ready'
        && $statuses['render_review'] === 'ready'
        && $statuses['final_render'] === 'ready';
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
check('full run: compliance event recorded with kuyash-v1 policy + ai label', (static function () use ($p4events, $p4ctx, $fullRunId): bool {
    $compliance = array_values(array_filter(
        $p4events->timelineForRun($p4ctx, $fullRunId),
        static fn (array $e): bool => $e['kind'] === 'compliance',
    ));

    // full runs carry TTS (synthetic voice) → pass_with_ai_label
    return count($compliance) === 1 && $compliance[0]['key'] === 'compliance.passed'
        && ($compliance[0]['params']['policy'] ?? '') === 'kuyash-v1'
        && ($compliance[0]['params']['status'] ?? '') === 'pass_with_ai_label';
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
[, $healWorker] = makeRig($failDb, new MockExecutor(), $failNow);
while ($healWorker->tick()) {
}
check('manual retry: healed run reaches render review', (static function () use ($failJobs, $failRuns, $failCtx, $failRunId): bool {
    $run = $failRuns->find($failCtx, $failRunId);
    $awaiting = $failJobs->awaitingApproval($failCtx);

    return $run['status'] === 'awaiting_approval' && count($awaiting) === 1
        && $awaiting[0]['type'] === 'render_review';
})());

echo "== Retry: non-retryable failure dead-letters on first attempt (401/403 fast-fail) ==\n";

// (a) an executor that RETURNS JobResult::failedPermanent → Engine dead-letters
// at once, NO backoff requeue, even though retries remained.
$nrDb = migratedDb($basePath);
[$nrUser, $nrWs] = seedUser($nrDb, 'nr@example.com', $argonHash, 'NR WS');
$_SESSION = [];
$nrCtx = new WorkspaceContext($nrDb);
$nrCtx->set($nrWs);
$nrNow = '2026-06-13T14:00:00Z';
[$nrEngine, $nrWorker, $nrEvents] = makeRig($nrDb, new PermanentlyFailsExecutor(), $nrNow);
(new WorkflowRepository($nrDb, $validator4))->ensureDefaults($nrCtx);
$nrWf = (new WorkflowRepository($nrDb, $validator4))->listFor($nrCtx)[1]; // distribution
$nrAsset = seedReadyVideo($nrDb, $nrWs);
$nrRunId = $nrEngine->startRun($nrCtx, $nrWf['id'], $nrAsset, $nrUser);
$nrJobs = new JobRepository($nrDb);
$nrRuns = new RunRepository($nrDb);

$nrWorker->tick(); // ONE attempt → immediate dead-letter (no backoff)

check('fast-fail: non-retryable job dead-letters on the FIRST attempt', (static function () use ($nrJobs, $nrCtx, $nrRunId): bool {
    $job = $nrJobs->jobsForRun($nrCtx, $nrRunId)[0];

    return $job['status'] === 'failed' && $job['retry_count'] === 1
        && str_contains((string) $job['error_message'], 'non-retryable:');
})());
check('fast-fail: the run failed immediately', $nrRuns->find($nrCtx, $nrRunId)['status'] === 'failed');
check('fast-fail: NO requeue/backoff event was emitted (budget not burned)', (static function () use ($nrEvents, $nrCtx, $nrRunId): bool {
    $keys = array_column($nrEvents->timelineForRun($nrCtx, $nrRunId), 'key');

    return !in_array('job.requeued', $keys, true) && in_array('job.failed', $keys, true);
})());
check('fast-fail: queue not claimable again (terminal)', $nrWorker->tick() === false);
check('fast-fail: a dead-lettered non-retryable job is still manually retriable', (static function () use ($nrEngine, $nrJobs, $nrCtx, $nrRunId, $nrUser): bool {
    $job = $nrJobs->jobsForRun($nrCtx, $nrRunId)[0];

    return $nrEngine->retryJob($nrCtx, $job['id'], $nrUser, 'nr@example.com') === Decision::Ok;
})());

// (b) an executor that THROWS a PermanentFailure → the Worker classifies it as
// non-retryable and the SAME fast-fail dead-letter applies.
$nrtDb = migratedDb($basePath);
[$nrtUser, $nrtWs] = seedUser($nrtDb, 'nrt@example.com', $argonHash, 'NRT WS');
$_SESSION = [];
$nrtCtx = new WorkspaceContext($nrtDb);
$nrtCtx->set($nrtWs);
$nrtNow = '2026-06-13T15:00:00Z';
[$nrtEngine, $nrtWorker] = makeRig($nrtDb, new ThrowsPermanentExecutor(), $nrtNow);
(new WorkflowRepository($nrtDb, $validator4))->ensureDefaults($nrtCtx);
$nrtWf = (new WorkflowRepository($nrtDb, $validator4))->listFor($nrtCtx)[1];
$nrtAsset = seedReadyVideo($nrtDb, $nrtWs);
$nrtRunId = $nrtEngine->startRun($nrtCtx, $nrtWf['id'], $nrtAsset, $nrtUser);
$nrtJobs = new JobRepository($nrtDb);
$nrtWorker->tick();
check('fast-fail: a thrown PermanentFailure also dead-letters on the first attempt', (static function () use ($nrtJobs, $nrtCtx, $nrtRunId): bool {
    $job = $nrtJobs->jobsForRun($nrtCtx, $nrtRunId)[0];

    return $job['status'] === 'failed' && $job['retry_count'] === 1
        && str_contains((string) $job['error_message'], 'non-retryable:')
        && str_contains((string) $job['error_message'], 'HTTP 403');
})());

// (c) a transient RuntimeException is STILL retryable (backoff path unchanged)
check('fast-fail: an ordinary throw is still retryable (requeued, not dead-lettered)', (static function () use ($basePath, $argonHash, $validator4): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'tr@example.com', $argonHash, 'TR WS');
    $_SESSION = [];
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = '2026-06-13T16:00:00Z';
    [$engine, $worker] = makeRig($db, new AlwaysThrowsExecutor(), $now);
    (new WorkflowRepository($db, $validator4))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, $validator4))->listFor($ctx)[1];
    $asset = seedReadyVideo($db, $ws);
    $runId = $engine->startRun($ctx, $wf['id'], $asset, $u);
    $worker->tick();
    $job = (new JobRepository($db))->jobsForRun($ctx, $runId)[0];

    return $job['status'] === 'queued' && $job['retry_count'] === 1; // requeued with backoff
})());

echo "== Watchdog: stale processing jobs ==\n";

$dogDb = migratedDb($basePath);
[$dogUser, $dogWs] = seedUser($dogDb, 'dog@example.com', $argonHash, 'Dog WS');
$_SESSION = [];
$dogCtx = new WorkspaceContext($dogDb);
$dogCtx->set($dogWs);
$dogNow = '2026-06-12T15:00:00Z';
[$dogEngine, $dogWorker, $dogEvents, $dogWatchdog] = makeRig($dogDb, new MockExecutor(), $dogNow);
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
    new AssetRepository($p4db), new \Kuyash\Publish\PostRepository($p4db), $p4ctx, $p4auth, new Csrf(), new Flash(),
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
    new AssetRepository($p4db), new \Kuyash\Publish\PostRepository($p4db), $p4ctx, $authB, new Csrf(), new Flash(),
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
check('openai: 401/403 → PermanentFailureException (non-retryable, status only, NOT the domain type)', (static function () use ($makeOpenAi): bool {
    [$p401] = $makeOpenAi([new HttpResponse(401, '{"error":{"message":"sk-test-SECRET-DO-NOT-LEAK"}}')]);
    $is401 = throws(static fn () => $p401->generate('idea', ['topic' => 't'], 1), \Kuyash\Core\PermanentFailureException::class);
    // a PermanentFailureException is NOT a TextProviderException, so it slips past
    // the executor's domain catch and reaches the Worker's non-retryable classifier
    $notDomain = !throws(static fn () => $makeOpenAi([new HttpResponse(403, '{}')])[0]->generate('idea', ['topic' => 't'], 1), TextProviderException::class);
    [$p403] = $makeOpenAi([new HttpResponse(403, '{"error":{"message":"sk-test-SECRET-DO-NOT-LEAK"}}')]);
    $leak = false;
    try {
        $p403->generate('idea', ['topic' => 't'], 1);
    } catch (\Kuyash\Core\PermanentFailureException $e) {
        $leak = str_contains($e->getMessage(), 'sk-test-SECRET-DO-NOT-LEAK');
    }

    return $is401 && $notDomain && !$leak;
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
[$ceEngine, $ceWorker] = makeRig($ceDb, new MockExecutor(), $ceNow);
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
[$costEngine, $costWorker] = makeRig($costDb, new MockExecutor(), $costNow, new StubCostTextProvider());
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
[$cfEngine, $cfWorker] = makeRig($cfDb, new MockExecutor(), $cfNow);
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

/* ================== Phase 7: Media Production ================== */

echo "== Media: WavWriter ==\n";

$wavDir = tempDir('wav');
$wavPath = $wavDir . '/a.wav';
$wavBytes = WavWriter::writeSilence($wavPath, 2.0);
check('wav: writes a non-trivial file', is_file($wavPath) && $wavBytes > 1000 && filesize($wavPath) === $wavBytes);
check('wav: RIFF/WAVE header', (static function () use ($wavPath): bool {
    $head = (string) file_get_contents($wavPath, false, null, 0, 12);

    return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE';
})());
check('wav: durationOf reads back the duration', abs((WavWriter::durationOf($wavPath) ?? 0) - 2.0) < 0.05);
check('wav: duration clamped to a sane floor', (static function () use ($wavDir): bool {
    WavWriter::writeSilence($wavDir . '/tiny.wav', 0.0);

    return (WavWriter::durationOf($wavDir . '/tiny.wav') ?? 0) >= 0.1;
})());

echo "== Media: SubtitleBuilder ==\n";

$srt = SubtitleBuilder::build('one two three four five six seven eight nine ten eleven twelve', 6.0);
check('srt: produces cues with valid timecodes', str_contains($srt, '00:00:00,000 --> ')
    && preg_match_all('/\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}/', $srt) >= 2);
check('srt: cues are index-numbered from 1', str_starts_with($srt, "1\n"));
check('srt: empty script → empty SRT', SubtitleBuilder::build('   ', 6.0) === '');
check('srt: last cue ends within the duration', (static function () use ($srt): bool {
    preg_match_all('/--> (\d{2}):(\d{2}):(\d{2}),(\d{3})/', $srt, $m, PREG_SET_ORDER);
    $last = end($m);
    $end = (int) $last[1] * 3600 + (int) $last[2] * 60 + (int) $last[3] + (int) $last[4] / 1000;

    return $end <= 6.05;
})());

echo "== Media: MediaPaths (refs, validation, traversal) ==\n";

$mp = new MediaPaths(['asset' => '/r/a', 'cache' => '/r/c', 'render' => '/r/v', 'work' => '/r/w']);
$mpName = $mp->newName('mp4');
check('paths: newName is {32hex}.ext', preg_match('/^[a-f0-9]{32}\.mp4$/', $mpName) === 1);
check('paths: ref/resolve round-trip', $mp->resolve($mp->ref('cache', 7, $mpName)) === '/r/c/7/' . $mpName);
check('paths: rejects unknown store', throws(static fn () => $mp->ref('evil', 1, $mpName), RuntimeException::class));
check('paths: rejects traversal / bad name', throws(static fn () => $mp->ref('cache', 1, '../../etc/passwd'), RuntimeException::class)
    && throws(static fn () => $mp->resolve('cache:1:..%2f..'), RuntimeException::class));
check('paths: rejects malformed ref ws', throws(static fn () => $mp->resolve('cache:abc:' . $mpName), RuntimeException::class));
check('paths: cleanupWorkDir refuses paths outside the work root', (static function () use ($mp): bool {
    $mp->cleanupWorkDir('/r/a/1'); // not under /r/w — must be a no-op, no throw

    return true;
})());

echo "== Media: AssetCache (content-addressed, hit/miss/cost) ==\n";

$acDb = migratedDb($basePath);
[$acUser, $acWs] = seedUser($acDb, 'ac@example.com', $argonHash, 'AC WS');
$acRoot = tempDir('acache');
$acPaths = new MediaPaths(['asset' => "$acRoot/a", 'cache' => "$acRoot/c", 'render' => "$acRoot/v", 'work' => "$acRoot/w"]);
$acCache = new AssetCache($acDb, $acPaths);
$acCalls = 0;
$producer = function (string $path) use (&$acCalls): array {
    $acCalls++;
    file_put_contents($path, 'DATA');

    return ['duration_s' => 3.0, 'cost_cents' => 12];
};
$e1 = $acCache->remember($acWs, 'tts', 'key-abc', 'wav', $producer);
$e2 = $acCache->remember($acWs, 'tts', 'key-abc', 'wav', $producer);
check('cache: first call is a miss that produces the file', !$e1->cached && $acCalls === 1 && is_file($acPaths->resolve($e1->ref)));
check('cache: second call is a hit (no producer run, same ref)', $e2->cached && $acCalls === 1 && $e2->ref === $e1->ref);
check('cache: meta survives the round-trip', ($e2->meta['cost_cents'] ?? 0) === 12);
check('cache: hits are counted', $acCache->hitCountFor($acWs) === 1);
check('cache: a different key is a separate miss', (static function () use ($acCache, $acWs, $producer, &$acCalls): bool {
    $before = $acCalls;
    $e = $acCache->remember($acWs, 'tts', 'key-xyz', 'wav', $producer);

    return !$e->cached && $acCalls === $before + 1;
})());

echo "== Media: TTS provider selection via the real binding ==\n";

$buildTts = static function (string $mock, string $key) use ($basePath): TtsProvider {
    $_ENV['TTS_MOCK'] = $mock;
    $_ENV['OPENAI_API_KEY'] = $key;

    return (require $basePath . '/src/bootstrap.php')->get(TtsProvider::class);
};
$ttsEnvBak = [$_ENV['TTS_MOCK'] ?? null, $_ENV['OPENAI_API_KEY'] ?? null];
check('tts selection: mock=true → MockTtsProvider', $buildTts('true', '') instanceof MockTtsProvider);
check('tts selection: mock=false + key → OpenAiTtsProvider', $buildTts('false', 'sk-live') instanceof OpenAiTtsProvider);
check('tts selection: mock=false + no key → mock (fail-safe)', $buildTts('false', '') instanceof MockTtsProvider);
if ($ttsEnvBak[0] === null) { unset($_ENV['TTS_MOCK']); } else { $_ENV['TTS_MOCK'] = $ttsEnvBak[0]; }
if ($ttsEnvBak[1] === null) { unset($_ENV['OPENAI_API_KEY']); } else { $_ENV['OPENAI_API_KEY'] = $ttsEnvBak[1]; }

echo "== Media: stock provider selection via the real binding ==\n";

$buildStock = static function (string $mock, string $key) use ($basePath): StockProvider {
    $_ENV['STOCK_MOCK'] = $mock;
    $_ENV['PEXELS_API_KEY'] = $key;

    return (require $basePath . '/src/bootstrap.php')->get(StockProvider::class);
};
$stkEnvBak = [$_ENV['STOCK_MOCK'] ?? null, $_ENV['PEXELS_API_KEY'] ?? null];
check('stock selection: mock=true → MockStockProvider', $buildStock('true', '') instanceof MockStockProvider);
check('stock selection: mock=false + key → PexelsStockProvider', $buildStock('false', 'pk-live') instanceof PexelsStockProvider);
check('stock selection: mock=false + no key → mock (fail-safe)', $buildStock('false', '') instanceof MockStockProvider);
if ($stkEnvBak[0] === null) { unset($_ENV['STOCK_MOCK']); } else { $_ENV['STOCK_MOCK'] = $stkEnvBak[0]; }
if ($stkEnvBak[1] === null) { unset($_ENV['PEXELS_API_KEY']); } else { $_ENV['PEXELS_API_KEY'] = $stkEnvBak[1]; }

echo "== Media: OpenAiTtsProvider (fake transport, ZERO network) ==\n";

$ttsCfg = ['api_key' => 'sk-TTS-SECRET', 'model' => 'gpt-4o-mini-tts', 'voice' => 'alloy', 'endpoint' => 'https://api.openai.test/v1/audio/speech', 'timeout' => 5, 'price_cents_per_million_chars' => 1500.0];
$ttsOutDir = tempDir('ttsout');
check('openai tts: writes the audio body + reports cost', (static function () use ($ttsCfg, $ttsOutDir): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, str_repeat('AUDIObytes', 50))]);
    $r = (new OpenAiTtsProvider($fake, $ttsCfg))->synthesize('hello world from openai', 'alloy', $ttsOutDir . '/o.wav');

    return is_file($ttsOutDir . '/o.wav') && $r->durationSeconds > 0 && $r->costCents !== null && $r->model === 'gpt-4o-mini-tts'
        && $fake->calls[0]['method'] === 'POST';
})());
check('openai tts: 429 → exception, key never leaked', (static function () use ($ttsCfg, $ttsOutDir): bool {
    $fake = new FakeHttpClient([new HttpResponse(429, 'slow down')]);
    try {
        (new OpenAiTtsProvider($fake, $ttsCfg))->synthesize('x', 'alloy', $ttsOutDir . '/n.wav');

        return false;
    } catch (Kuyash\Media\TtsProviderException $e) {
        return str_contains($e->getMessage(), '429') && !str_contains($e->getMessage(), 'SECRET');
    }
})());
check('openai tts: empty body → exception', (static function () use ($ttsCfg, $ttsOutDir): bool {
    $fake = new FakeHttpClient([new HttpResponse(200, '')]);

    return throws(static fn () => (new OpenAiTtsProvider($fake, $ttsCfg))->synthesize('x', 'alloy', $ttsOutDir . '/e.wav'), Kuyash\Media\TtsProviderException::class);
})());

echo "== Media: PexelsStockProvider (fake transport, ZERO network) ==\n";

/** In-memory BlobClient fake: a simulated bucket (url => bytes) + recorded calls. */
final class FakeBlobClient implements BlobClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    public array $calls = [];
    /** @var array<string, string> url => stored bytes (the simulated bucket) */
    public array $store = [];
    /** Body returned by download() when the url isn't in $store (e.g. a Pexels CDN). */
    public ?string $downloadBody = null;
    public ?int $uploadStatus = null;

    public function upload(string $url, array $headers, string $sourcePath, int $timeoutSeconds): int
    {
        $this->calls[] = ['method' => 'PUT', 'url' => $url, 'headers' => $headers];
        $this->store[$url] = (string) file_get_contents($sourcePath);

        return $this->uploadStatus ?? 200;
    }

    public function download(string $method, string $url, array $headers, string $destPath, int $maxBytes, int $timeoutSeconds): int
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        $bytes = $this->store[$url] ?? $this->downloadBody ?? '';
        if (strlen($bytes) > $maxBytes) {
            @unlink($destPath); // mirror CurlBlobClient: cap breach removes the partial
            throw new HttpTransportException("Blob download exceeded the {$maxBytes}-byte cap");
        }
        file_put_contents($destPath, $bytes);

        return strlen($bytes);
    }

    public function send(string $method, string $url, array $headers, int $timeoutSeconds): BlobResult
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        if ($method === 'HEAD') {
            return isset($this->store[$url])
                ? new BlobResult(200, ['content-length' => (string) strlen($this->store[$url])])
                : new BlobResult(404);
        }
        if ($method === 'DELETE') {
            $had = isset($this->store[$url]);
            unset($this->store[$url]);

            return new BlobResult($had ? 204 : 404);
        }

        return new BlobResult(200);
    }
}

$pexCfg = ['api_key' => 'pk-PEXELS-SECRET', 'endpoint' => 'https://api.pexels.test/videos/search', 'timeout' => 5];
$pexOutDir = tempDir('pexout');
$pexFfmpeg = new Ffmpeg($ffmpegBin, $ffprobeBin, 30);
function pexelsSearchBody(): HttpResponse
{
    return new HttpResponse(200, json_encode([
        'videos' => [[
            'id' => 99, 'video_files' => [
                ['quality' => 'sd', 'file_type' => 'video/mp4', 'width' => 1080, 'height' => 1920, 'link' => 'https://cdn.pexels.test/clip.mp4'],
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
}
check('pexels: search (http) → pick portrait → stream download (blob) → write', (static function () use ($pexCfg, $pexOutDir, $pexFfmpeg): bool {
    $http = new FakeHttpClient([pexelsSearchBody()]);
    $blob = new FakeBlobClient();
    $blob->downloadBody = 'MP4CLIPBYTES';
    $r = (new PexelsStockProvider($http, $blob, $pexFfmpeg, $pexCfg))->fetchClip('cooking', 5.0, $pexOutDir . '/c.mp4');

    return is_file($pexOutDir . '/c.mp4') && $r->height === 1920 && $r->costCents === null
        && count($http->calls) === 1 && count($blob->calls) === 1
        && $blob->calls[0]['method'] === 'GET' && $blob->calls[0]['url'] === 'https://cdn.pexels.test/clip.mp4';
})());
check('pexels: 429 → exception, key never leaked', (static function () use ($pexCfg, $pexOutDir, $pexFfmpeg): bool {
    $http = new FakeHttpClient([new HttpResponse(429, 'rate')]);
    try {
        (new PexelsStockProvider($http, new FakeBlobClient(), $pexFfmpeg, $pexCfg))->fetchClip('x', 5.0, $pexOutDir . '/n.mp4');

        return false;
    } catch (Kuyash\Media\StockProviderException $e) {
        return str_contains($e->getMessage(), '429') && !str_contains($e->getMessage(), 'SECRET');
    }
})());
check('pexels: no portrait clip → exception', (static function () use ($pexCfg, $pexOutDir, $pexFfmpeg): bool {
    $body = new HttpResponse(200, json_encode(['videos' => [['video_files' => [['file_type' => 'video/mp4', 'width' => 1920, 'height' => 1080, 'link' => 'https://x.test/l.mp4']]]]], JSON_THROW_ON_ERROR));
    $http = new FakeHttpClient([$body]);

    return throws(static fn () => (new PexelsStockProvider($http, new FakeBlobClient(), $pexFfmpeg, $pexCfg))->fetchClip('x', 5.0, $pexOutDir . '/p.mp4'), Kuyash\Media\StockProviderException::class);
})());
check('pexels: oversized clip aborts at the byte cap (HARD GATE cleared)', (static function () use ($pexOutDir, $pexFfmpeg): bool {
    $http = new FakeHttpClient([pexelsSearchBody()]);
    $blob = new FakeBlobClient();
    $blob->downloadBody = str_repeat('X', 64); // 64 bytes vs a 5-byte cap → abort
    $capCfg = ['api_key' => 'pk', 'endpoint' => 'https://api.pexels.test/videos/search', 'timeout' => 5, 'max_download_bytes' => 5];

    $threw = throws(static fn () => (new PexelsStockProvider($http, $blob, $pexFfmpeg, $capCfg))->fetchClip('x', 5.0, $pexOutDir . '/big.mp4'), Kuyash\Media\StockProviderException::class);

    return $threw && !is_file($pexOutDir . '/big.mp4'); // no partial left behind
})());

/* ================== Phase 8: Storage (R2 abstraction) ================== */

echo "== Storage: StorageKey validation ==\n";

$skName = bin2hex(random_bytes(16)) . '.mp4';
check('storage key: make → parse round-trip', (static function () use ($skName): bool {
    $k = StorageKey::make('render', 7, $skName);
    $p = StorageKey::parse($k);

    return $k === "render/7/{$skName}" && $p->store === 'render' && $p->workspaceId === 7 && $p->name === $skName;
})());
check('storage key: rejects unknown store', throws(static fn () => StorageKey::make('secrets', 1, $skName), Kuyash\Storage\StorageException::class));
check('storage key: rejects traversal / bad name', throws(static fn () => StorageKey::make('asset', 1, '../../etc/passwd'), Kuyash\Storage\StorageException::class));
check('storage key: rejects bad workspace in parse', throws(static fn () => StorageKey::parse('asset/0/' . $skName), Kuyash\Storage\StorageException::class));

echo "== Storage: SigV4Signer (known-answer, deterministic) ==\n";

// AWS docs worked example "GET iam.amazonaws.com ListUsers": the canonical
// request SHA256 is the AWS-published f536975d06c0309214f805bb90ccff089219ecd68b2577efef23edd43b7e1a59
// ("Create a string to sign", SigV4 docs); the key derivation is canonical, so
// this signature is the unique correct value (independently reproduced).
check('sigv4: ListUsers known-answer signature + signed headers', (static function (): bool {
    $s = new SigV4Signer('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam');
    $r = $s->signRequest('GET', '/', 'Action=ListUsers&Version=2010-05-08', [
        'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
        'host' => 'iam.amazonaws.com',
        'x-amz-date' => '20150830T123600Z',
    ], SigV4Signer::EMPTY_PAYLOAD_SHA256, '20150830T123600Z');

    return $r['signature'] === '33f5dad2191de0cb4b7ab912f876876c2c4f72e2991a458f9499233c7b992438'
        && $r['signed_headers'] === 'content-type;host;x-amz-date'
        && str_starts_with($r['authorization'], 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request');
})());
check('sigv4: empty-payload constant matches sha256("")', hash('sha256', '') === SigV4Signer::EMPTY_PAYLOAD_SHA256);
check('sigv4: presigned GET is well-formed (query-signed, response headers pinned)', (static function (): bool {
    $s = new SigV4Signer('AKID', 'secretkey', 'auto', 's3');
    $u = $s->presignGet('acct.r2.cloudflarestorage.com', '/bucket/render/3/' . bin2hex(random_bytes(16)) . '.mp4', 120, ['response-content-type' => 'video/mp4'], '20150830T123600Z');

    return str_starts_with($u, 'https://acct.r2.cloudflarestorage.com/bucket/render/3/')
        && str_contains($u, 'X-Amz-Algorithm=AWS4-HMAC-SHA256')
        && str_contains($u, 'X-Amz-Credential=AKID%2F20150830%2Fauto%2Fs3%2Faws4_request')
        && str_contains($u, 'X-Amz-Expires=120')
        && str_contains($u, 'X-Amz-SignedHeaders=host')
        && str_contains($u, 'response-content-type=video%2Fmp4')
        && preg_match('/&X-Amz-Signature=[0-9a-f]{64}$/', $u) === 1;
})());

echo "== Storage: LocalStorageProvider (round-trip) ==\n";

$lspRoot = tempDir('lsp');
$lsp = new LocalStorageProvider(['asset' => "$lspRoot/assets", 'cache' => "$lspRoot/cache", 'render' => "$lspRoot/renders"]);
$lspName = bin2hex(random_bytes(16)) . '.bin';
$lspKey = StorageKey::make('render', 4, $lspName);
$lspSrc = $lspRoot . '/src.bin';
file_put_contents($lspSrc, 'RENDERBYTES-2048');
check('local provider: put copies into place + exists + size', (static function () use ($lsp, $lspKey, $lspSrc): bool {
    $lsp->put($lspKey, $lspSrc, 'video/mp4');

    return $lsp->exists($lspKey) && $lsp->size($lspKey) === strlen('RENDERBYTES-2048');
})());
check('local provider: getToLocal streams the bytes back', (static function () use ($lsp, $lspKey, $lspRoot): bool {
    $dest = $lspRoot . '/out.bin';
    $lsp->getToLocal($lspKey, $dest);

    return is_file($dest) && file_get_contents($dest) === 'RENDERBYTES-2048';
})());
check('local provider: temporaryUrl is null (serve streams, never redirects)', $lsp->temporaryUrl($lspKey, 300) === null);
check('local provider: in-place put is a no-op (default deployments unchanged)', (static function () use ($lsp): bool {
    $name = bin2hex(random_bytes(16)) . '.bin';
    $key = StorageKey::make('asset', 9, $name);
    $inPlace = $lsp->path($key);
    @mkdir(dirname($inPlace), 0750, true);
    file_put_contents($inPlace, 'X');
    $lsp->put($key, $inPlace, 'application/octet-stream'); // src === dest

    return $lsp->exists($key) && file_get_contents($inPlace) === 'X';
})());
check('local provider: delete removes the object', (static function () use ($lsp, $lspKey): bool {
    return $lsp->delete($lspKey) && !$lsp->exists($lspKey);
})());

echo "== Storage: R2StorageProvider (FakeBlobClient, ZERO network) ==\n";

$makeR2 = static function (FakeBlobClient $blob): R2StorageProvider {
    return new R2StorageProvider($blob, new SigV4Signer('AKID-R2', 'r2-SECRET-DO-NOT-LEAK', 'auto', 's3'), 'acct.r2.cloudflarestorage.com', 'kuyash', 300, 1 << 29, 60);
};
$r2Name = bin2hex(random_bytes(16)) . '.mp4';
$r2Key = StorageKey::make('render', 5, $r2Name);
$r2Src = tempDir('r2src') . '/clip.mp4';
file_put_contents($r2Src, 'R2-VIDEO-BYTES');
check('r2 provider: put issues a SIGNED streamed PUT (Authorization + UNSIGNED-PAYLOAD)', (static function () use ($makeR2, $r2Key, $r2Src): bool {
    $blob = new FakeBlobClient();
    $makeR2($blob)->put($r2Key, $r2Src, 'video/mp4');
    $call = $blob->calls[0] ?? null;

    return $call !== null && $call['method'] === 'PUT'
        && str_starts_with((string) ($call['headers']['Authorization'] ?? ''), 'AWS4-HMAC-SHA256 Credential=AKID-R2/')
        && ($call['headers']['x-amz-content-sha256'] ?? '') === 'UNSIGNED-PAYLOAD'
        && str_contains((string) ($call['headers']['Authorization'] ?? ''), 'SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date');
})());
check('r2 provider: never leaks the secret in a signed header', (static function () use ($makeR2, $r2Key, $r2Src): bool {
    $blob = new FakeBlobClient();
    $makeR2($blob)->put($r2Key, $r2Src, 'video/mp4');

    return !str_contains(json_encode($blob->calls, JSON_THROW_ON_ERROR), 'r2-SECRET-DO-NOT-LEAK');
})());
check('r2 provider: exists/size via signed HEAD; getToLocal round-trips; delete signs DELETE', (static function () use ($makeR2, $r2Key, $r2Src): bool {
    $blob = new FakeBlobClient();
    $r2 = $makeR2($blob);
    $r2->put($r2Key, $r2Src, 'video/mp4');
    $dest = tempDir('r2dl') . '/got.mp4';
    $r2->getToLocal($r2Key, $dest);
    $ok = $r2->exists($r2Key) && $r2->size($r2Key) === strlen('R2-VIDEO-BYTES')
        && is_file($dest) && file_get_contents($dest) === 'R2-VIDEO-BYTES';
    $deleted = $r2->delete($r2Key) && !$r2->exists($r2Key);
    // a DELETE was signed and sent
    $sawDelete = false;
    foreach ($blob->calls as $c) {
        if ($c['method'] === 'DELETE' && str_starts_with((string) ($c['headers']['Authorization'] ?? ''), 'AWS4-HMAC-SHA256')) {
            $sawDelete = true;
        }
    }

    return $ok && $deleted && $sawDelete;
})());
check('r2 provider: getToLocal honors the byte cap (oversized → throws)', (static function () use ($r2Key): bool {
    $blob = new FakeBlobClient();
    $blob->store['https://acct.r2.cloudflarestorage.com/kuyash/' . $r2Key] = str_repeat('Y', 200);
    $tiny = new R2StorageProvider($blob, new SigV4Signer('AKID', 'sk', 'auto', 's3'), 'acct.r2.cloudflarestorage.com', 'kuyash', 300, 10, 60);

    return throws(static fn () => $tiny->getToLocal($r2Key, tempDir('r2cap') . '/x.mp4'), Kuyash\Storage\StorageException::class);
})());
check('r2 provider: temporaryUrl returns a presigned GET (non-null, signed)', (static function () use ($makeR2, $r2Key): bool {
    $u = $makeR2(new FakeBlobClient())->temporaryUrl($r2Key, 120, ['response-content-type' => 'video/mp4']);

    return is_string($u) && str_contains($u, '/kuyash/render/5/') && str_contains($u, 'X-Amz-Signature=') && str_contains($u, 'X-Amz-Expires=120');
})());

echo "== Storage: StorageManager (per-disk resolution) ==\n";

$smLocal = new LocalStorageProvider(['asset' => "$lspRoot/assets", 'cache' => "$lspRoot/cache", 'render' => "$lspRoot/renders"]);
$smR2 = $makeR2(new FakeBlobClient());
$sm = new StorageManager(['local' => $smLocal, 'r2' => $smR2], 'local');
check('manager: disk() resolves each provider', $sm->disk('local') === $smLocal && $sm->disk('r2') === $smR2);
check('manager: default() + defaultName()', $sm->default() === $smLocal && $sm->defaultName() === 'local');
check('manager: has() reports configured disks', $sm->has('r2') && !$sm->has('s3'));
check('manager: unknown disk throws', throws(static fn () => $sm->disk('s3'), Kuyash\Storage\StorageException::class));

echo "== Storage: serving redirect (tenant check BEFORE the URL is minted) ==\n";

$srvDb = migratedDb($basePath);
[$srvUser, $srvWs] = seedUser($srvDb, 'srv@example.com', $argonHash, 'Serve WS');
[$srvUser2, $srvWs2] = seedUser($srvDb, 'srv2@example.com', $argonHash, 'Serve WS2');
$srvRoot = tempDir('srv');
$srvStorage = new AssetStorage("$srvRoot/assets", static fn (string $f, string $t): bool => rename($f, $t));
$srvDisks = new StorageManager([
    'local' => new LocalStorageProvider(['asset' => "$srvRoot/assets", 'cache' => "$srvRoot/cache", 'render' => "$srvRoot/renders"]),
    'r2' => $makeR2(new FakeBlobClient()),
], 'local');
$srvCtx = new WorkspaceContext($srvDb);
$srvRepo = new AssetRepository($srvDb);

// an R2-located asset and a LOCAL asset, both owned by srvWs
$r2AssetName = bin2hex(random_bytes(16)) . '.mp4';
$srvDb->run(
    "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes, sha256, tags, storage_disk, status, created_at, updated_at)
     VALUES (?, 'video', 'own', 'r2 clip', 'c.mp4', ?, 'video/mp4', 10, 'h', '[]', 'r2', 'ready', ?, ?)",
    [$srvWs, $r2AssetName, gmdate(NOW_ISO), gmdate(NOW_ISO)],
);
$r2AssetId = $srvDb->lastInsertId();
$localAssetName = bin2hex(random_bytes(16)) . '.mp4';
@mkdir("$srvRoot/assets/$srvWs", 0750, true);
file_put_contents("$srvRoot/assets/$srvWs/$localAssetName", 'LOCALBYTES');
$srvDb->run(
    "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes, sha256, tags, storage_disk, status, created_at, updated_at)
     VALUES (?, 'video', 'own', 'local clip', 'l.mp4', ?, 'video/mp4', 10, 'h', '[]', 'local', 'ready', ?, ?)",
    [$srvWs, $localAssetName, gmdate(NOW_ISO), gmdate(NOW_ISO)],
);
$localAssetId = $srvDb->lastInsertId();

$srvMedia = new MediaController($srvRepo, $srvStorage, $srvDisks, $srvCtx, 300);
$srvCtx->set($srvWs);
$r2Resp = $srvMedia->serve(['id' => (string) $r2AssetId]);
check('serve: R2 asset → 302 to a presigned GET', $r2Resp->status() === 302 && str_contains((string) ($r2Resp->headers()['Location'] ?? ''), 'X-Amz-Signature='));
check('serve: R2 redirect is marked no-store', str_contains((string) ($r2Resp->headers()['Cache-Control'] ?? ''), 'no-store'));
check('serve: local asset → streamed (200), NOT redirected', $srvMedia->serve(['id' => (string) $localAssetId])->status() === 200);
$srvCtx->set($srvWs2);
check('serve: another tenant\'s R2 asset → 404, NO url minted', $srvMedia->serve(['id' => (string) $r2AssetId])->status() === 404);

// renders: seed a full run + an R2-located render with a poster
$srvCtx->set($srvWs);
$srvDb->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'wf', 'full', '[]', ?, ?)", [$srvWs, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$srvWf = $srvDb->lastInsertId();
$srvDb->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, entity_id, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', NULL, '[]', 'running', ?, ?, ?)", [$srvWs, $srvWf, $srvUser, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$srvRun = $srvDb->lastInsertId();
$rndName = bin2hex(random_bytes(16)) . '.mp4';
$rndPoster = bin2hex(random_bytes(16)) . '.jpg';
$srvDb->run(
    "INSERT INTO renders (workspace_id, run_id, job_id, kind, stored_name, poster_name, mime, width, height, duration_s, size_bytes, storage_disk, created_at)
     VALUES (?, ?, NULL, 'final', ?, ?, 'video/mp4', 1080, 1920, 12.0, 100, 'r2', ?)",
    [$srvWs, $srvRun, $rndName, $rndPoster, gmdate(NOW_ISO)],
);
$srvRndId = $srvDb->lastInsertId();
$srvRender = new RenderController(new RenderRepository($srvDb), new MediaPaths(['asset' => "$srvRoot/assets", 'cache' => "$srvRoot/cache", 'render' => "$srvRoot/renders", 'work' => "$srvRoot/work"]), $srvDisks, $srvCtx, 300);
check('serve: R2 render → 302 presigned', $srvRender->serve(['id' => (string) $srvRndId])->status() === 302);
check('serve: R2 render poster → 302 presigned', $srvRender->poster(['id' => (string) $srvRndId])->status() === 302);
$srvCtx->set($srvWs2);
check('serve: another tenant\'s R2 render → 404', $srvRender->serve(['id' => (string) $srvRndId])->status() === 404);

echo "== Storage: backfill (local → r2, resumable, verified, non-destructive) ==\n";

$bfDb = migratedDb($basePath);
[$bfUser, $bfWs] = seedUser($bfDb, 'bf@example.com', $argonHash, 'Backfill WS');
$bfRoot = tempDir('bf');
$bfBlob = new FakeBlobClient();
$bfDisks = new StorageManager([
    'local' => new LocalStorageProvider(['asset' => "$bfRoot/assets", 'cache' => "$bfRoot/cache", 'render' => "$bfRoot/renders"]),
    'r2' => $makeR2($bfBlob),
], 'local');

// one asset (with a real local file), one render (+ poster) with real files
$bfAssetName = bin2hex(random_bytes(16)) . '.mp4';
@mkdir("$bfRoot/assets/$bfWs", 0750, true);
file_put_contents("$bfRoot/assets/$bfWs/$bfAssetName", 'ASSET-FILE-BYTES');
$bfDb->run("INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes, sha256, tags, status, created_at, updated_at) VALUES (?, 'video','own','a','a.mp4',?, 'video/mp4', 16, 'h', '[]','ready',?,?)", [$bfWs, $bfAssetName, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$bfAssetId = $bfDb->lastInsertId();
$bfDb->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'wf','full','[]',?,?)", [$bfWs, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$bfWf = $bfDb->lastInsertId();
$bfDb->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, entity_id, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', NULL, '[]','running',?,?,?)", [$bfWs, $bfWf, $bfUser, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$bfRun = $bfDb->lastInsertId();
$bfRndName = bin2hex(random_bytes(16)) . '.mp4';
$bfRndPoster = bin2hex(random_bytes(16)) . '.jpg';
@mkdir("$bfRoot/renders/$bfWs", 0750, true);
file_put_contents("$bfRoot/renders/$bfWs/$bfRndName", 'RENDER-FILE-BYTES');
file_put_contents("$bfRoot/renders/$bfWs/$bfRndPoster", 'POSTER');
$bfDb->run("INSERT INTO renders (workspace_id, run_id, job_id, kind, stored_name, poster_name, mime, width, height, duration_s, size_bytes, storage_disk, created_at) VALUES (?, ?, NULL, 'final', ?, ?, 'video/mp4', 1080, 1920, 12.0, 17, 'local', ?)", [$bfWs, $bfRun, $bfRndName, $bfRndPoster, gmdate(NOW_ISO)]);
$bfRndId = $bfDb->lastInsertId();

$noop = static function (string $line): void {};
$backfill = new StorageBackfill($bfDb, $bfDisks);

$dry = $backfill->run('r2', 'all', true, 100, $noop);
check('backfill: --dry-run reports work but mutates nothing', $dry['would_copy'] === 2 && $dry['copied'] === 0
    && $bfDb->one('SELECT storage_disk FROM assets WHERE id = ?', [$bfAssetId])['storage_disk'] === 'local'
    && count($bfBlob->calls) === 0);

$run1 = $backfill->run('r2', 'all', false, 100, $noop);
check('backfill: copies + verifies + flips both rows to r2', $run1['copied'] === 2 && $run1['errors'] === 0
    && $bfDb->one('SELECT storage_disk FROM assets WHERE id = ?', [$bfAssetId])['storage_disk'] === 'r2'
    && $bfDb->one('SELECT storage_disk FROM renders WHERE id = ?', [$bfRndId])['storage_disk'] === 'r2');
check('backfill: render poster was also uploaded (PUT count = asset + render + poster)', (static function () use ($bfBlob): bool {
    $puts = array_filter($bfBlob->calls, static fn ($c): bool => $c['method'] === 'PUT');

    return count($puts) === 3;
})());
check('backfill: local copies are NEVER deleted (non-destructive)', is_file("$bfRoot/assets/$bfWs/$bfAssetName") && is_file("$bfRoot/renders/$bfWs/$bfRndName"));

$run2 = $backfill->run('r2', 'all', false, 100, $noop);
check('backfill: re-run is a no-op (idempotent / resumable)', $run2['copied'] === 0 && $run2['would_copy'] === 0);

// a row whose local file is gone → skipped, never flipped
$bfMissingName = bin2hex(random_bytes(16)) . '.mp4';
$bfDb->run("INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime, size_bytes, sha256, tags, status, created_at, updated_at) VALUES (?, 'video','own','gone','g.mp4',?, 'video/mp4', 1, 'h', '[]','ready',?,?)", [$bfWs, $bfMissingName, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$bfMissingId = $bfDb->lastInsertId();
$run3 = $backfill->run('r2', 'all', false, 100, $noop);
check('backfill: missing local file → skipped, marker stays local', $run3['missing'] === 1 && $run3['copied'] === 0
    && $bfDb->one('SELECT storage_disk FROM assets WHERE id = ?', [$bfMissingId])['storage_disk'] === 'local');

echo "== Media: WorkspaceSettings avatar (tenant-scoped) ==\n";

$avDb = migratedDb($basePath);
[$avUser, $avWs] = seedUser($avDb, 'av@example.com', $argonHash, 'Av WS');
[, $avWs2] = seedUser($avDb, 'av2@example.com', $argonHash, 'Av WS2');
$avSettings = new Kuyash\Workspace\WorkspaceSettings($avDb);
$avAsset = seedReadyVideo($avDb, $avWs, 'avatar clip');
check('avatar: starts unset', $avSettings->avatarAssetId($avWs) === null);
check('avatar: set to a ready asset works', $avSettings->setAvatar($avWs, $avAsset) && $avSettings->avatarAssetId($avWs) === $avAsset);
check('avatar: cannot set another tenant\'s asset', !$avSettings->setAvatar($avWs2, $avAsset));
check('avatar: clear resets it', (static function () use ($avSettings, $avWs): bool {
    $avSettings->clearAvatar($avWs);

    return $avSettings->avatarAssetId($avWs) === null;
})());

if (!$mediaReady) {
    echo "== Media: ffmpeg NOT available — skipping real-render tests ==\n";
}

if ($mediaReady) {
    echo "== Media: ffmpeg arg-safety (no shell) ==\n";
    $safeDir = tempDir('ffsafe');
    check('ffmpeg: shell metachars in a path are a literal filename, not a command', (static function () use ($ffmpegBin, $ffprobeBin, $safeDir): bool {
        $ff = new Ffmpeg($ffmpegBin, $ffprobeBin, 30);
        $evil = $safeDir . '/x.mp4; touch ' . $safeDir . '/INJECTED.txt';
        try {
            $ff->run(['-f', 'lavfi', '-i', 'color=c=red:s=64x64:d=1', '-frames:v', '1', $evil]);
        } catch (Kuyash\Media\FfmpegException) {
            // failing is fine; the point is no shell ran
        }

        return !is_file($safeDir . '/INJECTED.txt');
    })());

    echo "== Media: real render chain (TTS → asset_fetch → assembly → final) ==\n";
    $rrDb = migratedDb($basePath);
    [$rrUser, $rrWs] = seedUser($rrDb, 'rr@example.com', $argonHash, 'RR WS');
    [, $rrWs2] = seedUser($rrDb, 'rr2@example.com', $argonHash, 'RR WS2');
    $m = makeMediaExecutors($rrDb);
    $rrDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,?,?,?,?,?)", [$rrWs, 'Full', 'full', '[]', gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $rrWf = $rrDb->lastInsertId();
    $rrDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)", [$rrWs, $rrWf, 'trend', '[]', 'running', $rrUser, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $rrRun = $rrDb->lastInsertId();
    $rrDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,run_after,created_at) VALUES (?,?,?,?,?,?,?)", [$rrWs, $rrRun, 'ASSEMBLE', 6, 'assembly', gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $rrJob = $rrDb->lastInsertId();
    $job = ['workspace_id' => $rrWs, 'run_id' => $rrRun, 'id' => $rrJob, 'entity_type' => 'trend', 'entity_id' => null];
    $prior = ['script_draft' => ['script' => 'Hook here. Body explains the idea in a few words. Call to action now.'], 'trend_fetch' => ['niche' => 'cooking', 'trend' => 'one pan dinner', 'format' => 'faceless']];
    $rt = $m['tts']->execute($job + ['step' => 4], $prior);
    $prior['tts'] = $rt->result;
    $ra = $m['asset_fetch']->execute($job + ['step' => 5], $prior);
    $prior['asset_fetch'] = $ra->result;
    $rasm = $m['assembly']->execute($job + ['step' => 6], $prior);
    check('render: tts produced a cached audio ref + duration', $rt->status === 'ready' && ($rt->result['duration_s'] ?? 0) > 0 && str_starts_with((string) $rt->result['audio_ref'], 'cache:'));
    check('render: asset_fetch produced a stock clip', $ra->status === 'ready' && ($ra->result['source'] ?? '') === 'stock' && ($ra->result['visual_kind'] ?? '') === 'clip');
    check('render: assembly produced a playable 540x960 draft + poster', (static function () use ($rasm, $m, $rrWs): bool {
        if ($rasm->status !== 'ready') {
            return false;
        }
        $row = $m['renders']->find($rrWs, (int) $rasm->result['render_id']);
        $path = $m['paths']->resolve($m['paths']->ref('render', $rrWs, (string) $row['stored_name']));

        return $row['kind'] === 'draft' && $row['width'] === 540 && $row['height'] === 960
            && is_file($path) && $m['ffmpeg']->probeDuration($path) !== null && $row['poster_name'] !== null;
    })());
    $rfin = $m['final_render']->execute($job + ['step' => 13, 'id' => $rrJob], $prior);
    check('render: final_render produced a 1080x1920 artifact', $rfin->status === 'ready' && ($rfin->result['width'] ?? 0) === 1080 && ($rfin->result['height'] ?? 0) === 1920);
    check('render: RenderRepository.find is tenant-scoped', $m['renders']->find($rrWs2, (int) $rasm->result['render_id']) === null);
    check('render: identical TTS inputs reuse the cache (no respend)', (static function () use ($m, $job, $prior): bool {
        $again = $m['tts']->execute($job + ['step' => 4], $prior);

        return ($again->result['cached'] ?? false) === true;
    })());

    echo "== Media: reference-asset resolution order ==\n";
    // seed a real photo + a real video as references
    $refPhoto = (static function () use ($rrDb, $rrWs, $ffmpegBin, $TEST_MEDIA_ROOT): int {
        $stored = bin2hex(random_bytes(16)) . '.png';
        $rrDb->run("INSERT INTO assets (workspace_id,kind,type,title,original_filename,stored_name,mime,size_bytes,sha256,tags,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", [$rrWs, 'photo', 'own', 'ref photo', 'p.png', $stored, 'image/png', 100, 'h', '[]', 'ready', gmdate(NOW_ISO), gmdate(NOW_ISO)]);
        $dir = "$TEST_MEDIA_ROOT/assets/$rrWs";
        if (!is_dir($dir)) { mkdir($dir, 0750, true); }
        $p = proc_open([$ffmpegBin, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=400x400:d=1', '-frames:v', '1', "$dir/$stored"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        if (is_resource($p)) { stream_get_contents($pp[1]); stream_get_contents($pp[2]); fclose($pp[1]); fclose($pp[2]); proc_close($p); }

        return $rrDb->lastInsertId();
    })();
    $refVideo = seedReadyVideo($rrDb, $rrWs, 'ref video');
    // per-run reference photo → still-clip
    $rrDb->run('UPDATE runs SET reference_asset_id = ? WHERE id = ?', [$refPhoto, $rrRun]);
    $refRes = $m['asset_fetch']->execute($job + ['step' => 5], $prior);
    check('reference: per-run photo → generated still-clip', $refRes->status === 'ready' && ($refRes->result['source'] ?? '') === 'reference' && ($refRes->result['visual_kind'] ?? '') === 'clip' && ($refRes->result['asset_id'] ?? 0) === $refPhoto);
    // per-run reference video → referenced as-is
    $rrDb->run('UPDATE runs SET reference_asset_id = ? WHERE id = ?', [$refVideo, $rrRun]);
    $refResV = $m['asset_fetch']->execute($job + ['step' => 5], $prior);
    check('reference: per-run video → referenced as a video', ($refResV->result['visual_kind'] ?? '') === 'video' && str_starts_with((string) $refResV->result['visual_ref'], 'asset:'));
    // avatar fallback for face format (no per-run reference)
    $rrDb->run('UPDATE runs SET reference_asset_id = NULL WHERE id = ?', [$rrRun]);
    $rrDb->run('UPDATE workspaces SET avatar_asset_id = ? WHERE id = ?', [$refVideo, $rrWs]);
    $facePrior = $prior;
    $facePrior['trend_fetch']['format'] = 'face';
    $avatarRes = $m['asset_fetch']->execute($job + ['step' => 5], $facePrior);
    check('reference: face format falls back to the workspace avatar', ($avatarRes->result['source'] ?? '') === 'avatar' && ($avatarRes->result['asset_id'] ?? 0) === $refVideo);
    // faceless with no reference/avatar → stock
    $rrDb->run('UPDATE workspaces SET avatar_asset_id = NULL WHERE id = ?', [$rrWs]);
    $stockRes = $m['asset_fetch']->execute($job + ['step' => 5], $prior);
    check('reference: faceless with nothing set → stock', ($stockRes->result['source'] ?? '') === 'stock');
}

/* ============================ PHASE 9: Compliance ============================ */

echo "== 0007 schema: workspace compliance columns ==\n";

$c9db = migratedDb($basePath);
[$c9UserA, $c9WsA] = seedUser($c9db, 'c9a@example.com', $argonHash, 'C9 Tenant A');
[$c9UserB, $c9WsB] = seedUser($c9db, 'c9b@example.com', $argonHash, 'C9 Tenant B');
$c9now = '2026-06-12T12:00:00Z';

check('0007: workspaces gained the 4 compliance columns with defaults', (static function () use ($c9db, $c9WsA): bool {
    $w = $c9db->one('SELECT approval_mode, kill_switch, daily_post_cap, budget_cap_cents FROM workspaces WHERE id = ?', [$c9WsA]);

    return $w['approval_mode'] === 'manual' && (int) $w['kill_switch'] === 0
        && (int) $w['daily_post_cap'] === 2 && $w['budget_cap_cents'] === null;
})());
check('0007: approval_mode CHECK rejects a bad value', throws(static fn () => $c9db->run(
    'UPDATE workspaces SET approval_mode = ? WHERE id = ?', ['semi', $c9WsA],
), PDOException::class));
check('0007: daily_post_cap CHECK rejects 0 and 11', throws(static fn () => $c9db->run(
    'UPDATE workspaces SET daily_post_cap = 0 WHERE id = ?', [$c9WsA],
), PDOException::class) && throws(static fn () => $c9db->run(
    'UPDATE workspaces SET daily_post_cap = 11 WHERE id = ?', [$c9WsA],
), PDOException::class));
check('0007: budget_cap_cents CHECK rejects <= 0, allows NULL + positive', throws(static fn () => $c9db->run(
    'UPDATE workspaces SET budget_cap_cents = 0 WHERE id = ?', [$c9WsA],
), PDOException::class) && (static function () use ($c9db, $c9WsA): bool {
    $c9db->run('UPDATE workspaces SET budget_cap_cents = 500 WHERE id = ?', [$c9WsA]);
    $ok = (int) $c9db->one('SELECT budget_cap_cents AS b FROM workspaces WHERE id = ?', [$c9WsA])['b'] === 500;
    $c9db->run('UPDATE workspaces SET budget_cap_cents = NULL WHERE id = ?', [$c9WsA]);

    return $ok;
})());

echo "== 0007 schema: approvals truthful-record CHECK ==\n";

// seed a run + a job to satisfy approvals FKs
$c9db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$c9WsA, $c9now, $c9now]);
$c9wf = $c9db->lastInsertId();
$c9db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$c9WsA, $c9wf, $c9UserA, $c9now, $c9now]);
$c9run = $c9db->lastInsertId();
$c9db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, run_after, created_at) VALUES (?, ?, 'PUBLISH', 12, 'render_review', 'ready', ?, ?)", [$c9WsA, $c9run, $c9now, $c9now]);
$c9job = $c9db->lastInsertId();

$insApproval = static function (array $cols) use ($c9db, $c9WsA, $c9run, $c9job, $c9now): void {
    $c9db->run(
        'INSERT INTO approvals (workspace_id, run_id, job_id, node, decision, mode, decided_by, decided_at, policy_version, score_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$c9WsA, $c9run, $c9job, 'PUBLISH', $cols['decision'] ?? 'approved', $cols['mode'], $cols['decided_by'] ?? null, $c9now, $cols['policy_version'] ?? null, $cols['score_json'] ?? null],
    );
};

check('0007: manual record (real user, no policy) is accepted', (static function () use ($insApproval, $c9UserA, $c9db): bool {
    $insApproval(['mode' => 'manual', 'decided_by' => $c9UserA]);

    return (int) $c9db->one('SELECT COUNT(*) AS n FROM approvals')['n'] === 1;
})());
check('0007: auto record (no user, policy version) is accepted', (static function () use ($insApproval, $c9db): bool {
    $insApproval(['mode' => 'auto', 'policy_version' => 'kuyash-v1', 'score_json' => '{"x":1}']);

    return (int) $c9db->one('SELECT COUNT(*) AS n FROM approvals')['n'] === 2;
})());
check('0007: untruthful auto record WITH a user is REJECTED', throws(static fn () => $insApproval(
    ['mode' => 'auto', 'decided_by' => $c9UserA, 'policy_version' => 'kuyash-v1'],
), PDOException::class));
check('0007: untruthful auto record WITHOUT a policy is REJECTED', throws(static fn () => $insApproval(
    ['mode' => 'auto'],
), PDOException::class));
check('0007: untruthful manual record WITHOUT a user is REJECTED', throws(static fn () => $insApproval(
    ['mode' => 'manual'],
), PDOException::class));
check('0007: untruthful manual record WITH a policy stamp is REJECTED', throws(static fn () => $insApproval(
    ['mode' => 'manual', 'decided_by' => $c9UserA, 'policy_version' => 'kuyash-v1'],
), PDOException::class));

echo "== Compliance: WorkspaceSettings (compliance accessors, tenant-scoped) ==\n";

$c9settings = new WorkspaceSettings($c9db);
check('settings: defaults read back', (static function () use ($c9settings, $c9WsA): bool {
    $s = $c9settings->compliance($c9WsA);

    return $s['approval_mode'] === 'manual' && $s['kill_switch'] === false
        && $s['daily_post_cap'] === 2 && $s['budget_cap_cents'] === null;
})());
check('settings: setApprovalMode validates + persists', $c9settings->setApprovalMode($c9WsA, 'auto')
    && !$c9settings->setApprovalMode($c9WsA, 'bogus')
    && $c9settings->compliance($c9WsA)['approval_mode'] === 'auto');
check('settings: setDailyPostCap enforces the 1-10 band', $c9settings->setDailyPostCap($c9WsA, 5)
    && !$c9settings->setDailyPostCap($c9WsA, 0) && !$c9settings->setDailyPostCap($c9WsA, 11)
    && $c9settings->compliance($c9WsA)['daily_post_cap'] === 5);
check('settings: setBudgetCapCents rejects <= 0, accepts NULL', $c9settings->setBudgetCapCents($c9WsA, 1000)
    && !$c9settings->setBudgetCapCents($c9WsA, -1) && !$c9settings->setBudgetCapCents($c9WsA, 0)
    && $c9settings->compliance($c9WsA)['budget_cap_cents'] === 1000
    && $c9settings->setBudgetCapCents($c9WsA, null)
    && $c9settings->compliance($c9WsA)['budget_cap_cents'] === null);
check('settings: kill switch flips both ways', (static function () use ($c9settings, $c9WsA): bool {
    $c9settings->setKillSwitch($c9WsA, true);
    $on = $c9settings->compliance($c9WsA)['kill_switch'] === true;
    $c9settings->setKillSwitch($c9WsA, false);

    return $on && $c9settings->compliance($c9WsA)['kill_switch'] === false;
})());
// reset A back to manual defaults for later sections
$c9settings->setApprovalMode($c9WsA, 'manual');
$c9settings->setDailyPostCap($c9WsA, 2);

echo "== Compliance: SlopScorer (bands, history, isolation) ==\n";

// helper: seed a "content run" carrying script + caption text for slop history
$seedContentRun = static function (Database $db, int $ws, int $userId, string $script, array $captions): int {
    $now = gmdate(NOW_ISO);
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'completed', ?, ?, ?)", [$ws, $wf, $userId, $now, $now]);
    $run = $db->lastInsertId();
    $scriptJson = json_encode(['script' => $script], JSON_UNESCAPED_UNICODE);
    $capJson = json_encode(['captions' => $captions], JSON_UNESCAPED_UNICODE);
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, result_json, run_after, created_at) VALUES (?, ?, 'SCRIPT', 3, 'script_draft', 'ready', ?, ?, ?)", [$ws, $run, $scriptJson, $now, $now]);
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, result_json, run_after, created_at) VALUES (?, ?, 'CAPTION', 7, 'caption_generation', 'ready', ?, ?, ?)", [$ws, $run, $capJson, $now, $now]);

    return $run;
};

$slDb = migratedDb($basePath);
[$slUser, $slWs] = seedUser($slDb, 'sl@example.com', $argonHash, 'Slop WS');
[, $slWs2] = seedUser($slDb, 'sl2@example.com', $argonHash, 'Slop WS2');
$scorer = new SlopScorer($slDb);

$baseText = 'The fastest way to brew espresso at home without a fancy machine and zero waste.';
check('slop: empty history scores 0', $scorer->score($slWs, 999, $baseText)['score'] === 0.0);
check('slop: blank candidate scores 0', $scorer->score($slWs, 999, '   ')['score'] === 0.0);

// empty captions so the history text == the script (a clean identity match)
$histRun = $seedContentRun($slDb, $slWs, $slUser, $baseText, []);
check('slop: identical text vs one history run scores 1.0', $scorer->score($slWs, 12345, $baseText)['score'] === 1.0);
check('slop: near-duplicate scores high (>= warn, < 1)', (static function () use ($scorer, $slWs, $baseText): bool {
    $tweaked = str_replace('espresso', 'coffee', $baseText) . ' Enjoy.';
    $s = $scorer->score($slWs, 12345, $tweaked)['score'];

    return $s >= CompliancePolicy::SLOP_WARN && $s < 1.0;
})());
check('slop: a wholly different topic scores below warn', (static function () use ($scorer, $slWs): bool {
    $s = $scorer->score($slWs, 12345, 'Three quick stretches for lower back pain you can do at your desk today.')['score'];

    return $s < CompliancePolicy::SLOP_WARN;
})());
check('slop: tenant isolation — another workspace\'s runs never count', (static function () use ($scorer, $slDb, $slWs2, $slUser, $baseText, $seedContentRun): bool {
    // tenant B has the exact same text; A's score against B must stay 0
    $seedContentRun($slDb, $slWs2, $slUser, $baseText, ['instagram' => 'a']);

    return $scorer->score($slWs2, 999, 'totally unrelated gardening tips for beginners in spring')['score'] < CompliancePolicy::SLOP_WARN
        && $scorer->score($slWs2, 999, $baseText)['score'] === 1.0;
})());
check('slop: history excludes the current run', (static function () use ($scorer, $slWs, $histRun, $baseText): bool {
    // scoring the history run against itself (excluded) → no self-match
    return $scorer->score($slWs, $histRun, $baseText)['score'] === 0.0;
})());
check('slop: candidateText = script + captions (full) / captions only (dist)', (static function (): bool {
    $full = SlopScorer::candidateText(['script_draft' => ['script' => 'S'], 'caption_generation' => ['captions' => ['ig' => 'C1', 'tt' => 'C2']]]);
    $dist = SlopScorer::candidateText(['caption_generation' => ['captions' => ['ig' => 'C1']]]);

    return str_contains($full, 'S') && str_contains($full, 'C1') && str_contains($full, 'C2')
        && $dist === 'C1' && !str_contains($dist, 'S');
})());

echo "== Compliance: ComplianceCheckExecutor (statuses, ai-label, format) ==\n";

$ceDb = migratedDb($basePath);
[$ceUser, $ceWs] = seedUser($ceDb, 'ce@example.com', $argonHash, 'CE WS');
$ce = new ComplianceCheckExecutor($ceDb, new SlopScorer($ceDb));

// helper: a run + a seeded render row for format checks
$seedRunWithRender = static function (Database $db, int $ws, int $userId, ?array $render): array {
    $now = gmdate(NOW_ISO);
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$ws, $wf, $userId, $now, $now]);
    $run = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, run_after, created_at) VALUES (?, ?, 'COMPLIANCE', 11, 'compliance_check', 'processing', ?, ?)", [$ws, $run, $now, $now]);
    $job = $db->lastInsertId();
    if ($render !== null) {
        $db->run("INSERT INTO renders (workspace_id, run_id, job_id, kind, stored_name, width, height, duration_s, created_at) VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?)", [$ws, $run, $job, bin2hex(random_bytes(16)) . '.mp4', $render['w'], $render['h'], $render['d'], $now]);
    }

    return ['workspace_id' => $ws, 'run_id' => $run, 'id' => $job];
};

// in-range render, no AI, no TTS, no slop history → pass
$ceJob1 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 20.0]);
$ceR1 = $ce->execute($ceJob1, ['asset_fetch' => ['ai_label_required' => false]]);
check('compliance: clean in-range render → pass', $ceR1->status === 'ready'
    && $ceR1->result['status'] === 'pass' && $ceR1->result['policy'] === 'kuyash-v1'
    && $ceR1->result['checks']['format']['status'] === 'pass'
    && $ceR1->result['ai_label_required'] === false && $ceR1->provider === 'kuyash-compliance' && $ceR1->costCents === null);

// AI visuals → pass_with_ai_label + reason ai_visuals
$ceJob2 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 20.0]);
$ceR2 = $ce->execute($ceJob2, ['asset_fetch' => ['ai_label_required' => true]]);
check('compliance: AI visuals → pass_with_ai_label + reason ai_visuals', $ceR2->result['status'] === 'pass_with_ai_label'
    && $ceR2->result['ai_label_required'] === true
    && in_array('ai_visuals', $ceR2->result['checks']['ai_label']['reasons'], true));

// synthetic voice from ANY tts → pass_with_ai_label + reason synthetic_voice (mock counts)
$ceJob3 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 20.0]);
$ceR3 = $ce->execute($ceJob3, ['tts' => ['audio_ref' => 'cache:1:abc', 'provider' => 'mock']]);
check('compliance: mock TTS narration → synthetic_voice label required', $ceR3->result['status'] === 'pass_with_ai_label'
    && in_array('synthetic_voice', $ceR3->result['checks']['ai_label']['reasons'], true));

// out-of-range duration → block
$ceJob4 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 60.0]);
$ceR4 = $ce->execute($ceJob4, []);
check('compliance: out-of-range duration → block with a reason', $ceR4->status === 'ready'
    && $ceR4->result['status'] === 'block' && $ceR4->result['reasons'] !== []
    && str_contains(implode(' ', $ceR4->result['reasons']), 'duration'));

// non-9:16 aspect → block
$ceJob5 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1920, 'h' => 1080, 'd' => 20.0]);
$ceR5 = $ce->execute($ceJob5, []);
check('compliance: non-9:16 aspect → block', $ceR5->result['status'] === 'block'
    && str_contains(implode(' ', $ceR5->result['reasons']), 'aspect'));

// no render, no measurable metadata → unknown, never blocks
$ceJob6 = $seedRunWithRender($ceDb, $ceWs, $ceUser, null);
$ceR6 = $ce->execute($ceJob6, ['asset_fetch' => ['source' => 'stock']]);
check('compliance: missing format metadata → unknown, never blocks', $ceR6->result['status'] === 'pass'
    && $ceR6->result['checks']['format']['status'] === 'unknown');

// distribution-shape: asset metadata used when no render exists
$ceJob7 = $seedRunWithRender($ceDb, $ceWs, $ceUser, null);
$ceAssetId = seedReadyVideo($ceDb, $ceWs, 'CE dist clip'); // 1080x1920, 21.5s
$ceR7 = $ce->execute($ceJob7, ['asset_fetch' => ['asset_id' => $ceAssetId, 'duration_s' => 21.5]]);
check('compliance: distribution uses asset metadata for format (pass)', $ceR7->result['status'] === 'pass'
    && $ceR7->result['checks']['format']['status'] === 'pass'
    && $ceR7->result['checks']['format']['source'] === 'asset');

// slop warn / block via seeded history on the SAME workspace
$ceHistText = 'Stop scrolling. Here is the one espresso trick nobody tells you about at home today.';
$ceHistRun = $seedContentRun($ceDb, $ceWs, $ceUser, $ceHistText, ['instagram' => 'save this espresso trick', 'tiktok' => 'wait for it espresso', 'youtube' => 'full espresso breakdown']);
$ceJob8 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 20.0]);
$ceR8 = $ce->execute($ceJob8, ['script_draft' => ['script' => $ceHistText], 'caption_generation' => ['captions' => ['instagram' => 'save this espresso trick', 'tiktok' => 'wait for it espresso', 'youtube' => 'full espresso breakdown']]]);
check('compliance: near-duplicate of history → block (>= 0.80)', $ceR8->result['status'] === 'block'
    && $ceR8->result['checks']['slop']['score'] >= CompliancePolicy::SLOP_BLOCK);
$ceJob9 = $seedRunWithRender($ceDb, $ceWs, $ceUser, ['w' => 1080, 'h' => 1920, 'd' => 20.0]);
$ceWarnScript = 'Here is the one espresso trick nobody tells you about at home today and tomorrow.';
$ceR9 = $ce->execute($ceJob9, ['script_draft' => ['script' => $ceWarnScript], 'caption_generation' => ['captions' => ['instagram' => 'save this espresso trick', 'tiktok' => 'wait for it espresso', 'youtube' => 'new espresso angle']]]);
check('compliance: moderately similar → warn band (>= 0.55, < 0.80)', $ceR9->result['status'] === 'warn'
    && $ceR9->result['checks']['slop']['score'] >= CompliancePolicy::SLOP_WARN
    && $ceR9->result['checks']['slop']['score'] < CompliancePolicy::SLOP_BLOCK);
check('compliance: result_json is a full audit record', (static function () use ($ceR1): bool {
    $r = $ceR1->result;

    return isset($r['status'], $r['policy'], $r['reasons'], $r['ai_label_required'])
        && isset($r['checks']['ai_label'], $r['checks']['format'], $r['checks']['slop'])
        && array_key_exists('score', $r['checks']['slop']) && array_key_exists('warn_at', $r['checks']['slop']);
})());

echo "== Compliance: Engine outcomes (pass / warn / block) ==\n";

// drive real compliance through the engine: a render_review pause carries the
// verdict; a block cancels the run; status stays 'ready' (a verdict, not a fail)
$enDb = migratedDb($basePath);
[$enUser, $enWs] = seedUser($enDb, 'en@example.com', $argonHash, 'EN WS');
$_SESSION = [];
$enCtx = new WorkspaceContext($enDb);
$enCtx->set($enWs);
$enWfRepo = new WorkflowRepository($enDb, new WorkflowValidator());
$enWfRepo->ensureDefaults($enCtx);
$enWfs = $enWfRepo->listFor($enCtx);
$enDistWf = $enWfs[1]['id'];
$enRuns = new RunRepository($enDb);
$enJobs = new JobRepository($enDb);
$enEvents = new EventLog($enDb);
$enNow = '2026-06-12T09:00:00Z';
[$enEngine, $enWorker] = makeRig($enDb, new MockExecutor(), $enNow);

// distribution clean run → compliance pass → pauses at render_review
$enAsset = seedReadyVideo($enDb, $enWs, 'EN clip');
$enRun1 = $enEngine->startRun($enCtx, $enDistWf, $enAsset, $enUser);
while ($enWorker->tick()) {
}
check('engine: clean run records compliance.passed + pauses at review', (static function () use ($enEvents, $enCtx, $enJobs, $enRun1): bool {
    $tl = $enEvents->timelineForRun($enCtx, $enRun1);
    $passed = array_values(array_filter($tl, static fn ($e) => $e['key'] === 'compliance.passed'));
    $cc = $enJobs->jobsForRun($enCtx, $enRun1);
    $ccJob = array_values(array_filter($cc, static fn ($j) => $j['type'] === 'compliance_check'))[0] ?? null;
    $awaiting = $enJobs->awaitingApproval($enCtx);

    return count($passed) === 1 && ($passed[0]['params']['policy'] ?? '') === 'kuyash-v1'
        && $ccJob !== null && $ccJob['status'] === 'ready'
        && count($awaiting) === 1 && $awaiting[0]['type'] === 'render_review';
})());
// approve to clear the queue
$enEngine->approve($enCtx, $enJobs->awaitingApproval($enCtx)[0]['id'], $enUser, 'en@example.com');
while ($enWorker->tick()) {
}

// BLOCK path: seed a history run, then start a near-identical distribution run
// whose captions repeat the history → slop block → run cancelled, not failed
$enBlockText = 'Repeat me exactly. The same caption every single time is textbook slop output.';
$seedContentRun($enDb, $enWs, $enUser, $enBlockText, ['instagram' => $enBlockText, 'tiktok' => $enBlockText, 'youtube' => $enBlockText]);
// a fake text provider that always emits the SAME caption text (forces a block)
$cloneProvider = new class ($enBlockText) implements Kuyash\Content\TextProvider {
    public function __construct(private string $t)
    {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function generate(string $kind, array $context, int $seed): Kuyash\Content\TextResult
    {
        $data = match ($kind) {
            'idea' => ['idea' => $this->t, 'hook' => $this->t, 'format' => '15-45s vertical'],
            'script' => ['script' => $this->t, 'word_count' => 12, 'estimated_duration_s' => 20.0],
            'caption' => ['captions' => ['instagram' => $this->t, 'tiktok' => $this->t, 'youtube' => $this->t]],
            'hashtag' => ['hashtags' => ['#a', '#b', '#c']],
            default => throw new Kuyash\Content\TextProviderException("bad kind {$kind}"),
        };

        return new Kuyash\Content\TextResult($data, 'mock', 'mock.v1', null, null);
    }
};
$enNow2 = '2026-06-12T10:00:00Z';
[$enEngine2, $enWorker2] = makeRig($enDb, new MockExecutor(), $enNow2, $cloneProvider);
$enBlockRun = $enEngine2->startRun($enCtx, $enDistWf, $enAsset, $enUser);
while ($enWorker2->tick()) {
}
check('engine: slop block cancels the run with reasons (job stays ready)', (static function () use ($enEvents, $enRuns, $enJobs, $enCtx, $enBlockRun): bool {
    $run = $enRuns->find($enCtx, $enBlockRun);
    $tl = $enEvents->timelineForRun($enCtx, $enBlockRun);
    $keys = array_column($tl, 'key');
    $jobs = $enJobs->jobsForRun($enCtx, $enBlockRun);
    $ccJob = array_values(array_filter($jobs, static fn ($j) => $j['type'] === 'compliance_check'))[0] ?? null;
    $hasReview = array_values(array_filter($jobs, static fn ($j) => $j['type'] === 'render_review'));

    return $run['status'] === 'cancelled'
        && in_array('compliance.blocked', $keys, true)
        && in_array('run.blocked_by_compliance', $keys, true)
        && $ccJob !== null && $ccJob['status'] === 'ready'   // a verdict, not a job failure
        && $hasReview === [];                                // never advanced to review
})());
check('engine: blocked compliance event carries reasons', (static function () use ($enEvents, $enCtx, $enBlockRun): bool {
    $blocked = array_values(array_filter(
        $enEvents->timelineForRun($enCtx, $enBlockRun),
        static fn ($e) => $e['key'] === 'compliance.blocked',
    ))[0] ?? null;

    return $blocked !== null && ($blocked['params']['reason'] ?? '') !== ''
        && ($blocked['params']['status'] ?? '') === 'block';
})());

echo "== Compliance: AutoApprovalGate (auto-approve + guardrails) ==\n";

$gtDb = migratedDb($basePath);
[$gtUser, $gtWs] = seedUser($gtDb, 'gt@example.com', $argonHash, 'GT WS');
[$gtUser2, $gtWs2] = seedUser($gtDb, 'gt2@example.com', $argonHash, 'GT WS2');
$gtNow = '2026-06-12T12:00:00Z';
$gtClock = static fn (): string => $gtNow;
$gtEvents = new EventLog($gtDb);
$gtSettings = new WorkspaceSettings($gtDb);
$gtGate = new AutoApprovalGate($gtDb, $gtEvents, $gtSettings, new QualityScore($gtDb, $gtClock), new UsageRepository($gtDb));

// seed a run + a compliance_check(ready, given status) + a render_review job
$seedGateScenario = static function (Database $db, int $ws, int $userId, string $complianceStatus) use ($gtNow): array {
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$ws, $gtNow, $gtNow]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$ws, $wf, $userId, $gtNow, $gtNow]);
    $run = $db->lastInsertId();
    $ccResult = json_encode(['status' => $complianceStatus, 'policy' => 'kuyash-v1', 'checks' => ['slop' => ['score' => 0.1]]], JSON_UNESCAPED_UNICODE);
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, result_json, run_after, created_at) VALUES (?, ?, 'COMPLIANCE', 11, 'compliance_check', 'ready', ?, ?, ?)", [$ws, $run, $ccResult, $gtNow, $gtNow]);
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, run_after, created_at) VALUES (?, ?, 'PUBLISH', 12, 'render_review', 'awaiting_approval', ?, ?)", [$ws, $run, $gtNow, $gtNow]);
    $jobId = $db->lastInsertId();

    return ['workspace_id' => $ws, 'run_id' => $run, 'id' => $jobId];
};

// manual mode → silent manual (no event)
$gtSettings->setApprovalMode($gtWs, 'manual');
check('gate: manual mode → manual decision, silent', (static function () use ($gtGate, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $before = (int) $gtDb->one('SELECT COUNT(*) AS n FROM events')['n'];
    $d = $gtGate->evaluate($job, $gtNow);
    $after = (int) $gtDb->one('SELECT COUNT(*) AS n FROM events')['n'];

    return !$d->approve && $d->path === GateDecision::PATH_MANUAL_MODE && $after === $before;
})());

// auto mode + clean pass → auto-approve with policy + score snapshot
$gtSettings->setApprovalMode($gtWs, 'auto');
$gtSettings->setDailyPostCap($gtWs, 5);
check('gate: auto + pass → auto-approve carrying policy + score', (static function () use ($gtGate, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $d = $gtGate->evaluate($job, $gtNow);

    return $d->approve && $d->path === GateDecision::PATH_AUTO
        && $d->policyVersion === 'kuyash-v1' && isset($d->score['quality']['score']);
})());
check('gate: auto + pass_with_ai_label → auto-approve (locked decision 1)', (static function () use ($gtGate, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass_with_ai_label');

    return $gtGate->evaluate($job, $gtNow)->approve;
})());
check('gate: auto + warn → manual review (never auto)', (static function () use ($gtGate, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $d = $gtGate->evaluate($seedGateScenario($gtDb, $gtWs, $gtUser, 'warn'), $gtNow);

    return !$d->approve && $d->path === GateDecision::PATH_NOT_CLEAN;
})());
check('gate: auto + block → manual review (never auto)', (static function () use ($gtGate, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    return !$gtGate->evaluate($seedGateScenario($gtDb, $gtWs, $gtUser, 'block'), $gtNow)->approve;
})());

// kill switch → deny + guardrail.kill_switch event
check('gate: kill switch ON → deny + guardrail event', (static function () use ($gtGate, $gtSettings, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $gtSettings->setKillSwitch($gtWs, true);
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $d = $gtGate->evaluate($job, $gtNow);
    $ev = $gtDb->one("SELECT key FROM events WHERE kind = 'guardrail' AND key = 'guardrail.kill_switch' AND run_id = ?", [$job['run_id']]);
    $gtSettings->setKillSwitch($gtWs, false);

    return !$d->approve && $d->path === GateDecision::PATH_KILL_SWITCH && $ev !== null;
})());

// daily cap: record one auto approval today, set cap 1 → next denied
check('gate: daily cap reached → deny + guardrail event', (static function () use ($gtGate, $gtSettings, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $gtSettings->setDailyPostCap($gtWs, 1);
    // a prior auto approval today (truthful: no user, policy stamp)
    $prior = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $gtDb->run("INSERT INTO approvals (workspace_id, run_id, job_id, node, decision, mode, decided_at, policy_version, score_json) VALUES (?, ?, ?, 'PUBLISH', 'approved', 'auto', ?, 'kuyash-v1', '{}')", [$gtWs, $prior['run_id'], $prior['id'], $gtNow]);
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $d = $gtGate->evaluate($job, $gtNow);
    $ev = $gtDb->one("SELECT key FROM events WHERE key = 'guardrail.daily_cap_reached' AND run_id = ?", [$job['run_id']]);
    $gtSettings->setDailyPostCap($gtWs, 5);

    return !$d->approve && $d->path === GateDecision::PATH_DAILY_CAP && $ev !== null;
})());
check('gate: autoApprovalsToday counts only auto-approved rows for the day', (static function () use ($gtGate, $gtDb, $gtWs, $gtNow): bool {
    // the prior loop left exactly 1 auto approval dated gtNow
    return $gtGate->autoApprovalsToday($gtWs, $gtNow) === 1
        && $gtGate->autoApprovalsToday($gtWs, '2026-07-01T00:00:00Z') === 0;
})());

// budget cap: seed a costly usage_event this month (Phase 11: MTD spend now
// reads usage_events, not jobs.cost_cents), set a low cap → deny
check('gate: month budget cap reached → deny + guardrail event', (static function () use ($gtGate, $gtSettings, $seedGateScenario, $gtDb, $gtWs, $gtUser, $gtNow): bool {
    $job = $seedGateScenario($gtDb, $gtWs, $gtUser, 'pass');
    $gtDb->run(
        "INSERT INTO usage_events (workspace_id, run_id, job_id, provider, category, cost_cents, created_at)
         VALUES (?, ?, ?, 'openai', 'ai_text', 200, ?)",
        [$gtWs, $job['run_id'], $job['id'], $gtNow],
    );
    $gtSettings->setBudgetCapCents($gtWs, 50);
    $d = $gtGate->evaluate($job, $gtNow);
    $ev = $gtDb->one("SELECT key FROM events WHERE key = 'guardrail.budget_cap_reached' AND run_id = ?", [$job['run_id']]);
    $gtSettings->setBudgetCapCents($gtWs, null);

    return !$d->approve && $d->path === GateDecision::PATH_BUDGET_CAP && $ev !== null;
})());

// quality breach → fall back to Manual (persisted) + event. Fresh DB so the
// earlier gate scenarios' checks don't dilute the (deterministic) breach math.
check('gate: quality breach flips workspace to Manual + fallback event', (static function () use ($basePath, $argonHash, $seedGateScenario, $gtNow): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'gtq@example.com', $argonHash, 'GTQ WS');
    $settings = new WorkspaceSettings($db);
    $gate = new AutoApprovalGate($db, new EventLog($db), $settings, new QualityScore($db, static fn (): string => $gtNow), new UsageRepository($db));
    $settings->setApprovalMode($ws, 'auto');
    // 5 blocked, high-slop checks → risk = 0.40*0.95 + 0.35*1.0 = 0.73 → score 27
    for ($i = 0; $i < 5; $i++) {
        $bj = $seedGateScenario($db, $ws, $u, 'pass');
        $db->run("UPDATE jobs SET result_json = ? WHERE workspace_id = ? AND run_id = ? AND type = 'compliance_check'", [json_encode(['status' => 'block', 'checks' => ['slop' => ['score' => 0.95]]]), $ws, $bj['run_id']]);
    }
    $job = $seedGateScenario($db, $ws, $u, 'pass');
    $d = $gate->evaluate($job, $gtNow);
    $ev = $db->one("SELECT key FROM events WHERE key = 'guardrail.fallback_to_manual' AND run_id = ?", [$job['run_id']]);

    return !$d->approve && $d->path === GateDecision::PATH_QUALITY_BREACH
        && $settings->compliance($ws)['approval_mode'] === 'manual'   // persisted flip
        && $ev !== null;
})());
check('gate: tenant isolation — gate reads only its own workspace state', (static function () use ($gtGate, $seedGateScenario, $gtSettings, $gtDb, $gtWs2, $gtUser2, $gtNow): bool {
    // WS2 is untouched (manual default) → manual decision regardless of WS1 state
    $gtSettings->setApprovalMode($gtWs2, 'auto');
    $gtSettings->setDailyPostCap($gtWs2, 5);
    $d = $gtGate->evaluate($seedGateScenario($gtDb, $gtWs2, $gtUser2, 'pass'), $gtNow);

    return $d->approve && $gtGate->autoApprovalsToday($gtWs2, $gtNow) === 0;
})());

echo "== Compliance: QualityScore (formula, boundaries, sample floor) ==\n";

$qsDb = migratedDb($basePath);
[$qsUser, $qsWs] = seedUser($qsDb, 'qs@example.com', $argonHash, 'QS WS');
$qsNow = '2026-06-12T12:00:00Z';
$qsClock = static fn (): string => $qsNow;
$qs = new QualityScore($qsDb, $qsClock);
// a dummy run per workspace satisfies the jobs.run_id FK (compliance scoring
// aggregates by workspace, so a single shared run is fine)
$dummyRunFor = static function (Database $db, int $ws) use ($qsNow): int {
    static $cache = [];
    $key = spl_object_id($db) . ':' . $ws;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $u = (int) $db->one('SELECT user_id FROM workspace_users WHERE workspace_id = ? LIMIT 1', [$ws])['user_id'];
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','full','[]',?,?)", [$ws, $qsNow, $qsNow]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','completed',?,?,?)", [$ws, $wf, $u, $qsNow, $qsNow]);

    return $cache[$key] = $db->lastInsertId();
};
$seedCheck = static function (Database $db, int $ws, string $status, float $slop) use ($qsNow, $dummyRunFor): void {
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, result_json, run_after, created_at) VALUES (?, ?, 'COMPLIANCE', 11, 'compliance_check', 'ready', ?, ?, ?)", [$ws, $dummyRunFor($db, $ws), json_encode(['status' => $status, 'checks' => ['slop' => ['score' => $slop]]]), $qsNow, $qsNow]);
};

check('quality: empty windows → score 100, no breach', (static function () use ($qs, $qsWs): bool {
    $q = $qs->compute($qsWs);

    return $q['score'] === 100 && $q['sample'] === 0 && $q['breach'] === false;
})());
check('quality: slop_avg drives the score (0.40 weight)', (static function () use ($qs, $seedCheck, $qsDb, $qsWs): bool {
    for ($i = 0; $i < 5; $i++) {
        $seedCheck($qsDb, $qsWs, 'pass', 0.20);
    }
    // risk = 0.40*0.20 = 0.08 → score 92
    $q = $qs->compute($qsWs);

    return $q['score'] === 92 && $q['sample'] === 5 && abs($q['slop_avg'] - 0.20) < 1e-9 && $q['breach'] === false;
})());
check('quality: blocks + high slop breach below 60 with enough sample', (static function () use ($seedCheck, $qsDb, $qsWs, $qsClock): bool {
    $db = migratedDb($GLOBALS['basePath']);
    // 5 blocked, slop 0.9 → risk = 0.40*0.9 + 0.35*1.0 = 0.71 → score 29
    $qs2 = new QualityScore($db, $qsClock);
    [, $ws] = seedUser($db, 'qsb@example.com', $GLOBALS['argonHash'], 'QSB');
    for ($i = 0; $i < 5; $i++) {
        $seedCheck($db, $ws, 'block', 0.90);
    }
    $q = $qs2->compute($ws);

    return $q['score'] === 29 && $q['breach'] === true && abs($q['block_rate'] - 1.0) < 1e-9;
})());
check('quality: sample floor — < 5 checks never breaches', (static function () use ($seedCheck, $qsClock): bool {
    $db = migratedDb($GLOBALS['basePath']);
    [, $ws] = seedUser($db, 'qsf@example.com', $GLOBALS['argonHash'], 'QSF');
    $qs3 = new QualityScore($db, $qsClock);
    for ($i = 0; $i < 4; $i++) {
        $seedCheck($db, $ws, 'block', 0.99);
    }
    $q = $qs3->compute($ws);

    return $q['score'] < 60 && $q['sample'] === 4 && $q['breach'] === false;
})());
check('quality: reject/fail rate component (rejected reviews + failed publishes)', (static function () use ($qsClock): bool {
    $db = migratedDb($GLOBALS['basePath']);
    [$u, $ws] = seedUser($db, 'qsr@example.com', $GLOBALS['argonHash'], 'QSR');
    $now = '2026-06-12T12:00:00Z';
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','full','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','running',?,?,?)", [$ws, $wf, $u, $now, $now]);
    $run = $db->lastInsertId();
    // 2 render_review jobs, 1 rejected + 1 approved
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',12,'render_review','ready',?,?)", [$ws, $run, $now, $now]);
    $rj1 = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',12,'render_review','ready',?,?)", [$ws, $run, $now, $now]);
    $rj2 = $db->lastInsertId();
    $db->run("INSERT INTO approvals (workspace_id,run_id,job_id,node,decision,mode,decided_by,decided_at) VALUES (?,?,?,'PUBLISH','rejected','manual',?,?)", [$ws, $run, $rj1, $u, $now]);
    $db->run("INSERT INTO approvals (workspace_id,run_id,job_id,node,decision,mode,decided_by,decided_at) VALUES (?,?,?,'PUBLISH','approved','manual',?,?)", [$ws, $run, $rj2, $u, $now]);
    // 2 publishes, 1 failed + 1 published
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at,finished_at) VALUES (?,?,'PUBLISH',14,'publish','failed',?,?,?)", [$ws, $run, $now, $now, $now]);
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at,finished_at) VALUES (?,?,'PUBLISH',14,'publish','published',?,?,?)", [$ws, $run, $now, $now, $now]);
    // reject_fail_rate = (1+1)/(2+2) = 0.5
    $q = (new QualityScore($db, $qsClock))->compute($ws);

    return abs($q['reject_fail_rate'] - 0.5) < 1e-9;
})());

echo "== Compliance: PublishGateExecutor (defer semantics) ==\n";

$pgDb = migratedDb($basePath);
[$pgUser, $pgWs] = seedUser($pgDb, 'pg@example.com', $argonHash, 'PG WS');
$pgNow = '2026-06-12T12:00:00Z';
$pgClock = static fn (): string => $pgNow;
$pgMock = new MockExecutor();
$pgGate = new PublishGateExecutor(
    $pgDb, $pgMock, new \Kuyash\Publish\PublishCounter($pgDb), new \Kuyash\Publish\AccountRepository($pgDb), $pgClock,
);

$seedPublishRun = static function (Database $db, int $ws, int $userId, string $mode) use ($pgNow): array {
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','full','[]',?,?)", [$ws, $pgNow, $pgNow]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','running',?,?,?)", [$ws, $wf, $userId, $pgNow, $pgNow]);
    $run = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',14,'publish','processing',?,?)", [$ws, $run, $pgNow, $pgNow]);
    $job = $db->lastInsertId();
    if ($mode === 'auto') {
        $db->run("INSERT INTO approvals (workspace_id,run_id,job_id,node,decision,mode,decided_at,policy_version,score_json) VALUES (?,?,?,'PUBLISH','approved','auto',?,'kuyash-v1','{}')", [$ws, $run, $job, $pgNow]);
    } else {
        $db->run("INSERT INTO approvals (workspace_id,run_id,job_id,node,decision,mode,decided_by,decided_at) VALUES (?,?,?,'PUBLISH','approved','manual',?,?)", [$ws, $run, $job, $userId, $pgNow]);
    }

    return ['workspace_id' => $ws, 'run_id' => $run, 'id' => $job, 'step' => 14, 'type' => 'publish', 'error_message' => null];
};

check('publish gate: manual-approved run passes straight through', (static function () use ($pgGate, $seedPublishRun, $pgDb, $pgWs, $pgUser): bool {
    $job = $seedPublishRun($pgDb, $pgWs, $pgUser, 'manual');
    $r = $pgGate->execute($job, ['final_render' => ['render_id' => 1]]);

    return $r->status === 'published';
})());
check('publish gate: auto run with kill switch ON → deferred', (static function () use ($pgGate, $seedPublishRun, $pgDb, $pgWs, $pgUser): bool {
    $pgDb->run('UPDATE workspaces SET kill_switch = 1 WHERE id = ?', [$pgWs]);
    $job = $seedPublishRun($pgDb, $pgWs, $pgUser, 'auto');
    $r = $pgGate->execute($job, []);
    $pgDb->run('UPDATE workspaces SET kill_switch = 0 WHERE id = ?', [$pgWs]);

    return $r->status === 'deferred' && $r->errorMessage === 'kill_switch' && $r->deferSeconds > 0;
})());
check('publish gate: auto run, a connected account at its per-account cap → deferred to midnight', (static function () use ($pgGate, $seedPublishRun, $pgDb, $pgWs, $pgUser, $pgNow): bool {
    $pgDb->run('UPDATE workspaces SET daily_post_cap = 1 WHERE id = ?', [$pgWs]);
    // a connected account that has already published its 1 post today (posts =
    // the unified per-account counter source)
    $pgDb->run("INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at) VALUES (?,'instagram','@cap','zacct_cap','connected','ok',?,?)", [$pgWs, $pgNow, $pgNow]);
    $acct = $pgDb->lastInsertId();
    $capRun = $seedPublishRun($pgDb, $pgWs, $pgUser, 'auto');
    $pgDb->run("INSERT INTO posts (workspace_id,run_id,account_id,platform,status,ai_label_applied,idempotency_key,posted_at,created_at,updated_at) VALUES (?,?,?,'instagram','published',0,?,?,?,?)", [$pgWs, $capRun['run_id'], $acct, 'cap:' . $acct, $pgNow, $pgNow, $pgNow]);
    $job = $seedPublishRun($pgDb, $pgWs, $pgUser, 'auto');
    $r = $pgGate->execute($job, []);
    $pgDb->run('UPDATE workspaces SET daily_post_cap = 2 WHERE id = ?', [$pgWs]);
    $pgDb->run("UPDATE accounts SET status = 'disconnected' WHERE id = ?", [$acct]); // isolate later tests

    return $r->status === 'deferred' && $r->errorMessage === 'daily_cap' && $r->deferSeconds > 0;
})());

// Engine.finalizeDeferred: queued back, no retry bump, event only on reason change
$dfDb = migratedDb($basePath);
[$dfUser, $dfWs] = seedUser($dfDb, 'df@example.com', $argonHash, 'DF WS');
$dfNow = '2026-06-12T12:00:00Z';
[$dfEngine] = makeRig($dfDb, new MockExecutor(), $dfNow);
$dfDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','full','[]',?,?)", [$dfWs, $dfNow, $dfNow]);
$dfWf = $dfDb->lastInsertId();
$dfDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','running',?,?,?)", [$dfWs, $dfWf, $dfUser, $dfNow, $dfNow]);
$dfRun = $dfDb->lastInsertId();
$dfDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,retry_count,worker_id,run_after,created_at,started_at) VALUES (?,?,'PUBLISH',14,'publish','processing',1,'w:1',?,?,?)", [$dfWs, $dfRun, $dfNow, $dfNow, $dfNow]);
$dfJobId = $dfDb->lastInsertId();
$dfJob = $dfDb->one('SELECT * FROM jobs WHERE id = ?', [$dfJobId]);
$dfEngine->finalize($dfJob, Kuyash\Workflow\JobResult::deferred('kill_switch', 300));
check('defer: job returns to queued with future run_after, retry_count unchanged', (static function () use ($dfDb, $dfJobId, $dfNow): bool {
    $j = $dfDb->one('SELECT * FROM jobs WHERE id = ?', [$dfJobId]);

    return $j['status'] === 'queued' && (int) $j['retry_count'] === 1
        && $j['worker_id'] === null && $j['run_after'] > $dfNow
        && str_starts_with((string) $j['error_message'], 'deferred: kill_switch');
})());
check('defer: a guardrail.publish_deferred event is recorded on first defer', (int) $dfDb->one(
    "SELECT COUNT(*) AS n FROM events WHERE key = 'guardrail.publish_deferred' AND job_id = ?", [$dfJobId],
)['n'] === 1);
// re-defer with the SAME reason → no new event (no spam)
$dfJob2 = $dfDb->one('SELECT * FROM jobs WHERE id = ?', [$dfJobId]);
$dfDb->run("UPDATE jobs SET status = 'processing', worker_id = 'w:1' WHERE id = ?", [$dfJobId]);
$dfJob2 = $dfDb->one('SELECT * FROM jobs WHERE id = ?', [$dfJobId]);
$dfEngine->finalize($dfJob2, Kuyash\Workflow\JobResult::deferred('kill_switch', 300));
check('defer: re-defer with the same reason emits NO new event (no spam)', (int) $dfDb->one(
    "SELECT COUNT(*) AS n FROM events WHERE key = 'guardrail.publish_deferred' AND job_id = ?", [$dfJobId],
)['n'] === 1);

echo "== Compliance: Auto-mode end-to-end (truthful records, publish) ==\n";

$aeDb = migratedDb($basePath);
[$aeUser, $aeWs] = seedUser($aeDb, 'ae@example.com', $argonHash, 'AE WS');
$_SESSION = [];
$aeCtx = new WorkspaceContext($aeDb);
$aeCtx->set($aeWs);
$aeWfRepo = new WorkflowRepository($aeDb, new WorkflowValidator());
$aeWfRepo->ensureDefaults($aeCtx);
$aeDistWf = $aeWfRepo->listFor($aeCtx)[1]['id'];
$aeRuns = new RunRepository($aeDb);
$aeJobs = new JobRepository($aeDb);
$aeEvents = new EventLog($aeDb);
(new WorkspaceSettings($aeDb))->setApprovalMode($aeWs, 'auto');
$aeNow = '2026-06-12T12:00:00Z';
[$aeEngine, $aeWorker] = makeRig($aeDb, new MockExecutor(), $aeNow, null, true); // autoCompliance ON
$aeAsset = seedReadyVideo($aeDb, $aeWs, 'AE clip');
$aeRun = $aeEngine->startRun($aeCtx, $aeDistWf, $aeAsset, $aeUser);
while ($aeWorker->tick()) {
}
check('auto e2e: distribution run auto-approves + publishes without human input', (static function () use ($aeRuns, $aeJobs, $aeCtx, $aeRun): bool {
    $run = $aeRuns->find($aeCtx, $aeRun);
    $jobs = $aeJobs->jobsForRun($aeCtx, $aeRun);
    $statuses = array_column($jobs, 'status', 'type');

    return $run['status'] === 'completed'
        && ($statuses['render_review'] ?? '') === 'ready'
        && ($statuses['publish'] ?? '') === 'published';
})());
check('auto e2e: approval record is TRUTHFUL (mode auto, no user, policy + score)', (static function () use ($aeRuns, $aeCtx, $aeRun): bool {
    $records = $aeRuns->approvalsForRun($aeCtx, $aeRun);

    return count($records) === 1 && $records[0]['mode'] === 'auto'
        && $records[0]['decided_by'] === null && $records[0]['decided_by_email'] === null
        && $records[0]['policy_version'] === 'kuyash-v1'
        && str_contains((string) $records[0]['score_json'], 'quality');
})());
check('auto e2e: approval.auto_approved event recorded (compliance kind)', (static function () use ($aeEvents, $aeCtx, $aeRun): bool {
    $auto = array_values(array_filter(
        $aeEvents->timelineForRun($aeCtx, $aeRun),
        static fn ($e) => $e['key'] === 'approval.auto_approved',
    ));

    return count($auto) === 1 && $auto[0]['kind'] === 'compliance'
        && ($auto[0]['params']['policy'] ?? '') === 'kuyash-v1';
})());

echo "== Compliance: DigestReport (derive-only daily read-model) ==\n";

$dgDigest = new DigestReport($aeDb, new QualityScore($aeDb, static fn (): string => $aeNow));
$dgReport = $dgDigest->forDate($aeCtx, '2026-06-12');
check('digest: lists the day\'s auto-approved item with policy + run', count($dgReport['auto_approved']) === 1
    && $dgReport['auto_approved'][0]['run_id'] === $aeRun
    && $dgReport['auto_approved'][0]['policy_version'] === 'kuyash-v1');
check('digest: lists the day\'s auto-published job', count($dgReport['auto_published']) === 1
    && $dgReport['auto_published'][0]['run_id'] === $aeRun);
check('digest: empty on a different date', (static function () use ($dgDigest, $aeCtx): bool {
    $other = $dgDigest->forDate($aeCtx, '2026-06-11');

    return $other['auto_approved'] === [] && $other['auto_published'] === [];
})());
check('digest: tenant isolation — another workspace sees nothing of AE', (static function () use ($aeDb, $aeNow): bool {
    $_SESSION = [];
    [$dgU, $dgWs] = seedUser($aeDb, 'dg@example.com', $GLOBALS['argonHash'], 'DG WS');
    $ctx = new WorkspaceContext($aeDb);
    $ctx->set($dgWs);
    $r = (new DigestReport($aeDb, new QualityScore($aeDb, static fn (): string => $aeNow)))->forDate($ctx, '2026-06-12');

    return $r['auto_approved'] === [] && $r['auto_published'] === [];
})());

echo "== Compliance: truthful badges in UI (runs/show, queue) ==\n";

$_SESSION = [];
$aeCtx->set($aeWs);
$badgeRuns = new RunRepository($aeDb);
$badgeView = new View($basePath . '/templates');
$badgeBody = $badgeView->render('runs/show', [
    'title' => 'x', 'active' => 'queue', 'workspaceName' => 'AE', 'csrfField' => '',
    'flashes' => [], 'run' => $badgeRuns->find($aeCtx, $aeRun),
    'jobs' => $aeJobs->jobsForRun($aeCtx, $aeRun),
    'timeline' => $aeEvents->timelineForRun($aeCtx, $aeRun),
    'approvals' => $badgeRuns->approvalsForRun($aeCtx, $aeRun),
], 'layout/app');
check('badge: auto record renders "Auto-approved by compliance agent", NEVER "by you"', (static function () use ($badgeBody): bool {
    return str_contains($badgeBody, 'Auto-approved by compliance agent')
        && str_contains($badgeBody, 'policy kuyash-v1')
        && !str_contains($badgeBody, 'Approved by you');
})());

// a manual run for the opposite badge
$_SESSION = [];
[$mbUser, $mbWs] = seedUser($aeDb, 'mb@example.com', $argonHash, 'MB WS');
$mbCtx = new WorkspaceContext($aeDb);
$mbCtx->set($mbWs);
$mbWfRepo = new WorkflowRepository($aeDb, new WorkflowValidator());
$mbWfRepo->ensureDefaults($mbCtx);
$mbDistWf = $mbWfRepo->listFor($mbCtx)[1]['id'];
$mbNow = '2026-06-12T13:00:00Z';
[$mbEngine, $mbWorker] = makeRig($aeDb, new MockExecutor(), $mbNow); // manual (no autoGate)
$mbAsset = seedReadyVideo($aeDb, $mbWs, 'MB clip');
$mbRun = $mbEngine->startRun($mbCtx, $mbDistWf, $mbAsset, $mbUser);
while ($mbWorker->tick()) {
}
$mbEngine->approve($mbCtx, $aeJobs->awaitingApproval($mbCtx)[0]['id'], $mbUser, 'mb@example.com');
while ($mbWorker->tick()) {
}
$mbBody = $badgeView->render('runs/show', [
    'title' => 'x', 'active' => 'queue', 'workspaceName' => 'MB', 'csrfField' => '',
    'flashes' => [], 'run' => $badgeRuns->find($mbCtx, $mbRun),
    'jobs' => $aeJobs->jobsForRun($mbCtx, $mbRun),
    'timeline' => $aeEvents->timelineForRun($mbCtx, $mbRun),
    'approvals' => $badgeRuns->approvalsForRun($mbCtx, $mbRun),
], 'layout/app');
check('badge: manual record renders "Approved by you · email", NEVER the agent', (static function () use ($mbBody): bool {
    return str_contains($mbBody, 'Approved by you') && str_contains($mbBody, 'mb@example.com')
        && !str_contains($mbBody, 'Auto-approved by compliance agent');
})());

echo "== Compliance: SettingsController + DigestController (unit) ==\n";

$_SESSION = [];
$_SESSION['auth_user_id'] = $aeUser;
$aeCtx->set($aeWs);
$scSettings = new WorkspaceSettings($aeDb);
$scQuality = new QualityScore($aeDb, static fn (): string => $aeNow);
$scGate = new AutoApprovalGate($aeDb, $aeEvents, $scSettings, $scQuality, new UsageRepository($aeDb));
$scAuth = new Auth($aeDb, new LoginThrottle($aeDb), $aeCtx);
$settingsCtl = new SettingsController($badgeView, $scSettings, $scQuality, $scGate, $aeEvents, $aeCtx, $scAuth, new Csrf(), new Flash());

check('settings ctl: index renders mode, policy, quality, auto-slots', (static function () use ($settingsCtl): bool {
    $body = $settingsCtl->index()->body();

    return str_contains($body, 'Approval mode') && str_contains($body, 'kuyash-v1')
        && str_contains($body, 'Quality score') && str_contains($body, 'auto slots used today')
        && str_contains($body, 'Kill switch');
})());
check('settings ctl: save valid settings persists + flashes success', (static function () use ($settingsCtl, $scSettings, $aeWs): bool {
    $_POST = ['approval_mode' => 'manual', 'daily_post_cap' => '3', 'budget_cap_usd' => '25'];
    $r = $settingsCtl->save();
    $_POST = [];
    $s = $scSettings->compliance($aeWs);

    return $r->status() === 303 && $s['approval_mode'] === 'manual'
        && $s['daily_post_cap'] === 3 && $s['budget_cap_cents'] === 2500;
})());
check('settings ctl: invalid cap → error flash, nothing changed', (static function () use ($settingsCtl, $scSettings, $aeWs): bool {
    $before = $scSettings->compliance($aeWs);
    $_POST = ['approval_mode' => 'auto', 'daily_post_cap' => '99', 'budget_cap_usd' => ''];
    $r = $settingsCtl->save();
    $_POST = [];
    $after = $scSettings->compliance($aeWs);

    return $r->status() === 303 && $after['approval_mode'] === $before['approval_mode']
        && $after['daily_post_cap'] === $before['daily_post_cap'];
})());
check('settings ctl: mode change is audited (guardrail event with user)', (static function () use ($settingsCtl, $aeDb, $aeWs): bool {
    $_POST = ['approval_mode' => 'auto', 'daily_post_cap' => '2', 'budget_cap_usd' => ''];
    $settingsCtl->save();
    $_POST = [];
    $ev = $aeDb->one("SELECT params_json FROM events WHERE workspace_id = ? AND key = 'guardrail.approval_mode_changed' ORDER BY id DESC LIMIT 1", [$aeWs]);

    return $ev !== null && str_contains((string) $ev['params_json'], 'ae@example.com');
})());
check('settings ctl: kill switch POST flips state + writes audit event', (static function () use ($settingsCtl, $scSettings, $aeDb, $aeWs): bool {
    $_POST = ['state' => 'on'];
    $settingsCtl->killSwitch();
    $_POST = [];
    $on = $scSettings->compliance($aeWs)['kill_switch'] === true;
    $ev = $aeDb->one("SELECT key FROM events WHERE workspace_id = ? AND key = 'guardrail.kill_switch_on' ORDER BY id DESC LIMIT 1", [$aeWs]);
    $_POST = ['state' => 'off'];
    $settingsCtl->killSwitch();
    $_POST = [];

    return $on && $ev !== null && $scSettings->compliance($aeWs)['kill_switch'] === false;
})());

$digestCtl = new DigestController($badgeView, new DigestReport($aeDb, $scQuality), $aeCtx, new Csrf(), new Flash());
check('digest ctl: renders the day with the auto-approved item', (static function () use ($digestCtl): bool {
    $_GET = ['date' => '2026-06-12'];
    $body = $digestCtl->index()->body();
    $_GET = [];

    return str_contains($body, 'Daily digest') && str_contains($body, 'Auto-approved by compliance agent');
})());
check('digest ctl: a malformed date falls back to today (no error)', (static function () use ($digestCtl): bool {
    $_GET = ['date' => 'not-a-date'];
    $status = $digestCtl->index()->status();
    $_GET = [];

    return $status === 200;
})());

/* ================== Phase 10: Zernio Publishing ================== */

echo "== 0008 schema: accounts / posts / webhook_events ==\n";

$p10db = migratedDb($basePath);
[$p10User, $p10Ws] = seedUser($p10db, 'p10@example.com', $argonHash, 'P10 WS');
$p10Now = '2026-06-13T10:00:00Z';

check('schema 0008: three tables + runs.publish_after column', count($p10db->all(
    "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('accounts','posts','webhook_events')"
)) === 3 && in_array('publish_after', array_column($p10db->all('PRAGMA table_info(runs)'), 'name'), true));
check('schema 0008: accounts carries NO token/password column', (static function () use ($p10db): bool {
    $cols = array_map('strtolower', array_column($p10db->all('PRAGMA table_info(accounts)'), 'name'));
    foreach ($cols as $c) {
        if (str_contains($c, 'token') || str_contains($c, 'password') || str_contains($c, 'secret')) {
            return false;
        }
    }

    return in_array('external_ref', $cols, true) && in_array('health', $cols, true);
})());
check('schema 0008: bad platform rejected', throws(static fn () => $p10db->run(
    "INSERT INTO accounts (workspace_id,platform,handle,status,health,created_at,updated_at) VALUES (?, 'facebook','@x','connected','ok',?,?)",
    [$p10Ws, $p10Now, $p10Now],
), PDOException::class));
check('schema 0008: bad post status rejected', throws(static function () use ($p10db, $p10Ws, $p10Now): void {
    $p10db->run("INSERT INTO accounts (workspace_id,platform,handle,status,health,created_at,updated_at) VALUES (?, 'tiktok','@y','connected','ok',?,?)", [$p10Ws, $p10Now, $p10Now]);
    $acc = $p10db->lastInsertId();
    $p10db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$p10Ws, $p10Now, $p10Now]);
    $wf = $p10db->lastInsertId();
    $p10db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$p10Ws, $wf, $GLOBALS['p10User'] ?? 1, $p10Now, $p10Now]);
    $run = $p10db->lastInsertId();
    $p10db->run("INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,created_at,updated_at) VALUES (?,?,?,'tiktok','levitating','k1',?,?)", [$p10Ws, $run, $acc, $p10Now, $p10Now]);
}, PDOException::class));
check('schema 0008: posts idempotency_key is UNIQUE', (static function () use ($p10db, $p10Ws, $p10Now): bool {
    $p10db->run("INSERT INTO accounts (workspace_id,platform,handle,status,health,created_at,updated_at) VALUES (?, 'youtube','@z','connected','ok',?,?)", [$p10Ws, $p10Now, $p10Now]);
    $acc = $p10db->lastInsertId();
    $p10db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$p10Ws, $p10Now, $p10Now]);
    $wf = $p10db->lastInsertId();
    $p10db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$p10Ws, $wf, 1, $p10Now, $p10Now]);
    $run = $p10db->lastInsertId();
    $p10db->run("INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,created_at,updated_at) VALUES (?,?,?,'youtube','publishing','dupkey',?,?)", [$p10Ws, $run, $acc, $p10Now, $p10Now]);

    return throws(static fn () => $p10db->run(
        "INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,created_at,updated_at) VALUES (?,?,?,'youtube','publishing','dupkey',?,?)",
        [$p10Ws, $run, $acc, $p10Now, $p10Now],
    ), PDOException::class);
})());
check('schema 0008: webhook_events external_event_id is UNIQUE', (static function () use ($p10db, $p10Now): bool {
    $p10db->run("INSERT INTO webhook_events (source,external_event_id,payload_json,received_at) VALUES ('zernio','ev1','{}',?)", [$p10Now]);

    return throws(static fn () => $p10db->run(
        "INSERT INTO webhook_events (source,external_event_id,payload_json,received_at) VALUES ('zernio','ev1','{}',?)",
        [$p10Now],
    ), PDOException::class);
})());

echo "== Publish: MockPublishProvider (deterministic, all modes) ==\n";

$mock = new MockPublishProvider();
$mkReq = static fn (string $handle): PublishRequest => new PublishRequest('instagram', $handle, 'zacct_1', 'run:1:acct:1:publish');
check('mock provider: default handle → published with stable external id', (static function () use ($mock, $mkReq): bool {
    $o = $mock->publish($mkReq('@normal'));
    $o2 = $mock->publish($mkReq('@normal'));

    return $o->status === PublishOutcome::PUBLISHED && $o->externalPostId === $o2->externalPostId
        && str_contains((string) $o->externalUrl, 'instagram.example');
})());
check('mock provider: reject marker → rejected (terminal)', $mock->publish($mkReq('@reject_acct'))->status === PublishOutcome::REJECTED);
check('mock provider: authfail marker → auth_failed', $mock->publish($mkReq('@authfail'))->status === PublishOutcome::AUTH_FAILED);
check('mock provider: ratelimit marker → rate_limited', $mock->publish($mkReq('@ratelimit'))->status === PublishOutcome::RATE_LIMITED);
check('mock provider: async marker → accepted (webhook/reconcile later)', $mock->publish($mkReq('@async'))->status === PublishOutcome::ACCEPTED);
check('mock provider: timeout marker → throws PublishProviderException', throws(
    static fn () => $mock->publish($mkReq('@timeout')),
    \Kuyash\Publish\PublishProviderException::class,
));
check('mock provider: status() converges an accepted post to published', $mock->status('zp_abc')->status === PublishOutcome::PUBLISHED);
check('zernio stub: flag-off real client throws "doc-gated", never calls out', (static function (): bool {
    $stub = new ZernioPublishProvider(new FakeHttpClient([]), ['endpoint' => 'x', 'timeout' => 5]);

    return throws(static fn () => $stub->publish(new PublishRequest('tiktok', '@h', 'r', 'k')), \Kuyash\Publish\PublishProviderException::class)
        && throws(static fn () => $stub->status('zp_x'), \Kuyash\Publish\PublishProviderException::class);
})());

echo "== Publish: ZernioPublishExecutor (per-account fan-out) ==\n";

$mkPublishJob = static function (Database $db, int $ws, int $userId, string $now): array {
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$ws, $wf, $userId, $now, $now]);
    $run = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',14,'publish','processing',?,?)", [$ws, $run, $now, $now]);

    return ['workspace_id' => $ws, 'run_id' => $run, 'id' => $db->lastInsertId(), 'step' => 14, 'type' => 'publish'];
};
$connect = static function (Database $db, int $ws, string $platform, string $handle, string $now): int {
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at) VALUES (?,?,?,?,'connected','ok',?,?)",
        [$ws, $platform, $handle, 'zacct_' . bin2hex(random_bytes(3)), $now, $now],
    );

    return $db->lastInsertId();
};
$mkExec = static fn (Database $db, string $now): ZernioPublishExecutor => new ZernioPublishExecutor(
    $db, new MockPublishProvider(), new AccountRepository($db), new PostRepository($db), new \Kuyash\Workflow\EventLog($db), static fn (): string => $now,
);

check('executor: no connected accounts → published, 0 posts, no_accounts event', (static function () use ($mkPublishJob, $mkExec, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex0@x.com', $argonHash, 'EX0');
    $now = '2026-06-13T10:00:00Z';
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);
    $ev = (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key='publish.no_accounts'")['n'];

    return $r->status === 'published' && (int) ($r->result['posts'] ?? -1) === 0 && $ev === 1;
})());
check('executor: success → post published, provider tag mock, publish.success event', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex1@x.com', $argonHash, 'EX1');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@happy', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);
    $post = $db->one("SELECT * FROM posts WHERE run_id = ?", [$job['run_id']]);

    return $r->status === 'published' && $r->provider === 'mock'
        && $post['status'] === 'published' && $post['external_post_id'] !== null && $post['posted_at'] !== null
        && (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key='publish.success'")['n'] === 1;
})());
check('executor: AI label truthful — NOT applied when compliance did not require it', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex2@x.com', $argonHash, 'EX2');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@noai', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $mkExec($db, $now)->execute($job, ['compliance_check' => ['ai_label_required' => false]]);

    return (int) $db->one("SELECT ai_label_applied FROM posts WHERE run_id = ?", [$job['run_id']])['ai_label_applied'] === 0;
})());
check('executor: AI label set per platform exactly when compliance required it', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex3@x.com', $argonHash, 'EX3');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'tiktok', '@ai', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, ['compliance_check' => ['ai_label_required' => true]]);

    return (int) $db->one("SELECT ai_label_applied FROM posts WHERE run_id = ?", [$job['run_id']])['ai_label_applied'] === 1
        && ($r->result['ai_label_applied'] ?? false) === true;
})());
check('executor: partial multi-platform (2 ok + 1 reject) → job published, per-target truthful', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex4@x.com', $argonHash, 'EX4');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@ok1', $now);
    $connect($db, $ws, 'tiktok', '@ok2', $now);
    $connect($db, $ws, 'youtube', '@reject_one', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);
    $pub = (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id=? AND status='published'", [$job['run_id']])['n'];
    $fail = (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id=? AND status='failed'", [$job['run_id']])['n'];

    return $r->status === 'published' && $pub === 2 && $fail === 1
        && (int) ($r->result['published'] ?? 0) === 2 && (int) ($r->result['failed'] ?? 0) === 1;
})());
check('executor: auth failure → post failed + account flagged reauth_needed', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex5@x.com', $argonHash, 'EX5');
    $now = '2026-06-13T10:00:00Z';
    $acc = $connect($db, $ws, 'instagram', '@authfail', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);

    return $r->status === 'published'
        && $db->one("SELECT status FROM posts WHERE run_id=?", [$job['run_id']])['status'] === 'failed'
        && $db->one("SELECT status FROM accounts WHERE id=?", [$acc])['status'] === 'reauth_needed'
        && (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key='publish.account_reauth'")['n'] === 1;
})());
check('executor: rate-limit → job FAILED (retryable backoff), post stays in-flight', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex6@x.com', $argonHash, 'EX6');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@ratelimit', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);

    return $r->status === 'failed' && str_contains((string) $r->errorMessage, 'retry')
        && $db->one("SELECT status FROM posts WHERE run_id=?", [$job['run_id']])['status'] === 'publishing';
})());
check('executor: transport timeout → job FAILED (retryable), post stays in-flight', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex7@x.com', $argonHash, 'EX7');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@timeout', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $r = $mkExec($db, $now)->execute($job, []);

    return $r->status === 'failed' && $db->one("SELECT status FROM posts WHERE run_id=?", [$job['run_id']])['status'] === 'publishing';
})());
check('executor: IDEMPOTENT — re-run never double-posts a published target', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'ex8@x.com', $argonHash, 'EX8');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@once', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $exec = $mkExec($db, $now);
    $exec->execute($job, []);
    $first = $db->one("SELECT external_post_id FROM posts WHERE run_id=?", [$job['run_id']])['external_post_id'];
    $r2 = $exec->execute($job, []); // re-enqueue / retry

    return $r2->status === 'published'
        && (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id=?", [$job['run_id']])['n'] === 1
        && $db->one("SELECT external_post_id FROM posts WHERE run_id=?", [$job['run_id']])['external_post_id'] === $first;
})());

echo "== Publish: PostRepository UNIQUE backstop (graceful idempotency-key collision) ==\n";

check('postrepo: a duplicate insertPublishing returns the existing post id (no throw)', (static function () use ($mkPublishJob, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'prbk@x.com', $argonHash, 'PRBK');
    $now = '2026-06-13T10:00:00Z';
    $acct = $connect($db, $ws, 'instagram', '@dup', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $repo = new PostRepository($db);
    $key = "run:{$job['run_id']}:acct:{$acct}:publish";

    $firstId = $repo->insertPublishing($ws, $job['run_id'], $job['id'], $acct, 'instagram', false, null, $key, $now);
    // a racing second insert with the SAME key trips UNIQUE → backstop returns the winner's id
    $secondId = $repo->insertPublishing($ws, $job['run_id'], $job['id'], $acct, 'instagram', false, null, $key, $now);

    return $firstId === $secondId
        && (int) $db->one('SELECT COUNT(*) AS n FROM posts WHERE idempotency_key = ?', [$key])['n'] === 1;
})());

echo "== Publish: webhook inbox + signature + idempotency ==\n";

$whSecret = 'phase10-test-secret';
$sign = static fn (string $body): string => hash_hmac('sha256', $body, $whSecret);

check('webhook ctl: empty secret → fail-closed 503', (new WebhookController(new WebhookInbox(migratedDb($basePath), new PostRepository(migratedDb($basePath)), new \Kuyash\Workflow\EventLog(migratedDb($basePath))), ''))->handle('{}', 'x')->status() === 503);
check('webhook ctl: invalid signature → 401, nothing persisted', (static function () use ($basePath, $whSecret): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = '{"event_id":"ev_bad","post_id":"zp_1","status":"published"}';
    $r = $ctl->handle($body, 'deadbeef');

    return $r->status() === 401 && (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events")['n'] === 0;
})());
check('webhook ctl: valid signature → 200, raw persisted; missing event_id → 400', (static function () use ($basePath, $whSecret, $sign): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = '{"event_id":"ev_ok","post_id":"zp_1","status":"published"}';
    $ok = $ctl->handle($body, $sign($body))->status();
    $stored = (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events WHERE external_event_id='ev_ok'")['n'];
    $noId = $ctl->handle('{"post_id":"zp_2"}', $sign('{"post_id":"zp_2"}'))->status();

    return $ok === 200 && $stored === 1 && $noId === 400;
})());
check('webhook ctl: duplicate event_id → 200 ack, single stored row (idempotent inbox)', (static function () use ($basePath, $whSecret, $sign): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = '{"event_id":"ev_dup","post_id":"zp_9","status":"published"}';
    $ctl->handle($body, $sign($body));
    $second = $ctl->handle($body, $sign($body))->status();

    return $second === 200 && (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events WHERE external_event_id='ev_dup'")['n'] === 1;
})());
check('webhook inbox: processing converges the post + records publish.webhook_received', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath, $whSecret, $sign): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'wh1@x.com', $argonHash, 'WH1');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@async', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $mkExec($db, $now)->execute($job, []); // accepted → post in-flight with external id
    $ext = $db->one("SELECT external_post_id FROM posts WHERE run_id=?", [$job['run_id']])['external_post_id'];
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = json_encode(['event_id' => 'ev_p', 'post_id' => $ext, 'status' => 'published', 'url' => 'https://x/y']);
    $ctl->handle($body, $sign($body));
    $inbox = new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db));
    $n = $inbox->processPending('2026-06-13T10:05:00Z');
    $n2 = $inbox->processPending('2026-06-13T10:06:00Z'); // already processed → 0

    return $n === 1 && $n2 === 0
        && $db->one("SELECT status FROM posts WHERE run_id=?", [$job['run_id']])['status'] === 'published'
        && (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key='publish.webhook_received'")['n'] === 1;
})());
check('webhook inbox: a javascript: url is rejected → safe https fallback stored', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'whx@x.com', $argonHash, 'WHX');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@async', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $mkExec($db, $now)->execute($job, []);
    $ext = $db->one("SELECT external_post_id FROM posts WHERE run_id=?", [$job['run_id']])['external_post_id'];
    // a verified-but-hostile sender tries a javascript: scheme in the url field
    $db->run("INSERT INTO webhook_events (source,external_event_id,payload_json,received_at) VALUES ('zernio','ev_xss',?,?)", [json_encode(['post_id' => $ext, 'status' => 'published', 'url' => 'javascript:alert(1)']), $now]);
    (new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)))->processPending('2026-06-13T10:05:00Z');
    $url = (string) $db->one("SELECT external_url FROM posts WHERE run_id=?", [$job['run_id']])['external_url'];

    return !str_contains($url, 'javascript:') && str_starts_with($url, 'https://');
})());
check('webhook ctl: oversized body → 413, nothing persisted', (static function () use ($basePath, $whSecret): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $big = str_repeat('x', 70000);

    return $ctl->handle($big, 'sig')->status() === 413 && (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events")['n'] === 0;
})());
check('webhook inbox: unmatched post id → process_error recorded, no crash', (static function () use ($basePath): bool {
    $db = migratedDb($basePath);
    $db->run("INSERT INTO webhook_events (source,external_event_id,payload_json,received_at) VALUES ('zernio','ev_orphan',?,?)", ['{"post_id":"zp_missing","status":"published"}', '2026-06-13T10:00:00Z']);
    $inbox = new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db));
    $inbox->processPending('2026-06-13T10:01:00Z');
    $row = $db->one("SELECT processed_at, process_error FROM webhook_events WHERE external_event_id='ev_orphan'");

    return $row['processed_at'] !== null && $row['process_error'] === 'post_not_found';
})());

echo "== Core: RateLimiter + webhook per-IP throttle (Phase 13) ==\n";

check('rate_limits: table + lookup index exist', (static function () use ($basePath): bool {
    $db = migratedDb($basePath);

    return (int) $db->one("SELECT COUNT(*) AS n FROM sqlite_master WHERE type='table' AND name='rate_limits'")['n'] === 1
        && (int) $db->one("SELECT COUNT(*) AS n FROM sqlite_master WHERE type='index' AND name='idx_rate_limits_lookup'")['n'] === 1;
})());
check('ratelimiter: blocks the (cap+1)th hit, isolates by ip + bucket', (static function () use ($basePath): bool {
    $db = migratedDb($basePath);
    $limiter = new \Kuyash\Core\RateLimiter($db, 2, 60, 86400, static fn (): int => 1_000_000);
    $a1 = $limiter->tooMany('webhook:zernio', '1.2.3.4'); // count 0 → false
    $a2 = $limiter->tooMany('webhook:zernio', '1.2.3.4'); // count 1 → false
    $a3 = $limiter->tooMany('webhook:zernio', '1.2.3.4'); // count 2 → BLOCKED
    $otherIp = $limiter->tooMany('webhook:zernio', '9.9.9.9'); // separate IP → false
    $otherBucket = $limiter->tooMany('other:bucket', '1.2.3.4'); // separate bucket → false

    return $a1 === false && $a2 === false && $a3 === true && $otherIp === false && $otherBucket === false;
})());
check('ratelimiter: a hit outside the trailing window no longer counts', (static function () use ($basePath): bool {
    $db = migratedDb($basePath);
    $t = 1_000_000;
    $limiter = new \Kuyash\Core\RateLimiter($db, 1, 60, 86400, static function () use (&$t): int { return $t; });
    $limiter->tooMany('b', 'ip'); // hit at t
    $t += 120;                    // advance past the 60s window
    $blocked = $limiter->tooMany('b', 'ip'); // prior hit aged out → false

    return $blocked === false;
})());
check('webhook ctl: per-IP rate limit → 429 once the cap is exceeded (other IPs unaffected)', (static function () use ($basePath, $whSecret): bool {
    $db = migratedDb($basePath);
    $limiter = new \Kuyash\Core\RateLimiter($db, 2, 60); // 2 / minute
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret, $limiter);
    $body = '{"event_id":"ev_rl","status":"published"}';
    $s1 = $ctl->handle($body, 'badsig', '5.5.5.5')->status(); // counts a hit → 401 (bad sig)
    $s2 = $ctl->handle($body, 'badsig', '5.5.5.5')->status(); // 401
    $s3 = $ctl->handle($body, 'badsig', '5.5.5.5')->status(); // over cap → 429
    $other = $ctl->handle($body, 'badsig', '6.6.6.6')->status(); // different IP → 401

    return $s1 === 401 && $s2 === 401 && $s3 === 429 && $other === 401;
})());

echo "== Publish: reconciliation (lost-webhook recovery) ==\n";

check('reconciler: stale in-flight post polled → converges to published', (static function () use ($mkPublishJob, $mkExec, $connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'rc1@x.com', $argonHash, 'RC1');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@async', $now);
    $job = $mkPublishJob($db, $ws, $u, $now);
    $mkExec($db, $now)->execute($job, []); // accepted, updated_at = now
    $rec = new Reconciler(new PostRepository($db), new MockPublishProvider(), new \Kuyash\Workflow\EventLog($db));
    $fresh = $rec->sweep($now); // post is fresh → not swept
    $converged = $rec->sweep('2026-06-13T10:30:00Z'); // 30 min later → stale → polled

    return $fresh === 0 && $converged === 1
        && $db->one("SELECT status FROM posts WHERE run_id=?", [$job['run_id']])['status'] === 'published'
        && (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key='publish.reconciled'")['n'] === 1;
})());

echo "== Publish: PublishCounter (unified per-account cap source) ==\n";

check('counter: per-account vs workspace-wide published-today', (static function () use ($connect, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'cnt@x.com', $argonHash, 'CNT');
    $now = '2026-06-13T10:00:00Z';
    $a1 = $connect($db, $ws, 'instagram', '@a1', $now);
    $a2 = $connect($db, $ws, 'tiktok', '@a2', $now);
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$ws, $wf, $u, $now, $now]);
    $run = $db->lastInsertId();
    $ins = static fn (int $acc, string $k) => $db->run("INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,posted_at,created_at,updated_at) VALUES (?,?,?,'instagram','published',?,?,?,?)", [$ws, $run, $acc, $k, $now, $now, $now]);
    $ins($a1, 'k1');
    $ins($a1, 'k2');
    $ins($a2, 'k3');
    $c = new PublishCounter($db);

    return $c->publishedToday($ws, $now, $a1) === 2 && $c->publishedToday($ws, $now, $a2) === 1
        && $c->publishedToday($ws, $now) === 3 && $c->publishedToday($ws, '2026-06-14T10:00:00Z', $a1) === 0;
})());

echo "== Publish: scheduling (defer publish via run_after) ==\n";

$schDb = migratedDb($basePath);
[$schUser, $schWs] = seedUser($schDb, 'sch@x.com', $argonHash, 'SCH WS');
$schNow = '2026-06-13T10:00:00Z';
$_SESSION = [];
$schCtx = new WorkspaceContext($schDb);
$schCtx->set($schWs);
$schWfRepo = new \Kuyash\Workflow\WorkflowRepository($schDb, new WorkflowValidator());
$schWfRepo->ensureDefaults($schCtx);
$schDistWf = $schWfRepo->listFor($schCtx)[1]['id'];
[$schEngine, $schWorker] = makeRig($schDb, new MockExecutor(), $schNow);
$connect($schDb, $schWs, 'instagram', '@sched', $schNow);
$schAsset = seedReadyVideo($schDb, $schWs, 'sched clip');
$schRun = $schEngine->startRun($schCtx, $schDistWf, $schAsset, $schUser);
while ($schWorker->tick()) {
}
$schReview = (new JobRepository($schDb))->awaitingApproval($schCtx)[0]['id'];
$schEngine->approve($schCtx, $schReview, $schUser, 'sch@x.com', '2026-06-13T11:00:00Z'); // 1h future
while ($schWorker->tick()) {
}
check('schedule: approval stores runs.publish_after', $schDb->one("SELECT publish_after FROM runs WHERE id=?", [$schRun])['publish_after'] === '2026-06-13T11:00:00Z');
check('schedule: publish job is queued in the future, NOT yet claimed', (static function () use ($schDb, $schRun, $schNow): bool {
    $j = $schDb->one("SELECT status, run_after FROM jobs WHERE run_id=? AND type='publish'", [$schRun]);

    return $j !== null && $j['status'] === 'queued' && $j['run_after'] === '2026-06-13T11:00:00Z' && $j['run_after'] > $schNow
        && (int) $schDb->one("SELECT COUNT(*) AS n FROM posts WHERE run_id=?", [$schRun])['n'] === 0;
})());
$schNow = '2026-06-13T12:00:00Z'; // advance past the scheduled time
while ($schWorker->tick()) {
}
check('schedule: once due, the publish fires and the post records scheduled_for', (static function () use ($schDb, $schRun): bool {
    $post = $schDb->one("SELECT status, scheduled_for FROM posts WHERE run_id=?", [$schRun]);

    return $post !== null && $post['status'] === 'published' && $post['scheduled_for'] === '2026-06-13T11:00:00Z'
        && $schDb->one("SELECT status FROM runs WHERE id=?", [$schRun])['status'] === 'completed';
})());
check('schedule: a PAST scheduled time is ignored (publish immediate)', (static function () use ($schDb, $schWs, $schUser, $basePath, $argonHash, $connect): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'sch2@x.com', $argonHash, 'SCH2');
    $now = '2026-06-13T10:00:00Z';
    $_SESSION = [];
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $wfRepo = new \Kuyash\Workflow\WorkflowRepository($db, new WorkflowValidator());
    $wfRepo->ensureDefaults($ctx);
    $distWf = $wfRepo->listFor($ctx)[1]['id'];
    [$eng, $wrk] = makeRig($db, new MockExecutor(), $now);
    $connect($db, $ws, 'instagram', '@imm', $now);
    $asset = seedReadyVideo($db, $ws, 'imm clip');
    $run = $eng->startRun($ctx, $distWf, $asset, $u);
    while ($wrk->tick()) {
    }
    $review = (new JobRepository($db))->awaitingApproval($ctx)[0]['id'];
    $eng->approve($ctx, $review, $u, 'sch2@x.com', '2020-01-01T00:00:00Z'); // past → ignored
    while ($wrk->tick()) {
    }

    return $db->one("SELECT publish_after FROM runs WHERE id=?", [$run])['publish_after'] === null
        && $db->one("SELECT status FROM posts WHERE run_id=?", [$run])['status'] === 'published';
})());
$_SESSION = [];

echo "== Publish: end-to-end auto run publishes to a connected account ==\n";

$peDb = migratedDb($basePath);
[$peUser, $peWs] = seedUser($peDb, 'pe@x.com', $argonHash, 'PE WS');
$_SESSION = [];
$peCtx = new WorkspaceContext($peDb);
$peCtx->set($peWs);
$peWfRepo = new \Kuyash\Workflow\WorkflowRepository($peDb, new WorkflowValidator());
$peWfRepo->ensureDefaults($peCtx);
$peDistWf = $peWfRepo->listFor($peCtx)[1]['id'];
(new WorkspaceSettings($peDb))->setApprovalMode($peWs, 'auto');
$peNow = '2026-06-13T10:00:00Z';
[$peEngine, $peWorker] = makeRig($peDb, new MockExecutor(), $peNow, null, true);
$connect($peDb, $peWs, 'instagram', '@e2e', $peNow);
$peAsset = seedReadyVideo($peDb, $peWs, 'PE clip');
$peRun = $peEngine->startRun($peCtx, $peDistWf, $peAsset, $peUser);
while ($peWorker->tick()) {
}
check('publish e2e: auto distribution run creates a published post for the connected account', (static function () use ($peDb, $peRun): bool {
    $post = $peDb->one("SELECT * FROM posts WHERE run_id=?", [$peRun]);
    $run = $peDb->one("SELECT status FROM runs WHERE id=?", [$peRun]);

    return $post !== null && $post['status'] === 'published' && $post['external_post_id'] !== null
        && $run['status'] === 'completed';
})());
check('publish e2e: post is tenant-scoped — another workspace sees none of it', (static function () use ($peDb, $peRun, $argonHash): bool {
    $_SESSION = [];
    [$oU, $oWs] = seedUser($peDb, 'other@x.com', $argonHash, 'OTHER WS');
    $ctx = new WorkspaceContext($peDb);
    $ctx->set($oWs);

    return (new PostRepository($peDb))->forRun($ctx, $peRun) === [];
})());
$_SESSION = [];

echo "== Publish: AccountRepository + AccountsController ==\n";

$acDb = migratedDb($basePath);
[$acUser, $acWs] = seedUser($acDb, 'ac@x.com', $argonHash, 'AC WS');
[$acUserB, $acWsB] = seedUser($acDb, 'acb@x.com', $argonHash, 'AC WS B');
$_SESSION = [];
$acCtx = new WorkspaceContext($acDb);
$acCtx->set($acWs);
$acRepo = new AccountRepository($acDb);
$acNow = '2026-06-13T10:00:00Z';

check('account repo: connect stores reference + ok health, NO token', (static function () use ($acRepo, $acCtx, $acDb, $acWs, $acNow): bool {
    $id = $acRepo->connect($acCtx, 'instagram', '@me', 'zacct_ref', $acNow);
    $row = $acDb->one("SELECT * FROM accounts WHERE id=?", [$id]);

    return $row['status'] === 'connected' && $row['health'] === 'ok' && $row['external_ref'] === 'zacct_ref'
        && $row['connected_at'] === $acNow && (int) $row['workspace_id'] === $acWs;
})());
check('account repo: setDefaultReference rejects a cross-tenant asset', (static function () use ($acRepo, $acCtx, $acDb, $acWsB): bool {
    $id = $acRepo->connectedFor($acDb->one("SELECT workspace_id FROM accounts LIMIT 1")['workspace_id'])[0]['id'];
    $foreign = seedReadyVideo($acDb, $acWsB, 'foreign');

    return $acRepo->setDefaultReference($acCtx, (int) $id, $foreign) === false;
})());

$acAuthAsset = seedReadyVideo($acDb, $acWs, 'my ref');
$acCtl = new AccountsController(
    $view, $acRepo, new PostRepository($acDb), new AssetRepository($acDb), new PublishCounter($acDb),
    new WorkspaceSettings($acDb), $acCtx, new Csrf(), new Flash(),
);
check('accounts ctl: index lists accounts + connect buttons + next-scheduled line', (static function () use ($acCtl): bool {
    $body = $acCtl->index()->body();

    return str_contains($body, '@me') && str_contains($body, 'Connect instagram') && str_contains($body, 'Next scheduled');
})());
check('accounts ctl: connectStart renders authorize screen + sets state nonce', (static function () use ($acCtl): bool {
    $_SESSION = ['workspace_id' => $GLOBALS['acWs']];
    $body = $acCtl->connectStart(['platform' => 'tiktok'])->body();

    return str_contains($body, 'Authorize') && ($_SESSION['oauth_state'] ?? '') !== '' && str_contains($body, 'state');
})());
check('accounts ctl: callback with VALID state creates the account', (static function () use ($acCtl, $acDb, $acWs): bool {
    $_SESSION = ['workspace_id' => $acWs, 'oauth_state' => 'STATE123'];
    $_GET = ['platform' => 'youtube', 'state' => 'STATE123', 'handle' => '@chan', 'code' => 'mock'];
    $before = (int) $acDb->one("SELECT COUNT(*) AS n FROM accounts WHERE platform='youtube'")['n'];
    $r = $acCtl->connectCallback();
    $_GET = [];

    return $r->status() === 303 && (int) $acDb->one("SELECT COUNT(*) AS n FROM accounts WHERE platform='youtube'")['n'] === $before + 1;
})());
check('accounts ctl: callback with BAD state is rejected (no account, error flash)', (static function () use ($acCtl, $acDb): bool {
    $_SESSION = ['workspace_id' => $GLOBALS['acWs'], 'oauth_state' => 'GOOD'];
    $_GET = ['platform' => 'tiktok', 'state' => 'FORGED', 'code' => 'mock'];
    $before = (int) $acDb->one("SELECT COUNT(*) AS n FROM accounts WHERE platform='tiktok'")['n'];
    $r = $acCtl->connectCallback();
    $_GET = [];

    return $r->status() === 303 && (int) $acDb->one("SELECT COUNT(*) AS n FROM accounts WHERE platform='tiktok'")['n'] === $before;
})());
check('accounts ctl: disconnect flips status; cross-tenant disconnect is denied', (static function () use ($acCtl, $acRepo, $acCtx, $acDb, $acWsB, $argonHash, $acNow): bool {
    $_SESSION = ['workspace_id' => $GLOBALS['acWs']];
    $mine = $acRepo->connect($acCtx, 'instagram', '@todrop', 'zacct_d', $acNow);
    $acCtl->disconnect(['id' => (string) $mine]);
    $dropped = $acDb->one("SELECT status FROM accounts WHERE id=?", [$mine])['status'] === 'disconnected';

    // an account owned by workspace B must be untouched by A's controller
    $bCtx = new WorkspaceContext($acDb);
    $_SESSION = ['workspace_id' => $acWsB];
    $bCtx->set($acWsB);
    $bId = (new AccountRepository($acDb))->connect($bCtx, 'tiktok', '@bacct', 'zacct_b', $acNow);
    $_SESSION = ['workspace_id' => $GLOBALS['acWs']];
    $acCtl->disconnect(['id' => (string) $bId]);

    return $dropped && $acDb->one("SELECT status FROM accounts WHERE id=?", [$bId])['status'] === 'connected';
})());
$_SESSION = [];

/* ================== Phase 11: Usage, Costs & Credit Ledger ================== */

echo "== 0009 schema: usage ledger (usage_events + credit_transactions) ==\n";

$ulDb = migratedDb($basePath);
[$ulUser, $ulWs] = seedUser($ulDb, 'ul@example.com', $argonHash, 'UL WS');
$ulNow = '2026-06-12T12:00:00Z';

// minimal workflow + run + job (FKs: usage_events.job_id / run_id reference real rows)
$ulSeedJob = static function (Database $db, int $ws, int $user, string $now, string $type = 'script_draft'): array {
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$ws, $wf, $user, $now, $now]);
    $run = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, run_after, created_at) VALUES (?, ?, 'SCRIPT', 3, ?, 'processing', ?, ?)", [$ws, $run, $type, $now, $now]);

    return ['run_id' => $run, 'id' => $db->lastInsertId(), 'workspace_id' => $ws, 'type' => $type];
};

$ulJob = $ulSeedJob($ulDb, $ulWs, $ulUser, $ulNow);

check('0009: usage_events category CHECK rejects an unknown category', throws(static fn () => $ulDb->run(
    "INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', 'bogus', 5, ?)",
    [$ulWs, $ulJob['id'], $ulNow],
), PDOException::class));
check('0009: usage_events cost_cents CHECK rejects a negative value', throws(static fn () => $ulDb->run(
    "INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', 'ai_text', -1, ?)",
    [$ulWs, $ulJob['id'], $ulNow],
), PDOException::class));
check('0009: usage_events accepts a valid row', (static function () use ($ulDb, $ulWs, $ulJob, $ulNow): bool {
    $ulDb->run("INSERT INTO usage_events (workspace_id, run_id, job_id, provider, category, unit_type, cost_cents, created_at) VALUES (?, ?, ?, 'openai', 'ai_text', 'tokens', 7, ?)", [$ulWs, $ulJob['run_id'], $ulJob['id'], $ulNow]);

    return (int) $ulDb->one('SELECT cost_cents AS c FROM usage_events WHERE job_id = ?', [$ulJob['id']])['c'] === 7;
})());
check('0009: usage_events UNIQUE(job_id) blocks a second row for the same job', throws(static fn () => $ulDb->run(
    "INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', 'ai_text', 7, ?)",
    [$ulWs, $ulJob['id'], $ulNow],
), PDOException::class));
check('0009: credit_transactions type CHECK rejects an unknown type', throws(static fn () => $ulDb->run(
    "INSERT INTO credit_transactions (workspace_id, type, amount_cents, created_at) VALUES (?, 'bogus', 100, ?)",
    [$ulWs, $ulNow],
), PDOException::class));
check('0009: credit_transactions partial UNIQUE blocks two spends for one job (grants OK)', (static function () use ($ulDb, $ulWs, $ulJob, $ulNow): bool {
    $ulDb->run("INSERT INTO credit_transactions (workspace_id, type, amount_cents, ref_job_id, created_at) VALUES (?, 'spend', -7, ?, ?)", [$ulWs, $ulJob['id'], $ulNow]);
    $secondSpend = throws(static fn () => $ulDb->run("INSERT INTO credit_transactions (workspace_id, type, amount_cents, ref_job_id, created_at) VALUES (?, 'spend', -7, ?, ?)", [$ulWs, $ulJob['id'], $ulNow]), PDOException::class);
    // two grants (ref_job_id NULL) are NOT covered by the partial index → allowed
    $ulDb->run("INSERT INTO credit_transactions (workspace_id, type, amount_cents, created_at) VALUES (?, 'grant', 100, ?)", [$ulWs, $ulNow]);
    $ulDb->run("INSERT INTO credit_transactions (workspace_id, type, amount_cents, created_at) VALUES (?, 'grant', 100, ?)", [$ulWs, $ulNow]);

    return $secondSpend;
})());

echo "== Core: Format::cents (money formatting) ==\n";
check('Format::cents formats cents → dollars, signs, null', Format::cents(7) === '$0.07'
    && Format::cents(1250) === '$12.50' && Format::cents(-120) === '-$1.20'
    && Format::cents(0) === '$0.00' && Format::cents(null) === '—');

echo "== Usage: CostEstimator (deterministic per-node config) ==\n";

$ceCfg = require $basePath . '/config/usage.php';
$estimator = new CostEstimator($ceCfg);
$fullEst = $estimator->estimateRun('full', \Kuyash\Workflow\Nodes::defaultNodes('full'));
check('estimator: full template total is deterministic (idea1+script2+caption1+hashtag1+tts5 = 10c)', $fullEst['total_cents'] === 10);
check('estimator: full template groups ai_text(5) + tts(5)', ($fullEst['by_category']['ai_text'] ?? 0) === 5 && ($fullEst['by_category']['tts'] ?? 0) === 5);
$distEst = $estimator->estimateRun('distribution', \Kuyash\Workflow\Nodes::defaultNodes('distribution'));
check('estimator: distribution template total (caption1+hashtag1 = 2c, ai_text only)', $distEst['total_cents'] === 2 && ($distEst['by_category']['ai_text'] ?? 0) === 2);
check('estimator: empty nodes falls back to the template sequence', $estimator->estimateRun('full', [])['total_cents'] === 10);
check('estimator: accepts a bare node-id list', $estimator->estimateRun('full', ['SCRIPT'])['total_cents'] === 2);
// config consistency: every priced type must map to a schema-valid category, so a
// future estimable type can never be silently dropped by the recorder (truthfulness)
check('config/usage: every priced type maps to a valid ledger category', (static function () use ($ceCfg): bool {
    $allowed = ['ai_text', 'tts', 'stock', 'publish', 'ai_video'];
    foreach ($ceCfg['estimate_cents'] as $type => $cents) {
        if ($cents > 0 && !isset($ceCfg['categories'][$type])) {
            return false;
        }
    }
    foreach ($ceCfg['categories'] as $cat) {
        if (!in_array($cat, $allowed, true)) {
            return false;
        }
    }

    return true;
})());

echo "== Usage: UsageRecorder (truthful spend, idempotent) ==\n";

$urDb = migratedDb($basePath);
[$urUser, $urWs] = seedUser($urDb, 'ur@example.com', $argonHash, 'UR WS');
$urNow = '2026-06-12T09:00:00Z';
$urCfg = require $basePath . '/config/usage.php';
$recorder = new UsageRecorder($urDb, $urCfg);

$urJob = $ulSeedJob($urDb, $urWs, $urUser, $urNow, 'script_draft');
$recorder->record($urJob, JobResult::awaitingApproval(['x' => 1], 'openai', 7), $urNow);
check('recorder: real cost writes exactly one usage_event (ai_text, tokens)', (static function () use ($urDb, $urJob): bool {
    $rows = $urDb->all('SELECT category, provider, cost_cents, unit_type FROM usage_events WHERE job_id = ?', [$urJob['id']]);

    return count($rows) === 1 && $rows[0]['category'] === 'ai_text' && (int) $rows[0]['cost_cents'] === 7
        && $rows[0]['provider'] === 'openai' && $rows[0]['unit_type'] === 'tokens';
})());
check('recorder: real cost mirrors a negative spend into credit_transactions', (static function () use ($urDb, $urJob): bool {
    $tx = $urDb->one("SELECT amount_cents FROM credit_transactions WHERE ref_job_id = ? AND type = 'spend'", [$urJob['id']]);

    return $tx !== null && (int) $tx['amount_cents'] === -7;
})());
check('recorder: idempotent — re-recording the same job adds no second row', (static function () use ($recorder, $urDb, $urJob, $urNow): bool {
    $recorder->record($urJob, JobResult::awaitingApproval(['x' => 1], 'openai', 7), $urNow);

    return (int) $urDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$urJob['id']])['n'] === 1
        && (int) $urDb->one('SELECT COUNT(*) AS n FROM credit_transactions WHERE ref_job_id = ?', [$urJob['id']])['n'] === 1;
})());
$urJob2 = $ulSeedJob($urDb, $urWs, $urUser, $urNow, 'script_draft');
$recorder->record($urJob2, JobResult::ready(['cached' => true], 'mock', null), $urNow);
check('recorder: null cost (mock / cache hit) writes NOTHING (truthful)',
    (int) $urDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$urJob2['id']])['n'] === 0);
$urJob3 = $ulSeedJob($urDb, $urWs, $urUser, $urNow, 'assembly');
$recorder->record($urJob3, JobResult::ready([], 'ffmpeg', 5), $urNow);
check('recorder: unmapped type (assembly) records nothing even with a cost',
    (int) $urDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$urJob3['id']])['n'] === 0);
$urJob4 = $ulSeedJob($urDb, $urWs, $urUser, $urNow, 'tts');
$recorder->record($urJob4, JobResult::ready([], 'openai', 3), $urNow);
check('recorder: tts job → tts category, chars unit', (static function () use ($urDb, $urJob4): bool {
    $r = $urDb->one('SELECT category, unit_type FROM usage_events WHERE job_id = ?', [$urJob4['id']]);

    return $r !== null && $r['category'] === 'tts' && $r['unit_type'] === 'chars';
})());

echo "== Usage: CreditLedger (balance, grant, adjust, isolation) ==\n";

$clDb = migratedDb($basePath);
[$clU, $clWs] = seedUser($clDb, 'cl@example.com', $argonHash, 'CL WS');
[$clU2, $clWs2] = seedUser($clDb, 'cl2@example.com', $argonHash, 'CL WS2');
$ledger = new CreditLedger($clDb);
$ledger->grant($clWs, 5000, 'seed', '2026-06-01T00:00:00Z');
$ledger->adjust($clWs, -250, 'correction', '2026-06-02T00:00:00Z');
check('ledger: balance = SUM(amount_cents)', $ledger->balanceCents($clWs) === 4750);
check('ledger: totals split grant / spend / adjust', (static function () use ($ledger, $clWs): bool {
    $t = $ledger->totals($clWs);

    return $t['granted'] === 5000 && $t['adjusted'] === -250 && $t['spent'] === 0;
})());
check('ledger: recent is newest-first', (static function () use ($ledger, $clWs): bool {
    $r = $ledger->recent($clWs, 5);

    return count($r) === 2 && $r[0]['type'] === 'adjust';
})());
check('ledger: tenant isolation — another workspace balance is 0', $ledger->balanceCents($clWs2) === 0);
check('ledger: grant normalizes a negative arg to a positive grant', (static function () use ($ledger, $clWs2): bool {
    $ledger->grant($clWs2, -1000, 'abs');

    return $ledger->balanceCents($clWs2) === 1000;
})());

echo "== Usage: UsageRepository (MTD, by-category, isolation) ==\n";

$repDb = migratedDb($basePath);
[$repU, $repWs] = seedUser($repDb, 'rep@example.com', $argonHash, 'REP WS');
[$repU2, $repWs2] = seedUser($repDb, 'rep2@example.com', $argonHash, 'REP WS2');
$repNow = '2026-06-15T12:00:00Z';
$usageRepo = new UsageRepository($repDb);
$insEv = static function (Database $db, int $ws, int $job, string $cat, int $cents, string $at): void {
    $db->run("INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', ?, ?, ?)", [$ws, $job, $cat, $cents, $at]);
};
$rj1 = $ulSeedJob($repDb, $repWs, $repU, $repNow, 'script_draft');
$rj2 = $ulSeedJob($repDb, $repWs, $repU, $repNow, 'tts');
$rj3 = $ulSeedJob($repDb, $repWs, $repU, $repNow, 'script_draft');
$rj4 = $ulSeedJob($repDb, $repWs2, $repU2, $repNow, 'script_draft');
$insEv($repDb, $repWs, $rj1['id'], 'ai_text', 7, '2026-06-10T00:00:00Z');
$insEv($repDb, $repWs, $rj2['id'], 'tts', 5, '2026-06-11T00:00:00Z');
$insEv($repDb, $repWs, $rj3['id'], 'ai_text', 100, '2026-05-30T00:00:00Z'); // last month → excluded
$insEv($repDb, $repWs2, $rj4['id'], 'ai_text', 999, '2026-06-12T00:00:00Z'); // other ws
check('repo: MTD spend sums only this month + this workspace', $usageRepo->monthToDateSpendCents($repWs, $repNow) === 12);
check('repo: MTD by-category grouping', (static function () use ($usageRepo, $repWs, $repNow): bool {
    $b = $usageRepo->monthToDateByCategory($repWs, $repNow);

    return ($b['ai_text'] ?? 0) === 7 && ($b['tts'] ?? 0) === 5;
})());
check('repo: tenant isolation — each workspace sees only its own MTD', $usageRepo->monthToDateSpendCents($repWs2, $repNow) === 999);
check('repo: monthStart derives the first of the UTC month', UsageRepository::monthStart('2026-06-15T12:00:00Z') === '2026-06-01T00:00:00Z');
check('repo: recentCharges newest-first + workspace-scoped', count($usageRepo->recentCharges($repWs, 10)) === 3);
check('repo: event count is month + workspace scoped', $usageRepo->monthToDateEventCount($repWs, $repNow) === 2);

echo "== Usage: AutoApprovalGate MTD parity (usage_events vs old jobs.cost_cents SUM) ==\n";

$parDb = migratedDb($basePath);
[$parU, $parWs] = seedUser($parDb, 'par@example.com', $argonHash, 'PAR WS');
$parNow = '2026-06-12T12:00:00Z';
$parGate = new AutoApprovalGate($parDb, new EventLog($parDb), new WorkspaceSettings($parDb), new QualityScore($parDb, static fn (): string => $parNow), new UsageRepository($parDb));
$pj1 = $ulSeedJob($parDb, $parWs, $parU, $parNow, 'script_draft');
$pj2 = $ulSeedJob($parDb, $parWs, $parU, $parNow, 'tts');
// jobs.cost_cents (the OLD source) deliberately holds a DIFFERENT, larger total
// (999c) than usage_events (the NEW source, 12c). The assertion only passes if
// the gate truly reads usage_events and ignores the stale jobs.cost_cents rollup
// — a regression that still summed jobs.cost_cents would return 999, not 12.
$parDb->run('UPDATE jobs SET cost_cents = 990 WHERE id = ?', [$pj1['id']]);
$parDb->run('UPDATE jobs SET cost_cents = 9 WHERE id = ?', [$pj2['id']]);
$parDb->run("INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', 'ai_text', 7, ?)", [$parWs, $pj1['id'], $parNow]);
$parDb->run("INSERT INTO usage_events (workspace_id, job_id, provider, category, cost_cents, created_at) VALUES (?, ?, 'openai', 'tts', 5, ?)", [$parWs, $pj2['id'], $parNow]);
$oldJobsSum = (int) $parDb->one("SELECT COALESCE(SUM(cost_cents), 0) AS s FROM jobs WHERE workspace_id = ? AND cost_cents IS NOT NULL AND created_at >= '2026-06-01T00:00:00Z'", [$parWs])['s'];
check('parity: gate MTD reads usage_events (12c), NOT the stale jobs.cost_cents rollup (999c)', $parGate->monthToDateSpendCents($parWs, $parNow) === 12 && $oldJobsSum === 999);

echo "== Usage: pre-flight budget gate (hard block) in Engine::startRun ==\n";

$pfDb = migratedDb($basePath);
[$pfUser, $pfWs] = seedUser($pfDb, 'pf@example.com', $argonHash, 'PF WS');
$pfCtx = new WorkspaceContext($pfDb);
$_SESSION = ['workspace_id' => $pfWs];
$pfCfg = require $basePath . '/config/usage.php';
$pfSettings = new WorkspaceSettings($pfDb);
$pfEvents = new EventLog($pfDb);
$pfPreflight = new PreflightGate(new CostEstimator($pfCfg), new UsageRepository($pfDb), $pfSettings, $pfEvents);
$pfEngine = new Engine($pfDb, $pfEvents, new WorkflowValidator(), static fn (): string => '2026-06-12T12:00:00Z', null, null, $pfPreflight);
$pfDb->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'Full', 'full', ?, '2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z')", [$pfWs, json_encode(\Kuyash\Workflow\Nodes::defaultNodes('full'))]);
$pfWf = $pfDb->lastInsertId();

check('preflight: no cap → run starts (never blocks)', $pfEngine->startRun($pfCtx, $pfWf, null, $pfUser) > 0);
$pfSettings->setBudgetCapCents($pfWs, 5); // remaining 5c < full-run estimate 10c
check('preflight: estimate over remaining → BudgetExceededException', throws(static fn () => $pfEngine->startRun($pfCtx, $pfWf, null, $pfUser), BudgetExceededException::class));
check('preflight: a blocked run writes a guardrail.preflight_block event',
    $pfDb->one("SELECT key FROM events WHERE workspace_id = ? AND key = 'guardrail.preflight_block'", [$pfWs]) !== null);
$pfRunsBefore = (int) $pfDb->one('SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$pfWs])['n'];
check('preflight: a blocked run leaves NO new run row', (static function () use ($pfEngine, $pfCtx, $pfWf, $pfUser, $pfDb, $pfWs, $pfRunsBefore): bool {
    try {
        $pfEngine->startRun($pfCtx, $pfWf, null, $pfUser);
    } catch (BudgetExceededException) {
        // expected
    }

    return (int) $pfDb->one('SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$pfWs])['n'] === $pfRunsBefore;
})());
check('preflight: exception carries estimate / remaining / cap + flash key', (static function () use ($pfEngine, $pfCtx, $pfWf, $pfUser): bool {
    try {
        $pfEngine->startRun($pfCtx, $pfWf, null, $pfUser);
    } catch (BudgetExceededException $e) {
        return $e->estimateCents === 10 && $e->remainingCents === 5 && $e->capCents === 5 && $e->messageKey === 'run.budget_exceeded';
    }

    return false;
})());
$pfSettings->setBudgetCapCents($pfWs, 100); // remaining 100c > estimate 10c
check('preflight: cap above estimate → run starts again', $pfEngine->startRun($pfCtx, $pfWf, null, $pfUser) > 0);
$_SESSION = [];

echo "== Usage: recording via Engine::finalize (real cost vs mock) ==\n";

$feDb = migratedDb($basePath);
[$feU, $feWs] = seedUser($feDb, 'fe@example.com', $argonHash, 'FE WS');
$feNow = '2026-06-12T12:00:00Z';
$feRecorder = new UsageRecorder($feDb, require $basePath . '/config/usage.php');
$feEngine = new Engine($feDb, new EventLog($feDb), new WorkflowValidator(), static fn (): string => $feNow, null, $feRecorder, null);
$feSeedClaimed = static function (Database $db, int $ws, int $user, string $now, string $type, string $worker): array {
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'WF', 'full', '[]', ?, ?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$ws, $wf, $user, $now, $now]);
    $run = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, worker_id, run_after, created_at) VALUES (?, ?, 'CAPTION', 7, ?, 'processing', ?, ?, ?)", [$ws, $run, $type, $worker, $now, $now]);

    return $db->one('SELECT * FROM jobs WHERE id = ?', [$db->lastInsertId()]);
};
$feJob = $feSeedClaimed($feDb, $feWs, $feU, $feNow, 'caption_generation', 'w1');
$feEngine->finalize($feJob, JobResult::ready(['caption' => 'x'], 'openai', 4));
check('finalize: real-cost job records one usage_event (4c) + a -4c spend', (static function () use ($feDb, $feJob): bool {
    return (int) $feDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$feJob['id']])['n'] === 1
        && (int) $feDb->one('SELECT cost_cents AS c FROM usage_events WHERE job_id = ?', [$feJob['id']])['c'] === 4
        && (int) $feDb->one("SELECT amount_cents AS a FROM credit_transactions WHERE ref_job_id = ? AND type = 'spend'", [$feJob['id']])['a'] === -4;
})());
$feJob2 = $feSeedClaimed($feDb, $feWs, $feU, $feNow, 'caption_generation', 'w1');
$feEngine->finalize($feJob2, JobResult::ready(['caption' => 'x'], 'mock', null));
check('finalize: mock (null cost) job records NO usage_event (truthful)',
    (int) $feDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$feJob2['id']])['n'] === 0);
// the awaiting path (a paused real script already spent money before approval):
// drive it through Engine::finalize, not the recorder directly
$feJob3 = $feSeedClaimed($feDb, $feWs, $feU, $feNow, 'script_draft', 'w1');
$feEngine->finalize($feJob3, JobResult::awaitingApproval(['script' => 'x'], 'openai', 6));
check('finalize: awaiting (paused script) ledgers its already-incurred spend via the Engine', (static function () use ($feDb, $feJob3): bool {
    return (int) $feDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$feJob3['id']])['n'] === 1
        && (int) $feDb->one('SELECT cost_cents AS c FROM usage_events WHERE job_id = ?', [$feJob3['id']])['c'] === 6
        && (string) $feDb->one('SELECT status AS s FROM jobs WHERE id = ?', [$feJob3['id']])['s'] === 'awaiting_approval'
        && (int) $feDb->one("SELECT amount_cents AS a FROM credit_transactions WHERE ref_job_id = ? AND type = 'spend'", [$feJob3['id']])['a'] === -6;
})());

echo "== Usage: recorder never rolls back an otherwise-successful finalize (Phase 13 regression) ==\n";

// pin Phase 11 follow-up #3: a ledger collision (a job_id already recorded) must
// NOT roll back the finalize transaction it runs inside — the job still commits.
$rbJob = $feSeedClaimed($feDb, $feWs, $feU, $feNow, 'caption_generation', 'w1');
// pre-seed a usage_events row for this job_id so the recorder's INSERT OR IGNORE
// is a no-op (the historical worry: this throwing/rolling back the finalize)
$feDb->run(
    "INSERT INTO usage_events (workspace_id, run_id, job_id, provider, category, unit_type, cost_cents, created_at)
     VALUES (?, ?, ?, 'openai', 'ai_text', 'tokens', 99, ?)",
    [$feWs, $rbJob['run_id'], $rbJob['id'], $feNow],
);
$feEngine->finalize($rbJob, JobResult::ready(['caption' => 'x'], 'openai', 4));
check('recorder no-rollback: finalize still commits (job ready, cost_cents written) despite the ledger collision', (static function () use ($feDb, $rbJob): bool {
    $row = $feDb->one('SELECT status, cost_cents FROM jobs WHERE id = ?', [$rbJob['id']]);

    return (string) $row['status'] === 'ready' && (int) $row['cost_cents'] === 4;
})());
check('recorder no-rollback: the pre-existing usage row is preserved, no duplicate (INSERT OR IGNORE)', (static function () use ($feDb, $rbJob): bool {
    return (int) $feDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE job_id = ?', [$rbJob['id']])['n'] === 1
        && (int) $feDb->one('SELECT cost_cents AS c FROM usage_events WHERE job_id = ?', [$rbJob['id']])['c'] === 99;
})());

echo "== Usage: UsageController (live page, states, isolation) ==\n";

$ucDb = migratedDb($basePath);
[$ucU, $ucWs] = seedUser($ucDb, 'uc@example.com', $argonHash, 'UC WS');
$ucCtx = new WorkspaceContext($ucDb);
$_SESSION = ['auth_user_id' => $ucU, 'workspace_id' => $ucWs];
$ucCtx->set($ucWs);
$ucCtl = new UsageController(new View($basePath . '/templates'), new UsageRepository($ucDb), new CreditLedger($ucDb), new WorkspaceSettings($ucDb), $ucCtx, new Csrf(), new Flash());
check('usage ctl: empty state renders (no spend, no cap)', (static function () use ($ucCtl): bool {
    $body = $ucCtl->index()->body();

    return str_contains($body, 'Usage &amp; costs') && str_contains($body, 'No spend recorded this month')
        && str_contains($body, 'No monthly budget cap is set');
})());
(new WorkspaceSettings($ucDb))->setBudgetCapCents($ucWs, 1000); // $10 cap
$ucJob = $ulSeedJob($ucDb, $ucWs, $ucU, gmdate('Y-m-d\TH:i:s\Z'), 'script_draft');
$ucDb->run("INSERT INTO usage_events (workspace_id, run_id, job_id, provider, category, unit_type, cost_cents, created_at) VALUES (?, ?, ?, 'openai', 'ai_text', 'tokens', 250, ?)", [$ucWs, $ucJob['run_id'], $ucJob['id'], gmdate('Y-m-d\TH:i:s\Z')]);
(new CreditLedger($ucDb))->grant($ucWs, 5000, 'seed');
check('usage ctl: renders spend, cap, breakdown label + credit balance', (static function () use ($ucCtl): bool {
    $body = $ucCtl->index()->body();

    return str_contains($body, '$2.50') && str_contains($body, '$10.00') && str_contains($body, 'AI text')
        && str_contains($body, 'Credit balance') && str_contains($body, '$50.00');
})());
check('usage ctl: tenant-scoped — another workspace sees none of this spend', (static function () use ($basePath, $ucDb, $argonHash): bool {
    [, $ows] = seedUser($ucDb, 'ucother@example.com', $argonHash, 'UC OTHER');
    $octx = new WorkspaceContext($ucDb);
    $octx->set($ows);
    $body = (new UsageController(new View($basePath . '/templates'), new UsageRepository($ucDb), new CreditLedger($ucDb), new WorkspaceSettings($ucDb), $octx, new Csrf(), new Flash()))->index()->body();

    return str_contains($body, 'No spend recorded this month') && !str_contains($body, '$2.50');
})());
$_SESSION = [];

/* ===================== PHASE 12: Quick Create AI video ===================== */

/** Seed a ready PHOTO asset (with a real png on disk when ffmpeg is present). */
$seedReadyPhoto = static function (Database $db, int $ws, string $title = 'ref photo') use ($ffmpegBin, $TEST_MEDIA_ROOT, $mediaReady): int {
    $stored = bin2hex(random_bytes(16)) . '.png';
    $now = gmdate(NOW_ISO);
    $db->run(
        "INSERT INTO assets (workspace_id,kind,type,title,original_filename,stored_name,mime,size_bytes,sha256,width,height,aspect,tags,status,created_at,updated_at)
         VALUES (?, 'photo', 'own', ?, 'p.png', ?, 'image/png', 100, 'h', 400, 600, '2:3', '[]', 'ready', ?, ?)",
        [$ws, $title, $stored, $now, $now],
    );
    $id = $db->lastInsertId();
    if ($mediaReady) {
        $dir = "$TEST_MEDIA_ROOT/assets/$ws";
        if (!is_dir($dir)) { mkdir($dir, 0750, true); }
        $p = proc_open([$ffmpegBin, '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=teal:s=400x600:d=1', '-frames:v', '1', "$dir/$stored"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        if (is_resource($p)) { stream_get_contents($pp[1]); stream_get_contents($pp[2]); fclose($pp[1]); fclose($pp[2]); proc_close($p); }
    }

    return $id;
};

echo "== 0010 schema: quick_create template + workflows parent-table rebuild ==\n";

$qcDb = migratedDb($basePath);
[$qcUser, $qcWs] = seedUser($qcDb, 'qc@example.com', $argonHash, 'QC WS');
$qcNow = '2026-06-13T09:00:00Z';
$qcDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'F','full','[]',?,?)", [$qcWs, $qcNow, $qcNow]);
$qcWfId = $qcDb->lastInsertId();
$qcDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','running',?,?,?)", [$qcWs, $qcWfId, $qcUser, $qcNow, $qcNow]);
check('0010: workflows.template now accepts quick_create', (static function () use ($qcDb, $qcWs, $qcNow): bool {
    $qcDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'Q','quick_create','[]',?,?)", [$qcWs, $qcNow, $qcNow]);

    return true;
})());
check('0010: a bogus template is still rejected', throws(static fn () => $qcDb->run(
    "INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'X','bogus','[]',?,?)",
    [$qcWs, $qcNow, $qcNow],
), PDOException::class));
check('0010: rebuilt workflows keeps the runs FK intact (foreign_key_check clean + run resolves)',
    $qcDb->all('PRAGMA foreign_key_check') === []
    && (int) ($qcDb->one('SELECT w.id AS i FROM runs r JOIN workflows w ON w.id = r.workflow_id WHERE r.workflow_id = ?', [$qcWfId])['i'] ?? 0) === $qcWfId);

echo "== Migrator: FK-safe rebuild of a PARENT table that has child rows ==\n";
// the generic proof of the workflows trap: dropping a parent with child rows
// throws "FOREIGN KEY constraint failed" unless enforcement is OFF for the rewrite.
$fkDir = sys_get_temp_dir() . '/kuyash_mig_' . bin2hex(random_bytes(4));
@mkdir($fkDir, 0750, true);
file_put_contents("$fkDir/0001_seed.sql",
    "CREATE TABLE parent (id INTEGER PRIMARY KEY, label TEXT NOT NULL CHECK (label IN ('a','b')));\n"
    . "CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id));\n"
    . "INSERT INTO parent (id,label) VALUES (1,'a');\nINSERT INTO child (id,parent_id) VALUES (10,1);\n");
$fkDb = new Database(':memory:');
(new Migrator($fkDb, $fkDir))->migrate();
file_put_contents("$fkDir/0002_widen.sql",
    "CREATE TABLE parent_new (id INTEGER PRIMARY KEY, label TEXT NOT NULL CHECK (label IN ('a','b','c')));\n"
    . "INSERT INTO parent_new SELECT id,label FROM parent;\nDROP TABLE parent;\nALTER TABLE parent_new RENAME TO parent;\n");
$fkApplied = (new Migrator($fkDb, $fkDir))->migrate();
check('migrator: rebuilds a parent table with child rows + the FK survives',
    $fkApplied === ['0002_widen.sql']
    && $fkDb->all('PRAGMA foreign_key_check') === []
    && (int) ($fkDb->one('SELECT parent_id AS p FROM child WHERE id = 10')['p'] ?? 0) === 1);
check('migrator: enforcement is restored after the rebuild (orphan child rejected)',
    throws(static fn () => $fkDb->run('INSERT INTO child (id,parent_id) VALUES (11, 999)'), PDOException::class));
@unlink("$fkDir/0001_seed.sql"); @unlink("$fkDir/0002_widen.sql"); @rmdir($fkDir);

// the post-file foreign_key_check gate: a migration that orphans a row is a hard failure
$fkDir2 = sys_get_temp_dir() . '/kuyash_mig_' . bin2hex(random_bytes(4));
@mkdir($fkDir2, 0750, true);
file_put_contents("$fkDir2/0001_seed.sql",
    "CREATE TABLE parent (id INTEGER PRIMARY KEY);\nCREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id));\nINSERT INTO parent (id) VALUES (1);\n");
$fkDb2 = new Database(':memory:');
(new Migrator($fkDb2, $fkDir2))->migrate();
file_put_contents("$fkDir2/0002_orphan.sql", "INSERT INTO child (id,parent_id) VALUES (9, 999);\n");
check('migrator: foreign_key_check gate rejects an orphaning migration', throws(static fn () => (new Migrator($fkDb2, $fkDir2))->migrate(), RuntimeException::class));
@unlink("$fkDir2/0001_seed.sql"); @unlink("$fkDir2/0002_orphan.sql"); @rmdir($fkDir2);

echo "== Nodes: quick_create template + source-aware expand ==\n";

$qcTypes = ['ai_video', 'caption_generation', 'hashtag_generation', 'music_note', 'preview', 'compliance_check', 'render_review', 'final_render', 'publish'];
check('nodes: quick_create template resolves to QUICK_CREATE', Nodes::template('quick_create') === Nodes::QUICK_CREATE);
check('nodes: defaultNodes(quick_create) expands VISUALS(ai) → ai_video chain', array_column(Nodes::expand(Nodes::defaultNodes('quick_create')), 'type') === $qcTypes);
check('nodes: bare QUICK_CREATE (no settings) keeps VISUALS → asset_fetch (back-compat)',
    array_column(Nodes::expand(Nodes::QUICK_CREATE), 'type')[0] === 'asset_fetch');
check('nodes: expand is source-aware (stock → asset_fetch, ai → ai_video)', (static function (): bool {
    $stock = array_column(Nodes::expand([['node' => 'VISUALS', 'settings' => ['source' => 'stock']]]), 'type');
    $ai = array_column(Nodes::expand([['node' => 'VISUALS', 'settings' => ['source' => 'ai']]]), 'type');

    return $stock === ['asset_fetch'] && $ai === ['ai_video'];
})());
check('nodes: defaultNodes(quick_create) sets VISUALS source=ai + empty prompt', (static function (): bool {
    $n = Nodes::defaultNodes('quick_create');

    return ($n[0]['node'] ?? '') === 'VISUALS' && ($n[0]['settings']['source'] ?? '') === 'ai' && ($n[0]['settings']['prompt'] ?? null) === '';
})());
check('nodes: full/distribution chains unchanged by the source-aware expander',
    array_column(Nodes::expand(Nodes::defaultNodes('full')), 'type') === FULL_TYPES
    && array_column(Nodes::expand(Nodes::defaultNodes('distribution')), 'type') === DIST_TYPES);
check('nodes: ai_video defaults (timeout 600, no blind retry)', Nodes::timeoutFor('ai_video') === 600 && Nodes::maxRetriesFor('ai_video') === 1);
check('validator: default quick_create template passes', (new WorkflowValidator())->validate('quick_create', Nodes::defaultNodes('quick_create')) === []);

echo "== Usage: estimator + ai_video category (Quick Create) ==\n";
check('estimator: quick_create total = ai_video 700 + caption 1 + hashtag 1 = 702c', (static function () use ($basePath): bool {
    $est = (new CostEstimator(require $basePath . '/config/usage.php'))->estimateRun('quick_create', Nodes::defaultNodes('quick_create'));

    return $est['total_cents'] === 702 && ($est['by_category']['ai_video'] ?? 0) === 700 && ($est['by_category']['ai_text'] ?? 0) === 2;
})());
// a real ai_video cost records one ai_video usage_event via Engine::finalize (mock = none)
$qfDb = migratedDb($basePath);
[$qfU, $qfWs] = seedUser($qfDb, 'qf@example.com', $argonHash, 'QF WS');
$qfNow = '2026-06-13T09:00:00Z';
$qfEngine = new Engine($qfDb, new EventLog($qfDb), new WorkflowValidator(), static fn (): string => $qfNow, null, new UsageRecorder($qfDb, require $basePath . '/config/usage.php'), null);
$qfDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'Q','quick_create','[]',?,?)", [$qfWs, $qfNow, $qfNow]);
$qfWf = $qfDb->lastInsertId();
$qfDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'quick_create','[]','running',?,?,?)", [$qfWs, $qfWf, $qfU, $qfNow, $qfNow]);
$qfRun = $qfDb->lastInsertId();
$qfDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,worker_id,run_after,created_at) VALUES (?,?,'VISUALS',1,'ai_video','processing','w1',?,?)", [$qfWs, $qfRun, $qfNow, $qfNow]);
$qfJob = $qfDb->one('SELECT * FROM jobs WHERE id = ?', [$qfDb->lastInsertId()]);
$qfEngine->finalize($qfJob, JobResult::ready(['visual_ref' => 'cache:x', 'ai_label_required' => true], 'fal', 700));
check('finalize: a real ai_video cost records ONE ai_video usage_event (700c) + a -700c spend', (static function () use ($qfDb, $qfJob): bool {
    $ev = $qfDb->one('SELECT category, cost_cents FROM usage_events WHERE job_id = ?', [$qfJob['id']]);
    $spend = (int) ($qfDb->one("SELECT amount_cents AS a FROM credit_transactions WHERE ref_job_id = ? AND type = 'spend'", [$qfJob['id']])['a'] ?? 0);

    return $ev !== null && (string) $ev['category'] === 'ai_video' && (int) $ev['cost_cents'] === 700 && $spend === -700;
})());

echo "== Quick Create: VideoGenProvider (mock clip, sentinel, doc-gated real) ==\n";
check('videogen: real fal provider is DOC-GATED — throws before any HTTP',
    throws(static fn () => (new \Kuyash\Media\FalVideoGenProvider(new CurlHttpClient(), []))->generateFromImage('/x.png', 'p', 16.0, '/y.mp4'), \Kuyash\Media\VideoGenProviderException::class));
if (!$mediaReady) {
    echo "  (skipped ffmpeg-backed videogen/executor tests — ffmpeg unavailable)\n";
} else {
    $vgFf = new Ffmpeg($ffmpegBin, $ffprobeBin, 60);
    $vgDir = "$TEST_MEDIA_ROOT/vg";
    if (!is_dir($vgDir)) { mkdir($vgDir, 0750, true); }
    $vgPhoto = "$vgDir/p.png";
    $vgFf->run(['-f', 'lavfi', '-i', 'color=c=teal:s=400x600:d=1', '-frames:v', '1', $vgPhoto]);
    $vgProv = new \Kuyash\Media\MockVideoGenProvider($vgFf, 1080, 1920, 24);
    $vgOut = "$vgDir/clip.mp4";
    $vgRes = $vgProv->generateFromImage($vgPhoto, 'slow cinematic push-in', 16.0, $vgOut);
    check('videogen: mock produces a real 9:16 clip with null cost', is_file($vgOut) && filesize($vgOut) > 0
        && $vgRes->width === 1080 && $vgRes->height === 1920 && abs($vgRes->durationSeconds - 16.0) < 0.6
        && $vgRes->costCents === null && $vgRes->model === 'mock');
    check('videogen: the fail sentinel throws (testable failure path)',
        throws(static fn () => $vgProv->generateFromImage($vgPhoto, \Kuyash\Media\MockVideoGenProvider::FAIL_SENTINEL, 16.0, "$vgDir/x.mp4"), \Kuyash\Media\VideoGenProviderException::class));

    echo "== Quick Create: AiVideoExecutor (ai-label, draft render, cache reuse) ==\n";
    $axDb = migratedDb($basePath);
    [$axU, $axWs] = seedUser($axDb, 'ax@example.com', $argonHash, 'AX WS');
    $axNow = '2026-06-13T09:00:00Z';
    $axPhoto = $seedReadyPhoto($axDb, $axWs, 'ax face');
    $axNodes = Nodes::defaultNodes('quick_create');
    $axNodes[0]['settings']['prompt'] = 'gentle parallax zoom';
    $axDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'Q','quick_create',?,?,?)", [$axWs, json_encode($axNodes), $axNow, $axNow]);
    $axWf = $axDb->lastInsertId();
    $axDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,reference_asset_id,nodes_json,status,current_node,created_by,created_at,updated_at) VALUES (?,?,'quick_create',?,?,'running','VISUALS',?,?,?)", [$axWs, $axWf, $axPhoto, json_encode($axNodes), $axU, $axNow, $axNow]);
    $axRun = $axDb->lastInsertId();
    $axDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'VISUALS',1,'ai_video','processing',?,?)", [$axWs, $axRun, $axNow, $axNow]);
    $axJobId = $axDb->lastInsertId();

    $axPaths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/assets", 'cache' => "$TEST_MEDIA_ROOT/cache", 'render' => "$TEST_MEDIA_ROOT/renders", 'work' => "$TEST_MEDIA_ROOT/work"]);
    $axCache = new AssetCache($axDb, $axPaths);
    $axDisks = localStorageManager("$TEST_MEDIA_ROOT/assets", "$TEST_MEDIA_ROOT/cache", "$TEST_MEDIA_ROOT/renders");
    $axAssembly = new AssemblyEngine($vgFf, $axPaths, new RenderRepository($axDb), $axDisks->default(), 24, ['burn_subtitles' => false], 'local');
    $axExec = new \Kuyash\Media\AiVideoExecutor($axDb, new \Kuyash\Media\MockVideoGenProvider($vgFf, 1080, 1920, 24), $axCache, $axAssembly, $axPaths, $axDisks, ['width' => 540, 'height' => 960, 'preset' => 'ultrafast'], 16.0, 30.0);
    $axJob = ['id' => $axJobId, 'workspace_id' => $axWs, 'run_id' => $axRun];
    $axRes = $axExec->execute($axJob, []);
    check('ai_video exec: ready, AI-label required, visual_ref + draft render produced',
        $axRes->status === 'ready' && ($axRes->result['ai_label_required'] ?? null) === true
        && is_string($axRes->result['visual_ref'] ?? null) && str_starts_with((string) $axRes->result['visual_ref'], 'cache:')
        && ($axRes->result['draft_render_id'] ?? null) !== null && ($axRes->result['title'] ?? '') === 'gentle parallax zoom');
    check('ai_video exec: mock provider records NO spend (cost null, truthful)', $axRes->costCents === null && ($axRes->result['cached'] ?? null) === false);
    check('ai_video exec: a 9:16 draft render row exists for the run', (static function () use ($axDb, $axWs, $axRun): bool {
        $r = (new RenderRepository($axDb))->latestForRun($axWs, $axRun, 'draft');

        return $r !== null && (int) $r['width'] === 540 && (int) $r['height'] === 960;
    })());
    $axRes2 = $axExec->execute($axJob, []);
    check('ai_video exec: same photo+prompt reuses the cached generation (cached=true, null cost)',
        ($axRes2->result['cached'] ?? null) === true && $axRes2->costCents === null);
    check('ai_video exec: a missing reference photo fails honestly', (static function () use ($axDb, $axWs, $axRun, $axJobId, $axExec): bool {
        $axDb->run('UPDATE runs SET reference_asset_id = NULL WHERE id = ?', [$axRun]);
        $r = $axExec->execute(['id' => $axJobId, 'workspace_id' => $axWs, 'run_id' => $axRun], []);

        return $r->status === JobResult::STATUS_FAILED;
    })());

    // real-cost passthrough (closes Phase 12 follow-up #4): a provider that reports
    // a non-null cost on a MISS → the executor surfaces it on the JobResult, and
    // Engine::finalize ledgers it (the recording side was proven separately at $700).
    $axCostProv = new class ($vgFf) implements \Kuyash\Media\VideoGenProvider {
        public function __construct(private readonly Ffmpeg $ff)
        {
        }

        public function generateFromImage(string $imagePath, string $prompt, float $durationSeconds, string $targetPath): \Kuyash\Media\VideoResult
        {
            $r = (new \Kuyash\Media\MockVideoGenProvider($this->ff, 1080, 1920, 24))->generateFromImage($imagePath, $prompt, $durationSeconds, $targetPath);

            return new \Kuyash\Media\VideoResult($r->width, $r->height, $r->durationSeconds, 350, 'stub-real', $r->meta);
        }

        public function clipExtension(): string
        {
            return 'mp4';
        }

        public function name(): string
        {
            return 'stub-real';
        }
    };
    $axDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,reference_asset_id,nodes_json,status,current_node,created_by,created_at,updated_at) VALUES (?,?,'quick_create',?,?,'running','VISUALS',?,?,?)", [$axWs, $axWf, $axPhoto, json_encode($axNodes), $axU, $axNow, $axNow]);
    $axCostRun = $axDb->lastInsertId();
    $axDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,worker_id,run_after,created_at) VALUES (?,?,'VISUALS',1,'ai_video','processing','w1',?,?)", [$axWs, $axCostRun, $axNow, $axNow]);
    $axCostJobId = $axDb->lastInsertId();
    $axCostExec = new \Kuyash\Media\AiVideoExecutor($axDb, $axCostProv, $axCache, $axAssembly, $axPaths, $axDisks, ['width' => 540, 'height' => 960, 'preset' => 'ultrafast'], 16.0, 30.0);
    $axCostRes = $axCostExec->execute(['id' => $axCostJobId, 'workspace_id' => $axWs, 'run_id' => $axCostRun], []);
    check('ai_video exec: a REAL provider cost passes through on a MISS (surfaced, not cached)',
        $axCostRes->status === 'ready' && $axCostRes->costCents === 350
        && ($axCostRes->result['cached'] ?? null) === false && $axCostRes->provider === 'stub-real');
    $axFinNow = '2026-06-13T09:30:00Z';
    $axFinEngine = new Engine($axDb, new EventLog($axDb), new WorkflowValidator(), static fn (): string => $axFinNow, null, new UsageRecorder($axDb, require $basePath . '/config/usage.php'), null);
    $axFinEngine->finalize($axDb->one('SELECT * FROM jobs WHERE id = ?', [$axCostJobId]), $axCostRes);
    check('ai_video exec: Engine::finalize ledgers the passed-through spend (350c, ai_video category)',
        (int) $axDb->one('SELECT cost_cents AS c FROM usage_events WHERE job_id = ?', [$axCostJobId])['c'] === 350
        && (string) $axDb->one('SELECT category AS c FROM usage_events WHERE job_id = ?', [$axCostJobId])['c'] === 'ai_video');
}

echo "== Quick Create: Engine::startRun validation + prompt snapshot ==\n";
$qvDb = migratedDb($basePath);
[$qvUser, $qvWs] = seedUser($qvDb, 'qv@example.com', $argonHash, 'QV WS');
$_SESSION = [];
$qvCtx = new WorkspaceContext($qvDb); $qvCtx->set($qvWs);
$qvNow = '2026-06-13T11:00:00Z';
$qvEngine = new Engine($qvDb, new EventLog($qvDb), new WorkflowValidator(), static fn (): string => $qvNow);
$qvWfRepo = new WorkflowRepository($qvDb, new WorkflowValidator());
$qvWfRepo->ensureDefaults($qvCtx);
$qvWf = $qvWfRepo->findByTemplate($qvCtx, 'quick_create');
$qvVideo = seedReadyVideo($qvDb, $qvWs, 'a video');
$qvPhoto = $seedReadyPhoto($qvDb, $qvWs, 'a photo');
check('workflows: quick_create is seeded but EXCLUDED from the builder list',
    array_column($qvWfRepo->listFor($qvCtx), 'template') === ['full', 'distribution'] && $qvWf !== null);
check('quick startRun: no reference photo → rejected', throws(static fn () => $qvEngine->startRun($qvCtx, (int) $qvWf['id'], null, $qvUser, null, null, 'zoom'), WorkflowException::class));
check('quick startRun: a VIDEO reference is rejected (photo required)', throws(static fn () => $qvEngine->startRun($qvCtx, (int) $qvWf['id'], null, $qvUser, null, $qvVideo, 'zoom'), WorkflowException::class));
check('quick startRun: an empty/blank prompt → rejected', throws(static fn () => $qvEngine->startRun($qvCtx, (int) $qvWf['id'], null, $qvUser, null, $qvPhoto, '   '), WorkflowException::class));
check('quick startRun: valid photo + prompt → quick_create run with prompt snapshot', (static function () use ($qvEngine, $qvCtx, $qvWf, $qvUser, $qvPhoto, $qvDb): bool {
    $rid = $qvEngine->startRun($qvCtx, (int) $qvWf['id'], null, $qvUser, null, $qvPhoto, 'cinematic slow push-in');
    $run = $qvDb->one('SELECT entity_type, reference_asset_id, nodes_json FROM runs WHERE id = ?', [$rid]);
    $nodes = json_decode((string) $run['nodes_json'], true);

    return (string) $run['entity_type'] === 'quick_create' && (int) $run['reference_asset_id'] === $qvPhoto
        && ($nodes[0]['settings']['prompt'] ?? '') === 'cinematic slow push-in';
})());

echo "== Quick Create: pre-flight budget gate blocks an over-budget run ==\n";
$qbDb = migratedDb($basePath);
[$qbUser, $qbWs] = seedUser($qbDb, 'qb@example.com', $argonHash, 'QB WS');
$_SESSION = [];
$qbCtx = new WorkspaceContext($qbDb); $qbCtx->set($qbWs);
$qbNow = '2026-06-13T11:00:00Z';
(new WorkspaceSettings($qbDb))->setBudgetCapCents($qbWs, 100); // $1 cap — far below the ~$7 ai_video estimate
$qbWfRepo = new WorkflowRepository($qbDb, new WorkflowValidator());
$qbWfRepo->ensureDefaults($qbCtx);
$qbWf = $qbWfRepo->findByTemplate($qbCtx, 'quick_create');
$qbPhoto = $seedReadyPhoto($qbDb, $qbWs, 'qb photo');
$qbPreflight = new PreflightGate(new CostEstimator(require $basePath . '/config/usage.php'), new UsageRepository($qbDb), new WorkspaceSettings($qbDb), new EventLog($qbDb));
$qbEngine = new Engine($qbDb, new EventLog($qbDb), new WorkflowValidator(), static fn (): string => $qbNow, null, null, $qbPreflight);
check('quick preflight: over-budget quick_create is hard-blocked (BudgetExceededException)',
    throws(static fn () => $qbEngine->startRun($qbCtx, (int) $qbWf['id'], null, $qbUser, null, $qbPhoto, 'zoom'), BudgetExceededException::class));
check('quick preflight: blocked run leaves NO run row + writes one preflight_block event',
    (int) $qbDb->one('SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$qbWs])['n'] === 0
    && (int) $qbDb->one("SELECT COUNT(*) AS n FROM events WHERE workspace_id = ? AND key = 'guardrail.preflight_block'", [$qbWs])['n'] === 1);

echo "== E2E: Quick Create run (photo + prompt → AI clip → compliance → publish) ==\n";
$qeDb = migratedDb($basePath);
[$qeUser, $qeWs] = seedUser($qeDb, 'qe@example.com', $argonHash, 'QE WS');
$_SESSION = [];
$qeCtx = new WorkspaceContext($qeDb); $qeCtx->set($qeWs);
$qeNow = '2026-06-13T11:00:00Z';
[$qeEngine, $qeWorker] = makeRig($qeDb, new MockExecutor(), $qeNow);
$qeWfRepo = new WorkflowRepository($qeDb, new WorkflowValidator());
$qeWfRepo->ensureDefaults($qeCtx);
$qeWf = $qeWfRepo->findByTemplate($qeCtx, 'quick_create');
$connect($qeDb, $qeWs, 'instagram', '@qe', $qeNow); // a connected account so publish fans out
$qePhoto = $seedReadyPhoto($qeDb, $qeWs, 'qe face');
$qeRun = $qeEngine->startRun($qeCtx, (int) $qeWf['id'], null, $qeUser, null, $qePhoto, 'cinematic slow zoom');
while ($qeWorker->tick()) {
}
$qeReview = (new JobRepository($qeDb))->awaitingApproval($qeCtx)[0] ?? null;
check('quick e2e: pauses at render_review, AI-label flagged' . ($mediaReady ? ' + AI clip preview' : ''),
    $qeReview !== null && (string) $qeReview['type'] === 'render_review'
    && ($qeReview['result']['ai_label_required'] ?? null) === true
    && ($mediaReady ? ($qeReview['result']['draft_render_id'] ?? null) !== null : true));
$qeEngine->approve($qeCtx, (int) $qeReview['id'], $qeUser, 'qe@example.com');
while ($qeWorker->tick()) {
}
check('quick e2e: completes — chain runs ai_video…publish in order, run completed', (static function () use ($qeDb, $qeCtx, $qeRun, $qcTypes): bool {
    $run = (new RunRepository($qeDb))->find($qeCtx, $qeRun);
    $types = array_column((new JobRepository($qeDb))->jobsForRun($qeCtx, $qeRun), 'type');

    return $run['status'] === 'completed' && $types === $qcTypes;
})());
check('quick e2e: compliance pass_with_ai_label + published post carries the AI label', (static function () use ($qeDb, $qeRun): bool {
    $post = $qeDb->one('SELECT * FROM posts WHERE run_id = ?', [$qeRun]);
    $cc = $qeDb->one("SELECT result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$qeRun]);
    $ccStatus = json_decode((string) ($cc['result_json'] ?? ''), true)['status'] ?? '';

    return $post !== null && (string) $post['status'] === 'published' && (int) $post['ai_label_applied'] === 1
        && $ccStatus === 'pass_with_ai_label';
})());
check('quick e2e: mock generation recorded $0 real spend (truthful)',
    (int) $qeDb->one('SELECT COUNT(*) AS n FROM usage_events WHERE workspace_id = ?', [$qeWs])['n'] === 0);
check('quick e2e: run + post are tenant-scoped (another workspace sees neither)', (static function () use ($qeDb, $qeRun, $argonHash): bool {
    $_SESSION = [];
    [, $oWs] = seedUser($qeDb, 'qeother@example.com', $argonHash, 'QE OTHER');
    $octx = new WorkspaceContext($qeDb);
    $octx->set($oWs);

    return (new RunRepository($qeDb))->find($octx, $qeRun) === null && (new PostRepository($qeDb))->forRun($octx, $qeRun) === [];
})());

echo "== Quick Create: controller (page states, estimate, AI-label notice) ==\n";
$qkDb = migratedDb($basePath);
[$qkU, $qkWs] = seedUser($qkDb, 'qk@example.com', $argonHash, 'QK WS');
$_SESSION = ['auth_user_id' => $qkU, 'workspace_id' => $qkWs];
$qkCtx = new WorkspaceContext($qkDb); $qkCtx->set($qkWs);
$qkLib = require $basePath . '/config/library.php';
$qkAssets = new AssetRepository($qkDb);
$qkIngest = new AssetIngest(
    new AssetValidator((array) $qkLib['allowed'], (int) $qkLib['max_video_bytes'], (int) $qkLib['max_photo_bytes']),
    new MediaProbe(),
    new AssetStorage((string) $qkLib['storage_root']),
    $qkAssets,
    localStorageManager((string) $qkLib['storage_root']),
    (int) $qkLib['max_tags'],
    (int) $qkLib['max_tag_length'],
);
$qkCtl = new \Kuyash\Controllers\QuickCreateController(
    new View($basePath . '/templates'),
    $qkAssets,
    $qkIngest,
    new WorkflowRepository($qkDb, new WorkflowValidator()),
    new Engine($qkDb, new EventLog($qkDb), new WorkflowValidator()),
    new CostEstimator(require $basePath . '/config/usage.php'),
    $qkCtx,
    new Auth($qkDb, new LoginThrottle($qkDb), $qkCtx),
    new Csrf(),
    new Flash(),
    (array) $qkLib,
);
check('quick ctl: empty-library page shows the AI-label notice + ~$7.02 estimate + empty hint', (static function () use ($qkCtl): bool {
    $body = $qkCtl->index()->body();

    return str_contains($body, 'Quick Create') && str_contains($body, 'AI label')
        && str_contains($body, '$7.02') && str_contains($body, 'No photos in your library yet');
})());
$qkPhoto = $seedReadyPhoto($qkDb, $qkWs, 'mug shot');
check('quick ctl: a ready photo appears in the pick grid', (static function () use ($qkCtl): bool {
    $body = $qkCtl->index()->body();

    return str_contains($body, 'photo-pick') && str_contains($body, 'mug shot');
})());

// POST validation + happy path (CSRF is enforced centrally in public/index.php; the
// controller itself does not double-check, so create() is callable directly here).
$qkVidForPost = seedReadyVideo($qkDb, $qkWs, 'not a photo');
$qkFlash = new Flash();
$qkCtlP = new \Kuyash\Controllers\QuickCreateController(
    new View($basePath . '/templates'),
    $qkAssets,
    $qkIngest,
    new WorkflowRepository($qkDb, new WorkflowValidator()),
    new Engine($qkDb, new EventLog($qkDb), new WorkflowValidator(), static fn (): string => '2026-06-13T11:00:00Z'),
    new CostEstimator(require $basePath . '/config/usage.php'),
    $qkCtx,
    new Auth($qkDb, new LoginThrottle($qkDb), $qkCtx),
    new Csrf(),
    $qkFlash,
    (array) $qkLib,
);
$qkDrive = static function (array $post) use ($qkCtlP, $qkFlash): array {
    $_FILES = [];
    $_POST = $post;
    $r = $qkCtlP->create([]);
    $flash = $qkFlash->pull();
    $_POST = [];

    return ['status' => $r->status(), 'loc' => $r->headers()['Location'] ?? '', 'key' => $flash[0]['key'] ?? ''];
};
$qkR1 = $qkDrive(['prompt' => '   ']);
check('quick ctl POST: empty prompt → quick.prompt_required, back to /quick', $qkR1['status'] === 303 && $qkR1['loc'] === '/quick' && $qkR1['key'] === 'quick.prompt_required');
check('quick ctl POST: over-long prompt → quick.prompt_too_long', $qkDrive(['prompt' => str_repeat('x', 301)])['key'] === 'quick.prompt_too_long');
check('quick ctl POST: valid prompt + no photo → quick.photo_required', $qkDrive(['prompt' => 'a good motion prompt'])['key'] === 'quick.photo_required');
check('quick ctl POST: picked a VIDEO id → quick.photo_invalid', $qkDrive(['prompt' => 'zoom', 'photo_id' => (string) $qkVidForPost])['key'] === 'quick.photo_invalid');
$qkR5 = $qkDrive(['prompt' => 'cinematic push in', 'photo_id' => (string) $qkPhoto]);
check('quick ctl POST: valid prompt + picked photo → run started, redirect /queue', $qkR5['status'] === 303 && $qkR5['loc'] === '/queue' && $qkR5['key'] === 'quick.started'
    && (int) $qkDb->one("SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ? AND entity_type = 'quick_create'", [$qkWs])['n'] === 1);
$_SESSION = [];

// clean up the per-run temp media root (no rm -rf; explicit unlink/rmdir)
if (is_dir($TEST_MEDIA_ROOT)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($TEST_MEDIA_ROOT, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($TEST_MEDIA_ROOT);
    @rmdir(dirname($TEST_MEDIA_ROOT)); // drop the empty parent too (no-op if shared)
}

echo "\n" . $pass . ' PASS, ' . count($failures) . " FAIL\n";

if ($failures !== []) {
    echo "Failed:\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

exit(0);
