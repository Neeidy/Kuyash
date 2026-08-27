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
use Kuyash\Core\I18n;
use Kuyash\Controllers\LocaleController;
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
use Kuyash\Publish\OccurrenceMaterializer;
use Kuyash\Publish\OccurrenceRepository;
use Kuyash\Publish\PlanRunner;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
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

check('migrate: fresh DB applies all in order', $applied === ['0001_init.sql', '0002_assets.sql', '0003_workflow_engine.sql', '0004_trends.sql', '0005_media.sql', '0006_storage_location.sql', '0007_compliance.sql', '0008_accounts.sql', '0009_usage_ledger.sql', '0010_ai_video.sql', '0011_rate_limits.sql', '0012_user_locale.sql', '0013_ai_disclosure.sql', '0014_account_metrics.sql', '0015_accounts_dedup.sql', '0016_publish_slots.sql', '0017_plan_occurrences.sql', '0018_demo_seed_manifest.sql']);
check('migrate: second run applies nothing', $migrator->migrate() === []);
check('migrate: tracking rows recorded', count($mdb->all('SELECT filename FROM migrations')) === 18);
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
    ['type' => 'success', 'key' => 'upload.success', 'params' => []],
    ['type' => 'error', 'key' => 'upload.empty', 'params' => []],
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
    new OccurrenceRepository($cdb),
);
$mediaCtl = new MediaController($crepo, $cstorage, $cdisks, $cctx, null, 300);

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
use Kuyash\Controllers\PlanController;
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
function makeRig(Database $db, JobExecutor $executor, string &$now, ?TextProvider $contentProvider = null, bool $autoCompliance = false, ?\Kuyash\Publish\PublishProvider $publishProvider = null): array
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
            // Phase 24 spike: an optional provider lets a test capture the exact
            // PublishRequest the executor built (default stays the mock).
            $db, $publishProvider ?? new \Kuyash\Publish\MockPublishProvider(), $pubAccounts, $pubPosts, $events, new WorkspaceSettings($db), $clock,
        );
        $registry->register('publish', new \Kuyash\Compliance\PublishGateExecutor(
            $db, $pubExec, new \Kuyash\Publish\PublishCounter($db), $pubAccounts, $clock,
        ));
    }

    $watchdog = new Watchdog($db, $events);
    $worker = new Worker($db, $engine, $registry, $events, $watchdog, 'test-worker:1:abcd', $clock);

    return [$engine, $worker, $events, $watchdog];
}

/** The Phase 25 text-editor read model over a test DB. */
function makeTextEditorView(Database $db, ?\Kuyash\Content\DraftStash $drafts = null): \Kuyash\Content\TextEditorView
{
    global $basePath;

    return new \Kuyash\Content\TextEditorView(
        new \Kuyash\Content\ContentRevision($db),
        new \Kuyash\Content\PlatformLimits(require $basePath . '/config/platforms.php'),
        new AccountRepository($db),
        $db,
        new WorkspaceSettings($db),
        $drafts,
    );
}

/**
 * A fully wired PlanController over a test DB (Phase 24). Mirrors the web
 * binding so controller tests exercise the real collaborators, not stubs.
 */
function makePlanController(Database $db, WorkspaceContext $ctx, View $view): \Kuyash\Controllers\PlanController
{
    $occ = new OccurrenceRepository($db);
    $events = new EventLog($db);

    return new \Kuyash\Controllers\PlanController(
        $view,
        new SlotRepository($db),
        new SlotResolver(),
        new WorkspaceSettings($db),
        new PostRepository($db),
        $ctx,
        new Csrf(),
        new Flash(),
        $occ,
        new OccurrenceMaterializer($occ, new SlotResolver()),
        new \Kuyash\Publish\PlanBoard($occ),
        new AssetRepository($db),
        new WorkflowRepository($db, new WorkflowValidator()),
        new Engine($db, $events, new WorkflowValidator()),
        $events,
        new Auth($db, new LoginThrottle($db), $ctx),
        new AccountRepository($db),
        null,
        new \Kuyash\Usage\CostEstimator(require dirname(__DIR__) . '/config/usage.php'),
    );
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

check('messages: event template substitution + Phase 21 type humanization', Messages::event('job.requeued', ['type' => 'tts', 'retry' => 2, 'max' => 3, 'run' => 7])
    === 'Voiceover requeued, retry 2/3 (run #7)');
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
$queueCtl = new QueueController($view, $p4jobs, $p4runs, $p4engine, $p4ctx, $p4auth, new Csrf(), new Flash(), $deadHeartbeat, new SlotRepository($p4db), new SlotResolver(), new WorkspaceSettings($p4db), new OccurrenceRepository($p4db), $p4db, makeTextEditorView($p4db));
// wired with the render repo + media paths on purpose: without them the run
// page cannot tell a row from a file, and it withholds the player rather than
// emitting a <video> that 404s (that is what the fixture DB taught it)
$wfPaths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/assets", 'cache' => "$TEST_MEDIA_ROOT/cache", 'render' => "$TEST_MEDIA_ROOT/renders", 'work' => "$TEST_MEDIA_ROOT/work"]);
$wfCtl = new WorkflowController(
    $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine,
    new AssetRepository($p4db), new \Kuyash\Publish\PostRepository($p4db), $p4ctx, $p4auth, new Csrf(), new Flash(), makeTextEditorView($p4db),
    null, new \Kuyash\Media\RenderRepository($p4db), $wfPaths);
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

    // Phase 21: job types are humanized (no raw 'render_review' enum reaches the UI);
    // the render preview is the graceful inline player, not the old raw <video> that
    // showed a broken red block when its source was missing.
    return str_contains($body, 'Approvals') && str_contains($body, 'Preview approval')
        && !str_contains($body, 'render_review')
        && str_contains($body, 'inline-player') && !str_contains($body, 'approve-card__video')
        && str_contains($body, 'never faked');
})());
// ── P0a: a render gate with nothing to watch cannot be approved ────────────
// "Approve & publish" writes a record with the operator's name on it saying
// they approved a video. Over a "Preview pending" placeholder that record is
// indistinguishable from one where they had actually watched it, which is the
// exact misrepresentation .claude/rules/compliance.md forbids. Both halves are
// checked: the screens withhold the button, and the POST refuses — a hidden
// button is a suggestion, not a rule.
check('p0a: approving a render gate with no preview is refused, and nothing is decided', (static function () use ($queueCtl, $p4jobs, $p4ctx, $p4db): bool {
    $gate = null;
    foreach ($p4jobs->awaitingApproval($p4ctx) as $job) {
        if ((string) $job['type'] === 'render_review') {
            $gate = $job;
            break;
        }
    }
    if ($gate === null) {
        return false;
    }
    $id = (int) $gate['id'];
    $keep = (string) $p4db->one('SELECT result_json FROM jobs WHERE id = ?', [$id])['result_json'];

    // strip the preview, leaving a gate that names nothing to play
    $p4db->run("UPDATE jobs SET result_json = '{}' WHERE id = ?", [$id]);
    $blocked = $queueCtl->approve(['id' => (string) $id]);
    $stillOpen = (string) $p4db->one('SELECT status FROM jobs WHERE id = ?', [$id])['status'];
    $body = $queueCtl->index()->body();
    $p4db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [$keep, $id]);

    return $blocked->status() === 303                      // sent back, not through
        && $stillOpen === 'awaiting_approval'              // and nothing was decided
        && str_contains($body, 'approve_needs_preview') === false  // (key resolved, not printed raw)
        && str_contains($body, 'still being made')         // the screen says why
        && !str_contains($body, '/approve"');              // and offers no approve form at all
})());
check('p0a: the guard is narrow — a script gate is still approved by reading it', (static function () use ($basePath): bool {
    $ctl = (string) file_get_contents($basePath . '/src/Controllers/QueueController.php');

    // the SQL that decides must pin type AND open status: a broader read would
    // block script drafts (text, legitimately previewless) and already-decided
    // jobs, which are the engine's business
    return str_contains($ctl, "type = 'render_review' AND status = 'awaiting_approval'")
        && str_contains($ctl, 'previewMissing((int) $id)');
})());

// ── P0b: the run page shows the video it is about ──────────────────────────
check('p0b: run detail plays the run\'s own video (final render first, library clip otherwise)', (static function () use ($wfCtl, $p4jobs, $p4ctx): bool {
    $gate = null;
    foreach ($p4jobs->awaitingApproval($p4ctx) as $job) {
        if ((string) $job['type'] === 'render_review') {
            $gate = $job;
            break;
        }
    }
    if ($gate === null) {
        return false;
    }
    $body = $wfCtl->showRun(['id' => (string) $gate['run_id']])->body();
    $assetId = (int) ($gate['result']['library_asset_id'] ?? 0);

    // a distribution run has no render of its own — its video IS the clip, and
    // the poster is withheld (this controller was built without the poster
    // service) rather than emitted as a URL that would 404
    return $assetId > 0
        && str_contains($body, 'run-player')
        && str_contains($body, 'src="/media/' . $assetId . '"')
        && !str_contains($body, 'poster="');
})());
check('p0b: a render row whose file was never written gets no player', (static function () use (
    $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine, $p4db, $p4ctx, $p4auth
): bool {
    // the exact shape the visual fixture carries: rows, no bytes. The page used
    // to emit a <video> for one and paint a broken red block over the run's own
    // footage, with a 404 in the console the gate then caught.
    $blind = new WorkflowController(
        $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine,
        new AssetRepository($p4db), new \Kuyash\Publish\PostRepository($p4db), $p4ctx, $p4auth, new Csrf(), new Flash(), makeTextEditorView($p4db),
        null, new \Kuyash\Media\RenderRepository($p4db), new MediaPaths(['asset' => '/nowhere/a', 'cache' => '/nowhere/c', 'render' => '/nowhere/r', 'work' => '/nowhere/w']),
    );
    foreach ($p4runs->listFor($p4ctx, 100) as $run) {
        if (str_contains($blind->showRun(['id' => (string) $run['id']])->body(), 'run-player')) {
            return false;
        }
    }

    return true;
})());
check('p0b: a run with no video yet reserves no frame', (static function () use ($wfCtl, $p4runs, $p4ctx): bool {
    // the seeded workspace's earliest run is still upstream of any render
    foreach ($p4runs->listFor($p4ctx, 100) as $run) {
        $body = $wfCtl->showRun(['id' => (string) $run['id']])->body();
        if (!str_contains($body, 'run-player')) {
            return true;  // honest absence, not an empty tile
        }
    }

    return false;
})());
check('p0b/demo: the showcase seed installs committed fixtures by default, live stock only on request', (static function () use ($basePath): bool {
    $seed = (string) file_get_contents($basePath . '/bin/demo-seed.php');

    // the default must be the deterministic path: the operator's install, the
    // case study and the visual gate all have to be looking at the same tiles
    return str_contains($seed, "getenv('DEMO_MEDIA') !== 'live'");
})());

check('queue ctl: warns when the worker is not running', (static function () use ($queueCtl): bool {
    // $deadHeartbeat was never beaten → isAlive false → band shown (Phase 21: the
    // band copy is de-jargoned — no "worker"/command — but still surfaces a pause)
    return str_contains($queueCtl->index()->body(), 'Processing is paused');
})());
check('queue ctl: non-numeric job id → 404', $queueCtl->approve(['id' => 'abc'])->status() === 404
    && $queueCtl->retry(['id' => '../1'])->status() === 404);
check('runs ctl: run detail shows truthful approval + timeline', (static function () use ($wfCtl, $fullRunId): bool {
    $body = $wfCtl->showRun(['id' => (string) $fullRunId])->body();

    return str_contains($body, 'Approved by you') && str_contains($body, 'wf-a@example.com')
        && str_contains($body, 'append-only') && str_contains($body, 'compliance check passed');
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
$queueCtlB = new QueueController($view, $p4jobs, $p4runs, $p4engine, $p4ctx, $authB, new Csrf(), new Flash(), $deadHeartbeat, new SlotRepository($p4db), new SlotResolver(), new WorkspaceSettings($p4db), new OccurrenceRepository($p4db), $p4db, makeTextEditorView($p4db));
$wfCtlB = new WorkflowController(
    $view, $p4workflows, $p4runs, $p4jobs, $p4events, $p4engine,
    new AssetRepository($p4db), new \Kuyash\Publish\PostRepository($p4db), $p4ctx, $authB, new Csrf(), new Flash(), makeTextEditorView($p4db));

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

// Streaming WAV (OpenAI TTS): RIFF + data chunk sizes are the 0xFFFFFFFF "unknown
// length" sentinel. Build one by hand (24 kHz mono 16-bit → byteRate 48000) with a
// real 48000-byte payload = exactly 1.0s. Without the fix durationOf trusts the
// sentinel and returns 0xFFFFFFFF/48000 ≈ 89478s.
$buildWav = static function (string $path, int $declaredDataSize, int $payloadBytes, int $riffSize = 0xFFFFFFFF): void {
    $byteRate = 48000;   // 24000 Hz * 1 ch * 2 bytes
    $header = 'RIFF' . pack('V', $riffSize) . 'WAVE'
        . 'fmt ' . pack('V', 16)
        . pack('v', 1) . pack('v', 1)            // PCM, mono
        . pack('V', 24000) . pack('V', $byteRate)
        . pack('v', 2) . pack('v', 16)           // blockAlign, bits
        . 'data' . pack('V', $declaredDataSize);
    file_put_contents($path, $header . str_repeat("\0", $payloadBytes));
};
$streamWav = $wavDir . '/stream.wav';
$buildWav($streamWav, 0xFFFFFFFF, 48000, 0xFFFFFFFF);
$streamDur = WavWriter::durationOf($streamWav) ?? 0.0;
check('wav: streaming 0xFFFFFFFF data-size → filesize-based duration (~1.0s, not 89478)',
    abs($streamDur - 1.0) < 0.02);
check('wav: streaming sentinel never yields the bogus ~89478s reading', $streamDur < 100.0);

// Regression: a normal WAV whose data chunk declares a real size MUST keep using
// that declared size — even when extra bytes trail the data chunk — so the sentinel
// branch never hijacks well-formed files. Declared 24000 bytes = 0.5s; 1024 trailing.
$normalWav = $wavDir . '/declared.wav';
$buildWav($normalWav, 24000, 24000 + 1024, 36 + 24000);
check('wav: normal declared data-size unchanged (0.5s, trailing bytes ignored)',
    abs((WavWriter::durationOf($normalWav) ?? 0.0) - 0.5) < 0.02);

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

echo "== Media: AssetCache self-heals a HIT whose local file was evicted ==\n";

// A HIT row can outlive its local file once migrate-storage backfills the object
// to the durable disk (R2) and drops the local copy. Wire AssetCache with a
// StorageManager whose default 'r2' disk is a second LocalStorageProvider (no
// network) standing in for R2.
$healRoot = tempDir('acheal');
$healPaths = new MediaPaths(['asset' => "$healRoot/a", 'cache' => "$healRoot/c", 'render' => "$healRoot/v", 'work' => "$healRoot/w"]);
$healDurRoot = tempDir('achealdur');
$healDur = new LocalStorageProvider(['asset' => "$healDurRoot/a", 'cache' => "$healDurRoot/c", 'render' => "$healDurRoot/v"]);
$healSm = new StorageManager([
    'local' => new LocalStorageProvider(['asset' => "$healRoot/a", 'cache' => "$healRoot/c", 'render' => "$healRoot/v"]),
    'r2' => $healDur,
], 'r2');
$healDb = migratedDb($basePath);
[$healUser, $hws] = seedUser($healDb, 'heal@example.com', $argonHash, 'HEAL WS');
$healCache = new AssetCache($healDb, $healPaths, $healSm);
$healCalls = 0;
$healProd = function (string $p) use (&$healCalls): array {
    $healCalls++;
    file_put_contents($p, 'CLIPBYTES');

    return ['cost_cents' => 7];
};

// MISS produces the canonical local file + row; then simulate the R2 backfill:
// push to the durable disk, mark the row, evict the local copy.
$hm = $healCache->remember($hws, 'stock', 'k-heal', 'mp4', $healProd);
$hCanon = $healPaths->resolve($hm->ref);
$healDur->put(StorageKey::make('cache', $hws, $hm->name), $hCanon, 'video/mp4');
$healDb->run("UPDATE asset_cache SET storage_disk = 'r2' WHERE workspace_id = ? AND cache_key = ?", [$hws, 'k-heal']);
unlink($hCanon);
check('cache: precondition — HIT row exists but local file is gone', !is_file($hCanon) && $healCalls === 1);

$hHit = $healCache->remember($hws, 'stock', 'k-heal', 'mp4', $healProd);
check('cache: evicted HIT restores from the durable disk (no re-produce, still cached)',
    $hHit->cached === true && $healCalls === 1 && is_file($hCanon));

// regression: with the local file back, a further HIT is a clean reuse
$beforeClean = $healCalls;
$hClean = $healCache->remember($hws, 'stock', 'k-heal', 'mp4', $healProd);
check('cache: HIT with present local file is a clean reuse (no producer, no download)',
    $hClean->cached === true && $healCalls === $beforeClean && is_file($hCanon));

// unrecoverable (local-only disk, never uploaded) → re-produce IN PLACE, charged
$rm = $healCache->remember($hws, 'stock', 'k-repro', 'mp4', $healProd);
$rCanon = $healPaths->resolve($rm->ref);
$healDb->run("UPDATE asset_cache SET storage_disk = 'local' WHERE workspace_id = ? AND cache_key = ?", [$hws, 'k-repro']);
unlink($rCanon);
$beforeRepro = $healCalls;
$rHit = $healCache->remember($hws, 'stock', 'k-repro', 'mp4', $healProd);
check('cache: unrecoverable evicted HIT re-produces in place (cached=false, same name, charged)',
    $rHit->cached === false && $healCalls === $beforeRepro + 1 && is_file($rCanon) && $rHit->name === $rm->name);

if ($mediaReady) {
    echo "== Media: AssemblyEngine stages R2-evicted inputs end-to-end ==\n";

    // R2-mode simulation: visual + audio live ONLY on the durable disk; their
    // canonical local copies are removed (as migrate-storage would). The engine's
    // storage IS the durable disk, so assembly must stage both before ffmpeg.
    $asmDb = migratedDb($basePath);
    [$asmUser, $asmWs] = seedUser($asmDb, 'asmr2@example.com', $argonHash, 'ASM R2 WS');
    $asmRoot = tempDir('asmr2');
    $asmPaths = new MediaPaths(['asset' => "$asmRoot/a", 'cache' => "$asmRoot/c", 'render' => "$asmRoot/v", 'work' => "$asmRoot/w"]);
    $asmFf = new Ffmpeg($ffmpegBin, $ffprobeBin, 120);
    $asmDurRoot = tempDir('asmr2dur');
    $asmDur = new LocalStorageProvider(['asset' => "$asmDurRoot/a", 'cache' => "$asmDurRoot/c", 'render' => "$asmDurRoot/v"]);
    $asmSm = new StorageManager([
        'local' => new LocalStorageProvider(['asset' => "$asmRoot/a", 'cache' => "$asmRoot/c", 'render' => "$asmRoot/v"]),
        'r2' => $asmDur,
    ], 'r2');
    $asmEngine = new AssemblyEngine($asmFf, $asmPaths, new RenderRepository($asmDb), $asmSm->default(), 24, ['burn_subtitles' => false], 'r2');

    // a run + job so the render row's FKs (renders → runs/jobs) are satisfied
    $asmNow = gmdate(NOW_ISO);
    $asmDb->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, 'asm', 'full', '[]', ?, ?)", [$asmWs, $asmNow, $asmNow]);
    $asmWf = $asmDb->lastInsertId();
    $asmDb->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', ?, ?, ?)", [$asmWs, $asmWf, $asmUser, $asmNow, $asmNow]);
    $asmRun = $asmDb->lastInsertId();
    $asmDb->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, run_after, created_at) VALUES (?, ?, 'ASSEMBLE', 1, 'assembly', ?, ?)", [$asmWs, $asmRun, $asmNow, $asmNow]);
    $asmJob = $asmDb->lastInsertId();

    // produce a REAL visual clip + narration at their canonical local paths
    $vName = $asmPaths->newName('mp4');
    $vPath = $asmPaths->pathFor('cache', $asmWs, $vName);
    (new MockStockProvider($asmFf, 540, 960, 24))->fetchClip('skyline', 3.0, $vPath);
    $aName = $asmPaths->newName('wav');
    $aPath = $asmPaths->pathFor('cache', $asmWs, $aName);
    WavWriter::writeSilence($aPath, 3.0);
    $vRef = $asmPaths->ref('cache', $asmWs, $vName);
    $aRef = $asmPaths->ref('cache', $asmWs, $aName);

    // backfill to the durable disk, then evict the local copies
    $asmDur->put(StorageKey::make('cache', $asmWs, $vName), $vPath, 'video/mp4');
    $asmDur->put(StorageKey::make('cache', $asmWs, $aName), $aPath, 'audio/wav');
    unlink($vPath);
    unlink($aPath);
    check('assembly: R2-mode precondition — both ffmpeg inputs evicted from local', !is_file($vPath) && !is_file($aPath));

    $asmOut = $asmEngine->assembleNarrated($asmWs, $asmRun, $asmJob, 'draft', ['width' => 540, 'height' => 960, 'preset' => 'ultrafast'], $vRef, $aRef, 'one two three four');
    check('assembly: R2-evicted visual+audio staged → render completes end-to-end',
        ($asmOut['render_id'] ?? 0) > 0 && is_file($asmPaths->resolve($asmOut['render_ref'])));
    check('assembly: staging restored the inputs to their canonical local paths', is_file($vPath) && is_file($aPath));
}

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

$srvMedia = new MediaController($srvRepo, $srvStorage, $srvDisks, $srvCtx, null, 300);
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
$mbRender = static function (?int $viewerId) use ($badgeView, $badgeRuns, $aeJobs, $aeEvents, $mbCtx, $mbRun): string {
    return $badgeView->render('runs/show', [
        'title' => 'x', 'active' => 'queue', 'workspaceName' => 'MB', 'csrfField' => '',
        'flashes' => [], 'run' => $badgeRuns->find($mbCtx, $mbRun),
        'jobs' => $aeJobs->jobsForRun($mbCtx, $mbRun),
        'timeline' => $aeEvents->timelineForRun($mbCtx, $mbRun),
        'approvals' => $badgeRuns->approvalsForRun($mbCtx, $mbRun),
        'viewerId' => $viewerId,
    ], 'layout/app');
};
$mbBody = $mbRender($mbUser);
check('badge: manual record renders "Approved by you · email", NEVER the agent', (static function () use ($mbBody): bool {
    return str_contains($mbBody, 'Approved by you') && str_contains($mbBody, 'mb@example.com')
        && !str_contains($mbBody, 'Auto-approved by compliance agent');
})());

check('badge: "by you" is said ONLY to the person who actually decided', (static function () use ($mbRender, $mbUser): bool {
    // "you" is deictic — it resolves to whoever is reading. The label was
    // hard-coded, so a second operator in the same workspace (and anyone looking
    // at a record made by another account) was told THEY approved it, while the
    // email beside it said otherwise. The claim was in the chip; the truth was in
    // the metadata.
    $other = $mbRender($mbUser + 1000);
    $anon = $mbRender(null);

    foreach ([$other, $anon] as $body) {
        if (str_contains($body, 'Approved by you')
            || !str_contains($body, 'Approved by')
            || !str_contains($body, 'mb@example.com')
            // and it is still a HUMAN record, never re-labelled as the agent
            || str_contains($body, 'Auto-approved by compliance agent')
        ) {
            return false;
        }
    }

    return true;
})());

echo "== Compliance: SettingsController + DigestController (unit) ==\n";

$_SESSION = [];
$_SESSION['auth_user_id'] = $aeUser;
$aeCtx->set($aeWs);
$scSettings = new WorkspaceSettings($aeDb);
$scQuality = new QualityScore($aeDb, static fn (): string => $aeNow);
$scGate = new AutoApprovalGate($aeDb, $aeEvents, $scSettings, $scQuality, new UsageRepository($aeDb));
$scAuth = new Auth($aeDb, new LoginThrottle($aeDb), $aeCtx);
$settingsCtl = new SettingsController($badgeView, $scSettings, $scQuality, $scGate, $aeEvents, $aeCtx, $scAuth, new Csrf(), new Flash(), new SlotRepository($aeDb), new SlotResolver(), new AccountRepository($aeDb));

check('settings ctl: index renders mode, policy, quality, auto-slots', (static function () use ($settingsCtl): bool {
    $body = $settingsCtl->index()->body();

    // Phase 21: the standalone "policy kuyash-v1" info chip is removed from
    // settings (the policy version stays only on truthful approval RECORDS).
    return str_contains($body, 'Approval mode') && !str_contains($body, 'kuyash-v1')
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
// ── Real Zernio adapter (Phase 10) — schemas taken VERBATIM from the live
//    openapi.yaml, exercised with fakes (ZERO network). Maps every documented
//    response to the PublishOutcome taxonomy + sets the native AI flags that
//    YouTube/TikTok expose; Instagram (no native field) carries no AI flag.
$zdb = migratedDb($basePath);
[$zUser, $zWs] = seedUser($zdb, 'zern@x.com', $argonHash, 'Zern WS');
$zNow = '2026-06-13T10:00:00Z';
$zdb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$zWs, $zNow, $zNow]);
$zWf0 = (int) $zdb->lastInsertId();
$zdb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$zWs, $zWf0, $zUser, $zNow, $zNow]);
$zRun0 = (int) $zdb->lastInsertId();
$zPaths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/za", 'cache' => "$TEST_MEDIA_ROOT/zc", 'render' => "$TEST_MEDIA_ROOT/zr", 'work' => "$TEST_MEDIA_ROOT/zw"]);
$zRenderName = str_repeat('e', 32) . '.mp4';
$zRenderPath = $zPaths->resolve($zPaths->ref('render', $zWs, $zRenderName));
@mkdir(dirname($zRenderPath), 0777, true);
file_put_contents($zRenderPath, 'fake-mp4-bytes');
$zdb->run("INSERT INTO renders (workspace_id,run_id,kind,stored_name,mime,width,height,duration_s,storage_disk,created_at) VALUES (?,?,'final',?,'video/mp4',1080,1920,28.0,'local',?)", [$zWs, $zRun0, $zRenderName, $zNow]);
$zRenderId = (int) $zdb->lastInsertId();
$zStorage = new StorageManager(['local' => new LocalStorageProvider(['render' => "$TEST_MEDIA_ROOT/zr"])], 'local');
$zCfg = ['endpoint' => 'https://zernio.test/api', 'api_key' => 'sk_test', 'timeout' => 5];
$mkZernio = static function (FakeHttpClient $http, int $uploadStatus = 200) use ($zdb, $zStorage, $zPaths, $zCfg): ZernioPublishProvider {
    $blob = new FakeBlobClient();
    $blob->uploadStatus = $uploadStatus;
    return new ZernioPublishProvider($http, $blob, new \Kuyash\Media\RenderRepository($zdb), $zStorage, $zPaths, $zCfg, static fn (int $ms) => null);
};
$zReq = static fn (string $platform, bool $ai = false): PublishRequest => new PublishRequest($platform, '@acct', 'acc_zzz', "k:{$platform}", $ai, null, $zRenderId, 'Hello caption', ['#a'], $zWs);
$zPresign = static fn (): HttpResponse => new HttpResponse(200, (string) json_encode(['uploadUrl' => 'https://up.zernio.test/x', 'publicUrl' => 'https://media.zernio.com/temp/x.mp4', 'key' => 'temp/x.mp4', 'expiresIn' => 3600]));
// PostCreateResponse = { message, post: { _id, status, platforms:[PlatformTarget] } };
// PlatformTarget (verbatim spec) = { platform, status, platformPostUrl, errorMessage, errorCategory }.
$zPost = static fn (string $platform, string $st, string $url = '', string $errMsg = '', string $errCat = ''): HttpResponse => new HttpResponse(201, (string) json_encode(['message' => 'ok', 'post' => ['_id' => 'p_1', 'status' => $st, 'platforms' => [array_filter(['platform' => $platform, 'status' => $st, 'platformPostUrl' => $url, 'errorMessage' => $errMsg, 'errorCategory' => $errCat], static fn ($v) => $v !== '')]]]));

$o = $mkZernio(new FakeHttpClient([$zPresign(), $zPost('instagram', 'published', 'https://instagram.com/p/abc')]))->publish($zReq('instagram'));
check('zernio: published response → PUBLISHED + external id/url', $o->status === PublishOutcome::PUBLISHED && $o->externalPostId === 'p_1' && $o->externalUrl === 'https://instagram.com/p/abc');
$o = $mkZernio(new FakeHttpClient([$zPresign(), $zPost('instagram', 'pending')]))->publish($zReq('instagram'));
check('zernio: pending/scheduled response → ACCEPTED (webhook/reconcile)', $o->status === PublishOutcome::ACCEPTED && $o->externalPostId === 'p_1');
$o = $mkZernio(new FakeHttpClient([$zPresign(), new HttpResponse(400, (string) json_encode(['error' => 'invalid media', 'code' => 'BAD_MEDIA']))]))->publish($zReq('instagram'));
check('zernio: 4xx error envelope → REJECTED (terminal) + sanitized code', $o->status === PublishOutcome::REJECTED && str_contains((string) $o->error, 'BAD_MEDIA'));
$o = $mkZernio(new FakeHttpClient([$zPresign(), $zPost('instagram', 'failed', '', 'Instagram access token expired', 'auth_expired')]))->publish($zReq('instagram'));
check('zernio: per-platform errorCategory=auth_expired → AUTH_FAILED (account reauth)', $o->status === PublishOutcome::AUTH_FAILED && str_contains((string) $o->error, 'token expired'));
$o = $mkZernio(new FakeHttpClient([$zPresign(), $zPost('instagram', 'failed', '', 'Caption too long', 'user_content')]))->publish($zReq('instagram'));
check('zernio: per-platform non-auth failure → REJECTED (terminal) with errorMessage', $o->status === PublishOutcome::REJECTED && str_contains((string) $o->error, 'Caption too long'));
$o = $mkZernio(new FakeHttpClient([$zPresign(), new HttpResponse(429, '{}'), new HttpResponse(429, '{}'), new HttpResponse(429, '{}')]))->publish($zReq('instagram'));
check('zernio: 429 after bounded retry → RATE_LIMITED (queue backs off)', $o->status === PublishOutcome::RATE_LIMITED);
$o = $mkZernio(new FakeHttpClient([$zPresign(), new HttpResponse(402, (string) json_encode(['error' => 'tier', 'code' => 'PAYMENT_REQUIRED', 'reason' => 'free_tier_exceeded']))]))->publish($zReq('instagram'));
check('zernio: 402 PAYMENT_REQUIRED → REJECTED (terminal, not retried)', $o->status === PublishOutcome::REJECTED && str_contains((string) $o->error, 'free_tier_exceeded'));
check('zernio: transport throw → PublishProviderException (transient)', throws(
    static fn () => $mkZernio(new FakeHttpClient([new HttpTransportException('timeout')]))->publish($zReq('instagram')),
    \Kuyash\Publish\PublishProviderException::class,
));
check('zernio: presigned upload non-2xx → PublishProviderException', throws(
    static fn () => $mkZernio(new FakeHttpClient([$zPresign()]), 500)->publish($zReq('instagram')),
    \Kuyash\Publish\PublishProviderException::class,
));
// native AI flags: set ONLY where the platform exposes a field, from request.aiLabelApplied
$ytHttp = new FakeHttpClient([$zPresign(), $zPost('youtube', 'published', 'https://youtu.be/x')]);
$mkZernio($ytHttp)->publish($zReq('youtube', true));
$ytBody = (array) json_decode($ytHttp->calls[1]['body'], true);
check('zernio: YouTube AI flag → platformSpecificData.containsSyntheticMedia=true (verbatim openapi field)',
    ($ytBody['platforms'][0]['platformSpecificData']['containsSyntheticMedia'] ?? null) === true
    && isset($ytBody['title']));
$ttHttp = new FakeHttpClient([$zPresign(), $zPost('tiktok', 'published', 'https://tiktok.com/x')]);
$mkZernio($ttHttp)->publish($zReq('tiktok', true));
$ttBody = (array) json_decode($ttHttp->calls[1]['body'], true);
check('zernio: TikTok AI flag → platformSpecificData.videoMadeWithAi=true (verbatim openapi field)',
    ($ttBody['platforms'][0]['platformSpecificData']['videoMadeWithAi'] ?? null) === true);
$igHttp = new FakeHttpClient([$zPresign(), $zPost('instagram', 'published', 'https://ig/p')]);
$mkZernio($igHttp)->publish($zReq('instagram', true));
$igBody = (array) json_decode($igHttp->calls[1]['body'], true);
$igPsd = (array) ($igBody['platforms'][0]['platformSpecificData'] ?? []);
check('zernio: Instagram sends shareToFeed + NO contentType (enum is [story]; Reels auto-detected) + NO native AI field',
    ($igPsd['shareToFeed'] ?? null) === true
    && !isset($igPsd['contentType'])
    && !isset($igPsd['containsSyntheticMedia'], $igPsd['videoMadeWithAi'], $igPsd['madeWithAi']));
check('zernio: every request carries the Bearer auth header as an associative map (CurlHttpClient contract)',
    ($igHttp->calls[0]['headers']['Authorization'] ?? '') === 'Bearer sk_test'
    && ($igHttp->calls[0]['headers']['Accept'] ?? '') === 'application/json'
    && str_contains($igBody['mediaItems'][0]['url'], 'media.zernio.com'));
// read-only accounts() maps SocialAccount → the vendor-neutral shape
$acctHttp = new FakeHttpClient([new HttpResponse(200, (string) json_encode(['accounts' => [['_id' => 'acc_ig', 'platform' => 'instagram', 'username' => 'kuyash', 'displayName' => 'Kuyash', 'isActive' => true]]]))]);
$accts = $mkZernio($acctHttp)->accounts('instagram');
check('zernio: GET /accounts maps SocialAccount (_id→external_ref, platform, username, active)',
    count($accts) === 1 && $accts[0]['external_ref'] === 'acc_ig' && $accts[0]['platform'] === 'instagram' && $accts[0]['active'] === true);
$o = $mkZernio(new FakeHttpClient([$zPost('instagram', 'published', 'https://ig/p1')]))->status('p_1');
check('zernio: status() reconciliation poll converges to PUBLISHED', $o->status === PublishOutcome::PUBLISHED);

echo "== Publish: account id resolution (real Zernio _id) ==\n";

// payload accountId is the account's external_ref VERBATIM — so it must hold the
// real Zernio SocialAccount _id (24-hex), never a fabricated/handle value.
$realAcctId = '6a2f250a5f7d1751abb4803a';
$idReq = new PublishRequest('instagram', '@ai.neeidy', $realAcctId, 'k:idmap', false, null, $zRenderId, 'Cap', ['#x'], $zWs);
$idHttp = new FakeHttpClient([$zPresign(), $zPost('instagram', 'published', 'https://ig/p')]);
$mkZernio($idHttp)->publish($idReq);
$idBody = (array) json_decode($idHttp->calls[1]['body'], true);
check('zernio: payload platforms[0].accountId is the account external_ref (the Zernio _id), verbatim',
    ($idBody['platforms'][0]['accountId'] ?? null) === $realAcctId);

// the exact production failure: a bad accountId → 400 invalid_field_value, surfaced as REJECTED
$o = $mkZernio(new FakeHttpClient([$zPresign(), new HttpResponse(400, (string) json_encode(['error' => 'Invalid accountId format', 'code' => 'invalid_field_value']))]))->publish($zReq('instagram'));
check('zernio: 400 invalid_field_value (bad accountId) → REJECTED carrying the code',
    $o->status === PublishOutcome::REJECTED && str_contains((string) $o->error, 'invalid_field_value'));

// MockPublishProvider implements the interface accounts() with a format-valid id
$mockAccts = (new \Kuyash\Publish\MockPublishProvider())->accounts('instagram');
check('mock: accounts() returns one active instagram account, 24-hex id, vendor-neutral shape',
    count($mockAccts) === 1 && $mockAccts[0]['platform'] === 'instagram' && $mockAccts[0]['active'] === true
    && preg_match('/^[a-f0-9]{24}$/', (string) $mockAccts[0]['external_ref']) === 1);

// a provider double returning a controlled real _id for ai.neeidy
$mkAcctProvider = static fn (array $accts): \Kuyash\Publish\PublishProvider => new class($accts) implements \Kuyash\Publish\PublishProvider {
    /** @param list<array<string, mixed>> $accts */
    public function __construct(private array $accts) {}
    public function publish(PublishRequest $request): PublishOutcome { return PublishOutcome::rejected('n/a'); }
    public function status(string $externalPostId): PublishOutcome { return PublishOutcome::rejected('n/a'); }
    public function name(): string { return 'fake'; }
    public function accounts(?string $platform = null): array
    {
        return $platform === null ? $this->accts : array_values(array_filter($this->accts, static fn (array $a): bool => $a['platform'] === $platform));
    }
    public function accountMetrics(?string $platform = null, ?string $from = null, ?string $to = null): array
    {
        return array_map(static fn (array $a): array => [
            'external_ref' => (string) $a['external_ref'],
            'platform' => (string) $a['platform'],
            'username' => (string) $a['username'],
            'followers' => $a['followers'] ?? null,
            'has_analytics' => (bool) ($a['has_analytics'] ?? true),
            'posts' => $a['posts'] ?? [],
            'raw' => ['source' => 'fake'],
        ], $this->accounts($platform));
    }
};
$remoteAccts = [['external_ref' => $realAcctId, 'platform' => 'instagram', 'username' => 'ai.neeidy', 'display_name' => 'AI', 'active' => true]];

$syncDb = migratedDb($basePath);
[$su, $sws] = seedUser($syncDb, 'sync@example.com', $argonHash, 'Sync WS');
$snow = gmdate(NOW_ISO);
$sctx = new WorkspaceContext($syncDb);
$sctx->set($sws);
$srepo = new AccountRepository($syncDb);
$igId = $srepo->connect($sctx, 'instagram', '@AI.Neeidy', 'zacct_STALE', $snow); // stale + mixed-case + @
$ttId = $srepo->connect($sctx, 'tiktok', '@nomatch', 'zacct_TT', $snow);          // no remote match
$acctCtl = new AccountsController($view, $srepo, new PostRepository($syncDb), new AssetRepository($syncDb), new PublishCounter($syncDb), new WorkspaceSettings($syncDb), $sctx, new Csrf(), new Flash(), $mkAcctProvider($remoteAccts), new SlotRepository($syncDb));
$acctCtl->sync();
$igRef = $syncDb->one('SELECT external_ref FROM accounts WHERE id = ?', [$igId])['external_ref'];
$ttRef = $syncDb->one('SELECT external_ref FROM accounts WHERE id = ?', [$ttId])['external_ref'];
check('sync: matched account external_ref reconciled to the real Zernio _id (@/case-insensitive match)', $igRef === $realAcctId);
check('sync: non-matching account left untouched', $ttRef === 'zacct_TT');

check('account repo: setExternalRef updates tenant-scoped, no-op when unchanged', (static function () use ($syncDb, $sctx, $srepo, $ttId, $snow): bool {
    $changed = $srepo->setExternalRef($sctx, $ttId, 'resolved_tt', $snow);
    $again = $srepo->setExternalRef($sctx, $ttId, 'resolved_tt', $snow); // identical → false
    return $changed && !$again && $syncDb->one('SELECT external_ref FROM accounts WHERE id = ?', [$ttId])['external_ref'] === 'resolved_tt';
})());

// connectCallback now resolves the real _id from the provider (no fabricated zacct_)
$ccDb = migratedDb($basePath);
[$ccu, $ccws] = seedUser($ccDb, 'cc@example.com', $argonHash, 'CC WS');
$ccctx = new WorkspaceContext($ccDb);
$ccctx->set($ccws);
$ccrepo = new AccountRepository($ccDb);
$ccCtl = new AccountsController($view, $ccrepo, new PostRepository($ccDb), new AssetRepository($ccDb), new PublishCounter($ccDb), new WorkspaceSettings($ccDb), $ccctx, new Csrf(), new Flash(), $mkAcctProvider($remoteAccts), new SlotRepository($ccDb));
$_SESSION['oauth_state'] = 'st_xyz';
$_GET = ['platform' => 'instagram', 'handle' => '@ai.neeidy', 'state' => 'st_xyz'];
$ccCtl->connectCallback();
$_GET = [];
unset($_SESSION['oauth_state']);
$ccRow = $ccDb->one("SELECT external_ref FROM accounts WHERE workspace_id = ? AND platform = 'instagram'", [$ccws]);
check('connect: connectCallback stores the REAL provider _id (no fabricated zacct_)',
    ($ccRow['external_ref'] ?? '') === $realAcctId);

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
    $db, new MockPublishProvider(), new AccountRepository($db), new PostRepository($db), new \Kuyash\Workflow\EventLog($db), new WorkspaceSettings($db), static fn (): string => $now,
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

// ── per-platform AI disclosure (Phase 10): native flag for YT/TikTok, caption
//    line for IG, toggle-gated, suppression audited. A spy provider captures the
//    PublishRequest the executor built so we can assert the EFFECTIVE decision.
final class SpyPublishProvider implements \Kuyash\Publish\PublishProvider
{
    /** @var list<PublishRequest> */
    public array $requests = [];

    public function publish(PublishRequest $request): PublishOutcome
    {
        $this->requests[] = $request;

        return PublishOutcome::published('sp_' . count($this->requests), 'https://x/' . count($this->requests));
    }

    public function status(string $externalPostId): PublishOutcome
    {
        return PublishOutcome::published($externalPostId, 'https://x/' . $externalPostId);
    }

    public function name(): string
    {
        return 'spy';
    }

    public function accounts(?string $platform = null): array
    {
        return [];
    }

    public function accountMetrics(?string $platform = null, ?string $from = null, ?string $to = null): array
    {
        return [];
    }
}
$mkExecSpy = static fn (Database $db, SpyPublishProvider $spy, string $now): ZernioPublishExecutor => new ZernioPublishExecutor(
    $db, $spy, new AccountRepository($db), new PostRepository($db), new \Kuyash\Workflow\EventLog($db), new WorkspaceSettings($db), static fn (): string => $now,
);
$aiPrior = ['compliance_check' => ['ai_label_required' => true], 'caption_generation' => ['captions' => ['instagram' => 'Tasty one-pan dinner', 'youtube' => 'Tasty one-pan dinner', 'tiktok' => 'Tasty one-pan dinner']]];

I18n::setLocale('en');
check('exec/ai: settings default ON for all 3 platforms; setAiDisclosure persists; unknown platform rejected', (static function () use ($argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'aiset@x.com', $argonHash, 'AISET');
    $s = new WorkspaceSettings($db);
    $def = $s->aiDisclosure($ws);
    $ok = $def['instagram'] && $def['youtube'] && $def['tiktok'] && $s->aiDiscloses($ws, 'instagram') === true;
    $s->setAiDisclosure($ws, 'tiktok', false);
    return $ok && $s->aiDiscloses($ws, 'tiktok') === false && $s->aiDiscloses($ws, 'youtube') === true && $s->setAiDisclosure($ws, 'bogus', false) === false;
})());
check('exec/ai: Instagram AI media gets the "Made with AI" disclosure on its own final line', (static function () use ($mkPublishJob, $mkExecSpy, $connect, $aiPrior, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'aiig@x.com', $argonHash, 'AIIG');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@ig', $now);
    $spy = new SpyPublishProvider();
    $mkExecSpy($db, $spy, $now)->execute($mkPublishJob($db, $ws, $u, $now), $aiPrior);
    $req = $spy->requests[0] ?? null;
    return $req !== null && $req->aiLabelApplied === true
        && str_contains($req->caption, "\nMade with AI")          // own line after the caption
        && str_ends_with(rtrim($req->caption), 'Made with AI');    // it is the final line
})());
check('exec/ai: the Instagram disclosure is localized to the owner locale (TR → "AI ile üretildi")', (static function () use ($mkPublishJob, $mkExecSpy, $connect, $aiPrior, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'aitr@x.com', $argonHash, 'AITR');
    $db->run('UPDATE users SET locale = ? WHERE id = ?', ['tr', $u]);
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'instagram', '@igtr', $now);
    $spy = new SpyPublishProvider();
    $mkExecSpy($db, $spy, $now)->execute($mkPublishJob($db, $ws, $u, $now), $aiPrior);
    return str_contains($spy->requests[0]->caption ?? '', 'AI ile üretildi');
})());
check('exec/ai: YouTube + TikTok carry effective aiLabelApplied=true (→ native flag set in the adapter)', (static function () use ($mkPublishJob, $mkExecSpy, $connect, $aiPrior, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'aiyt@x.com', $argonHash, 'AIYT');
    $now = '2026-06-13T10:00:00Z';
    $connect($db, $ws, 'youtube', 'Chan', $now);
    $connect($db, $ws, 'tiktok', '@tt', $now);
    $spy = new SpyPublishProvider();
    $mkExecSpy($db, $spy, $now)->execute($mkPublishJob($db, $ws, $u, $now), $aiPrior);
    foreach ($spy->requests as $r) {
        if (!$r->aiLabelApplied) { return false; }
        if (str_contains($r->caption, 'Made with AI')) { return false; } // caption line is IG-only
    }
    return count($spy->requests) === 2;
})());
check('exec/ai: a turned-OFF platform suppresses disclosure (effective=false, no caption line) + writes a truthful audit', (static function () use ($mkPublishJob, $mkExecSpy, $connect, $aiPrior, $argonHash, $basePath): bool {
    $db = migratedDb($basePath);
    [$u, $ws] = seedUser($db, 'aioff@x.com', $argonHash, 'AIOFF');
    $now = '2026-06-13T10:00:00Z';
    (new WorkspaceSettings($db))->setAiDisclosure($ws, 'instagram', false);
    $connect($db, $ws, 'instagram', '@igoff', $now);
    $spy = new SpyPublishProvider();
    $mkExecSpy($db, $spy, $now)->execute($mkPublishJob($db, $ws, $u, $now), $aiPrior);
    $req = $spy->requests[0] ?? null;
    $audit = (int) $db->one("SELECT COUNT(*) AS n FROM events WHERE key = 'compliance.ai_disclosure_suppressed'")['n'];
    return $req !== null && $req->aiLabelApplied === false && !str_contains($req->caption, 'Made with AI') && $audit === 1;
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
check('webhook ctl: Zernio payload "id" is the dedup key (real field per openapi)', (static function () use ($basePath, $whSecret, $sign): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = '{"id":"evt_zern_1","event":"post.published","post":{"_id":"p1"},"timestamp":"2026-06-13T10:00:00Z"}';
    $ok = $ctl->handle($body, $sign($body))->status();

    return $ok === 200 && (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events WHERE external_event_id='evt_zern_1'")['n'] === 1;
})());
check('webhook ctl: X-Zernio-Event-Id header is the dedup key when present (overrides body)', (static function () use ($basePath, $whSecret, $sign): bool {
    $db = migratedDb($basePath);
    $ctl = new WebhookController(new WebhookInbox($db, new PostRepository($db), new \Kuyash\Workflow\EventLog($db)), $whSecret);
    $body = '{"event":"post.published","post":{"_id":"p2"}}'; // no id in body — header provides it
    $first = $ctl->handle($body, $sign($body), 'ip', 'hdr_evt_9')->status();
    $dup = $ctl->handle($body, $sign($body), 'ip', 'hdr_evt_9')->status();

    return $first === 200 && $dup === 200
        && (int) $db->one("SELECT COUNT(*) AS n FROM webhook_events WHERE external_event_id='hdr_evt_9'")['n'] === 1;
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
    new WorkspaceSettings($acDb), $acCtx, new Csrf(), new Flash(), new \Kuyash\Publish\MockPublishProvider(), new SlotRepository($acDb),
);
check('accounts ctl: index lists accounts + connect buttons + next-scheduled line', (static function () use ($acCtl): bool {
    $body = $acCtl->index()->body();

    // Phase 21: platform names are humanized (Instagram, not the lowercase enum)
    return str_contains($body, '@me') && str_contains($body, 'Connect Instagram') && str_contains($body, 'Next scheduled');
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
// the user's scenario: a $1 cap blocks an EXPENSIVE run (quick_create AI-video,
// ~$7.02 estimate) even with $0 spent — PreflightGate consults the SAME
// budget_cap_cents Settings writes, on the estimate alone (no photo/Engine needed).
check('preflight: $1 cap blocks a quick_create (AI-video ~$7) run on the estimate alone', (static function () use ($pfCfg, $pfDb, $pfSettings, $pfEvents, $pfWs): bool {
    $cap = $pfSettings->compliance($pfWs)['budget_cap_cents'];
    $gate = new PreflightGate(new CostEstimator($pfCfg), new UsageRepository($pfDb), $pfSettings, $pfEvents);
    return $cap === 100 && throws(
        static fn () => $gate->check($pfWs, 'quick_create', \Kuyash\Workflow\Nodes::defaultNodes('quick_create'), '2026-06-12T12:00:00Z'),
        BudgetExceededException::class,
    );
})());
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

echo "== i18n: I18n translator (fallback, interpolation, clamp, resolve) ==\n";

// crafted lang dir so fallback precedence is exercised exactly (tr → en → key)
$langTmp = tempDir('lang');
file_put_contents($langTmp . '/en.php', "<?php return ['only_en' => 'English only', 'both' => 'EN {x}', 'html' => '<b>{x}</b>'];");
file_put_contents($langTmp . '/tr.php', "<?php return ['both' => 'TR {x}'];");
I18n::setLangDir($langTmp);

I18n::setLocale('tr');
check('i18n: tr value used when the key exists in tr', I18n::t('both', ['x' => '7']) === 'TR 7');
check('i18n: missing tr key falls back to the en value', I18n::t('only_en') === 'English only');
check('i18n: key missing in both langs returns the key itself', I18n::t('no.such.key') === 'no.such.key');
check('i18n: a placeholder with no param is left literal', I18n::t('both') === 'TR {x}');
check('i18n: lookup() returns null on a total miss (custom-fallback seam)', I18n::lookup('no.such.key') === null);

I18n::setLocale('xx');
check('i18n: setLocale clamps an unsupported locale to en', I18n::locale() === 'en');
check('i18n: en locale reads the en map', I18n::t('both', ['x' => '1']) === 'EN 1');

check('i18n: resolve honors a valid session locale', I18n::resolve('tr', 'en') === 'tr');
check('i18n: resolve uses the default when session is null', I18n::resolve(null, 'tr') === 'tr');
check('i18n: resolve rejects an unsupported value → en', I18n::resolve('de', 'de') === 'en');

check('i18n: View::t escapes the translated string AND interpolated params', View::t('html', ['x' => '<i>']) === '&lt;b&gt;&lt;i&gt;&lt;/b&gt;');

// back to the real lang files for the remaining checks
I18n::setLangDir($basePath . '/lang');
I18n::setLocale('en');

echo "== i18n: lang-file parity + template key coverage (no-bare-literal guard) ==\n";

$enMap = require $basePath . '/lang/en.php';
$trMap = require $basePath . '/lang/tr.php';
check('i18n: no TR-only keys (catches a mistyped key)', array_diff(array_keys($trMap), array_keys($enMap)) === []);
check('i18n: every template View::t key exists in en.php', (static function () use ($basePath, $enMap): bool {
    $files = array_merge(glob($basePath . '/templates/*.php'), glob($basePath . '/templates/*/*.php'));
    foreach ($files as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match_all('/(?:View|I18n)::t\(\s*[\x27"]([^\x27"]+)[\x27"]/', $src, $m)) {
            foreach ($m[1] as $k) {
                if (str_ends_with($k, 'state_')) {
                    continue; // dynamic: runs.state_<state>, all six keys verified below
                }
                if ($k === 'day.') {
                    continue; // dynamic: day.<1-7>, all seven verified below
                }
                if ($k === 'plan.reason_') {
                    continue; // dynamic: every skip reason, all verified below
                }
                if ($k === 'content.locked_') {
                    continue; // dynamic: every lock reason, all verified below
                }
                if (!array_key_exists($k, $enMap)) {
                    return false;
                }
            }
        }
    }
    foreach (['pending', 'failed', 'awaiting', 'running', 'done', 'cancelled'] as $s) {
        if (!array_key_exists('runs.state_' . $s, $enMap)) {
            return false;
        }
    }
    for ($d = 1; $d <= 7; $d++) {   // weekday labels used by the weekly plan
        if (!array_key_exists('day.' . $d, $enMap)) {
            return false;
        }
    }
    // Phase 24: every reason a planned day can produce nothing must have a
    // label. An unlabeled reason would reach the calendar as a raw enum — the
    // exact jargon leak the experience phases spent a round removing.
    foreach (['no_content', 'not_produced', 'not_approved', 'missed', 'daily_cap', 'budget_cap',
              'kill_switch', 'plan_paused', 'compliance_block', 'no_owner', 'no_workflow',
              'no_account', 'cancelled'] as $reason) {
        if (!array_key_exists('plan.reason_' . $reason, $enMap)) {
            return false;
        }
    }
    // Phase 25: every reason the text editor can be locked for needs a label —
    // an unlabeled one would reach the screen as a raw enum.
    foreach (['publishing', 'run_over', 'not_found', 'not_ready'] as $reason) {
        if (!array_key_exists('content.locked_' . $reason, $enMap)) {
            return false;
        }
    }
    return true;
})());

echo "== i18n: Messages facade localizes after the fold ==\n";

I18n::setLocale('tr');
check('messages: status label localizes under tr', Messages::status('published') === 'yayınlandı');
check('messages: event line localizes + interpolates under tr', str_contains(
    Messages::event('job.requeued', ['type' => 'tts', 'retry' => 1, 'max' => 3, 'run' => 5]),
    'yeniden kuyruğa',
) && str_contains(Messages::event('job.requeued', ['type' => 'tts', 'retry' => 1, 'max' => 3, 'run' => 5]), '1/3'));
check('messages: flash text localizes under tr', Messages::text('settings.saved') === 'Ayarlar kaydedildi.');
check('messages: unknown status still falls back to the raw enum under tr', Messages::status('weird') === 'weird');
I18n::setLocale('en');

echo "== i18n: TR render smoke (≥2 screens) + <html lang> ==\n";

I18n::setLocale('tr');
$i18nView = new View($basePath . '/templates');
$loginTr = $i18nView->render('auth/login', ['title' => 'T', 'csrfField' => '', 'error' => null, 'email' => '']);
check('render tr: login is Turkish, carries lang="tr", no leftover EN chrome',
    str_contains($loginTr, 'Giriş yap')
    && str_contains($loginTr, '<html lang="tr">')
    && !str_contains($loginTr, 'Sign in to Kuyash'));
$errTr = $i18nView->render('errors/404', ['title' => '404']);
check('render tr: 404 page is Turkish', str_contains($errTr, 'Bulunamadı') && !str_contains($errTr, 'This page does not exist'));
I18n::setLocale('en');
$loginEn = $i18nView->render('auth/login', ['title' => 'T', 'csrfField' => '', 'error' => null, 'email' => '']);
check('render en: unchanged English baseline still renders', str_contains($loginEn, 'Sign in to Kuyash') && str_contains($loginEn, '<html lang="en">'));

echo "== i18n: compliance-string truthfulness in BOTH languages (gate) ==\n";

foreach (['en' => ['agent' => 'compliance agent', 'you' => 'Approved by you'],
          'tr' => ['agent' => 'uyumluluk aracı', 'you' => 'Sizin tarafınızdan onaylandı']] as $loc => $exp) {
    I18n::setLocale($loc);
    $auto = I18n::t('digest.approved_by_agent', ['policy' => 'v1']);
    $manual = I18n::t('runs.approved_by_you');
    check("compliance ({$loc}): auto record names the compliance agent, never the human",
        stripos($auto, $exp['agent']) !== false
        && $auto !== $manual
        && stripos($auto, 'by you') === false
        && stripos($auto, 'tarafınızdan') === false);
    check("compliance ({$loc}): manual record names the human (you), never the agent",
        str_contains($manual, $exp['you'])
        && stripos($manual, 'compliance agent') === false
        && stripos($manual, 'uyumluluk aracı') === false);
    check("compliance ({$loc}): AI-label-always-on string is present (not a missing key)",
        I18n::t('quick.ai_always_on') !== '' && I18n::t('quick.ai_always_on') !== 'quick.ai_always_on');
}
I18n::setLocale('en');

echo "== i18n: migration 0012 (users.locale) ==\n";

$locDb = migratedDb($basePath);
$userCols = array_column($locDb->all('PRAGMA table_info(users)'), 'name');
check('migration 0012: users.locale column added', in_array('locale', $userCols, true));
$locDb->run('INSERT INTO users (email, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)', ['loc@x', 'h', 't', 't']);
check('migration 0012: default locale is en', ($locDb->one("SELECT locale FROM users WHERE email = 'loc@x'")['locale'] ?? null) === 'en');
check('migration 0012: CHECK rejects an unsupported locale', throws(static fn () => $locDb->run(
    "INSERT INTO users (email, password_hash, locale, created_at, updated_at) VALUES ('bad@x', 'h', 'de', 't', 't')",
)));
check('migration 0012: CHECK accepts tr', (static function () use ($locDb): bool {
    $locDb->run("INSERT INTO users (email, password_hash, locale, created_at, updated_at) VALUES ('tr@x', 'h', 'tr', 't', 't')");
    return ($locDb->one("SELECT locale FROM users WHERE email = 'tr@x'")['locale'] ?? null) === 'tr';
})());

echo "== i18n: Auth caches locale + LocaleController /locale switch ==\n";

$liDb = migratedDb($basePath);
[$liUser] = seedUser($liDb, 'li@example.com', $argonHash, 'LI WS');
$liDb->run('UPDATE users SET locale = ? WHERE id = ?', ['tr', $liUser]);
$liCtx = new WorkspaceContext($liDb);
$liAuth = new Auth($liDb, new LoginThrottle($liDb), $liCtx);
$_SESSION = ['auth_user_id' => $liUser];
check('auth: user() exposes the locale column', ($liAuth->user()['locale'] ?? null) === 'tr');

$liFlash = new Flash();
$liCtl = new LocaleController($liDb, $liAuth, $liFlash);
$_SESSION = ['auth_user_id' => $liUser];
$_SERVER['HTTP_REFERER'] = 'http://localhost:8082/settings';
$_POST = ['locale' => 'tr'];
$rLoc = $liCtl->set([]);
check('locale ctl: valid switch → 303 back to the referer path', $rLoc->status() === 303 && ($rLoc->headers()['Location'] ?? '') === '/settings');
check('locale ctl: persists the column', ($liDb->one('SELECT locale FROM users WHERE id = ?', [$liUser])['locale'] ?? null) === 'tr');
check('locale ctl: caches the session locale', ($_SESSION[Auth::SESSION_LOCALE] ?? null) === 'tr');
check('locale ctl: success flash locale.updated', ($liFlash->pull()[0]['key'] ?? '') === 'locale.updated');

$_POST = ['locale' => 'de'];
$rBad = $liCtl->set([]);
check('locale ctl: unsupported locale is not persisted + error flash', ($liDb->one('SELECT locale FROM users WHERE id = ?', [$liUser])['locale'] ?? null) === 'tr'
    && ($liFlash->pull()[0]['key'] ?? '') === 'locale.invalid');

$_POST = ['locale' => 'en'];
$_SERVER['HTTP_REFERER'] = 'http://evil.example.com/somewhere?x=1';
$rExt = $liCtl->set([]);
check('locale ctl: external referer host is dropped, only the local path is used (no open redirect)', ($rExt->headers()['Location'] ?? '') === '/somewhere?x=1');
$liFlash->pull();

$_POST = ['locale' => 'tr'];
unset($_SERVER['HTTP_REFERER']);
$rNoRef = $liCtl->set([]);
check('locale ctl: missing referer → /dashboard', ($rNoRef->headers()['Location'] ?? '') === '/dashboard');
$liFlash->pull();

$_POST = ['locale' => 'tr'];
$_SERVER['HTTP_REFERER'] = 'http://localhost/\\evil.example.com/x'; // backslash protocol-relative
$rBackslash = $liCtl->set([]);
check('locale ctl: backslash protocol-relative referer is rejected → /dashboard', ($rBackslash->headers()['Location'] ?? '') === '/dashboard');
$_SESSION = [];
$_POST = [];
unset($_SERVER['HTTP_REFERER']);

echo "== Phase 16: motion/interaction shell (accent token + palette i18n parity) ==\n";
$p16Base = require $basePath . '/lang/en.php';
$p16Tr = require $basePath . '/lang/tr.php';
$p16Css = (string) file_get_contents($basePath . '/public/assets/css/base.css');
check('p16: accent token reconciled to the approved v3 teal (#2ff0d2)', str_contains($p16Css, '--accent: #2ff0d2;'));
check('p16: --glow token defined for the on-demand accent halo', str_contains($p16Css, '--glow:'));
$p16Keys = ['cmd.trigger', 'cmd.placeholder', 'cmd.label', 'cmd.empty', 'cmd.shortcuts', 'help.open_palette', 'help.close', 'help.navigate', 'help.select'];
check('p16: new palette/shortcuts keys all present in en.php', array_filter($p16Keys, static fn(string $k): bool => !isset($p16Base[$k])) === []);
check('p16: new palette/shortcuts keys all present in tr.php (parity, both languages)', array_filter($p16Keys, static fn(string $k): bool => !isset($p16Tr[$k])) === []);
check('p16: the command-palette + drawer partials are shipped', is_file($basePath . '/templates/layout/partials/command-palette.php') && is_file($basePath . '/templates/layout/partials/drawer.php'));
check('p16: build-free preserved — no package.json / node_modules added to the app', !is_file($basePath . '/package.json') && !is_dir($basePath . '/node_modules'));

echo "== Phase 17: signature-dashboard cockpit (real business KPIs, honest accounts, rich awaiting) ==\n";
$p17Db = migratedDb($basePath);
[$p17User, $p17Ws] = seedUser($p17Db, 'p17@example.com', $argonHash, 'P17 WS');
$p17Now = gmdate('Y-m-d\TH:i:s\Z');
$p17Db->run('INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$p17Ws, 'Full', 'full', '[]', $p17Now, $p17Now]);
$p17Wf = (int) $p17Db->lastInsertId();
$p17Db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, current_node, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'awaiting_approval', 'PREVIEW', ?, ?, ?)", [$p17Ws, $p17Wf, $p17User, $p17Now, $p17Now]);
$p17Run = (int) $p17Db->lastInsertId();
$p17Db->run(
    "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, max_retries, priority, run_after, created_at)
     VALUES (?, ?, 'PREVIEW', 8, 'render_review', 'awaiting_approval', '{}', ?, 3, 0, ?, ?)",
    [$p17Ws, $p17Run, json_encode(['ai_label_required' => true, 'compliance' => ['status' => 'pass']]), $p17Now, $p17Now],
);
$p17Db->run("INSERT INTO credit_transactions (workspace_id, type, amount_cents, reason, created_at) VALUES (?, 'grant', 5000, 'seed', ?)", [$p17Ws, $p17Now]);
$p17Db->run("INSERT INTO accounts (workspace_id, platform, handle, status, health, connected_at, created_at, updated_at) VALUES (?, 'instagram', '@p17', 'connected', 'ok', ?, ?, ?)", [$p17Ws, $p17Now, $p17Now, $p17Now]);
$p17Ctx = new WorkspaceContext($p17Db);
$p17Ctx->set($p17Ws);
$p17Paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
$p17Cockpit = new \Kuyash\Workflow\Cockpit(
    $p17Db,
    new AssetCache($p17Db, $p17Paths),
    new CreditLedger($p17Db),
    new UsageRepository($p17Db),
    new AccountRepository($p17Db),
    new \Kuyash\Workflow\JobRepository($p17Db),
);
$p17Snap = $p17Cockpit->snapshot($p17Ctx, $p17Now);
check('p17: business balance is the real ledger balance (grant 5000c)', $p17Snap['business']['balance_cents'] === 5000);
check('p17: cost-per-content is NULL when no renders exist (honest "—", never divide-by-zero)', $p17Snap['business']['cost_per_content_cents'] === null);
check('p17: granted-this-week reflects the recent grant', $p17Snap['business']['granted_week_cents'] === 5000);
check('p17: awaiting uses the rich shape (decoded result, AI-label flag)', count($p17Snap['awaiting']) === 1 && ($p17Snap['awaiting'][0]['result']['ai_label_required'] ?? null) === true);
// Phase 22 sharpened this: the widget may now carry a PROVIDER-REPORTED audience
// (followers_count, NULL until a sync/snapshot fills it) — but Cockpit still
// fabricates nothing itself, so no engagement metric may appear here.
check('p17/p22: accounts widget returns stored fields only — provider audience allowed, fabricated engagement never', count($p17Snap['accounts']) === 1
    && $p17Snap['accounts'][0]['platform'] === 'instagram'
    && $p17Snap['accounts'][0]['health'] === 'ok'
    && array_key_exists('followers_count', $p17Snap['accounts'][0])          // real column, honestly NULL when unsynced
    && $p17Snap['accounts'][0]['followers_count'] === null
    && !isset($p17Snap['accounts'][0]['followers'], $p17Snap['accounts'][0]['likes'], $p17Snap['accounts'][0]['engagement']));
check('p17: tenant isolation — a sibling workspace sees an empty cockpit', (static function () use ($p17Db, $p17Cockpit, $p17Now): bool {
    $other = new WorkspaceContext($p17Db);
    $p17Db->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', ['Other', $p17Now, $p17Now]);
    $other->set((int) $p17Db->lastInsertId());
    $snap = $p17Cockpit->snapshot($other, $p17Now);
    return $snap['awaiting'] === [] && $snap['accounts'] === [] && $snap['business']['balance_cents'] === 0;
})());

// ── K1: one definition of "waiting on you" ─────────────────────────────────
// The dashboard used to print three different answers in one frame: the KPI
// (runs awaiting), the approval card's badge (the SLICE the card was cut down
// to — so eight open gates read "4" for ever) and the plan band (this week's
// cells). The badge now prints the KPI's own number and the card says how many
// runs it is NOT showing. These checks pin that: the badge's source, the live
// tick's source and the KPI's source must be the same count, and the "and N
// more" arithmetic must be measured in runs — not in cards, which is the unit
// that made the two disagree in the first place.
check('k1: the card is sliced but the count is not — badge number = KPI number = live tick', (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'k1@example.com', $argonHash, 'K1 WS');
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $db->run('INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$ws, 'Full', 'full', '[]', $now, $now]);
    $wf = (int) $db->lastInsertId();

    // six runs awaiting a human, and the FIRST one holds two open gates — so
    // the number of cards (7) and the number of runs (6) genuinely differ, the
    // way the visual fixture does.
    $runIds = [];
    for ($i = 0; $i < 6; $i++) {
        $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, current_node, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'awaiting_approval', 'PREVIEW', ?, ?, ?)", [$ws, $wf, $user, $now, $now]);
        $runIds[] = (int) $db->lastInsertId();
    }
    // run #1's second gate goes EARLY, so the four cards the dashboard shows
    // really do cover fewer than four runs — that is the case the "and N more"
    // arithmetic has to get right.
    $gates = [$runIds[0], $runIds[0], $runIds[1], $runIds[2], $runIds[3], $runIds[4], $runIds[5]];
    foreach ($gates as $n => $runId) {
        $db->run(
            "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, max_retries, priority, run_after, created_at)
             VALUES (?, ?, 'PREVIEW', ?, 'render_review', 'awaiting_approval', '{}', '{}', 3, 0, ?, ?)",
            [$ws, $runId, 8 + $n, $now, $now],
        );
    }

    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
    $cockpit = new \Kuyash\Workflow\Cockpit(
        $db,
        new AssetCache($db, $paths),
        new CreditLedger($db),
        new UsageRepository($db),
        new AccountRepository($db),
        new \Kuyash\Workflow\JobRepository($db),
    );
    $snap = $cockpit->snapshot($ctx, $now);

    // the window is four RUNS — run #1 brings both of its open gates with it,
    // so five cards render. Cutting at four CARDS instead would show one of
    // run #1's gates and hide the other, and then "cards shown + and N more"
    // would add up to the queue's card count rather than to the badge.
    if (count($snap['awaiting']) !== 5 || $snap['awaitingShownRuns'] !== 4) {
        return false;
    }
    // no run is half-shown: every gate of a shown run is on the page
    $shown = [];
    foreach ($snap['awaiting'] as $j) {
        $shown[(int) $j['run_id']] = ($shown[(int) $j['run_id']] ?? 0) + 1;
    }
    if (($shown[$runIds[0]] ?? 0) !== 2) {
        return false;
    }
    // the badge is the KPI, not the window
    if ($snap['business']['awaiting'] !== 6 || $snap['kpis']['awaiting'] !== 6) {
        return false;
    }
    // and the live tick that overwrites that badge every few seconds agrees
    if ($cockpit->liveSnapshot($ws)['awaiting'] !== 6) {
        return false;
    }
    // 4 runs shown + "and 2 more" = 6 = the badge. The arithmetic a reader
    // does on the card has to land on the number above it.
    return ($snap['business']['awaiting'] - $snap['awaitingShownRuns']) === 2;
})());
check('k1: /queue prints the same unit as the dashboard badge — runs, not gates', (static function () use ($basePath): bool {
    $tpl = (string) file_get_contents($basePath . '/templates/queue/index.php');

    // the chip must be fed a de-duplicated run count; count($awaiting) is the
    // gate count, which is what made the two screens disagree one click apart
    return str_contains($tpl, "'n' => $awaitingRuns")
        && str_contains($tpl, 'array_unique(array_map(static fn (array $j): int => (int) ($j[\'run_id\'] ?? 0), $awaiting))')
        && !str_contains($tpl, "'n' => count($awaiting)");
})());
check('k1: both places that print the count carry the live hook, so they cannot drift apart on screen', (static function () use ($basePath): bool {
    $tpl = (string) file_get_contents($basePath . '/templates/dashboard.php');
    $js = (string) file_get_contents($basePath . '/public/assets/js/live-client.js');

    // two nodes in the markup, and a client that updates ALL of them (a
    // querySelector here would refresh the KPI and leave the badge stale)
    return substr_count($tpl, 'data-live-awaiting') === 2
        && str_contains($js, "querySelectorAll('[data-live-awaiting]')");
})());
$p17En = require $basePath . '/lang/en.php';
$p17Tr = require $basePath . '/lang/tr.php';
$p17Keys = ['dash.kpi_balance', 'dash.kpi_spent', 'dash.kpi_cost_per', 'dash.added_week', 'dash.charges_mtd', 'dash.no_data_yet', 'dash.accounts_title', 'dash.accounts_none', 'player.play', 'player.playing', 'player.preview_pending'];
check('p17: new dashboard/player keys present in en.php', array_filter($p17Keys, static fn(string $k): bool => !isset($p17En[$k])) === []);
check('p17: new dashboard/player keys present in tr.php (parity, both languages)', array_filter($p17Keys, static fn(string $k): bool => !isset($p17Tr[$k])) === []);

echo "== Dashboard: BYO-key budget KPI (remaining budget = cap − spent; honest no-data) ==\n";
$budDb = migratedDb($basePath);
[$budUser, $budWs] = seedUser($budDb, 'bud@example.com', $argonHash, 'Budget WS');
$budNow = gmdate(NOW_ISO);
$budCtx = new WorkspaceContext($budDb); $budCtx->set($budWs);
$budPaths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
$budCockpit = new \Kuyash\Workflow\Cockpit($budDb, new AssetCache($budDb, $budPaths), new CreditLedger($budDb), new UsageRepository($budDb), new AccountRepository($budDb), new \Kuyash\Workflow\JobRepository($budDb));
$budSettings = new WorkspaceSettings($budDb);
// minimal FK-valid chain (workflow → run → job) so a real usage_event + render attach
$budDb->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'F','full','[]',?,?)", [$budWs, $budNow, $budNow]);
$budWf = (int) $budDb->lastInsertId();
$budDb->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'trend','[]','running',?,?,?)", [$budWs, $budWf, $budUser, $budNow, $budNow]);
$budRun = (int) $budDb->lastInsertId();
$budDb->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,payload_json,result_json,run_after,priority,created_at) VALUES (?,?,'IDEA',1,'idea_generation','ready','{}','{}',?,100,?)", [$budWs, $budRun, $budNow, $budNow]);
$budJob = (int) $budDb->lastInsertId();
// no cap → both budget fields null ("no monthly limit")
$budBiz0 = $budCockpit->snapshot($budCtx, $budNow)['business'];
check('dash/budget: no cap → budget_cap_cents + remaining_budget_cents are NULL (UI shows "no limit")',
    $budBiz0['budget_cap_cents'] === null && $budBiz0['remaining_budget_cents'] === null);
// cost-per-content with a render but ZERO spend → "no data", not a misleading $0.00
$budDb->run("INSERT INTO renders (workspace_id,run_id,kind,stored_name,mime,width,height,duration_s,created_at) VALUES (?,?,'final',?,'video/mp4',1080,1920,20.0,?)", [$budWs, $budRun, str_repeat('1',32).'.mp4', $budNow]);
$budBizR = $budCockpit->snapshot($budCtx, $budNow)['business'];
check('dash/budget: render present but zero real spend → cost-per-content is NULL ("no data", not $0.00)',
    $budBizR['cost_per_content_cents'] === null);
// set a $5 cap via the SAME setter SettingsController::save calls, then spend $1.20 this month
$budSettings->setBudgetCapCents($budWs, 500);
$budDb->run("INSERT INTO usage_events (workspace_id,run_id,job_id,provider,category,units,cost_cents,created_at) VALUES (?,?,?,'openai','ai_text',1,120,?)", [$budWs, $budRun, $budJob, $budNow]);
$budBiz1 = $budCockpit->snapshot($budCtx, $budNow)['business'];
check('dash/budget: remaining budget = cap − month-to-date spend (500 − 120 = 380c)',
    $budBiz1['budget_cap_cents'] === 500 && $budBiz1['spent_mtd_cents'] === 120 && $budBiz1['remaining_budget_cents'] === 380);
check('dash/budget: with real spend + a render, cost-per-content becomes a real average (120c / 1 render)',
    $budBiz1['cost_per_content_cents'] === 120);

echo "== Phase 18: production-line node-graph (real job status → node state) ==\n";
$p18Db = migratedDb($basePath);
[$p18User, $p18Ws] = seedUser($p18Db, 'p18@example.com', $argonHash, 'P18 WS');
$p18Now = gmdate('Y-m-d\TH:i:s\Z');
$p18Db->run('INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
    [$p18Ws, 'Full', 'full', json_encode(\Kuyash\Workflow\Nodes::defaultNodes('full')), $p18Now, $p18Now]);
$p18Wf = (int) $p18Db->lastInsertId();
$p18Db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, current_node, created_by, created_at, updated_at) VALUES (?, ?, 'trend', ?, 'running', 'VOICE', ?, ?, ?)",
    [$p18Ws, $p18Wf, json_encode(\Kuyash\Workflow\Nodes::defaultNodes('full')), $p18User, $p18Now, $p18Now]);
$p18Run = (int) $p18Db->lastInsertId();
$p18Job = static function (string $node, int $step, string $type, string $status) use ($p18Db, $p18Ws, $p18Run, $p18Now): void {
    $p18Db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, run_after, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, '{}', '{}', ?, 100, ?)",
        [$p18Ws, $p18Run, $node, $step, $type, $status, $p18Now, $p18Now]);
};
$p18Job('TREND', 1, 'trend_fetch', 'ready');
$p18Job('IDEA', 2, 'idea_generation', 'ready');
$p18Job('VOICE', 4, 'tts', 'processing');
$p18Ctx = new WorkspaceContext($p18Db);
$p18Ctx->set($p18Ws);
$p18Paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
$p18Cockpit = new \Kuyash\Workflow\Cockpit($p18Db, new AssetCache($p18Db, $p18Paths), new CreditLedger($p18Db), new UsageRepository($p18Db), new AccountRepository($p18Db), new \Kuyash\Workflow\JobRepository($p18Db));
$p18Pipe = $p18Cockpit->snapshot($p18Ctx, $p18Now)['pipeline'];
$p18ByName = [];
foreach (($p18Pipe['nodes'] ?? []) as $n) { $p18ByName[$n['name']] = $n['state']; }
check('p18: completed-job nodes map to done', ($p18ByName['TREND'] ?? null) === 'done' && ($p18ByName['IDEA'] ?? null) === 'done');
check('p18: a processing job maps the node to active', ($p18ByName['VOICE'] ?? null) === 'active');
check('p18: a node with no job maps to wait', ($p18ByName['VISUALS'] ?? null) === 'wait' && ($p18ByName['PUBLISH'] ?? null) === 'wait');
check('p18: pipeline tracks the canonical full node order', ($p18Pipe['nodes'][0]['name'] ?? null) === 'TREND' && count($p18Pipe['nodes']) === count(\Kuyash\Workflow\Nodes::FULL));
check('p18: no active run → pipeline is null (honest empty, no fabricated graph)', (static function () use ($p18Db, $p18Cockpit, $p18Now): bool {
    $idle = new WorkspaceContext($p18Db);
    $p18Db->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', ['Idle', $p18Now, $p18Now]);
    $idle->set((int) $p18Db->lastInsertId());
    return $p18Cockpit->snapshot($idle, $p18Now)['pipeline'] === null;
})());
$p18En = require $basePath . '/lang/en.php';
$p18Tr = require $basePath . '/lang/tr.php';
$p18Keys = ['pipeline.title', 'pipeline.flow_in', 'pipeline.flow_process', 'pipeline.flow_out', 'node.desc.trend', 'node.desc.voice', 'node.desc.compliance', 'node.desc.publish'];
check('p18: pipeline/node-desc keys present in BOTH languages (parity)', array_filter($p18Keys, static fn(string $k): bool => !isset($p18En[$k]) || !isset($p18Tr[$k])) === []);
check('p18: node descriptions carry no UI tech-jargon (no ffmpeg/TTS/queue/job)', (static function () use ($p18En, $p18Tr): bool {
    foreach (['en' => $p18En, 'tr' => $p18Tr] as $map) {
        foreach ($map as $k => $v) {
            if (!str_starts_with((string) $k, 'node.desc.')) { continue; }
            if (preg_match('/\b(ffmpeg|tts|queue|cron|sql|webhook)\b/i', (string) $v)) { return false; }
        }
    }
    return true;
})());

echo "== Phase 19: live SSE endpoint (immediate-close, tenant-scoped, read-only) ==\n";
$p19Db = migratedDb($basePath);
[$p19User, $p19Ws] = seedUser($p19Db, 'p19@example.com', $argonHash, 'P19 WS');
$p19Now = gmdate('Y-m-d\TH:i:s\Z');
$p19Db->run('INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$p19Ws, 'F', 'full', '[]', $p19Now, $p19Now]);
$p19Wf = (int) $p19Db->lastInsertId();
$p19Db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'awaiting_approval', ?, ?, ?)", [$p19Ws, $p19Wf, $p19User, $p19Now, $p19Now]);
$p19Db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at) VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$p19Ws, $p19Wf, $p19User, $p19Now, $p19Now]);
$p19Ctx = new WorkspaceContext($p19Db);
$p19Ctx->set($p19Ws);
$p19Paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
$p19Cockpit = new \Kuyash\Workflow\Cockpit($p19Db, new AssetCache($p19Db, $p19Paths), new CreditLedger($p19Db), new UsageRepository($p19Db), new AccountRepository($p19Db), new \Kuyash\Workflow\JobRepository($p19Db));
$p19Live = $p19Cockpit->liveSnapshot($p19Ws);
check('p19: liveSnapshot counts active (running+awaiting) and awaiting', $p19Live['active'] === 2 && $p19Live['awaiting'] === 1);
check('p19: liveSnapshot is workspace-scoped — a sibling sees zero', (static function () use ($p19Db, $p19Cockpit, $p19Now): bool {
    $p19Db->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', ['Other19', $p19Now, $p19Now]);
    $other = (int) $p19Db->lastInsertId();
    $s = $p19Cockpit->liveSnapshot($other);
    return $s['active'] === 0 && $s['awaiting'] === 0;
})());
$p19Auth = new Auth($p19Db, new LoginThrottle($p19Db), $p19Ctx);
$_SESSION['auth_user_id'] = $p19User;
$p19Ctx->set($p19Ws); // workspace + auth both live in $_SESSION (as after a real login)
$p19Ctl = new \Kuyash\Controllers\LiveController($p19Auth, $p19Ctx, $p19Cockpit);
$p19RunsBefore = (int) ($p19Db->one('SELECT COUNT(*) AS c FROM runs')['c'] ?? 0);
$p19Res = $p19Ctl->stream([]);
$p19RunsAfter = (int) ($p19Db->one('SELECT COUNT(*) AS c FROM runs')['c'] ?? 0);
$p19H = $p19Res->headers();
check('p19: SSE response is text/event-stream + no-cache', str_contains($p19H['Content-Type'] ?? '', 'text/event-stream') && str_contains($p19H['Cache-Control'] ?? '', 'no-cache'));
$p19Body = $p19Res->body();
check('p19: SSE body carries a retry directive + a snapshot event (immediate-close stream)', str_contains($p19Body, 'retry: 5000') && str_contains($p19Body, 'event: snapshot'));
preg_match('/data: (.+)/', $p19Body, $p19M);
$p19Data = json_decode($p19M[1] ?? '{}', true);
check('p19: SSE data is a JSON snapshot with the tenant awaiting count + a timestamp', is_array($p19Data) && ($p19Data['awaiting'] ?? null) === 1 && ($p19Data['active'] ?? null) === 2 && isset($p19Data['ts']));
check('p19: the live endpoint is READ-ONLY (no rows written by a stream call)', $p19RunsAfter === $p19RunsBefore);
check('p19: unauthenticated → redirect to /login (route-guard backstop)', (static function () use ($p19Db, $p19Ctx, $p19Cockpit): bool {
    $auth = new Auth($p19Db, new LoginThrottle($p19Db), $p19Ctx);
    $_SESSION = [];
    $res = (new \Kuyash\Controllers\LiveController($auth, $p19Ctx, $p19Cockpit))->stream([]);
    return $res->status() === 302 && ($res->headers()['Location'] ?? '') === '/login';
})());
$_SESSION = [];
$p19En = require $basePath . '/lang/en.php';
$p19Tr = require $basePath . '/lang/tr.php';
check('p19: live.* keys present in BOTH languages (parity)', isset($p19En['live.label'], $p19En['live.updated'], $p19Tr['live.label'], $p19Tr['live.updated']));

echo "== Phase 20: polish / a11y / honest-copy close-out ==\n";
$p20Base = (string) file_get_contents($basePath . '/public/assets/css/base.css');
$p20En = require $basePath . '/lang/en.php';
$p20Tr = require $basePath . '/lang/tr.php';
// the faint tier must clear WCAG AA (>=4.5:1) on every surface — compute it
$p20Lin = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
$p20L = static fn (string $hex): float => 0.2126 * $p20Lin((float) hexdec(substr($hex, 0, 2))) + 0.7152 * $p20Lin((float) hexdec(substr($hex, 2, 2))) + 0.0722 * $p20Lin((float) hexdec(substr($hex, 4, 2)));
$p20Ratio = static function (string $a, string $b) use ($p20L): float { $la = $p20L($a); $lb = $p20L($b); return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05); };
preg_match('/--text-3:\s*#([0-9a-fA-F]{6})/', $p20Base, $p20m);
$p20T3 = $p20m[1] ?? '';
check('p20: faint tier --text-3 clears WCAG AA (>=4.5:1) on bg/surface/surface-2/surface-3 (A11Y-1+A11Y-2)',
    $p20T3 !== ''
    && $p20Ratio($p20T3, '0a0a0b') >= 4.5
    && $p20Ratio($p20T3, '111113') >= 4.5
    && $p20Ratio($p20T3, '17171a') >= 4.5
    && $p20Ratio($p20T3, '1e1e22') >= 4.5);
check('p20: sidebar foot copy is jargon-free (no internal phase label / mock-first / credit-gated)', (static function () use ($p20En, $p20Tr): bool {
    foreach (['en' => $p20En, 'tr' => $p20Tr] as $map) {
        foreach (['nav.foot_title', 'nav.foot_text'] as $k) {
            if (preg_match('/\b(phase|faz|mock|credit-gated|önce-mock|kredi kapılı)\b/i', (string) ($map[$k] ?? ''))) {
                return false;
            }
        }
    }
    return true;
})());
check('p20: the AI-label fact is kept in the foot copy (truthful, not stripped)', stripos((string) ($p20En['nav.foot_text'] ?? ''), 'AI-labeled') !== false && stripos((string) ($p20Tr['nav.foot_text'] ?? ''), 'AI etiketli') !== false);

echo "== Phase 21: full experience conversion (jargon scrub + account widget) ==\n";

I18n::setLocale('en');

// (1) enum humanizers — internal job-type / platform enums never reach the UI raw
check('p21: Messages::jobType maps internal enums to plain labels',
    Messages::jobType('render_review') === 'Preview approval'
    && Messages::jobType('script_draft') === 'Script draft'
    && Messages::jobType('tts') === 'Voiceover'
    && Messages::jobType('unknown_type') === 'unknown_type'); // unknown → raw fallback
check('p21: Messages::platform gives proper display names',
    Messages::platform('instagram') === 'Instagram'
    && Messages::platform('tiktok') === 'TikTok'
    && Messages::platform('youtube') === 'YouTube');
check('p21: Messages::node maps canonical pipeline ids to plain step labels',
    Messages::node('VOICE') === 'Voiceover'
    && Messages::node('PUBLISH') === 'Publish'
    && Messages::node('SCRIPT') === 'Script draft'
    && Messages::node('NOPE') === 'NOPE'); // unknown → raw fallback

// (2) account live-stream card (§1): deterministic, honest sample framing, humanized platform
$p21View = new View($basePath . '/templates');
$p21acct = ['id' => 7, 'platform' => 'instagram', 'handle' => '@det.kitchen', 'health' => 'ok', 'status' => 'connected'];
$p21card1 = $p21View->render('partials/account-card', ['account' => $p21acct, 'manage' => false]);
$p21card2 = $p21View->render('partials/account-card', ['account' => $p21acct, 'manage' => false]);
check('p21: account-card renders the §1 widget (handle, humanized platform, engagement, followers)',
    str_contains($p21card1, 'acc-card')
    && str_contains($p21card1, '@det.kitchen')
    && str_contains($p21card1, 'Instagram')           // humanized, not 'instagram'
    && str_contains($p21card1, 'followers'));
check('p21: account-card metrics are SAMPLE-framed (honest — not passed off as real)',
    str_contains($p21card1, View::t('acct.sample'))
    && stripos(View::t('acct.sample_note'), 'sample') !== false);
check('p21: account-card sample figures are deterministic (same account → identical render)',
    $p21card1 === $p21card2);
check('p21: account-card points at NO media file (media-free → no 404 in the visual gate)',
    !str_contains($p21card1, '/render/') && !str_contains($p21card1, '/media/')
    && !str_contains($p21card1, '<video') && !str_contains($p21card1, '<img'));

// (3) jargon-free guard over the WHOLE dictionary (both locales). The scrub target:
// build commands, the worker, mock/Zernio leaks, internal phase labels, raw step ids.
$p21En = require $basePath . '/lang/en.php';
$p21Tr = require $basePath . '/lang/tr.php';
check('p21: lang dictionaries are free of UI jargon (commands / worker / mock / Phase / node ids)', (static function () use ($p21En, $p21Tr): bool {
    $re = '#(bin/|worker\.php|render_review|script_draft|script\.v|prompt_version|\bZernio\b|doc-gated|\bmock\b|Phase \d|Faz \d|düğüm|\bnodes\b)#i';
    // infra words that must never leak into ANY user-facing string — including the
    // /logs activity feed (event.*). "zero jargon means zero": the event feed is no
    // longer exempt; its {type}/{platform}/{slop} tokens are humanized at display
    // time by Messages::event(), and the literal strings carry no infra words.
    $infra = '#(\bworker\b|işçi|\bpipelines?\b|\bwatchdog\b)#i';
    foreach ([$p21En, $p21Tr] as $map) {
        foreach ($map as $v) {
            if (preg_match($re, (string) $v) || preg_match($infra, (string) $v)) {
                return false;
            }
        }
    }
    return true;
})());

// (3b) the /logs activity feed renders humanized — raw job-type enums, worker ids
// and 0..1 slop never reach the rendered event line (zero-jargon incl. event.*)
check('p21: event feed humanizes job type + slop percentage (no raw enum/decimal)', (static function (): bool {
    $claimed = Messages::event('job.claimed', ['type' => 'render_review', 'run' => 5]);
    $warned = Messages::event('compliance.warned', ['slop' => 0.6102, 'run' => 5]);
    $approved = Messages::event('approval.approved', ['node' => 'VOICE', 'user' => 'a@b.co', 'run' => 5]);
    return str_contains($claimed, 'Preview approval') && !str_contains($claimed, 'render_review')
        && !str_contains($claimed, 'worker')
        && str_contains($warned, '61%') && !str_contains($warned, '0.61')
        && str_contains($approved, 'Voiceover') && !str_contains($approved, 'VOICE approved');
})());

// (3c) the queue render_review note is a clean compliance line — never the raw
// internal "Render review (mock): compliance pass (policy mock-v0)" summary (A2)
check('p21: queue render_review shows a clean compliance note, not the raw mock/policy summary', (static function () use ($basePath): bool {
    $v = new View($basePath . '/templates');
    $job = [
        'id' => 1, 'run_id' => 1, 'node' => 'PREVIEW', 'type' => 'render_review',
        'provider' => 'mock', 'status' => 'awaiting_approval',
        'result' => [
            'compliance' => ['status' => 'pass'], 'ai_label_required' => true,
            'summary' => 'Render review (mock): compliance pass (policy mock-v0)',
            'draft_render_id' => null,
        ],
    ];
    $body = $v->render('queue/index', ['awaiting' => [$job], 'jobs' => [], 'runs' => [], 'csrfField' => '', 'workerAlive' => true]);
    return str_contains($body, 'Compliance: passed')
        && !str_contains($body, 'mock-v0') && !str_contains($body, '(mock)');
})());

// (4) the new key families exist in BOTH locales (parity already enforced above)
check('p21: jobtype/platform/acct key families present in en + tr', (static function () use ($p21En, $p21Tr): bool {
    foreach (['jobtype.render_review', 'platform.instagram', 'acct.followers', 'acct.sample_note', 'ledger.type_grant'] as $k) {
        if (!array_key_exists($k, $p21En) || !array_key_exists($k, $p21Tr)) {
            return false;
        }
    }
    return true;
})());

// (5) the AI-label compliance fact is preserved through the scrub (truthful, not stripped)
check('p21: AI-label language preserved (compliance) in both locales',
    stripos((string) $p21En['quick.ai_callout_strong'], 'AI label') !== false
    && stripos((string) $p21Tr['quick.ai_always_on'], 'AI etiketi') !== false
    && stripos((string) $p21En['acct.sample_note'], 'sample') !== false);

echo "== Phase 21 rev: 4-item review fixes (workspace name · text tint · live pulse · node output) ==\n";
I18n::setLocale('en');
$revBase = (string) file_get_contents($basePath . '/public/assets/css/base.css');
$revApp = (string) file_get_contents($basePath . '/public/assets/css/app.css');

// ── Item 1: editable workspace name + topbar effect ───────────────────────
$revDb = migratedDb($basePath);
[$revUser, $revWs] = seedUser($revDb, 'rev@example.com', $argonHash, 'Old WS Name');
$revSettings = new \Kuyash\Workspace\WorkspaceSettings($revDb);
$revCtx = new WorkspaceContext($revDb);
$revCtx->set($revWs);
$revDb->run('INSERT INTO workspaces (name, created_at, updated_at) VALUES (?, ?, ?)', ['Sibling WS', gmdate(NOW_ISO), gmdate(NOW_ISO)]);
$revSibling = (int) $revDb->lastInsertId();
check('rev/item1: setName renames the active workspace (additive write to workspaces.name; trims + collapses whitespace)',
    $revSettings->setName($revWs, '  Neeidy   Studio  ') === true && $revCtx->currentName() === 'Neeidy Studio');
check('rev/item1: setName rejects empty / whitespace-only and over-60-char names',
    $revSettings->setName($revWs, '   ') === false && $revSettings->setName($revWs, str_repeat('x', 61)) === false);
check('rev/item1: rename is tenant-scoped — the sibling workspace is untouched',
    (string) ($revDb->one('SELECT name FROM workspaces WHERE id = ?', [$revSibling])['name'] ?? '') === 'Sibling WS'
    && $revCtx->currentName() === 'Neeidy Studio');
check('rev/item1: topbar workspace chip carries the v3 teal gradient effect (not dull gray)',
    preg_match('/\.mode-chip__name\s*\{[^}]*linear-gradient/s', $revApp) === 1);
$revSettingsTpl = (string) file_get_contents($basePath . '/templates/settings/index.php');
check('rev/item1: settings page exposes the editable workspace-name field posting to /settings/name',
    str_contains($revSettingsTpl, 'name="workspace_name"') && str_contains($revSettingsTpl, 'action="/settings/name"'));
check('rev/item1: /settings/name route is registered and is NOT in the CSRF-exempt allowlist (so it is protected)',
    str_contains((string) file_get_contents($basePath . '/src/routes.php'), "'/settings/name'")
    && !str_contains((string) file_get_contents($basePath . '/public/index.php'), '/settings/name'));

// ── Item 2: text ramp visibly teal + WCAG AA preserved ────────────────────
$revLin = static fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
$revL = static fn (string $h): float => 0.2126 * $revLin((float) hexdec(substr($h, 0, 2))) + 0.7152 * $revLin((float) hexdec(substr($h, 2, 2))) + 0.0722 * $revLin((float) hexdec(substr($h, 4, 2)));
$revRatio = static fn (string $a, string $b): float => (max($revL($a), $revL($b)) + 0.05) / (min($revL($a), $revL($b)) + 0.05);
$revSurf = ['0a0a0b', '111113', '17171a', '1e1e22'];
$revTeal = static function (string $token) use ($revBase, $revRatio, $revSurf): array {
    preg_match('/' . preg_quote($token, '/') . ':\s*#([0-9a-fA-F]{6})/', $revBase, $m);
    $h = $m[1] ?? '';
    $r = (int) hexdec(substr($h, 0, 2)); $g = (int) hexdec(substr($h, 2, 2)); $b = (int) hexdec(substr($h, 4, 2));
    $minAA = 99.0; foreach ($revSurf as $s) { $minAA = min($minAA, $revRatio($h, $s)); }
    return ['hex' => $h, 'gr' => $g - $r, 'br' => $b - $r, 'aa' => $minAA];
};
$revT2 = $revTeal('--text-2'); $revT3 = $revTeal('--text-3');
check('rev/item2: --text-2 is a CLEARLY teal-leaning slate (G−R ≥ 20, B > R) — not the old imperceptible whisper, not pure gray',
    $revT2['hex'] !== '' && $revT2['gr'] >= 20 && $revT2['br'] > 0);
check('rev/item2: --text-3 is a CLEARLY teal-leaning slate (G−R ≥ 20, B > R) — not pure gray',
    $revT3['hex'] !== '' && $revT3['gr'] >= 20 && $revT3['br'] > 0);
check('rev/item2: the tinted ramp still clears WCAG AA (≥4.5:1) on every surface (no luminance reduction)',
    $revT2['aa'] >= 4.5 && $revT3['aa'] >= 4.5);

// ── Item 3: live dot teal heartbeat + glow ────────────────────────────────
check('rev/item3: a teal heartbeat keyframe (live-beat) is defined', str_contains($revApp, '@keyframes live-beat'));
check('rev/item3: the connected live dot pulses (.is-live → live-beat animation)',
    preg_match('/\.topbar__live\.is-live\s+\.topbar__live-dot\s*\{[^}]*animation:\s*live-beat/s', $revApp) === 1);
check('rev/item3: the dot is teal with a glow (accent fill + box-shadow var(--glow)) — never dull gray',
    preg_match('/\.topbar__live-dot\s*\{[^}]*var\(--accent\)[^}]*box-shadow[^}]*var\(--glow\)/s', $revApp) === 1);
check('rev/item3: reduced-motion freezes the pulse (animation:none) but keeps a steady glow',
    preg_match('/prefers-reduced-motion[\s\S]*?\.topbar__live-dot\s*\{[^}]*animation:\s*none/', $revApp) === 1);

// ── Item 4: pipeline node drawer shows the REAL per-stage output ───────────
$revP = migratedDb($basePath);
[$revPUser, $revPWs] = seedUser($revP, 'revp@example.com', $argonHash, 'RevP WS');
$revPNow = gmdate(NOW_ISO);
$revPNodes = json_encode(\Kuyash\Workflow\Nodes::defaultNodes('full'));
$revP->run('INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [$revPWs, 'Full', 'full', $revPNodes, $revPNow, $revPNow]);
$revPWf = (int) $revP->lastInsertId();
$revP->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, current_node, created_by, created_at, updated_at) VALUES (?, ?, 'trend', ?, 'running', 'VOICE', ?, ?, ?)", [$revPWs, $revPWf, $revPNodes, $revPUser, $revPNow, $revPNow]);
$revPRun = (int) $revP->lastInsertId();
$revPJob = static function (string $node, int $step, string $type, string $status, array $result) use ($revP, $revPWs, $revPRun, $revPNow): void {
    $revP->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, run_after, priority, created_at) VALUES (?, ?, ?, ?, ?, ?, '{}', ?, ?, 100, ?)",
        [$revPWs, $revPRun, $node, $step, $type, $status, json_encode($result), $revPNow, $revPNow]);
};
$revPJob('TREND', 1, 'trend_fetch', 'ready', ['trend' => 'One-pan dinners', 'score' => 92, 'niche' => 'home cooking', 'region' => 'US']);
$revPJob('IDEA', 2, 'idea_generation', 'ready', ['idea' => 'Five pantry staples', 'hook' => 'Stop buying takeout']);
$revPJob('VOICE', 4, 'tts', 'processing', []); // mid-flight: no result yet
$revPCtx = new WorkspaceContext($revP); $revPCtx->set($revPWs);
$revPPaths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
$revPCockpit = new \Kuyash\Workflow\Cockpit($revP, new AssetCache($revP, $revPPaths), new CreditLedger($revP), new UsageRepository($revP), new AccountRepository($revP), new \Kuyash\Workflow\JobRepository($revP));
$revPPipe = $revPCockpit->snapshot($revPCtx, $revPNow)['pipeline'];
$revPByName = [];
foreach (($revPPipe['nodes'] ?? []) as $n) { $revPByName[(string) $n['name']] = $n; }
check('rev/item4: Cockpit attaches the REAL per-node result (read-only, tenant-scoped)',
    ($revPByName['TREND']['results']['trend_fetch']['trend'] ?? null) === 'One-pan dinners'
    && ($revPByName['IDEA']['results']['idea_generation']['hook'] ?? null) === 'Stop buying takeout');
check('rev/item4: a mid-flight (processing) node carries no result (honest empty)',
    ($revPByName['VOICE']['results'] ?? null) === []);
// render the partial directly and prove the drawer bodies are real + distinct + escaped
$revPView = new View($basePath . '/templates');
$revRenderPipeline = ['run_id' => 1, 'template' => 'full', 'nodes' => [
    ['name' => 'TREND', 'state' => 'done', 'results' => ['trend_fetch' => ['trend' => 'One-pan dinners', 'score' => 92, 'niche' => 'home cooking', 'region' => 'US']]],
    ['name' => 'IDEA', 'state' => 'done', 'results' => ['idea_generation' => ['idea' => 'Five pantry staples', 'hook' => 'Stop buying takeout']]],
    ['name' => 'SCRIPT', 'state' => 'done', 'results' => ['script_draft' => ['script' => "Line one.\nLine two.", 'word_count' => 4, 'estimated_duration_s' => 1.6]]],
    ['name' => 'COMPLIANCE', 'state' => 'done', 'results' => ['compliance_check' => ['status' => 'pass_with_ai_label', 'ai_label_required' => true, 'checks' => ['slop' => ['score' => 0.23]]]]],
    ['name' => 'CAPTION', 'state' => 'done', 'results' => ['caption_generation' => ['captions' => ['instagram' => 'Save this <recipe>']]]],
    ['name' => 'VOICE', 'state' => 'active', 'results' => []],
    ['name' => 'PUBLISH', 'state' => 'wait', 'results' => []],
]];
$revPHtml = $revPView->render('partials/pipeline', ['pipeline' => $revRenderPipeline]);
check('rev/item4: done nodes render their REAL, per-node-distinct output (trend/hook/script/similarity/platform)',
    str_contains($revPHtml, 'One-pan dinners') && str_contains($revPHtml, 'Stop buying takeout')
    && str_contains($revPHtml, 'Line one') && str_contains($revPHtml, '23%') && str_contains($revPHtml, 'Instagram'));
check('rev/item4: an active node with no result shows the honest "no output yet" line',
    str_contains($revPHtml, View::t('node.no_data')));
check('rev/item4: a waiting node shows "not started yet"',
    str_contains($revPHtml, View::t('node.not_started')));
check('rev/item4: drawer output is HTML-escaped (no raw markup injection from result data)',
    !str_contains($revPHtml, 'Save this <recipe>') && str_contains($revPHtml, 'Save this &lt;recipe&gt;'));
check('rev/item4: different nodes yield DIFFERENT drawer bodies (not one generic blurb)', (static function () use ($revPHtml): bool {
    preg_match_all('/<template id="node-tpl-\d+"[^>]*>(.*?)<\/template>/s', $revPHtml, $tm);
    $bodies = array_map('trim', $tm[1] ?? []);
    return count($bodies) >= 7 && count(array_unique($bodies)) >= 6;
})());

echo "== Phase 22: Panel + Real Data (metrics, dedup, honest labels) ==\n";

I18n::setLocale('en');

// ── (1) the metrics seam: audience AND per-post engagement ──────────────────

$p22Mock = new \Kuyash\Publish\MockPublishProvider();
$p22MockRows = $p22Mock->accountMetrics('instagram');
check('p22/adapter: mock accountMetrics returns the instagram account with a real-shaped ref + audience',
    count($p22MockRows) === 1
    && $p22MockRows[0]['platform'] === 'instagram'
    && preg_match('/^[a-f0-9]{24}$/', (string) $p22MockRows[0]['external_ref']) === 1
    && is_int($p22MockRows[0]['followers']));
check('p22/adapter: the seam carries PER-POST engagement, not follower-only (K1)',
    $p22MockRows[0]['posts'] !== []
    && array_key_exists('views', $p22MockRows[0]['posts'][0])
    && array_key_exists('likes', $p22MockRows[0]['posts'][0])
    && array_key_exists('comments', $p22MockRows[0]['posts'][0])
    && array_key_exists('shares', $p22MockRows[0]['posts'][0]));
check('p22/adapter: mock metrics are deterministic (no clock, no randomness)',
    $p22Mock->accountMetrics('instagram') === $p22MockRows);

/** Provider double: returns controlled metric rows, or fails transiently. */
$p22Provider = static fn (array $rows, bool $fail = false): \Kuyash\Publish\PublishProvider
    => new class($rows, $fail) implements \Kuyash\Publish\PublishProvider {
        /** @param list<array<string, mixed>> $rows */
        public function __construct(private array $rows, private bool $fail) {}
        public function publish(PublishRequest $request): PublishOutcome { return PublishOutcome::rejected('n/a'); }
        public function status(string $externalPostId): PublishOutcome { return PublishOutcome::rejected('n/a'); }
        public function name(): string { return 'p22fake'; }
        public function accounts(?string $platform = null): array { return []; }
        public function accountMetrics(?string $platform = null, ?string $from = null, ?string $to = null): array
        {
            if ($this->fail) {
                throw new \Kuyash\Publish\PublishProviderException('transient');
            }

            return $this->rows;
        }
    };

$p22Row = static fn (string $ref, ?int $followers, array $posts, bool $hasAnalytics = true): array => [
    'external_ref' => $ref, 'platform' => 'instagram', 'username' => 'ai.neeidy',
    'followers' => $followers, 'has_analytics' => $hasAnalytics, 'posts' => $posts, 'raw' => ['probe' => 'x'],
];

// ── (1b) the REAL adapter: what it keeps, and what it refuses to keep ───────
// The live account object carries ~43 fields (byokCredentials, tokenExpiresAt,
// platformUserId, profile URLs…). Kuyash never stores platform credentials, and
// the vendor may add a field at any time — so persistence is allowlisted.

$p22AcctBody = static fn (): HttpResponse => new HttpResponse(200, (string) json_encode([
    'hasAnalyticsAccess' => true,
    'accounts' => [[
        '_id' => '6a2f250a5f7d1751abb4803a', 'platform' => 'instagram', 'username' => 'ai.neeidy',
        'followersCount' => 7, 'externalPostCount' => 0, 'isActive' => true, 'platformStatus' => 'active',
        // fields that MUST NOT be retained:
        'byokCredentials' => ['isActive' => true], 'tokenExpiresAt' => '2026-10-01T00:00:00Z',
        'platformUserId' => '17841400000000000', 'profileUrl' => 'https://instagram.com/ai.neeidy',
        'profilePicture' => 'https://cdn.example/pic.jpg', 'userId' => 'u_secret_123',
    ]],
]));
$p22Analytics = static fn (array $posts = [], array $overview = []): HttpResponse => new HttpResponse(200, (string) json_encode([
    'hasAnalyticsAccess' => true, 'overview' => $overview ?: ['totalPosts' => 0], 'posts' => $posts,
    'pagination' => ['page' => 1, 'limit' => 50, 'total' => count($posts), 'pages' => 1],
]));

$p22Live = $mkZernio(new FakeHttpClient([$p22AcctBody(), $p22Analytics()]))->accountMetrics(null, '2026-07-24', '2026-08-23');
check('p22/adapter: the real adapter maps the VERIFIED live fields (id + audience)',
    count($p22Live) === 1 && $p22Live[0]['external_ref'] === '6a2f250a5f7d1751abb4803a'
    && $p22Live[0]['followers'] === 7 && $p22Live[0]['has_analytics'] === true);
check('p22/adapter: today\'s real state — analytics reachable but NO posts → honest empty list',
    $p22Live[0]['posts'] === []);
check('p22/security: credentials/token/PII fields are NOT retained (allowlist, not the raw object)',
    (static function () use ($p22Live): bool {
        $keys = array_keys($p22Live[0]['raw']['account'] ?? []);
        foreach (['byokCredentials', 'tokenExpiresAt', 'platformUserId', 'profileUrl', 'profilePicture', 'userId'] as $forbidden) {
            if (in_array($forbidden, $keys, true)) {
                return false;
            }
        }

        return in_array('_id', $keys, true) && in_array('followersCount', $keys, true);
    })());
check('p22/security: a NEW vendor field is not silently persisted (allowlist is closed, not a denylist)',
    (static function () use ($mkZernio, $p22Analytics): bool {
        $body = new HttpResponse(200, (string) json_encode(['hasAnalyticsAccess' => true, 'accounts' => [[
            '_id' => 'a1', 'platform' => 'instagram', 'username' => 'x', 'followersCount' => 1,
            'accessToken' => 'SECRET-SHOULD-NEVER-PERSIST',   // hypothetical future field
        ]]]));
        $rows = $mkZernio(new FakeHttpClient([$body, $p22Analytics()]))->accountMetrics();

        return !str_contains((string) json_encode($rows[0]['raw']), 'SECRET-SHOULD-NEVER-PERSIST');
    })());
check('p22/adapter: reach/impressions are NOT stored as views (a true number behind a false label is still a lie)',
    (static function () use ($mkZernio, $p22AcctBody, $p22Analytics): bool {
        $posts = [['accountId' => '6a2f250a5f7d1751abb4803a', 'platformPostId' => 'p1', 'reach' => 500, 'impressions' => 900, 'likes' => 3]];
        $rows = $mkZernio(new FakeHttpClient([$p22AcctBody(), $p22Analytics($posts)]))->accountMetrics();

        return $rows[0]['posts'][0]['views'] === null      // neither reach nor impressions became "views"
            && $rows[0]['posts'][0]['likes'] === 3;        // a real synonym still maps
    })());
check('p22/tenancy: install-wide analytics are not fanned into per-account rows',
    (static function () use ($mkZernio, $p22AcctBody, $p22Analytics): bool {
        $posts = [['platformPostId' => 'orphan', 'views' => 10]];   // no account id → unattributed
        $rows = $mkZernio(new FakeHttpClient([$p22AcctBody(), $p22Analytics($posts, ['totalPosts' => 42])]))->accountMetrics();
        $raw = (string) json_encode($rows[0]['raw']);

        return $rows[0]['posts'] === []                       // never guessed onto this account
            && !str_contains($raw, 'totalPosts')              // install-wide overview not copied per row
            && str_contains($raw, 'unattributed_post_count'); // only a harmless count is kept
    })());

// ── (2) daily snapshot chore ────────────────────────────────────────────────

$snapDb = migratedDb($basePath);
[, $snapWs] = seedUser($snapDb, 'snap@x.com', $argonHash, 'SnapWS');
$snapCtx = new WorkspaceContext($snapDb);
$snapCtx->set($snapWs);
$snapRepo = new AccountRepository($snapDb);
$snapDay1 = '2026-08-22T10:00:00Z';
$snapAcct = $snapRepo->connect($snapCtx, 'instagram', '@ai.neeidy', 'REF_LIVE', $snapDay1);

$snapPosts = [
    ['external_post_id' => 'p1', 'views' => 100, 'likes' => 10, 'comments' => 2, 'shares' => 1],
    ['external_post_id' => 'p2', 'views' => 50, 'likes' => 5, 'comments' => null, 'shares' => null],
];
$snapChore = new \Kuyash\Analytics\DailySnapshot($snapDb, $p22Provider([$p22Row('REF_LIVE', 7, $snapPosts)]));
$snapWrote = $snapChore->capture($snapDay1);
$snapRowDb = $snapDb->one('SELECT * FROM account_metrics WHERE account_id = ?', [$snapAcct]);

check('p22/snapshot: captures one row carrying the REAL follower count',
    $snapWrote === 1 && (int) $snapRowDb['followers'] === 7 && (int) $snapRowDb['workspace_id'] === $snapWs);
check('p22/snapshot: aggregates per-post engagement across the window',
    (int) $snapRowDb['post_count'] === 2 && (int) $snapRowDb['views'] === 150
    && (int) $snapRowDb['likes'] === 15 && (int) $snapRowDb['comments'] === 2 && (int) $snapRowDb['shares'] === 1);
check('p22/snapshot: per-post rows + raw payload are preserved for later re-mapping',
    str_contains((string) $snapRowDb['posts_json'], 'p1') && str_contains((string) $snapRowDb['raw_json'], 'probe'));
check('p22/snapshot: the hot follower value lands on the account row',
    (int) $snapDb->one('SELECT followers_count FROM accounts WHERE id = ?', [$snapAcct])['followers_count'] === 7);
check('p22/snapshot: re-running the same UTC day writes NOTHING (idempotent chore)',
    $snapChore->capture('2026-08-22T23:59:00Z') === 0
    && (int) $snapDb->one('SELECT COUNT(*) AS n FROM account_metrics')['n'] === 1);
check('p22/snapshot: a read-only metrics poll records ZERO spend (no usage/credit rows)',
    (int) $snapDb->one('SELECT COUNT(*) AS n FROM usage_events')['n'] === 0
    && (int) $snapDb->one('SELECT COUNT(*) AS n FROM credit_transactions')['n'] === 0);

// today's live reality: analytics reachable, but the post list is EMPTY
$snapDay2 = '2026-08-23T10:00:00Z';
$snapEmpty = new \Kuyash\Analytics\DailySnapshot($snapDb, $p22Provider([$p22Row('REF_LIVE', 9, [], false)]));
$snapEmpty->capture($snapDay2);
$snapRow2 = $snapDb->one('SELECT * FROM account_metrics WHERE snapshot_date = ?', ['2026-08-23']);
check('p22/snapshot: an empty analytics list stays HONEST — real followers, zero posts, NULL metrics (never 0)',
    (int) $snapRow2['followers'] === 9 && (int) $snapRow2['post_count'] === 0
    && $snapRow2['views'] === null && $snapRow2['likes'] === null
    && (int) $snapRow2['has_analytics'] === 0);

check('p22/snapshot: a transient provider failure yields 0 and never throws into the worker loop',
    (new \Kuyash\Analytics\DailySnapshot($snapDb, $p22Provider([], true)))->capture('2026-08-24T10:00:00Z') === 0);

// An account the provider does not report must still be RECORDED, otherwise it
// stays "due" forever and re-polls on every 5-minute chore tick (~288/day)
// against an undocumented rate limit.
check('p22/snapshot: an unmatched account is recorded with NULL metrics, so it stops re-polling',
    (static function () use ($basePath, $argonHash, $p22Provider, $p22Row): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'nomatch@x.com', $argonHash, 'NoMatchWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        (new AccountRepository($db))->connect($ctx, 'instagram', '@ghost', 'zacct_PLACEHOLDER', '2026-08-22T10:00:00Z');

        // the provider knows a DIFFERENT account
        $chore = new \Kuyash\Analytics\DailySnapshot($db, $p22Provider([$p22Row('SOMEONE_ELSE', 99, [])]));
        $first = $chore->capture('2026-08-22T10:00:00Z');
        $row = $db->one('SELECT * FROM account_metrics');
        $second = $chore->capture('2026-08-22T18:00:00Z');   // later the same UTC day

        return $first === 1
            && $row['followers'] === null && (int) $row['post_count'] === 0   // honest "learned nothing"
            && (int) $row['has_analytics'] === 0
            && $second === 0                                                   // no longer due → no re-poll
            && $db->one('SELECT followers_count FROM accounts')['followers_count'] === null;
    })());

// tenant isolation: a sibling workspace never receives another tenant's metrics
[, $snapWs2] = seedUser($snapDb, 'snap2@x.com', $argonHash, 'SnapWS2');
$snapCtx2 = new WorkspaceContext($snapDb);
$snapCtx2->set($snapWs2);
$snapAcct2 = $snapRepo->connect($snapCtx2, 'instagram', '@ai.neeidy', 'REF_LIVE', '2026-08-24T10:00:00Z');
(new \Kuyash\Analytics\DailySnapshot($snapDb, $p22Provider([$p22Row('REF_LIVE', 7, [])])))->capture('2026-08-24T10:00:00Z');
check('p22/snapshot: each workspace gets its OWN row, scoped to its own account',
    (int) $snapDb->one('SELECT COUNT(*) AS n FROM account_metrics WHERE workspace_id = ? AND account_id = ?', [$snapWs2, $snapAcct2])['n'] === 1
    && (int) $snapDb->one('SELECT COUNT(*) AS n FROM account_metrics WHERE workspace_id = ? AND account_id = ?', [$snapWs2, $snapAcct])['n'] === 0);

// ── (3) account de-duplication (the reconnect bug) ──────────────────────────

$dedDb = migratedDb($basePath);
[, $dedWs] = seedUser($dedDb, 'dedup@x.com', $argonHash, 'DedupWS');
$dedCtx = new WorkspaceContext($dedDb);
$dedCtx->set($dedWs);
$dedRepo = new AccountRepository($dedDb);
$dedNow = gmdate(NOW_ISO);
$dedFirst = $dedRepo->connect($dedCtx, 'instagram', '@ai.neeidy', 'REF_OLD', $dedNow);
$dedRepo->disconnect($dedCtx, $dedFirst);
$dedSecond = $dedRepo->connect($dedCtx, 'instagram', '@AI.Neeidy', 'REF_NEW', $dedNow); // same handle, different case + @

check('p22/dedup: reconnecting REVIVES the existing row instead of forking a duplicate',
    $dedSecond === $dedFirst && (int) $dedDb->one('SELECT COUNT(*) AS n FROM accounts')['n'] === 1);
check('p22/dedup: the revived row is connected again and carries the fresh provider ref + handle',
    (static function () use ($dedDb, $dedFirst): bool {
        $r = $dedDb->one('SELECT * FROM accounts WHERE id = ?', [$dedFirst]);
        return $r['status'] === 'connected' && $r['health'] === 'ok'
            && $r['external_ref'] === 'REF_NEW' && $r['handle'] === '@AI.Neeidy';
    })());
check('p22/dedup: a DIFFERENT handle on the same platform still creates its own account',
    $dedRepo->connect($dedCtx, 'instagram', '@other.acct', 'REF_OTHER', $dedNow) !== $dedFirst
    && (int) $dedDb->one('SELECT COUNT(*) AS n FROM accounts')['n'] === 2);
check('p22/dedup: another WORKSPACE may connect the same handle (multi-tenant stays legal)',
    (static function () use ($dedDb, $argonHash, $dedRepo): bool {
        [, $ws2] = seedUser($dedDb, 'dedup2@x.com', $argonHash, 'DedupWS2');
        $ctx2 = new WorkspaceContext($dedDb);
        $ctx2->set($ws2);
        $dedRepo->connect($ctx2, 'instagram', '@ai.neeidy', 'REF_WS2', gmdate(NOW_ISO));
        return (int) $dedDb->one('SELECT COUNT(*) AS n FROM accounts WHERE workspace_id = ?', [$ws2])['n'] === 1;
    })());
check('p22/dedup: the UNIQUE index is the backstop — a raw duplicate INSERT is rejected',
    throws(static fn () => $dedDb->run(
        "INSERT INTO accounts (workspace_id, platform, handle, external_ref, status, health, created_at, updated_at)
         VALUES (?, 'instagram', 'ai.neeidy', 'X', 'connected', 'ok', ?, ?)",
        [$dedWs, $dedNow, $dedNow],
    )));

// migration 0015 repairs EXISTING duplicates without orphaning anything (K2)
check('p22/dedup: migration 0015 re-points posts to the canonical row, then deletes the duplicate',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [$user, $ws] = seedUser($db, 'repair@x.com', $argonHash, 'RepairWS');
        $now = gmdate(NOW_ISO);
        // recreate the pre-fix state: the index must be gone for duplicates to exist
        $db->run('DROP INDEX uq_accounts_ws_platform_handle');
        $mk = static function (string $handle, string $status) use ($db, $ws, $now): int {
            $db->run(
                'INSERT INTO accounts (workspace_id, platform, handle, external_ref, status, health, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$ws, 'instagram', $handle, 'REF', $status, 'ok', $now, $now],
            );
            return $db->lastInsertId();
        };
        $stale = $mk('@ai.neeidy', 'disconnected');   // the duplicate to remove
        $live = $mk('ai.neeidy', 'connected');        // the canonical row
        $db->run(
            "INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
             VALUES (?, 'repair', 'full', '[]', ?, ?)",
            [$ws, $now, $now],
        );
        $wfId = $db->lastInsertId();
        $db->run(
            "INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at)
             VALUES (?, ?, 'trend', '[]', 'completed', ?, ?, ?)",
            [$ws, $wfId, $user, $now, $now],
        );
        $runId = $db->lastInsertId();
        $db->run(
            "INSERT INTO posts (workspace_id, run_id, account_id, platform, status, idempotency_key, created_at, updated_at)
             VALUES (?, ?, ?, 'instagram', 'published', ?, ?, ?)",
            [$ws, $runId, $stale, 'k1', $now, $now],   // a REAL published post on the doomed row
        );
        $postId = $db->lastInsertId();

        $sql = file_get_contents($basePath . '/database/migrations/0015_accounts_dedup.sql');
        $db->pdo()->exec((string) $sql);

        $post = $db->one('SELECT account_id FROM posts WHERE id = ?', [$postId]);
        $orphans = $db->all('PRAGMA foreign_key_check');

        return (int) $post['account_id'] === $live                                      // re-pointed, not orphaned
            && $db->one('SELECT id FROM accounts WHERE id = ?', [$stale]) === null      // duplicate gone
            && $db->one('SELECT id FROM accounts WHERE id = ?', [$live]) !== null       // canonical kept
            && $orphans === [];                                                          // FK integrity intact
    })());

// ── (4) real followers reach the UI, sample data stays labelled ─────────────

$syncFollowDb = migratedDb($basePath);
[, $sfWs] = seedUser($syncFollowDb, 'follow@x.com', $argonHash, 'FollowWS');
$sfCtx = new WorkspaceContext($syncFollowDb);
$sfCtx->set($sfWs);
$sfRepo = new AccountRepository($syncFollowDb);
$sfId = $sfRepo->connect($sfCtx, 'instagram', '@ai.neeidy', 'zacct_STALE', gmdate(NOW_ISO));
$sfCtl = new AccountsController(
    $view, $sfRepo, new PostRepository($syncFollowDb), new AssetRepository($syncFollowDb),
    new PublishCounter($syncFollowDb), new WorkspaceSettings($syncFollowDb), $sfCtx, new Csrf(), new Flash(),
    $p22Provider([$p22Row('REF_REAL', 7, [])]), new SlotRepository($syncFollowDb),
);
$sfCtl->sync();
$sfRow = $sfRepo->find($sfCtx, $sfId);

check('p22/sync: one reconcile pass fixes the provider ref AND stores the real audience',
    $sfRow['external_ref'] === 'REF_REAL' && $sfRow['followers_count'] === 7);
check('p22/sync: an unreported follower count never overwrites a stored one with 0',
    (static function () use ($view, $syncFollowDb, $sfRepo, $sfCtx, $sfId, $p22Provider, $p22Row): bool {
        (new AccountsController(
            $view, $sfRepo, new PostRepository($syncFollowDb), new AssetRepository($syncFollowDb),
            new PublishCounter($syncFollowDb), new WorkspaceSettings($syncFollowDb), $sfCtx, new Csrf(), new Flash(),
            $p22Provider([$p22Row('REF_REAL', null, [])]), new SlotRepository($syncFollowDb),
        ))->sync();

        return $sfRepo->find($sfCtx, $sfId)['followers_count'] === 7;
    })());
check('p22/repo: an account the provider never reported keeps a NULL audience (honest gap, not 0)',
    $sfRepo->find($sfCtx, $sfRepo->connect($sfCtx, 'tiktok', '@never.synced', 'R', gmdate(NOW_ISO)))['followers_count'] === null);

$p22View = new View($basePath . '/templates');
$p22Real = $p22View->render('partials/account-card', [
    'account' => ['id' => 7, 'platform' => 'instagram', 'handle' => '@ai.neeidy', 'health' => 'ok', 'status' => 'connected', 'followers_count' => 7],
    'manage' => false,
]);
$p22Sample = $p22View->render('partials/account-card', [
    'account' => ['id' => 7, 'platform' => 'instagram', 'handle' => '@ai.neeidy', 'health' => 'ok', 'status' => 'connected', 'followers_count' => null],
    'manage' => false,
]);
check('p22/card: a REAL follower count renders bare — no "sample" chip beside it',
    str_contains($p22Real, '>7</span> ' . View::t('acct.followers'))
    && substr_count($p22Real, 'acc-card__sample chip') === 0);   // no stand-in badge anywhere on a real account
check('p22/card: a stand-in follower count is CHIPPED as sample (every fabricated number is marked)',
    substr_count($p22Sample, 'acc-card__sample chip') === 1        // engagement strip
    && substr_count($p22Sample, 'acc-card__sample--foot') === 1    // the follower marker
    && !str_contains($p22Sample, '>7</span> ' . View::t('acct.followers')));
// REGRESSION GUARD: the chip briefly lived inside __who, which truncates with an
// ellipsis — the marker vanished on a narrow card and a fabricated follower count
// rendered as if it were measured. The screenshot gate passed anyway (no console
// error, no overflow), so the invariant is asserted here in markup terms.
check('p22/card: the footer chip sits OUTSIDE the truncating __who span (an ellipsis must never eat the label)',
    (static function () use ($p22Sample): bool {
        $whoTail = strpos($p22Sample, View::t('acct.followers'));
        $chip = strpos($p22Sample, 'acc-card__sample--foot');
        if ($whoTail === false || $chip === false) {
            return false;
        }
        $between = substr($p22Sample, $whoTail, $chip - $whoTail);

        return str_contains($between, '</span>');  // __who is closed before the chip opens
    })());
check('p22/card: the footer chip is a flex sibling, not clipped content (CSS backs the markup)',
    preg_match('/\.acc-card__sample--foot\s*\{[^}]*flex:\s*none/s', (string) file_get_contents($basePath . '/public/assets/css/app.css')) === 1);
check('p22/card: the sample growth line is hidden next to a real audience (no unlabelled fake beside a fact)',
    !str_contains($p22Real, 'acc-card__grow') && str_contains($p22Sample, 'acc-card__grow'));
// INVERTED in the fix round: this used to assert that a real account still showed
// sample-framed engagement. That is now exactly what must NOT happen — and the old
// assertion only passed because the class "acc-card__sample--empty" contains the
// substring "sample", so it would have kept passing if a real chip came back.
check('p22/card: a provider-backed card carries NO stand-in engagement chip at all',
    str_contains($p22Real, 'acc-card__eng')
    && !str_contains($p22Real, 'acc-card__sample chip')
    && str_contains($p22Real, View::t('acct.no_metrics')));
// A REAL, connected channel must never carry an invented engagement figure —
// not even a chipped one. A stand-in sitting on a genuine account misrepresents
// that account; the honest render is a dash plus "no data yet".
$p22RealCard = static fn (array $extra = []): string => (new View($basePath . '/templates'))->render('partials/account-card', [
    'account' => array_merge([
        'id' => 7, 'platform' => 'instagram', 'handle' => '@ai.neeidy',
        'health' => 'ok', 'status' => 'connected', 'followers_count' => 7,
    ], $extra),
    'manage' => false,
]);

$p22NoMetrics = $p22RealCard();
check('p22/compliance: a provider-backed account shows NO fabricated engagement — dashes, not stand-ins',
    substr_count($p22NoMetrics, '>—</span>') === 3
    && str_contains($p22NoMetrics, View::t('acct.no_metrics'))
    && !str_contains($p22NoMetrics, 'acc-card__sample chip'));
check('p22/compliance: the fabricated engagement numbers are absent from a real account\'s markup',
    (static function () use ($p22NoMetrics): bool {
        // the deterministic stand-ins for this seed must not appear anywhere
        foreach (['9.5K', '1.9K', '298'] as $standIn) {
            if (str_contains($p22NoMetrics, '>' . $standIn . '</span>')) {
                return false;
            }
        }

        return true;
    })());
check('p22/compliance: real reported engagement renders as a plain number with NO badge at all',
    (static function () use ($p22RealCard): bool {
        $html = $p22RealCard(['metric_likes' => 12, 'metric_comments' => 3, 'metric_shares' => 0]);

        return str_contains($html, '>12</span>') && str_contains($html, '>3</span>')
            && !str_contains($html, View::t('acct.no_metrics'))
            && !str_contains($html, 'acc-card__sample chip');   // unmarked == measured
    })());
check('p22/compliance: a demo (non-provider) account KEEPS its chipped stand-ins — screens stay populated',
    substr_count($p22Sample, 'acc-card__sample chip') === 1
    && !str_contains($p22Sample, View::t('acct.no_metrics'))
    && substr_count($p22Sample, '>—</span>') === 0);
// A mock provider invents its figures. If those reach the card unmarked they read
// as measurements — which would be WORSE than the chipped stand-ins this round
// replaced, and it is the DEFAULT state of a fresh install (ZERNIO_MOCK=true).
check('p22/compliance: a mock-sourced snapshot is treated as demo data, never as a measurement',
    (static function () use ($p22RealCard): bool {
        // the state a mock chore can actually produce: a snapshot row, and no
        // follower count, because DailySnapshot refuses to write one (asserted
        // directly by the next check)
        $html = $p22RealCard(['metric_provider' => 'mock', 'metric_date' => '2026-08-23',
            'metric_likes' => 4242, 'followers_count' => null]);

        return str_contains($html, 'acc-card__sample chip')      // chipped as a stand-in
            && !str_contains($html, '>4242</span>')              // the invented number is not shown as fact
            && !str_contains($html, View::t('acct.no_metrics'));
    })());
// The live defect this pins: ws2 ran with ZERNIO_MOCK=true, so the newest
// snapshot for a REAL connected Instagram account was mock-written — and the
// card demoted the whole account to the demo branch. The dashboard printed
// "7.2K followers", "+67 today", 9.5K likes and a still from a clip that
// account never published, under its real handle, while the provider-measured
// 7 sat unshown in the row it was reading.
check('p22/compliance: a mock snapshot is ABSENT DATA — it never licenses fabrication over a real channel',
    (static function () use ($p22RealCard): bool {
        $html = $p22RealCard(['metric_provider' => 'mock', 'metric_date' => '2026-08-27',
            'metric_likes' => 4242, 'followers_count' => 7]);

        return str_contains($html, '>7</span>')                   // the measured audience, bare
            && !str_contains($html, 'acc-card__sample chip')      // nothing on this card is invented
            && !str_contains($html, '>4242</span>')               // and the mock's own metric is not shown
            && substr_count($html, '>—</span>') === 3             // unreported engagement is a dash
            && str_contains($html, View::t('acct.no_metrics'))
            && !str_contains($html, 'acc-card__frame')            // no borrowed still on a real handle
            && !str_contains($html, 'acct.growth_today')
            && !str_contains($html, View::t('acct.growth_today', ['n' => 67]));
    })());
check('p22/compliance: a mock provider never writes the audience field the UI renders unmarked',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'mocksrc@x.com', $argonHash, 'MockSrcWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $repo = new AccountRepository($db);
        $mock = new \Kuyash\Publish\MockPublishProvider();
        $ref = $mock->accounts('instagram')[0]['external_ref'];
        $repo->connect($ctx, 'instagram', '@demo_instagram', $ref, '2026-08-22T10:00:00Z');

        (new \Kuyash\Analytics\DailySnapshot($db, $mock))->capture('2026-08-22T10:00:00Z');

        $row = $db->one('SELECT provider FROM account_metrics');
        $acct = $repo->listFor($ctx)[0];

        return $row['provider'] === 'mock'                  // audit trail kept
            && $acct['followers_count'] === null            // but the "measured" field stays empty
            && $acct['metric_provider'] === 'mock';         // and the card can tell
    })());
// The provider fills followersCount asynchronously, so engagement can arrive
// first. Keying "is this real?" on followers alone would drop such an account
// into the demo branch and paint fabricated engagement OVER measured data.
check('p22/compliance: engagement arriving BEFORE a follower count still counts as a real account',
    (static function () use ($p22RealCard): bool {
        $html = $p22RealCard(['followers_count' => null, 'metric_provider' => 'zernio',
            'metric_date' => '2026-08-23', 'metric_likes' => 12]);

        return str_contains($html, '>12</span>')                 // the measured number is shown
            && !str_contains($html, 'acc-card__sample chip')     // and nothing is fabricated over it
            && str_contains($html, '>—</span>');                 // the missing audience is a dash
    })());
check('p22/compliance: a real account with NO audience figure shows a dash, never a stand-in follower count',
    (static function () use ($p22RealCard): bool {
        $html = $p22RealCard(['followers_count' => null, 'metric_provider' => 'zernio', 'metric_date' => '2026-08-23']);

        return substr_count($html, '>—</span>') === 4            // 3 engagement + the follower line
            && !str_contains($html, 'acc-card__grow')            // no invented growth line either
            && !str_contains($html, 'acc-card__sample chip');
    })());
check('p22/repo: the account read carries the newest snapshot metrics, workspace-scoped',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'metrics@x.com', $argonHash, 'MetricsWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $repo = new AccountRepository($db);
        $id = $repo->connect($ctx, 'instagram', '@m', 'REF', gmdate(NOW_ISO));
        foreach ([['2026-08-21', 1], ['2026-08-23', 99], ['2026-08-22', 50]] as [$day, $likes]) {
            $db->run(
                "INSERT INTO account_metrics (workspace_id, account_id, snapshot_date, likes, provider, created_at)
                 VALUES (?, ?, ?, ?, 'x', ?)",
                [$ws, $id, $day, $likes, gmdate(NOW_ISO)],
            );
        }
        $row = $repo->find($ctx, $id);
        $listed = $repo->listFor($ctx)[0];

        // sibling workspace must not see these metrics
        [, $ws2] = seedUser($db, 'metrics2@x.com', $argonHash, 'MetricsWS2');
        $ctx2 = new WorkspaceContext($db);
        $ctx2->set($ws2);

        return $listed['metric_likes'] === 99          // newest snapshot wins
            && $listed['metric_date'] === '2026-08-23'
            && $row !== null
            && $repo->listFor($ctx2) === [];
    })());
check('p22/copy: the sample note claims nothing about unmarked figures being fabricated',
    stripos(View::t('acct.sample_note'), 'sample') !== false
    && stripos(View::t('acct.sample_note'), 'connected account') !== false);

// ── (5) the nav pill no longer springs back ────────────────────────────────

$p22Css = (string) file_get_contents($basePath . '/public/assets/css/app.css');
$p22Motion = (string) file_get_contents($basePath . '/public/assets/js/motion.js');

// THE REBOUND, properly diagnosed. Kuyash is multi-page: every nav click reloads
// the document, the pill is re-created at translateY(0) and JS then moves it to
// the active item. With a transform transition armed in the BASE state that
// startup move animates, so the indicator flew down from the top on every click.
// Measured on /settings before the fix: active offsetTop 351, pill translateY(0),
// a 250ms transform transition "running" immediately after load. Swapping the
// easing curve (an earlier attempt) could never fix this — the defect is that the
// first placement is animated at all.
check('p22/ui: the pill base state has NO transform transition (first placement must not animate)',
    preg_match('/\.nav-item__pill\s*\{(?:(?!\}).)*transition:(?:(?!\}).)*transform/s', $p22Css) !== 1);
check('p22/ui: the transform transition is armed only via .is-ready (so hover still glides)',
    preg_match('/\.nav-item__pill\.is-ready\s*\{[^}]*transition:\s*transform\s+var\(--dur-quick\)\s+var\(--ease-out\)/s', $p22Css) === 1);
check('p22/ui: --spring is NOT used by the pill (that curve also overshoots)',
    preg_match('/\.nav-item__pill[^{]*\{[^}]*var\(--spring\)/s', $p22Css) !== 1);
check('p22/ui: the initial position is committed with a layout flush before transitions are armed',
    preg_match('/moveTo\(activeItem\(\)\);\s*(?:\/\*.*?\*\/\s*)?void pill\.offsetHeight;/s', $p22Motion) === 1);
// rAF is suspended in a hidden tab, so a page opened in a background tab never
// reached .is-ready and the pill stayed invisible (opacity 0) until focus.
check('p22/ui: .is-ready is applied synchronously, not inside requestAnimationFrame (hidden tabs suspend rAF)',
    preg_match('/requestAnimationFrame\(function \(\) \{ pill\.classList\.add\(\x27is-ready\x27\)/s', $p22Motion) !== 1
    && preg_match('/void pill\.offsetHeight;\s*pill\.classList\.add\(\x27is-ready\x27\);/s', $p22Motion) === 1);
check('p22/ui: revealing the pill is a state flip, not an opacity transition (would also stall while hidden)',
    preg_match('/\.nav-item__pill\.is-ready\s*\{[^}]*transition:[^;]*opacity/s', $p22Css) !== 1);

// ── (6) jargon: a machine timestamp never reaches the operator ──────────────

check('p22/jargon: Messages::since humanizes an ISO stamp into a relative phrase',
    Messages::since('2026-08-22T10:00:30Z', '2026-08-22T10:00:50Z') === View::t('time.just_now')
    && Messages::since('2026-08-22T09:56:00Z', '2026-08-22T10:00:00Z') === View::t('time.minutes_ago', ['n' => 4])
    && Messages::since('2026-08-22T07:00:00Z', '2026-08-22T10:00:00Z') === View::t('time.hours_ago', ['n' => 3])
    && Messages::since('2026-08-19T10:00:00Z', '2026-08-22T10:00:00Z') === View::t('time.days_ago', ['n' => 3]));
check('p22/jargon: an unparseable stamp falls back to the raw value (never a wrong "just now")',
    Messages::since('not-a-date', '2026-08-22T10:00:00Z') === 'not-a-date');
check('p22/jargon: a future/clock-skewed stamp does not produce a negative age',
    Messages::since('2026-08-22T10:05:00Z', '2026-08-22T10:00:00Z') === View::t('time.just_now'));
check('p22/jargon: "sample" means ONE thing in the UI — the statistical sense is renamed to "checks"',
    (static function (): bool {
        foreach (['en', 'tr'] as $loc) {
            I18n::setLocale($loc);
            // the honesty marker keeps the word; the sample-SIZE label must not reuse it
            if (mb_strtolower(View::t('digest.sample')) === mb_strtolower(View::t('acct.sample'))) {
                return false;
            }
        }
        I18n::setLocale('en');

        return true;
    })());
check('p22/jargon: engineering vocabulary is gone from the budget copy (both languages)',
    (static function (): bool {
        foreach (['en' => 'pre-flight', 'tr' => 'ön-uçuş'] as $loc => $term) {
            I18n::setLocale($loc);
            $copy = mb_strtolower(View::t('usage.no_cap_body_1') . View::t('usage.no_cap_body_2'));
            if (str_contains($copy, $term) || trim($copy) === '.') {
                return false;
            }
        }
        I18n::setLocale('en');

        return true;
    })());
check('p22/jargon: trend format chips match ADR-012 (nothing is "shot") and TR avoids the "yüzsüz" idiom',
    (static function (): bool {
        I18n::setLocale('en');
        $en = View::t('trends.format_face') . '|' . View::t('trends.format_faceless');
        I18n::setLocale('tr');
        $tr = View::t('trends.format_face') . '|' . View::t('trends.format_faceless');
        I18n::setLocale('en');

        return !str_contains(mb_strtolower($en), 'shoot')
            && !str_contains(mb_strtolower($tr), 'yüzsüz')
            && !str_contains(mb_strtolower($tr), 'çekim');
    })());
check('p22/jargon: the trends freshness chip renders the relative phrase, not the Z-suffixed stamp',
    (static function () use ($basePath): bool {
        $tpl = (string) file_get_contents($basePath . '/templates/trends/index.php');
        return str_contains($tpl, 'Messages::since((string) $feed->fetchedAt)')
            && preg_match('/·\s*<\?=\s*View::e\(\(string\)\s*\$feed->fetchedAt\)/', $tpl) !== 1;
    })());

echo "== Phase 23: Planned publishing (weekly slots) ==\n";

I18n::setLocale('en');
$p23Resolver = new SlotResolver();

// ── (1) resolving a weekly slot to a real instant ───────────────────────────

// 2026-08-23 is a Sunday. Istanbul is UTC+3, so Monday 09:00 local = 06:00Z.
check('p23/resolve: a weekly slot becomes the next matching UTC instant',
    $p23Resolver->nextOccurrence('Europe/Istanbul', 1, '09:00', '2026-08-23T12:00:00Z') === '2026-08-24T06:00:00Z');
check('p23/resolve: a slot earlier TODAY rolls to next week, never into the past',
    $p23Resolver->nextOccurrence('UTC', 7, '09:00', '2026-08-23T12:00:00Z') === '2026-08-30T09:00:00Z');
check('p23/resolve: a slot later today fires today',
    $p23Resolver->nextOccurrence('UTC', 7, '18:00', '2026-08-23T12:00:00Z') === '2026-08-23T18:00:00Z');

// DAYLIGHT SAVING is the whole reason this is a resolver and not string maths:
// the operator asked for 09:00 local, so the UTC instant MUST move by an hour
// across a DST boundary — otherwise the post drifts twice a year.
check('p23/resolve: the same slot maps to different UTC instants in winter and summer',
    $p23Resolver->nextOccurrence('America/New_York', 3, '09:00', '2026-01-05T00:00:00Z') === '2026-01-07T14:00:00Z'
    && $p23Resolver->nextOccurrence('America/New_York', 3, '09:00', '2026-07-05T00:00:00Z') === '2026-07-08T13:00:00Z');
check('p23/resolve: a week that CROSSES the DST change keeps the local wall-clock time',
    // 2026-03-04 09:00 EST has passed → next Wednesday is 03-11, after the shift:
    // still 09:00 for the operator, which is 13:00Z and not 14:00Z
    $p23Resolver->nextOccurrence('America/New_York', 3, '09:00', '2026-03-04T15:00:00Z') === '2026-03-11T13:00:00Z');
check('p23/resolve: invalid weekday / time / timezone yield null, never a guessed instant',
    $p23Resolver->nextOccurrence('Europe/Istanbul', 8, '09:00', '2026-08-23T12:00:00Z') === null
    && $p23Resolver->nextOccurrence('Europe/Istanbul', 1, '25:00', '2026-08-23T12:00:00Z') === null
    && $p23Resolver->nextOccurrence('Mars/Olympus', 1, '09:00', '2026-08-23T12:00:00Z') === null);
check('p23/resolve: nextAmong returns the soonest of several slots',
    $p23Resolver->nextAmong('UTC', [
        ['weekday' => 5, 'time_hhmm' => '10:00'],
        ['weekday' => 1, 'time_hhmm' => '08:00'],
        ['weekday' => 3, 'time_hhmm' => '12:00'],
    ], '2026-08-23T12:00:00Z') === '2026-08-24T08:00:00Z');
check('p23/resolve: the resolver never reads the clock (same inputs → same output)',
    $p23Resolver->nextOccurrence('Europe/Istanbul', 1, '09:00', '2026-08-23T12:00:00Z')
    === $p23Resolver->nextOccurrence('Europe/Istanbul', 1, '09:00', '2026-08-23T12:00:00Z'));

// ── (2) the slot store ──────────────────────────────────────────────────────

$p23Db = migratedDb($basePath);
[, $p23Ws] = seedUser($p23Db, 'slots@x.com', $argonHash, 'SlotsWS');
$p23Ctx = new WorkspaceContext($p23Db);
$p23Ctx->set($p23Ws);
$p23Slots = new SlotRepository($p23Db);
$p23Now = '2026-08-23T12:00:00Z';

$p23SlotId = $p23Slots->add($p23Ctx, 1, '09:00', null, $p23Now);
check('p23/slots: a slot is stored and read back for the workspace',
    $p23SlotId !== null && count($p23Slots->listFor($p23Ctx)) === 1);
check('p23/slots: adding the SAME slot twice is a no-op (the UNIQUE index covers NULL accounts)',
    $p23Slots->add($p23Ctx, 1, '09:00', null, $p23Now) === null
    && count($p23Slots->listFor($p23Ctx)) === 1);
check('p23/slots: an invalid weekday or time is rejected outright',
    $p23Slots->add($p23Ctx, 0, '09:00', null, $p23Now) === null
    && $p23Slots->add($p23Ctx, 8, '09:00', null, $p23Now) === null
    && $p23Slots->add($p23Ctx, 2, '24:00', null, $p23Now) === null
    && $p23Slots->add($p23Ctx, 2, '9:00', null, $p23Now) === null);
check('p23/slots: pausing keeps the slot but drops it from the offered list',
    $p23Slots->setEnabled($p23Ctx, (int) $p23SlotId, false, $p23Now)
    && count($p23Slots->listFor($p23Ctx)) === 1
    && $p23Slots->listFor($p23Ctx, enabledOnly: true) === []);
check('p23/slots: resuming puts it back on offer',
    $p23Slots->setEnabled($p23Ctx, (int) $p23SlotId, true, $p23Now)
    && count($p23Slots->listFor($p23Ctx, enabledOnly: true)) === 1);
check('p23/slots: slots are ordered as a week reads (weekday, then time)',
    (static function () use ($p23Slots, $p23Ctx, $p23Now): bool {
        $p23Slots->add($p23Ctx, 1, '07:00', null, $p23Now);
        $p23Slots->add($p23Ctx, 3, '08:00', null, $p23Now);
        $order = array_map(
            static fn (array $s): string => $s['weekday'] . '@' . $s['time_hhmm'],
            $p23Slots->listFor($p23Ctx),
        );

        return $order === ['1@07:00', '1@09:00', '3@08:00'];
    })());

// Tenant isolation, both directions. NOTE: WorkspaceContext resolves the active
// tenant from $_SESSION, so every instance shares it — switching workspaces must
// be done by set()-ing and then restoring, not by holding two context objects.
check('p23/slots: a sibling workspace sees none of these slots and cannot delete them',
    (static function () use ($p23Db, $argonHash, $p23Slots, $p23SlotId, $p23Ctx, $p23Ws): bool {
        [, $ws2] = seedUser($p23Db, 'slots2@x.com', $argonHash, 'SlotsWS2');
        $p23Ctx->set($ws2);
        $isolated = $p23Slots->listFor($p23Ctx) === []
            && $p23Slots->find($p23Ctx, (int) $p23SlotId) === null
            && $p23Slots->remove($p23Ctx, (int) $p23SlotId) === false
            && $p23Slots->setEnabled($p23Ctx, (int) $p23SlotId, false, '2026-08-23T12:00:00Z') === false;
        $p23Ctx->set($p23Ws);

        return $isolated;
    })());
check('p23/slots: a slot cannot be narrowed to ANOTHER workspace\'s account',
    (static function () use ($p23Db, $argonHash, $p23Slots, $p23Ctx, $p23Ws, $p23Now): bool {
        [, $wsOther] = seedUser($p23Db, 'slots3@x.com', $argonHash, 'SlotsWS3');
        $p23Ctx->set($wsOther);
        $foreign = (new AccountRepository($p23Db))->connect($p23Ctx, 'instagram', '@theirs', 'REF', $p23Now);
        $p23Ctx->set($p23Ws);

        return $p23Slots->add($p23Ctx, 5, '10:00', $foreign, $p23Now) === null;
    })());
check('p23/slots: narrowing to an account of the SAME workspace is allowed',
    (static function () use ($p23Db, $p23Slots, $p23Ctx, $p23Now): bool {
        $mine = (new AccountRepository($p23Db))->connect($p23Ctx, 'instagram', '@mine', 'REF2', $p23Now);
        $id = $p23Slots->add($p23Ctx, 6, '11:00', $mine, $p23Now);
        $row = $id === null ? null : $p23Slots->find($p23Ctx, $id);

        return $row !== null && $row['account_id'] === $mine;
    })());
check('p23/slots: removing a slot is tenant-scoped and actually removes it',
    $p23Slots->remove($p23Ctx, (int) $p23SlotId)
    && $p23Slots->find($p23Ctx, (int) $p23SlotId) === null);

// ── (3) workspace timezone ──────────────────────────────────────────────────

$p23Settings = new WorkspaceSettings($p23Db);
check('p23/timezone: defaults to UTC and accepts a real zone',
    $p23Settings->timezone($p23Ws) === 'UTC'
    && $p23Settings->setTimezone($p23Ws, 'Europe/Istanbul')
    && $p23Settings->timezone($p23Ws) === 'Europe/Istanbul');
check('p23/timezone: a bogus zone is refused and the stored one is untouched',
    !$p23Settings->setTimezone($p23Ws, 'Mars/Olympus')
    && !$p23Settings->setTimezone($p23Ws, '')
    && $p23Settings->timezone($p23Ws) === 'Europe/Istanbul');

// ── (4) approval → the SAME queue gate the manual path already used ─────────

check('p23/approve: picking a slot schedules the publish at that slot\'s next instant',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [$user, $ws] = seedUser($db, 'slotapp@x.com', $argonHash, 'SlotAppWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        (new WorkspaceSettings($db))->setTimezone($ws, 'UTC');
        $slots = new SlotRepository($db);
        $slotId = $slots->add($ctx, 1, '09:00', null, '2026-08-23T12:00:00Z');

        // a render awaiting approval, exactly as the manual path builds it
        $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
                  VALUES (?, 'w', 'full', '[]', ?, ?)", [$ws, '2026-08-23T12:00:00Z', '2026-08-23T12:00:00Z']);
        $wf = $db->lastInsertId();
        $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at)
                  VALUES (?, ?, 'trend', '[]', 'awaiting_approval', ?, ?, ?)", [$ws, $wf, $user, '2026-08-23T12:00:00Z', '2026-08-23T12:00:00Z']);
        $runId = $db->lastInsertId();
        $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, max_retries, priority, run_after, created_at)
                  VALUES (?, ?, 'PREVIEW', 1, 'render_review', 'awaiting_approval', '{}', 3, 0, ?, ?)",
            [$ws, $runId, '2026-08-23T12:00:00Z', '2026-08-23T12:00:00Z']);
        $jobId = $db->lastInsertId();

        // the controller resolves the slot; the Engine keeps owning the decision
        $resolved = (new SlotResolver())->nextOccurrence('UTC', 1, '09:00', '2026-08-23T12:00:00Z');
        $engine = new Engine($db, new \Kuyash\Workflow\EventLog($db), new WorkflowValidator(), static fn (): string => '2026-08-23T12:00:00Z');
        $engine->approve($ctx, $jobId, $user, 'slotapp@x.com', $resolved);

        $run = $db->one('SELECT publish_after FROM runs WHERE id = ?', [$runId]);

        return $slotId !== null
            && $resolved === '2026-08-24T09:00:00Z'
            && $run['publish_after'] === '2026-08-24T09:00:00Z';   // the existing column, unchanged
    })());

// A hand-picked time must mean the SAME thing as a slot. The datetime-local
// field carries no zone; reading it as UTC while slots were written in the
// workspace zone made an operator on UTC+3 who typed 09:00 publish at 12:00
// local. Both paths now resolve through the workspace timezone.
check('p23/approve: a hand-picked time is read in the workspace zone, not silently as UTC',
    (static function () use ($basePath, $argonHash, $view): bool {
        $db = migratedDb($basePath);
        [$user, $ws] = seedUser($db, 'tzpick@x.com', $argonHash, 'TzPickWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $settings = new WorkspaceSettings($db);
        $settings->setTimezone($ws, 'Europe/Istanbul');   // UTC+3

        $ctl = new QueueController(
            $view, new JobRepository($db), new RunRepository($db),
            new Engine($db, new \Kuyash\Workflow\EventLog($db), new WorkflowValidator()),
            $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
            new WorkerHeartbeat(tempDir('hb') . '/p23.heartbeat'), new SlotRepository($db), new SlotResolver(), $settings,
            new OccurrenceRepository($db), $db, makeTextEditorView($db));

        $reflect = new ReflectionMethod($ctl, 'requestedSchedule');
        $reflect->setAccessible(true);

        // far enough ahead that it stays in the future whenever the suite runs
        $future = gmdate('Y-m-d', time() + (20 * 86400));
        $_POST = ['scheduled_for' => $future . 'T09:00'];
        $local = $reflect->invoke($ctl);
        $_POST = ['scheduled_for' => $future . 'T09:00:00Z'];   // explicit zone stays as given
        $explicit = $reflect->invoke($ctl);
        $_POST = [];

        return $local['at'] === $future . 'T06:00:00Z'      // 09:00 Istanbul, not 09:00Z
            && $local['error'] === null
            && $explicit['at'] === $future . 'T09:00:00Z';
    })());
check('p23/approve: an unknown or paused slot REFUSES the approval — never a silent immediate publish',
    (static function () use ($basePath, $argonHash, $view): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'slotguard@x.com', $argonHash, 'SlotGuardWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $settings = new WorkspaceSettings($db);
        $slots = new SlotRepository($db);
        $paused = $slots->add($ctx, 1, '09:00', null, '2026-08-23T12:00:00Z');
        $slots->setEnabled($ctx, (int) $paused, false, '2026-08-23T12:00:00Z');

        $ctl = new QueueController(
            $view, new JobRepository($db), new RunRepository($db),
            new Engine($db, new \Kuyash\Workflow\EventLog($db), new WorkflowValidator()),
            $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
            new WorkerHeartbeat(tempDir('hb') . '/p23b.heartbeat'), $slots, new SlotResolver(), $settings, new OccurrenceRepository($db), $db, makeTextEditorView($db));
        $reflect = new ReflectionMethod($ctl, 'requestedSchedule');
        $reflect->setAccessible(true);

        $_POST = ['slot_id' => '99999'];
        $unknown = $reflect->invoke($ctl);
        $_POST = ['slot_id' => (string) $paused];
        $disabled = $reflect->invoke($ctl);
        $_POST = [];

        // REFUSED, not silently downgraded to an immediate publish
        return $unknown['at'] === null && $unknown['error'] === 'slots.unresolvable'
            && $disabled['at'] === null && $disabled['error'] === 'slots.unresolvable';
    })());

// THE FAIL-OPEN THIS PHASE MUST NOT HAVE: a scheduling intent that cannot be
// honoured used to return null, which the Engine reads as "publish now". On a
// live account that is an irreversible post at the wrong time.
check('p23/safety: a past time REFUSES the approval instead of publishing immediately',
    (static function () use ($basePath, $argonHash, $view): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'pastsched@x.com', $argonHash, 'PastSchedWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $settings = new WorkspaceSettings($db);
        $ctl = new QueueController(
            $view, new JobRepository($db), new RunRepository($db),
            new Engine($db, new \Kuyash\Workflow\EventLog($db), new WorkflowValidator()),
            $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
            new WorkerHeartbeat(tempDir('hb') . '/p23c.heartbeat'), new SlotRepository($db), new SlotResolver(), $settings, new OccurrenceRepository($db), $db, makeTextEditorView($db));
        $reflect = new ReflectionMethod($ctl, 'requestedSchedule');
        $reflect->setAccessible(true);

        $_POST = ['scheduled_for' => '2020-01-01T09:00'];
        $past = $reflect->invoke($ctl);
        $_POST = ['scheduled_for' => '9999-12-31T23:59'];
        $farOut = $reflect->invoke($ctl);
        $_POST = ['scheduled_for' => 'garbage'];
        $junk = $reflect->invoke($ctl);
        $_POST = [];   // nothing requested → legitimately publish on approval
        $none = $reflect->invoke($ctl);

        return $past['error'] === 'slots.in_past' && $past['at'] === null
            && $farOut['error'] === 'slots.too_far'
            && $junk['error'] === 'slots.unresolvable'
            && $none === ['at' => null, 'error' => null];
    })());
check('p23/safety: the refusal messages exist in BOTH languages and name the consequence',
    (static function (): bool {
        foreach (['en', 'tr'] as $loc) {
            I18n::setLocale($loc);
            foreach (['slots.unresolvable', 'slots.in_past', 'slots.too_far', 'approval.approved_scheduled'] as $k) {
                if (View::t($k) === $k || trim(View::t($k)) === '') {
                    return false;
                }
            }
            // the two refusals must state that nothing went out
            $said = mb_strtolower(View::t('slots.unresolvable') . View::t('slots.in_past'));
            if (!str_contains($said, $loc === 'en' ? 'nothing was published' : 'hiçbir şey yayınlanmadı')) {
                return false;
            }
        }
        I18n::setLocale('en');

        return true;
    })());
check('p23/flash: a message carries placeholder VALUES, resolved into the reader\'s language', (static function (): bool {
    $saved = $_SESSION;
    $_SESSION = [];
    $f = new Flash();
    $f->add('success', 'approval.approved_scheduled', ['when' => 'in 3 h']);
    $resolved = Messages::resolveFlashes($f);
    $_SESSION = $saved;

    return count($resolved) === 1
        && str_contains($resolved[0]['text'], 'in 3 h')
        && !str_contains($resolved[0]['text'], '{when}');
})());
check('p23/copy: a scheduled approval CONFIRMS the time back to the operator',
    str_contains(View::t('approval.approved_scheduled', ['when' => 'in 3 h']), 'in 3 h'));

// The account column was a control nothing read — offering it claimed more than
// the system does, so the UI no longer shows it and a hand-posted value is
// rejected rather than silently widened to "every account".
check('p23/honesty: the plan screen offers no per-account slot control',
    (static function () use ($basePath): bool {
        $tpl = (string) file_get_contents($basePath . '/templates/plan/index.php');

        return !str_contains($tpl, 'name="account_id"') && !str_contains($tpl, 'slots.all_accounts_option');
    })());
check('p23/honesty: a hand-posted account_id is REFUSED, not quietly widened to every account',
    (static function () use ($basePath, $argonHash, $view): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'narrow@x.com', $argonHash, 'NarrowWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $slots = new SlotRepository($db);
        $ctl = makePlanController($db, $ctx, $view);
        $_POST = ['weekday' => '1', 'time_hhmm' => '09:00', 'account_id' => '7'];
        $ctl->addSlot();
        $_POST = [];

        return $slots->listFor($ctx) === [];   // nothing stored
    })());

// Guards the security review flagged: the DB must not accept a row the resolver
// would reject, and the plan must not grow without bound.
check('p23/guard: the schema rejects an out-of-range hour (24:00), not just the app',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'chk@x.com', $argonHash, 'ChkWS');

        return throws(static fn () => $db->run(
            "INSERT INTO publish_slots (workspace_id, account_id, weekday, time_hhmm, enabled, created_at, updated_at)
             VALUES (?, NULL, 1, '24:00', 1, ?, ?)",
            [$ws, '2026-08-23T12:00:00Z', '2026-08-23T12:00:00Z'],
        ), PDOException::class);
    })());
check('p23/guard: a workspace cannot grow an unbounded number of slots',
    (static function () use ($basePath, $argonHash): bool {
        $db = migratedDb($basePath);
        [, $ws] = seedUser($db, 'cap@x.com', $argonHash, 'CapWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $slots = new SlotRepository($db);
        $now = '2026-08-23T12:00:00Z';
        $added = 0;
        // 7 weekdays × many times — try to exceed the cap
        for ($d = 1; $d <= 7 && $added <= SlotRepository::MAX_PER_WORKSPACE + 5; $d++) {
            for ($h = 0; $h < 12; $h++) {
                if ($slots->add($ctx, $d, sprintf('%02d:00', $h), null, $now) !== null) {
                    $added++;
                }
            }
        }

        return $added === SlotRepository::MAX_PER_WORKSPACE
            && count($slots->listFor($ctx)) === SlotRepository::MAX_PER_WORKSPACE;
    })());

// ── (5) the cockpit reports SCHEDULED FACT, not the plan ────────────────────

check('p23/cockpit: next-publish reads a real queued job, is tenant-scoped, and is null when nothing waits',
    (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
        $db = migratedDb($basePath);
        [$user, $ws] = seedUser($db, 'nextpub@x.com', $argonHash, 'NextPubWS');
        $ctx = new WorkspaceContext($db);
        $ctx->set($ws);
        $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
        $cockpit = new \Kuyash\Workflow\Cockpit($db, new AssetCache($db, $paths), new CreditLedger($db), new UsageRepository($db), new AccountRepository($db), new JobRepository($db));

        $now = '2026-08-23T12:00:00Z';
        $empty = $cockpit->snapshot($ctx, $now)['nextPublish'];

        $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
                  VALUES (?, 'w', 'full', '[]', ?, ?)", [$ws, $now, $now]);
        $wf = $db->lastInsertId();
        $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at)
                  VALUES (?, ?, 'trend', '[]', 'running', ?, ?, ?)", [$ws, $wf, $user, $now, $now]);
        $runId = $db->lastInsertId();
        // a PAST publish and a FUTURE one — only the future one is "next"
        foreach ([['2026-08-23T08:00:00Z'], ['2026-08-25T09:00:00Z']] as [$at]) {
            $db->run("INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, max_retries, priority, run_after, created_at)
                      VALUES (?, ?, 'PUBLISH', 9, 'publish', 'queued', '{}', 3, 0, ?, ?)", [$ws, $runId, $at, $now]);
        }
        $next = $cockpit->snapshot($ctx, $now)['nextPublish'];

        // a sibling workspace must not see it
        [, $ws2] = seedUser($db, 'nextpub2@x.com', $argonHash, 'NextPubWS2');
        $ctx->set($ws2);   // shared session context — switch, then assert
        $siblingSeesNothing = $cockpit->snapshot($ctx, $now)['nextPublish'] === null;
        $ctx->set($ws);

        return $empty === null
            && $next !== null && $next['run_after'] === '2026-08-25T09:00:00Z' && $next['run_id'] === $runId
            && $siblingSeesNothing;
    })());

// ── (6) honest wording for a future instant ────────────────────────────────

check('p23/copy: Messages::until phrases a future instant as a wait',
    Messages::until('2026-08-23T12:30:00Z', '2026-08-23T12:00:00Z') === View::t('time.in_minutes', ['n' => 30])
    && Messages::until('2026-08-23T15:00:00Z', '2026-08-23T12:00:00Z') === View::t('time.in_hours', ['n' => 3])
    && Messages::until('2026-08-26T12:00:00Z', '2026-08-23T12:00:00Z') === View::t('time.in_days', ['n' => 3]));
check('p23/copy: an instant already due reads as imminent, never as a negative wait',
    Messages::until('2026-08-23T11:00:00Z', '2026-08-23T12:00:00Z') === View::t('time.imminent'));
check('p23/copy: an unparseable instant falls back to the raw value',
    Messages::until('not-a-date', '2026-08-23T12:00:00Z') === 'not-a-date');
check('p23/copy: weekday labels exist in BOTH languages (the plan is read, not decoded)',
    (static function (): bool {
        foreach (['en', 'tr'] as $loc) {
            I18n::setLocale($loc);
            for ($d = 1; $d <= 7; $d++) {
                if (trim(View::t('day.' . $d)) === '' || View::t('day.' . $d) === 'day.' . $d) {
                    return false;
                }
            }
        }
        I18n::setLocale('en');

        return true;
    })());

echo "== Phase 23 polish: styled file picker + plan discoverability ==\n";

// The browser's default file control looked nothing like the app. It is replaced
// by the app's own button — WITHOUT dropping the real input, which must stay in
// the DOM and keyboard-reachable (hidden with clip-path, never display:none).
check('p23/ui: the photo picker wears the app button, and the native control is gone',
    (static function () use ($basePath): bool {
        $tpl = (string) file_get_contents($basePath . '/templates/quick/index.php');

        return str_contains($tpl, 'class="filepick"')
            && str_contains($tpl, "<span class=\"btn btn--ghost\"><?= View::t('quick.choose_photo') ?></span>")
            && str_contains($tpl, 'data-file-name');
    })());
check('p23/ui: the real file input is still present and focusable (hidden, not display:none)',
    (static function () use ($basePath): bool {
        $css = (string) file_get_contents($basePath . '/public/assets/css/app.css');
        preg_match('/\.filepick input\[type="file"\]\s*\{([^}]*)\}/s', $css, $m);
        $rule = $m[1] ?? '';

        return str_contains($rule, 'opacity: 0')
            && str_contains($rule, 'clip-path')
            && !str_contains($rule, 'display: none')
            && !str_contains($rule, 'visibility: hidden');
    })());
check('p23/ui: choosing a file is announced without JS being required to open the picker',
    (static function () use ($basePath): bool {
        $js = (string) file_get_contents($basePath . '/public/assets/js/app.js');

        // JS only updates the NAME; the <label> itself opens the dialog
        return str_contains($js, 'filePickName')
            && str_contains($js, "querySelectorAll('.filepick input[type=\"file\"]')")
            && str_contains((string) file_get_contents($basePath . '/templates/quick/index.php'), '<label class="filepick">');
    })());
check('p23/ui: the picker label exists in both languages',
    (static function (): bool {
        foreach (['en', 'tr'] as $loc) {
            I18n::setLocale($loc);
            foreach (['quick.choose_photo', 'quick.no_file'] as $k) {
                if (View::t($k) === $k || trim(View::t($k)) === '') {
                    return false;
                }
            }
        }
        I18n::setLocale('en');

        return true;
    })());

// The plan was only reachable by scrolling to the bottom of Settings — the two
// empty states where someone is already thinking about timing now point at it.
check('p23/ui: the weekly plan is its OWN screen, reachable from the main nav',
    (static function () use ($basePath): bool {
        $nav = (string) file_get_contents($basePath . '/templates/layout/app.php');
        $routes = (string) file_get_contents($basePath . '/src/routes.php');

        return str_contains($nav, "href=\"/plan\"")
            && str_contains($nav, "View::t('nav.plan')")
            && str_contains($routes, "\$router->get('/plan'")
            && is_file($basePath . '/templates/plan/index.php');
    })());
check('p23/ui: Settings no longer carries the plan (one home for it, not two)',
    (static function () use ($basePath): bool {
        $tpl = (string) file_get_contents($basePath . '/templates/settings/index.php');

        return !str_contains($tpl, "View::t('slots.title')")
            && !str_contains($tpl, 'id="plan"')
            && !str_contains($tpl, '/settings/slots');
    })());
check('p23/ui: the slot actions live under /plan, not under /settings',
    (static function () use ($basePath): bool {
        $routes = (string) file_get_contents($basePath . '/src/routes.php');

        return str_contains($routes, "'/plan/slots'")
            && str_contains($routes, "'/plan/timezone'")
            && !str_contains($routes, "'/settings/slots'")
            && !str_contains($routes, "'/settings/timezone'");
    })());
check('p23/ui: the cockpit and the approval form both offer a way into the plan',
    (static function () use ($basePath): bool {
        $dash = (string) file_get_contents($basePath . '/templates/dashboard.php');
        $queue = (string) file_get_contents($basePath . '/templates/queue/index.php');

        return str_contains($dash, 'href="/plan"')
            && str_contains($queue, 'href="/plan"')
            // the queue link shows only when there is no plan yet
            && preg_match('/if \(\$slots === \[\]\).*?href="\/plan"/s', $queue) === 1;
    })());
check('p23/ui: the discovery labels exist in both languages',
    (static function (): bool {
        foreach (['en', 'tr'] as $loc) {
            I18n::setLocale($loc);
            foreach (['cockpit.open_plan', 'slots.create_plan', 'nav.plan', 'plan.title', 'plan.subtitle'] as $k) {
                if (View::t($k) === $k || trim(View::t($k)) === '') {
                    return false;
                }
            }
        }
        I18n::setLocale('en');

        return true;
    })());

// ─────────────────────────────────────────────────────────────────────────────
// Phase 24 — TASK 0: RISK SPIKE (no product code yet).
//
// The whole feature rests on ONE claim: a weekly slot's wall-clock time survives
// a DST shift, reaches the queue's run_after gate, and arrives at the publish
// adapter as the SAME UTC instant. Prove it end to end with the code that
// already exists — SlotResolver → runs.publish_after → jobs.run_after → the
// PublishRequest a spy provider receives — before a single new table is added.
//
// If this fails, the plan's central seam is wrong and Task 1 must not start.
echo "== p24/task0: RISK SPIKE — slot time → worker gate → adapter scheduledFor ==\n";

$spikeZone = 'America/New_York';
// 2026-03-08 is the US DST start. "Now" is Wednesday 2026-03-04 15:00Z (after
// that day's 09:00 local, which was 14:00Z in EST) → the next Wednesday 09:00
// falls on 2026-03-11, i.e. AFTER the clocks moved. A naive "+7 days on the
// timestamp" would land on 14:00Z; the operator asked for 09:00 local, so the
// correct answer is 13:00Z.
$spikeNowIso = '2026-03-04T15:00:00Z';
$spikeExpected = '2026-03-11T13:00:00Z';
$spikeResolved = (new SlotResolver())->nextOccurrence($spikeZone, 3, '09:00', $spikeNowIso);

check('p24/task0: a DST-crossing weekly slot resolves to the instant that keeps 09:00 local', $spikeResolved === $spikeExpected);

check('p24/task0: that instant survives run_after gating and reaches the adapter as scheduledFor', (static function () use ($basePath, $argonHash, $spikeZone, $spikeExpected, $spikeNowIso): bool {
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'p24-spike@example.com', $argonHash, 'P24 Spike');
    $db->run('UPDATE workspaces SET timezone = ? WHERE id = ?', [$spikeZone, $ws]);

    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $workflows = new WorkflowRepository($db, new WorkflowValidator());
    $workflows->ensureDefaults($ctx);
    $dist = $workflows->findByTemplate($ctx, 'distribution');
    if ($dist === null) {
        return false;
    }
    $assetId = seedReadyVideo($db, $ws, 'Spike clip');
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@spike', 'zacct_spike', 'connected', 'ok', ?, ?)",
        [$ws, $spikeNowIso, $spikeNowIso],
    );

    $now = $spikeNowIso;
    $spy = new SpyPublishProvider();
    [$engine, $worker] = makeRig($db, new MockExecutor(), $now, null, false, $spy);

    $runId = $engine->startRun($ctx, (int) $dist['id'], $assetId, $user);

    // Stand-in for what Task 4 will do at assignment time: the planned instant
    // is written when the run is BORN, not at approval.
    $db->run('UPDATE runs SET publish_after = ? WHERE id = ? AND workspace_id = ?', [$spikeExpected, $runId, $ws]);

    // drain to the approval gate
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    if ($review === null) {
        return false;
    }

    // approve WITHOUT naming a time — the pre-set publish_after must survive
    if ($engine->approve($ctx, (int) $review['id'], $user, 'p24-spike@example.com', null) !== Decision::Ok) {
        return false;
    }
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $survived = (string) ($db->one('SELECT publish_after FROM runs WHERE id = ?', [$runId])['publish_after'] ?? '') === $spikeExpected;

    // the publish job must be PARKED behind the gate, and the spy untouched
    $publish = $db->one("SELECT status, run_after FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);
    $parked = $publish !== null
        && (string) $publish['status'] === 'queued'
        && (string) $publish['run_after'] === $spikeExpected
        && $spy->requests === [];

    // clocks move past the planned instant → the gate opens
    $now = '2026-03-11T13:00:30Z';
    for ($i = 0; $i < 10 && $worker->tick(); $i++) {
    }
    $req = $spy->requests[0] ?? null;

    return $survived && $parked
        && $req instanceof PublishRequest
        && $req->scheduledFor === $spikeExpected
        && (string) ($db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId])['status'] ?? '') === 'published';
})());

// ── Phase 24, Tasks 1-3: the calendar layer, the worker seam, and the chore ──

echo "== p24/calendar: a weekly time becomes dated cells (DST-correct) ==\n";

$p24res = new SlotResolver();

check('p24/calendar: a two-week window over a weekly time yields exactly two dated cells', (static function () use ($p24res): bool {
    $hits = $p24res->occurrencesBetween('Europe/Istanbul', 1, '09:00', '2026-06-10T00:00:00Z', '2026-06-24T00:00:00Z');

    return count($hits) === 2
        && $hits[0]['local_date'] === '2026-06-15' && $hits[0]['at'] === '2026-06-15T06:00:00Z'
        && $hits[1]['local_date'] === '2026-06-22' && $hits[1]['at'] === '2026-06-22T06:00:00Z';
})());

check('p24/calendar: every cell is strictly inside the window (never in the past, never past the horizon)', (static function () use ($p24res): bool {
    $from = '2026-06-15T06:00:00Z';   // exactly ON a Monday 09:00 Istanbul
    $to = '2026-06-30T00:00:00Z';
    $hits = $p24res->occurrencesBetween('Europe/Istanbul', 1, '09:00', $from, $to);
    foreach ($hits as $h) {
        if ($h['at'] <= $from || $h['at'] >= $to) {
            return false;
        }
    }

    // the boundary instant itself is excluded: $from is exactly one of them
    return count($hits) === 2 && $hits[0]['at'] === '2026-06-22T06:00:00Z';
})());

check('p24/calendar: crossing a DST shift keeps the operator\'s wall-clock time, moving the UTC instant', (static function () use ($p24res): bool {
    // 2026-03-08 is the US DST start; the window spans it.
    $hits = $p24res->occurrencesBetween('America/New_York', 3, '09:00', '2026-03-01T00:00:00Z', '2026-03-20T00:00:00Z');

    return count($hits) === 3
        && $hits[0]['at'] === '2026-03-04T14:00:00Z'  // EST  → 09:00 local
        && $hits[1]['at'] === '2026-03-11T13:00:00Z'  // EDT  → still 09:00 local
        && $hits[2]['at'] === '2026-03-18T13:00:00Z'
        && $hits[0]['local_date'] === '2026-03-04'
        && $hits[1]['local_date'] === '2026-03-11';
})());

check('p24/calendar: the autumn fall-back hour produces ONE cell for that day, not two', (static function () use ($p24res): bool {
    // 2026-11-01: 01:30 America/New_York happens twice. One local day = one cell.
    $hits = $p24res->occurrencesBetween('America/New_York', 7, '01:30', '2026-10-28T00:00:00Z', '2026-11-03T00:00:00Z');
    $dates = array_column($hits, 'local_date');

    return count($hits) === 1 && $dates === ['2026-11-01'] && count(array_unique($dates)) === 1;
})());

check('p24/calendar: the spring-forward gap resolves to a real instant and the cell says which', (static function () use ($p24res): bool {
    // 02:30 does not exist on 2026-03-08 in New York; PHP normalizes to 03:30
    // local. The cell records the instant that WILL fire, so the screen can be
    // honest about it rather than promising a time that has no moment.
    $hits = $p24res->occurrencesBetween('America/New_York', 7, '02:30', '2026-03-05T00:00:00Z', '2026-03-10T00:00:00Z');

    return count($hits) === 1
        && $hits[0]['local_date'] === '2026-03-08'
        && $hits[0]['at'] === '2026-03-08T07:30:00Z';   // 03:30 EDT
})());

check('p24/calendar: an unknown zone or a malformed time yields no cells at all (never a guess)', (static function () use ($p24res): bool {
    return $p24res->occurrencesBetween('Mars/Olympus', 1, '09:00', '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z') === []
        && $p24res->occurrencesBetween('UTC', 1, '25:00', '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z') === []
        && $p24res->occurrencesBetween('UTC', 9, '09:00', '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z') === []
        && $p24res->occurrencesBetween('UTC', 1, '09:00', '2026-06-30T00:00:00Z', '2026-06-01T00:00:00Z') === [];
})());

echo "== p24/store: calendar cells (idempotency, guarded moves, tenancy) ==\n";

/** A workspace with a timezone, one weekly time, and a ready video. */
$p24seed = static function (Database $db, string $email, string $zone, string $mode, string $now) use ($argonHash): array {
    [$user, $ws] = seedUser($db, $email, $argonHash, 'P24 ' . $email);
    $db->run('UPDATE workspaces SET timezone = ? WHERE id = ?', [$zone, $ws]);
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $slots = new SlotRepository($db);
    $slotId = $slots->add($ctx, 1, '09:00', null, $now, $mode);

    return [$user, $ws, $ctx, (int) $slotId];
};

check('p24/store: a publishing time carries who fills it, and rejects anything but the two modes', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $ws, $ctx, $slotId] = $p24seed($db, 'mode@x.com', 'UTC', 'auto', '2026-06-10T00:00:00Z');
    $slots = new SlotRepository($db);
    $row = $slots->find($ctx, $slotId);

    return $row !== null && $row['mode'] === 'auto'
        && $slots->add($ctx, 2, '10:00', null, '2026-06-10T00:00:00Z', 'nonsense') === null
        && $slots->lastAddFailure() === 'invalid'
        && $slots->setMode($ctx, $slotId, 'manual', '2026-06-10T00:00:00Z')
        && $slots->find($ctx, $slotId)['mode'] === 'manual'
        && $slots->setMode($ctx, $slotId, 'sideways', '2026-06-10T00:00:00Z') === false;
})());

check('p24/store: adding the same time twice reports a DUPLICATE, not "invalid" (Phase 23 follow-up)', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, , $ctx] = $p24seed($db, 'dup@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    $slots = new SlotRepository($db);
    $again = $slots->add($ctx, 1, '09:00', null, '2026-06-10T00:00:00Z');

    return $again === null && $slots->lastAddFailure() === 'duplicate';
})());

check('p24/store: materializing twice creates the same cells once (the chore may run all day)', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $ws, , ] = $p24seed($db, 'idem@x.com', 'Europe/Istanbul', 'manual', '2026-06-10T00:00:00Z');
    $occ = new OccurrenceRepository($db);
    $mat = new OccurrenceMaterializer($occ, new SlotResolver());
    $slots = (new SlotRepository($db))->listForWorkspace($ws);
    $now = '2026-06-10T00:00:00Z';

    $first = $mat->materialize($ws, 'Europe/Istanbul', $slots, $now);
    $second = $mat->materialize($ws, 'Europe/Istanbul', $slots, $now);
    $count = (int) $db->one('SELECT COUNT(*) AS n FROM slot_occurrences WHERE workspace_id = ?', [$ws])['n'];

    return $first['created'] === 2 && $second['created'] === 0 && $count === 2;
})());

check('p24/store: a paused time stops producing new days but keeps the ones already there', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $ws, $ctx, $slotId] = $p24seed($db, 'pause@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    $occ = new OccurrenceRepository($db);
    $mat = new OccurrenceMaterializer($occ, new SlotResolver());
    $slots = new SlotRepository($db);
    $mat->materialize($ws, 'UTC', $slots->listForWorkspace($ws), '2026-06-10T00:00:00Z');
    $before = (int) $db->one('SELECT COUNT(*) AS n FROM slot_occurrences WHERE workspace_id = ?', [$ws])['n'];

    $slots->setEnabled($ctx, $slotId, false, '2026-06-10T00:00:00Z');
    // a later "now" would otherwise reach a third week
    $mat->materialize($ws, 'UTC', $slots->listForWorkspace($ws), '2026-06-17T00:00:00Z');
    $after = (int) $db->one('SELECT COUNT(*) AS n FROM slot_occurrences WHERE workspace_id = ?', [$ws])['n'];

    return $before === 2 && $after === 2;
})());

check('p24/store: changing the timezone re-times EMPTY cells and leaves committed ones alone', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $slotId] = $p24seed($db, 'tz@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    $occ = new OccurrenceRepository($db);
    $mat = new OccurrenceMaterializer($occ, new SlotResolver());
    $slots = new SlotRepository($db);
    $now = '2026-06-10T00:00:00Z';
    $mat->materialize($ws, 'UTC', $slots->listForWorkspace($ws), $now);

    $cells = $db->all('SELECT id, publish_at FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at', [$ws]);
    // pretend the FIRST cell already carries content
    $occ->reserve($ws, (int) $cells[0]['id'], null, $now);

    (new WorkspaceSettings($db))->setTimezone($ws, 'Europe/Istanbul');
    $mat->materialize($ws, 'Europe/Istanbul', $slots->listForWorkspace($ws), $now);

    $after = $db->all('SELECT id, publish_at, status FROM slot_occurrences WHERE workspace_id = ? ORDER BY id', [$ws]);
    $committed = null;
    $empty = null;
    foreach ($after as $row) {
        if ((int) $row['id'] === (int) $cells[0]['id']) {
            $committed = $row;
        } else {
            $empty = $row;
        }
    }

    return $committed !== null && $empty !== null
        && (string) $committed['publish_at'] === (string) $cells[0]['publish_at']   // untouched commitment
        && (string) $empty['publish_at'] === '2026-06-22T06:00:00Z';                // re-timed to 09:00 Istanbul
})());

check('p24/store: taking a cell is a guarded move — a double submit can only win once', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $ws, , ] = $p24seed($db, 'guard@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    $occ = new OccurrenceRepository($db);
    (new OccurrenceMaterializer($occ, new SlotResolver()))
        ->materialize($ws, 'UTC', (new SlotRepository($db))->listForWorkspace($ws), '2026-06-10T00:00:00Z');
    $id = (int) $db->one('SELECT id FROM slot_occurrences WHERE workspace_id = ? ORDER BY id LIMIT 1', [$ws])['id'];
    $now = '2026-06-10T00:00:00Z';

    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $mkRun = static function () use ($db, $ws, $wf, $now): int {
        $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',(SELECT user_id FROM workspace_users WHERE workspace_id=? LIMIT 1),?,?)", [$ws, $wf, $ws, $now, $now]);

        return $db->lastInsertId();
    };

    $first = $occ->reserve($ws, $id, null, $now);
    $second = $occ->reserve($ws, $id, null, $now);
    $attached = $occ->attachRun($ws, $id, $mkRun(), $now);
    $attachedTwice = $occ->attachRun($ws, $id, $mkRun(), $now);

    return $first && !$second && $attached && !$attachedTwice;
})());

check('p24/store: another workspace can neither see nor move this workspace\'s cells', (static function () use ($basePath, $p24seed): bool {
    $db = migratedDb($basePath);
    [, $wsA, $ctxA, ] = $p24seed($db, 'iso-a@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    [, $wsB, , ] = $p24seed($db, 'iso-b@x.com', 'UTC', 'manual', '2026-06-10T00:00:00Z');
    $occ = new OccurrenceRepository($db);
    $mat = new OccurrenceMaterializer($occ, new SlotResolver());
    $slots = new SlotRepository($db);
    $now = '2026-06-10T00:00:00Z';
    $mat->materialize($wsA, 'UTC', $slots->listForWorkspace($wsA), $now);
    $mat->materialize($wsB, 'UTC', $slots->listForWorkspace($wsB), $now);

    $aId = (int) $db->one('SELECT id FROM slot_occurrences WHERE workspace_id = ? ORDER BY id LIMIT 1', [$wsA])['id'];
    $ctxB = new WorkspaceContext($db);
    $ctxB->set($wsB);

    $notVisible = $occ->find($ctxB, $aId) === null;
    $notReservable = $occ->reserve($wsB, $aId, null, $now) === false;
    $notSkippable = $occ->markSkipped($wsB, $aId, 'missed', $now) === false;
    // A's own window still contains only A's cells
    $ctxA->set($wsA);
    $ownWindow = $occ->window($ctxA, '2026-06-01T00:00:00Z', '2026-07-01T00:00:00Z');
    $allMine = array_reduce($ownWindow, static fn (bool $c, array $r): bool => $c && (int) $r['workspace_id'] === $wsA, true);

    return $notVisible && $notReservable && $notSkippable && count($ownWindow) === 2 && $allMine;
})());

echo "== p24/auto: the plan chore — guardrails first, then production ==\n";

/** PlanRunner over a given engine; every collaborator is the real one. */
$p24runner = static function (Database $db, Engine $engine): PlanRunner {
    $occ = new OccurrenceRepository($db);

    return new PlanRunner(
        $db,
        $occ,
        new OccurrenceMaterializer($occ, new SlotResolver()),
        new SlotRepository($db),
        new WorkspaceSettings($db),
        new WorkflowRepository($db, new WorkflowValidator()),
        new AccountRepository($db),
        new PublishCounter($db),
        $engine,
        new EventLog($db),
    );
};

/**
 * A workspace whose Monday 09:00 UTC time is AUTOMATIC, with a connected
 * account and the default workflows. "Now" is two hours before that time, i.e.
 * inside the default three-hour production lead window.
 */
$p24auto = static function (Database $db, string $email, bool $withWorkflows = true) use ($argonHash): array {
    $now = '2026-06-15T07:00:00Z';           // Monday
    [$user, $ws] = seedUser($db, $email, $argonHash, 'AUTO ' . $email);
    $db->run('UPDATE workspaces SET timezone = ? WHERE id = ?', ['UTC', $ws]);
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'auto');
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@auto', 'zacct_auto', 'connected', 'ok', ?, ?)",
        [$ws, $now, $now],
    );
    if ($withWorkflows) {
        (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    }

    return [$user, $ws, $ctx, $now];
};

$p24cells = static fn (Database $db, int $ws): array => $db->all(
    'SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at', [$ws],
);
$p24runs = static fn (Database $db, int $ws): int => (int) $db->one(
    'SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$ws],
)['n'];

check('p24/auto: an automatic time inside its lead window produces exactly one piece of content', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , $now] = $p24auto($db, 'auto-happy@x.com');
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $runner = $p24runner($db, $engine);

    $first = $runner->tick($now);
    $afterOne = $p24runs($db, $ws);
    // running again in the same window must NOT start a second one
    $second = $runner->tick($now);

    $cells = $p24cells($db, $ws);
    $due = null;
    foreach ($cells as $cell) {
        if ((string) $cell['publish_at'] === '2026-06-15T09:00:00Z') {
            $due = $cell;
        }
    }
    if ($due === null || $due['run_id'] === null) {
        return false;
    }
    $publishAfter = (string) ($db->one('SELECT publish_after FROM runs WHERE id = ?', [(int) $due['run_id']])['publish_after'] ?? '');

    return $first['started'] === 1 && $second['started'] === 0 && $afterOne === 1 && $p24runs($db, $ws) === 1
        && (string) $due['status'] === 'assigned'
        // the planned instant is on the run from BIRTH, so approval cannot lose it
        && $publishAfter === '2026-06-15T09:00:00Z';
})());

check('p24/auto: a time still outside its lead window is left alone', (static function () use ($basePath, $p24auto, $p24runner, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , ] = $p24auto($db, 'auto-lead@x.com');
    $clock = '2026-06-15T04:00:00Z';
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $runner = $p24runner($db, $engine);

    // 5 hours out with a 3-hour lead → nothing yet
    $early = $runner->tick('2026-06-15T04:00:00Z');
    $none = $p24runs($db, $ws) === 0;

    // 2 hours out → now it is due
    $late = $runner->tick('2026-06-15T07:00:00Z');

    return $early['started'] === 0 && $none && $late['started'] === 1 && $p24runs($db, $ws) === 1;
})());

check('p24/auto: pausing automatic production creates nothing and says so on the cell', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , $now] = $p24auto($db, 'auto-paused@x.com');
    (new WorkspaceSettings($db))->setPlanPaused($ws, true);
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);

    $p24runner($db, $engine)->tick($now);
    $cells = $p24cells($db, $ws);

    return $p24runs($db, $ws) === 0
        && (string) $cells[0]['status'] === 'open'          // NOT closed — the block can still clear
        && (string) $cells[0]['skip_reason'] === 'plan_paused';
})());

check('p24/auto: the kill switch stops automatic production too', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , $now] = $p24auto($db, 'auto-kill@x.com');
    (new WorkspaceSettings($db))->setKillSwitch($ws, true);
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);

    $p24runner($db, $engine)->tick($now);

    return $p24runs($db, $ws) === 0 && (string) $p24cells($db, $ws)[0]['skip_reason'] === 'kill_switch';
})());

check('p24/auto: with every account at its daily limit nothing is produced and nothing is spent', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [$user, $ws, , $now] = $p24auto($db, 'auto-cap@x.com');
    (new WorkspaceSettings($db))->setDailyPostCap($ws, 1);
    $account = (int) $db->one('SELECT id FROM accounts WHERE workspace_id = ?', [$ws])['id'];
    // one post already went out today for that account
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','completed',?,?,?)", [$ws, $wf, $user, $now, $now]);
    $priorRun = $db->lastInsertId();
    $db->run(
        "INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,posted_at,created_at,updated_at)
         VALUES (?,?,?,'instagram','published',?,?,?,?)",
        [$ws, $priorRun, $account, 'k-cap', '2026-06-15T05:00:00Z', $now, $now],
    );

    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $p24runner($db, $engine)->tick($now);

    $usage = (int) $db->one('SELECT COUNT(*) AS n FROM usage_events WHERE workspace_id = ?', [$ws])['n'];

    return $p24runs($db, $ws) === 1                       // only the pre-existing one
        && $usage === 0
        && (string) $p24cells($db, $ws)[0]['skip_reason'] === 'daily_cap';
})());

check('p24/auto: a workspace with no full pipeline reports that, rather than failing quietly', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , $now] = $p24auto($db, 'auto-nowf@x.com', false);
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);

    $p24runner($db, $engine)->tick($now);

    return $p24runs($db, $ws) === 0 && (string) $p24cells($db, $ws)[0]['skip_reason'] === 'no_workflow';
})());

check('p24/auto: with no connected account nothing is produced (it could not go anywhere)', (static function () use ($basePath, $argonHash, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [, $ws] = seedUser($db, 'auto-noacct@x.com', $argonHash, 'NOACCT');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'auto');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);

    $p24runner($db, $engine)->tick($now);

    return $p24runs($db, $ws) === 0 && (string) $p24cells($db, $ws)[0]['skip_reason'] === 'no_account';
})());

check('p24/auto: an over-budget workspace produces NOTHING — no run row, and the block is audited', (static function () use ($basePath, $p24auto, $p24runner, $p24cells, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $ws, , $now] = $p24auto($db, 'auto-budget@x.com');
    // a full run is estimated around 10 cents; a 1-cent cap cannot cover it
    (new WorkspaceSettings($db))->setBudgetCapCents($ws, 1);

    $events = new EventLog($db);
    $clock = $now;
    $engine = new Engine(
        $db,
        $events,
        new WorkflowValidator(),
        static fn (): string => $clock,
        null,
        null,
        new \Kuyash\Usage\PreflightGate(
            new \Kuyash\Usage\CostEstimator(require $GLOBALS['basePath'] . '/config/usage.php'),
            new UsageRepository($db),
            new WorkspaceSettings($db),
            $events,
        ),
    );

    $p24runner($db, $engine)->tick($now);

    $blocked = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'guardrail.preflight_block'", [$ws]);
    $cells = $p24cells($db, $ws);

    return $p24runs($db, $ws) === 0                        // no half-started run
        && $blocked !== null
        && (string) $cells[0]['status'] === 'open'
        && (string) $cells[0]['skip_reason'] === 'budget_cap';
})());

check('p24/auto: two workspaces are produced independently — one being blocked never stops the other', (static function () use ($basePath, $p24auto, $p24runner, $p24runs): bool {
    $db = migratedDb($basePath);
    [, $wsA, , $now] = $p24auto($db, 'auto-multi-a@x.com');
    [, $wsB, , ] = $p24auto($db, 'auto-multi-b@x.com');
    (new WorkspaceSettings($db))->setKillSwitch($wsA, true);

    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $p24runner($db, $engine)->tick($now);

    return $p24runs($db, $wsA) === 0 && $p24runs($db, $wsB) === 1;
})());

echo "== p24/grace: a time that passed is closed honestly, never published late ==\n";

check('p24/grace: a planned publish more than an hour late is cancelled, not fired', (static function () use ($basePath, $argonHash, $p24runner): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'grace-late@x.com', $argonHash, 'GRACE');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'manual');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Late clip');
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@late', 'zacct_late', 'connected', 'ok', ?, ?)",
        [$ws, $now, $now],
    );

    $clock = $now;
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
    $occ = new OccurrenceRepository($db);
    (new OccurrenceMaterializer($occ, new SlotResolver()))
        ->materialize($ws, 'UTC', (new SlotRepository($db))->listForWorkspace($ws), $now);
    $cell = $db->one('SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at LIMIT 1', [$ws]);
    $cellId = (int) $cell['id'];
    $plannedAt = (string) $cell['publish_at'];   // 2026-06-15T09:00:00Z

    // an operator assigned a video and approved it for that time
    $occ->reserve($ws, $cellId, $asset, $now);
    $runId = $engine->startRunFor($ws, (int) $wf['id'], $asset, $user);
    $engine->setPublishAfter($ws, $runId, $plannedAt);
    $occ->attachRun($ws, $cellId, $runId, $now);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'grace-late@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $queued = (string) ($db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId])['status'] ?? '');

    // the worker was down; it comes back TWO HOURS after the planned time
    $lateNow = '2026-06-15T11:00:00Z';
    $clock = $lateNow;
    $p24runner($db, $engine)->tick($lateNow);

    $publish = $db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);
    $after = $db->one('SELECT status, skip_reason FROM slot_occurrences WHERE id = ?', [$cellId]);
    $posts = (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id = ? AND status = 'published'", [$runId])['n'];

    return $queued === 'queued'
        && (string) $publish['status'] === 'cancelled'
        && (string) $after['status'] === 'skipped'
        && (string) $after['skip_reason'] === 'missed'
        && $posts === 0;
})());

check('p24/grace: within the hour it is still allowed to go out', (static function () use ($basePath, $argonHash, $p24runner): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'grace-ok@x.com', $argonHash, 'GRACEOK');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'manual');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'OK clip');

    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $occ = new OccurrenceRepository($db);
    (new OccurrenceMaterializer($occ, new SlotResolver()))
        ->materialize($ws, 'UTC', (new SlotRepository($db))->listForWorkspace($ws), $now);
    $cell = $db->one('SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at LIMIT 1', [$ws]);
    $cellId = (int) $cell['id'];
    $occ->reserve($ws, $cellId, $asset, $now);
    $runId = $engine->startRunFor($ws, (int) $wf['id'], $asset, $user);
    $engine->setPublishAfter($ws, $runId, (string) $cell['publish_at']);
    $occ->attachRun($ws, $cellId, $runId, $now);

    // 30 minutes late — inside the grace window
    $p24runner($db, $engine)->tick('2026-06-15T09:30:00Z');
    $after = $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [$cellId]);
    $run = $db->one('SELECT status FROM runs WHERE id = ?', [$runId]);

    return (string) $after['status'] === 'assigned' && (string) $run['status'] !== 'cancelled';
})());

check('p24/grace: content still waiting for approval is KEPT, only its stale time is cleared', (static function () use ($basePath, $argonHash, $p24runner): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'grace-unapproved@x.com', $argonHash, 'GRACEUN');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'manual');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Unapproved clip');

    $clock = $now;
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
    $occ = new OccurrenceRepository($db);
    (new OccurrenceMaterializer($occ, new SlotResolver()))
        ->materialize($ws, 'UTC', (new SlotRepository($db))->listForWorkspace($ws), $now);
    $cell = $db->one('SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at LIMIT 1', [$ws]);
    $cellId = (int) $cell['id'];
    $occ->reserve($ws, $cellId, $asset, $now);
    $runId = $engine->startRunFor($ws, (int) $wf['id'], $asset, $user);
    $engine->setPublishAfter($ws, $runId, (string) $cell['publish_at']);
    $occ->attachRun($ws, $cellId, $runId, $now);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    // deliberately NOT approved

    $clock = '2026-06-15T11:00:00Z';
    $p24runner($db, $engine)->tick('2026-06-15T11:00:00Z');

    $run = $db->one('SELECT status, publish_after FROM runs WHERE id = ?', [$runId]);
    $review = $db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'render_review'", [$runId]);
    $after = $db->one('SELECT status, skip_reason FROM slot_occurrences WHERE id = ?', [$cellId]);

    return (string) $after['status'] === 'skipped'
        && (string) $after['skip_reason'] === 'not_approved'
        && (string) $run['status'] !== 'cancelled'            // the work is NOT thrown away
        && $run['publish_after'] === null                      // …but the stale time is gone
        && (string) $review['status'] === 'awaiting_approval'; // still approvable
})());

echo "== p24/engine: the two additions, and the promise that ordinary runs did not change ==\n";

check('p24/engine: a queued publish can be cancelled; one already in flight cannot', (static function () use ($basePath, $argonHash): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'cancel@x.com', $argonHash, 'CANCEL');
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $mk = static function (string $publishStatus) use ($db, $ws, $wf, $user, $now): int {
        $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','running',?,?,?)", [$ws, $wf, $user, $now, $now]);
        $run = $db->lastInsertId();
        $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',9,'publish',?,?,?)", [$ws, $run, $publishStatus, $now, $now]);

        return $run;
    };
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);

    $queuedRun = $mk('queued');
    $okQueued = $engine->cancelRun($ws, $queuedRun, 'me@x.com', 'plan.changed_mind') === Decision::Ok
        && (string) $db->one("SELECT status FROM jobs WHERE run_id = ?", [$queuedRun])['status'] === 'cancelled'
        && (string) $db->one('SELECT status FROM runs WHERE id = ?', [$queuedRun])['status'] === 'cancelled';

    $flightRun = $mk('processing');
    $refused = $engine->cancelRun($ws, $flightRun, 'me@x.com', 'plan.too_late') === Decision::AlreadyDecided
        && (string) $db->one('SELECT status FROM runs WHERE id = ?', [$flightRun])['status'] === 'running';

    // another workspace cannot cancel it
    $foreign = $engine->cancelRun($ws + 999, $queuedRun, 'x@x.com', 'nope') === Decision::NotFound;

    // cancelling is NOT an approval decision — no approvals row is written
    $noApproval = (int) $db->one('SELECT COUNT(*) AS n FROM approvals WHERE workspace_id = ?', [$ws])['n'] === 0;

    return $okQueued && $refused && $foreign && $noApproval;
})());

check('p24/engine: an ORDINARY run is unchanged — approving with no time still publishes right away', (static function () use ($basePath, $argonHash): bool {
    // REGRESSION LOCK (N2): writing publish_after at birth is a PLAN-only
    // behaviour. Someone who runs Distribution by hand and approves without
    // naming a time must still get an immediate publish, exactly as before.
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'ordinary@x.com', $argonHash, 'ORDINARY');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $repo = new WorkflowRepository($db, new WorkflowValidator());
    $repo->ensureDefaults($ctx);
    $wf = $repo->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Ordinary clip');
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@ord', 'zacct_ord', 'connected', 'ok', ?, ?)",
        [$ws, $now, $now],
    );

    $clock = $now;
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);   // NO plan involved
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'ordinary@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $run = $db->one('SELECT publish_after FROM runs WHERE id = ?', [$runId]);
    $publish = $db->one("SELECT status, run_after FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);

    return $run['publish_after'] === null                       // nothing was invented
        && (string) $publish['status'] === 'published'          // it went out immediately
        && (string) $publish['run_after'] === $now;
})());

check('p24/engine: a planned time set at birth survives an approval that names no time', (static function () use ($basePath, $argonHash): bool {
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'preset@x.com', $argonHash, 'PRESET');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $repo = new WorkflowRepository($db, new WorkflowValidator());
    $repo->ensureDefaults($ctx);
    $wf = $repo->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Preset clip');

    $clock = $now;
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
    $runId = $engine->startRunFor($ws, (int) $wf['id'], $asset, $user);
    $engine->setPublishAfter($ws, $runId, '2026-06-15T09:00:00Z');
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'preset@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $publish = $db->one("SELECT status, run_after FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);

    return (string) $publish['status'] === 'queued' && (string) $publish['run_after'] === '2026-06-15T09:00:00Z';
})());

echo "== p24/ui: putting a video on a day, and taking it back off ==\n";

/**
 * A workspace with a Monday 09:00 manual time, a ready video, a connected
 * account, the default workflows, and a signed-in owner.
 */
$p24ctlSeed = static function (Database $db, string $email, string $mode = 'manual') use ($argonHash, $view): array {
    $now = gmdate(NOW_ISO);
    [$user, $ws] = seedUser($db, $email, $argonHash, 'CTL ' . $email);
    $db->run('UPDATE workspaces SET timezone = ? WHERE id = ?', ['UTC', $ws]);
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    (new SlotRepository($db))->add($ctx, ((int) gmdate('N') % 7) + 1, '09:00', null, $now, $mode);
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@ctl', 'zacct_ctl', 'connected', 'ok', ?, ?)",
        [$ws, $now, $now],
    );
    $asset = seedReadyVideo($db, $ws, 'Plan me');
    $ctl = makePlanController($db, $ctx, $view);
    $ctl->index();   // materializes the calendar
    $cell = $db->one('SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at LIMIT 1', [$ws]);

    return [$user, $ws, $ctx, $ctl, $asset, $cell];
};

check('p24/ui: putting a video on a day starts the work and pins it to that time', (static function () use ($basePath, $p24ctlSeed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $ctl, $asset, $cell] = $p24ctlSeed($db, 'put@x.com');

    $_POST = ['asset_id' => (string) $asset];
    $res = $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];

    $after = $db->one('SELECT * FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]);
    if ((string) $after['status'] !== 'assigned' || $after['run_id'] === null) {
        return false;
    }
    $run = $db->one('SELECT publish_after FROM runs WHERE id = ?', [(int) $after['run_id']]);
    $audited = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'plan.assigned'", [$ws]);

    return $res->status() === 303
        && (int) $after['asset_id'] === $asset
        && (string) $run['publish_after'] === (string) $cell['publish_at']
        && $audited !== null;
})());

check('p24/ui: a day is refused when it is taken, in the past, automatic, or not yours', (static function () use ($basePath, $p24ctlSeed, $argonHash, $view): bool {
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'refuse@x.com');
    $flash = static fn (): string => (string) ($_SESSION['flash'][0]['key'] ?? ($_SESSION['_flash'][0]['key'] ?? ''));

    // taken
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $ctl->assign(['id' => (string) $cell['id']]);
    $runCount = (int) $db->one('SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$ws])['n'];

    // in the past
    $db->run("INSERT INTO slot_occurrences (workspace_id,slot_id,local_date,publish_at,mode,status,created_at,updated_at)
              VALUES (?, (SELECT id FROM publish_slots WHERE workspace_id=? LIMIT 1), '2020-01-06', '2020-01-06T09:00:00Z', 'manual', 'open', ?, ?)",
        [$ws, $ws, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $pastId = $db->lastInsertId();
    $ctl->assign(['id' => (string) $pastId]);
    $pastUntouched = (string) $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [$pastId])['status'] === 'open';

    // automatic day
    $db->run("INSERT INTO slot_occurrences (workspace_id,slot_id,local_date,publish_at,mode,status,created_at,updated_at)
              VALUES (?, (SELECT id FROM publish_slots WHERE workspace_id=? LIMIT 1), '2099-01-05', '2099-01-05T09:00:00Z', 'auto', 'open', ?, ?)",
        [$ws, $ws, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $autoId = $db->lastInsertId();
    $ctl->assign(['id' => (string) $autoId]);
    $autoUntouched = (string) $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [$autoId])['status'] === 'open';

    // ANOTHER workspace's video
    [, $wsB] = seedUser($db, 'refuse-b@x.com', $argonHash, 'CTL B');
    $foreign = seedReadyVideo($db, $wsB, 'Not yours');
    $db->run("INSERT INTO slot_occurrences (workspace_id,slot_id,local_date,publish_at,mode,status,created_at,updated_at)
              VALUES (?, (SELECT id FROM publish_slots WHERE workspace_id=? LIMIT 1), '2099-02-05', '2099-02-05T09:00:00Z', 'manual', 'open', ?, ?)",
        [$ws, $ws, gmdate(NOW_ISO), gmdate(NOW_ISO)]);
    $freeId = $db->lastInsertId();
    $_POST = ['asset_id' => (string) $foreign];
    $ctl->assign(['id' => (string) $freeId]);
    $foreignRefused = (string) $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [$freeId])['status'] === 'open';
    $_POST = [];

    return $runCount === 1 && $pastUntouched && $autoUntouched && $foreignRefused;
})());

check('p24/ui: taking a video back off cancels it, and nothing was published', (static function () use ($basePath, $p24ctlSeed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $ctl, $asset, $cell] = $p24ctlSeed($db, 'takeoff@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];

    $ctl->unassign(['id' => (string) $cell['id']]);

    $after = $db->one('SELECT status, run_id, asset_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]);
    $run = $db->one('SELECT status FROM runs WHERE id = ?', [$runId]);
    $posts = (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id = ? AND status = 'published'", [$runId])['n'];
    $approvals = (int) $db->one('SELECT COUNT(*) AS n FROM approvals WHERE workspace_id = ?', [$ws])['n'];

    return (string) $after['status'] === 'open'
        && $after['run_id'] === null && $after['asset_id'] === null
        && (string) $run['status'] === 'cancelled'
        && $posts === 0
        && $approvals === 0;   // cancelling is not a rejection: no approval record
})());

check('p24/ui: a video standing on the calendar cannot be deleted from the library', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'libguard@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];

    $occ = new OccurrenceRepository($db);
    $inUse = $occ->plannedUsesOfAsset($ctx, $asset, gmdate(NOW_ISO)) === 1;

    // once it is off the calendar again, it is deletable
    $ctl->unassign(['id' => (string) $cell['id']]);
    $free = $occ->plannedUsesOfAsset($ctx, $asset, gmdate(NOW_ISO)) === 0;

    return $inUse && $free;
})());

check('p24/ui: removing a publishing time that still holds videos needs confirming', (static function () use ($basePath, $p24ctlSeed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $ctl, $asset, $cell] = $p24ctlSeed($db, 'cascade@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $slotId = (int) $cell['slot_id'];
    $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];

    // no confirmation → nothing happens
    $ctl->removeSlot(['id' => (string) $slotId]);
    $stillThere = $db->one('SELECT id FROM publish_slots WHERE id = ?', [$slotId]) !== null;

    // confirmed → the time goes and its day is closed honestly
    $_POST = ['cascade' => '1'];
    $ctl->removeSlot(['id' => (string) $slotId]);
    $_POST = [];
    $gone = $db->one('SELECT id FROM publish_slots WHERE id = ?', [$slotId]) === null;
    // the days go with the time (they cannot outlive it), and the run that was
    // standing on one is cancelled rather than left to publish
    $daysGone = $db->one('SELECT id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]) === null;
    $runStopped = (string) $db->one('SELECT status FROM runs WHERE id = ?', [$runId])['status'] === 'cancelled';
    $recorded = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'guardrail.plan_time_removed'", [$ws]) !== null;

    return $stillThere && $gone && $daysGone && $runStopped && $recorded;
})());

check('p24/ui: plan changes are audited, like the other guardrails', (static function () use ($basePath, $p24ctlSeed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $ctl, , ] = $p24ctlSeed($db, 'audit@x.com');

    $ctl->togglePause();
    $_POST = ['lead_minutes' => '240'];
    $ctl->savePlanSettings();
    $_POST = ['timezone' => 'Europe/Istanbul'];
    $ctl->saveTimezone();
    // adding a time goes through the controller here (the seed uses the
    // repository directly, which is not the audited path)
    $_POST = ['weekday' => '6', 'time_hhmm' => '20:15', 'mode' => 'auto'];
    $ctl->addSlot();
    $_POST = [];

    $keys = array_column(
        $db->all("SELECT key FROM events WHERE workspace_id = ? AND kind = 'guardrail'", [$ws]),
        'key',
    );

    return in_array('guardrail.plan_paused', $keys, true)
        && in_array('guardrail.plan_lead', $keys, true)
        && in_array('guardrail.plan_timezone', $keys, true)
        && in_array('guardrail.plan_time_added', $keys, true);
})());

check('p24/ui: the lead window refuses values the schema would reject', (static function () use ($basePath, $p24ctlSeed): bool {
    $db = migratedDb($basePath);
    [, $ws, , $ctl, , ] = $p24ctlSeed($db, 'lead@x.com');
    $settings = new WorkspaceSettings($db);

    $_POST = ['lead_minutes' => '5'];
    $ctl->savePlanSettings();
    $tooSmall = $settings->plan($ws)['auto_lead_minutes'] === 180;

    $_POST = ['lead_minutes' => '99999'];
    $ctl->savePlanSettings();
    $tooBig = $settings->plan($ws)['auto_lead_minutes'] === 180;

    $_POST = ['lead_minutes' => '240'];
    $ctl->savePlanSettings();
    $_POST = [];

    return $tooSmall && $tooBig && $settings->plan($ws)['auto_lead_minutes'] === 240;
})());

check('p24/ui: plan writes are throttled per IP, and the flash says so plainly', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    [, $ws, $ctx, , , ] = $p24ctlSeed($db, 'throttle@x.com');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

    $occ = new OccurrenceRepository($db);
    $events = new EventLog($db);
    $ctl = new \Kuyash\Controllers\PlanController(
        $view, new SlotRepository($db), new SlotResolver(), new WorkspaceSettings($db),
        new PostRepository($db), $ctx, new Csrf(), new Flash(), $occ,
        new OccurrenceMaterializer($occ, new SlotResolver()),
        new \Kuyash\Publish\PlanBoard($occ), new AssetRepository($db),
        new WorkflowRepository($db, new WorkflowValidator()),
        new Engine($db, $events, new WorkflowValidator()), $events,
        new Auth($db, new LoginThrottle($db), $ctx),
        new AccountRepository($db),
        new \Kuyash\Core\RateLimiter($db, 2, 60),   // 2 changes per minute
    );

    $before = (new WorkspaceSettings($db))->plan($ws)['plan_paused'];
    $ctl->togglePause();   // 1
    $ctl->togglePause();   // 2
    $afterTwo = (new WorkspaceSettings($db))->plan($ws)['plan_paused'];
    $ctl->togglePause();   // 3 → blocked, state must NOT change
    $afterThree = (new WorkspaceSettings($db))->plan($ws)['plan_paused'];

    unset($_SERVER['REMOTE_ADDR']);

    return $before === false && $afterTwo === false && $afterThree === false
        && array_key_exists('rate.limited', require $basePath . '/lang/en.php')
        && array_key_exists('rate.limited', require $basePath . '/lang/tr.php');
})());

check('p24/ui: automatic times state their real cost, from the same estimator the budget gate uses', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    // an AUTOMATIC time → the cost line must appear with a real figure
    [, $ws, $ctx, $ctl, , ] = $p24ctlSeed($db, 'cost@x.com', 'auto');
    $body = $ctl->index()->body();

    $expected = (new \Kuyash\Usage\CostEstimator(require $basePath . '/config/usage.php'))
        ->estimateRun('full', (array) ((new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'full')['nodes'] ?? []))['total_cents'];

    // The per-video price is stated BESIDE the choice, so it is known before the
    // commitment — a manual plan sees it too. What only an automatic plan gets is
    // the WEEKLY total, which is a claim about what will actually be spent.
    $db2 = migratedDb($basePath);
    [, , , $ctl2, , ] = $p24ctlSeed($db2, 'cost-manual@x.com', 'manual');
    $manualBody = $ctl2->index()->body();
    $weekly = \Kuyash\Core\Format::cents($expected);   // 1 auto time/week → same figure

    return $expected > 0
        && str_contains($body, \Kuyash\Core\Format::cents($expected))
        && str_contains($body, 'a week')                    // the weekly claim
        && str_contains($manualBody, \Kuyash\Core\Format::cents($expected))
        && !str_contains($manualBody, 'a week');            // …not made for a manual plan
})());

echo "== p24/queue: a planned card states its day instead of asking again ==\n";

check('p24/queue: an approval card carries the day it was planned for', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'qplan@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];

    // drive the run to the approval gate
    $clock = gmdate(NOW_ISO);
    [, $worker] = makeRig($db, new MockExecutor(), $clock);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $queue = new QueueController(
        $view, new JobRepository($db), new RunRepository($db),
        new Engine($db, new EventLog($db), new WorkflowValidator()),
        $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
        new WorkerHeartbeat(tempDir('hb') . '/p24.heartbeat'), new SlotRepository($db), new SlotResolver(),
        new WorkspaceSettings($db), new OccurrenceRepository($db), $db, makeTextEditorView($db));
    $body = $queue->index()->body();

    $en = require $basePath . '/lang/en.php';
    $stated = str_replace('{when}', '', $en['queue.planned_for']);

    return str_contains($body, trim($stated))              // the day is STATED…
        && str_contains($body, 'Publish now instead')      // …with an explicit override
        && !str_contains($body, 'Or pick an exact time');  // the picker is replaced, not doubled
})());

check('p24/queue: approving a planned card keeps its day; asking to publish now clears it', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $run = static function (bool $publishNow) use ($basePath, $p24ctlSeed, $view): array {
        $db = migratedDb($basePath);
        [$user, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'qkeep' . ($publishNow ? 'n' : 'k') . '@x.com');
        $_POST = ['asset_id' => (string) $asset];
        $ctl->assign(['id' => (string) $cell['id']]);
        $_POST = [];

        $clock = gmdate(NOW_ISO);
        [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
        for ($i = 0; $i < 40 && $worker->tick(); $i++) {
        }
        $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];
        $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);

        $queue = new QueueController(
            $view, new JobRepository($db), new RunRepository($db), $engine,
            $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
            new WorkerHeartbeat(tempDir('hb') . '/p24b.heartbeat'), new SlotRepository($db), new SlotResolver(),
            new WorkspaceSettings($db), new OccurrenceRepository($db), $db, makeTextEditorView($db));
        $_POST = $publishNow ? ['publish_now' => '1'] : [];
        $queue->approve(['id' => (string) $review['id']]);
        $_POST = [];
        for ($i = 0; $i < 40 && $worker->tick(); $i++) {
        }

        return [
            (string) ($db->one('SELECT publish_after FROM runs WHERE id = ?', [$runId])['publish_after'] ?? 'NULL'),
            (string) $db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId])['status'],
            (string) $cell['publish_at'],
        ];
    };

    [$keptAfter, $keptStatus, $planned] = $run(false);
    [$nowAfter, $nowStatus, ] = $run(true);

    return $keptAfter === $planned && $keptStatus === 'queued'     // the planned day survived
        && $nowAfter === 'NULL' && $nowStatus === 'published';     // the explicit override went out
})());

echo "== p24/gatefix: the closing gates' findings, each pinned by a test ==\n";

check('p24/gatefix: removing a time cannot strand a run that would then publish immediately', (static function () use ($basePath, $p24ctlSeed): bool {
    // security H1 / compliance B3: a cell whose time passed MINUTES ago is still
    // inside the grace window, still holds a live run, and must not be deleted
    // silently — the leftover run kept a past publish_after, which the queue
    // reads as "publish now".
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'strand@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];
    $slotId = (int) $cell['slot_id'];

    // its moment has just gone by (inside grace — the sweep has not run)
    $db->run('UPDATE slot_occurrences SET publish_at = ? WHERE id = ?',
        [gmdate(NOW_ISO, time() - 600), (int) $cell['id']]);

    $occ = new OccurrenceRepository($db);
    // the confirmation gate MUST see it
    $seen = count($occ->committedForSlot($ctx, $slotId)) === 1;

    // removing without confirming must refuse
    $ctl->removeSlot(['id' => (string) $slotId]);
    $refused = $db->one('SELECT id FROM publish_slots WHERE id = ?', [$slotId]) !== null;

    // confirmed → the run is cancelled, so nothing can publish later
    $_POST = ['cascade' => '1'];
    $ctl->removeSlot(['id' => (string) $slotId]);
    $_POST = [];
    $runStatus = (string) $db->one('SELECT status FROM runs WHERE id = ?', [$runId])['status'];

    return $seen && $refused
        && $db->one('SELECT id FROM publish_slots WHERE id = ?', [$slotId]) === null
        && $runStatus === 'cancelled';
})());

check('p24/gatefix: the store refuses to delete a time while any of its days still holds a run', (static function () use ($basePath, $p24ctlSeed): bool {
    // defence in depth behind the controller's confirmation
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'defence@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];

    // straight at the repository, skipping the controller entirely
    return (new SlotRepository($db))->remove($ctx, (int) $cell['slot_id']) === false
        && $db->one('SELECT id FROM publish_slots WHERE id = ?', [(int) $cell['slot_id']]) !== null;
})());

check('p24/gatefix: a planned post that PUBLISHED is never swept or audited as missed', (static function () use ($basePath, $argonHash, $p24runner): bool {
    // compliance B1: the sweep closed every successful planned post as 'missed'
    // and wrote a guardrail warning for it
    $db = migratedDb($basePath);
    $now = '2026-06-15T07:00:00Z';
    [$user, $ws] = seedUser($db, 'published-ok@x.com', $argonHash, 'PUBOK');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new SlotRepository($db))->add($ctx, 1, '09:00', null, $now, 'manual');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Went out fine');
    $account = (static function () use ($db, $ws, $now): int {
        $db->run("INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
                  VALUES (?, 'instagram', '@ok', 'zacct_ok', 'connected', 'ok', ?, ?)", [$ws, $now, $now]);

        return $db->lastInsertId();
    })();

    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $occ = new OccurrenceRepository($db);
    (new OccurrenceMaterializer($occ, new SlotResolver()))
        ->materialize($ws, 'UTC', (new SlotRepository($db))->listForWorkspace($ws), $now);
    $cell = $db->one('SELECT * FROM slot_occurrences WHERE workspace_id = ? ORDER BY publish_at LIMIT 1', [$ws]);
    $occ->reserve($ws, (int) $cell['id'], $asset, $now);
    $runId = $engine->startRunFor($ws, (int) $wf['id'], $asset, $user);
    $engine->setPublishAfter($ws, $runId, (string) $cell['publish_at']);
    $occ->attachRun($ws, (int) $cell['id'], $runId, $now);
    // it published, successfully
    $db->run("UPDATE runs SET status = 'completed' WHERE id = ?", [$runId]);
    $db->run("INSERT INTO posts (workspace_id,run_id,account_id,platform,status,idempotency_key,posted_at,created_at,updated_at)
              VALUES (?,?,?,'instagram','published',?,?,?,?)",
        [$ws, $runId, $account, "run:{$runId}:acct:{$account}:publish", (string) $cell['publish_at'], $now, $now]);

    // …and the sweep runs two hours later
    $p24runner($db, $engine)->tick('2026-06-15T11:00:00Z');

    $after = $db->one('SELECT status, skip_reason FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]);
    $falseWarning = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'plan.slot_missed'", [$ws]);

    return (string) $after['status'] === 'assigned'   // untouched — it worked
        && $after['skip_reason'] === null
        && $falseWarning === null;                    // no fabricated failure in the audit log
})());

check('p24/gatefix: the day a publish was missed stays on the calendar, with its reason', (static function () use ($basePath, $p24ctlSeed): bool {
    // compliance/ux B2: the board windowed from `now`, so the one day that needs
    // an explanation was the one day that vanished
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, , $cell] = $p24ctlSeed($db, 'visible-miss@x.com');
    $occ = new OccurrenceRepository($db);
    // a few hours ago today, closed as missed
    $db->run("UPDATE slot_occurrences SET publish_at = ?, status = 'skipped', skip_reason = 'no_content' WHERE id = ?",
        [gmdate(NOW_ISO, time() - 7200), (int) $cell['id']]);

    $board = new \Kuyash\Publish\PlanBoard($occ);
    $days = $board->calendar($ctx, 'UTC', gmdate(NOW_ISO));
    $found = null;
    foreach ($days as $day) {
        foreach ($day['cells'] as $c) {
            if ((int) $c['id'] === (int) $cell['id']) {
                $found = $c;
            }
        }
    }
    $summary = $board->summary($ctx, 'UTC', gmdate(NOW_ISO));

    return $found !== null
        && $found['state'] === \Kuyash\Publish\PlanBoard::MISSED
        && $found['reason'] === 'no_content'
        && $found['is_past'] === true
        && $summary['missed'] === 1;   // the dashboard counter can actually move
})());

check('p24/gatefix: a guardrail holding a day back is reported as stopped, not as a failure', (static function () use ($basePath, $p24ctlSeed): bool {
    // ux B3: every skipped cell rendered as red "Missed", including days the
    // operator cleared and days a working guardrail held
    $db = migratedDb($basePath);
    [, $ws, $ctx, , , $cell] = $p24ctlSeed($db, 'stopped@x.com');
    $occ = new OccurrenceRepository($db);
    $board = new \Kuyash\Publish\PlanBoard($occ);
    $stateFor = static function (string $reason) use ($db, $cell, $board, $ctx): string {
        $db->run("UPDATE slot_occurrences SET publish_at = ?, status = 'skipped', skip_reason = ? WHERE id = ?",
            [gmdate(NOW_ISO, time() - 3600), $reason, (int) $cell['id']]);
        foreach ($board->calendar($ctx, 'UTC', gmdate(NOW_ISO)) as $day) {
            foreach ($day['cells'] as $c) {
                if ((int) $c['id'] === (int) $cell['id']) {
                    return (string) $c['state'];
                }
            }
        }

        return 'not-found';
    };

    return $stateFor('cancelled') === \Kuyash\Publish\PlanBoard::STOPPED
        && $stateFor('daily_cap') === \Kuyash\Publish\PlanBoard::STOPPED
        && $stateFor('compliance_block') === \Kuyash\Publish\PlanBoard::STOPPED
        && $stateFor('kill_switch') === \Kuyash\Publish\PlanBoard::STOPPED
        && $stateFor('no_content') === \Kuyash\Publish\PlanBoard::MISSED
        && $stateFor('not_approved') === \Kuyash\Publish\PlanBoard::MISSED;
})());

check('p24/gatefix: the calendar shows the time the QUEUE is holding, not the one the plan wanted', (static function () use ($basePath, $p24ctlSeed): bool {
    // security M5: the publish gate can defer a capped post; showing the planned
    // time then is exactly the "read the plan, not the job gate" mistake
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'moved@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];

    $moved = gmdate(NOW_ISO, strtotime((string) $cell['publish_at']) + 7200);
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at)
              VALUES (?,?,'PUBLISH',9,'publish','queued',?,?)", [$ws, $runId, $moved, gmdate(NOW_ISO)]);

    $board = new \Kuyash\Publish\PlanBoard($occ = new OccurrenceRepository($db));
    foreach ($board->calendar($ctx, 'UTC', gmdate(NOW_ISO)) as $day) {
        foreach ($day['cells'] as $c) {
            if ((int) $c['id'] === (int) $cell['id']) {
                return $c['state'] === \Kuyash\Publish\PlanBoard::SCHEDULED
                    && $c['at'] === $moved       // the REAL gate
                    && $c['moved'] === true;     // …and it says it moved
            }
        }
    }

    return false;
})());

check('p24/gatefix: an ordinary library video is still deletable once its day is done with it', (static function () use ($basePath, $p24ctlSeed): bool {
    // security M3: a published (or swept) day keeps its asset_id for the whole
    // retention window, and the foreign key turned an ordinary delete into a 500
    $db = migratedDb($basePath);
    [, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'libdel@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    // its day has gone by and was closed
    $db->run("UPDATE slot_occurrences SET publish_at = ?, status = 'skipped', skip_reason = 'missed', run_id = NULL WHERE id = ?",
        [gmdate(NOW_ISO, time() - 7200), (int) $cell['id']]);

    $occ = new OccurrenceRepository($db);
    $stillBlocked = $occ->plannedUsesOfAsset($ctx, $asset, gmdate(NOW_ISO)) === 0;
    $occ->forgetAssetOnFinishedDays($ctx, $asset, gmdate(NOW_ISO));

    // the delete must now succeed rather than raising a foreign-key error
    $deleted = (new AssetRepository($db))->delete($ctx, $asset);
    $dayKept = $db->one('SELECT status, skip_reason, asset_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]);

    return $stillBlocked && $deleted
        && (string) $dayKept['status'] === 'skipped'
        && (string) $dayKept['skip_reason'] === 'missed'   // the record survives
        && $dayKept['asset_id'] === null;
})());

check('p24/gatefix: retention keeps the day of a run that is still alive', (static function () use ($basePath, $p24ctlSeed): bool {
    // security L5
    $db = migratedDb($basePath);
    [, $ws, , $ctl, $asset, $cell] = $p24ctlSeed($db, 'retain@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $db->run('UPDATE slot_occurrences SET publish_at = ? WHERE id = ?',
        [gmdate(NOW_ISO, time() - 90 * 86400), (int) $cell['id']]);

    $occ = new OccurrenceRepository($db);
    $occ->pruneBefore(gmdate(NOW_ISO, time() - 30 * 86400));
    $kept = $db->one('SELECT id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]) !== null;

    // once the run is over, it prunes
    $db->run("UPDATE runs SET status = 'completed' WHERE id = (SELECT run_id FROM slot_occurrences WHERE id = ?)", [(int) $cell['id']]);
    $occ->pruneBefore(gmdate(NOW_ISO, time() - 30 * 86400));

    return $kept && $db->one('SELECT id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']]) === null;
})());

check('p24/gatefix: a replayed "publish now" cannot touch a decision the engine refused', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    // security M4
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $ctl, $asset, $cell] = $p24ctlSeed($db, 'replay@x.com');
    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];
    $clock = gmdate(NOW_ISO);
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $runId = (int) $db->one('SELECT run_id FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['run_id'];
    // a NON-approval job of the same planned run
    $other = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'caption_generation'", [$runId]);

    $queue = new QueueController(
        $view, new JobRepository($db), new RunRepository($db), $engine,
        $ctx, new Auth($db, new LoginThrottle($db), $ctx), new Csrf(), new Flash(),
        new WorkerHeartbeat(tempDir('hb') . '/p24c.heartbeat'), new SlotRepository($db), new SlotResolver(),
        new WorkspaceSettings($db), new OccurrenceRepository($db), $db, makeTextEditorView($db));
    $_POST = ['publish_now' => '1'];
    $queue->approve(['id' => (string) $other['id']]);   // engine will refuse this
    $_POST = [];

    // the planned instant must be untouched by a refused decision
    return (string) $db->one('SELECT publish_after FROM runs WHERE id = ?', [$runId])['publish_after']
        === (string) $cell['publish_at'];
})());

check('p24/gatefix: a failing plan tick never stops the queue', (static function () use ($basePath): bool {
    // security M2 — the guard is in bin/worker.php; assert it is really there,
    // at BOTH call sites, since a throw at the startup one halts all publishing
    $src = (string) file_get_contents($basePath . '/bin/worker.php');
    $guards = substr_count($src, 'catch (Throwable $e)');
    $runnerSrc = (string) file_get_contents($basePath . '/src/Publish/PlanRunner.php');

    return $guards >= 2
        && str_contains($src, "plan tick failed on startup")
        && str_contains($runnerSrc, 'plan tick failed for workspace');   // one tenant cannot abort the rest
})());

check('p24/gatefix: adding to the plan is refused while everything automatic is switched off', (static function () use ($basePath, $p24ctlSeed): bool {
    // security L7: do not spend on a caption the publish gate will then refuse
    $db = migratedDb($basePath);
    [, $ws, , $ctl, $asset, $cell] = $p24ctlSeed($db, 'killassign@x.com');
    (new WorkspaceSettings($db))->setKillSwitch($ws, true);

    $_POST = ['asset_id' => (string) $asset];
    $ctl->assign(['id' => (string) $cell['id']]);
    $_POST = [];

    return (int) $db->one('SELECT COUNT(*) AS n FROM runs WHERE workspace_id = ?', [$ws])['n'] === 0
        && (string) $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [(int) $cell['id']])['status'] === 'open';
})());

check('p24/gatefix: a compliance block is not reported with a cause that was not checked', (static function () use ($basePath): bool {
    // compliance B4: format blocks were being described as slop blocks
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';

    return !str_contains(strtolower($en['plan.reason_compliance_block']), 'recent post')
        && !str_contains(strtolower($tr['plan.reason_compliance_block']), 'benziyordu');
})());

echo "== p24/ui-fix: themed controls and a discoverable \"I add the video\" path ==\n";

check('p24/ui-fix: radios and checkboxes are painted from the tokens, and the real input stays focusable', (static function () use ($basePath): bool {
    $css = (string) file_get_contents($basePath . '/public/assets/css/base.css');

    // appearance:none removes only the NATIVE painting — the input itself must
    // never be display:none'd or moved off-screen, or the thing the user tabs to
    // and toggles with Space stops being the thing they see.
    $block = substr($css, strpos($css, 'input[type="radio"],'));
    $block = substr($block, 0, strpos($block, 'input[type="radio"]:disabled'));

    return str_contains($block, 'appearance: none')
        && !str_contains($block, 'display: none')
        && !str_contains($block, 'visibility: hidden')
        && !str_contains($block, 'position: absolute')
        && str_contains($block, 'var(--accent)')            // checked state from the token
        && str_contains($block, 'var(--border-strong)')     // resting border from the token
        // motion budget: colour + transform only, no blur/spring on a control
        && !str_contains($block, 'blur(')
        && !str_contains($block, '--spring')
        && str_contains($css, ':focus-visible { outline: 1.5px solid var(--accent)');
})());

check('p24/ui-fix: no control is left painting itself with the browser default', (static function () use ($basePath): bool {
    // accent-color only tints the NATIVE control, which appearance:none removes —
    // leaving it behind is a property that silently does nothing.
    $app = (string) file_get_contents($basePath . '/public/assets/css/app.css');

    return !str_contains($app, 'accent-color');
})());

check('p24/ui-fix: each mode states what to do next, revealed by its own radio and with no script', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    [, , , $ctl, , ] = $p24ctlSeed($db, 'modehelp@x.com');
    $body = $ctl->index()->body();
    $css = (string) file_get_contents($basePath . '/public/assets/css/app.css');

    return str_contains($body, 'Upload your videos to the Library')
        && str_contains($body, 'Open the Library')
        && str_contains($body, 'href="/library"')
        && str_contains($body, 'Kuyash builds it ahead of the time')
        // a plain sibling selector: it reveals on selection with JS switched off
        && str_contains($css, '.mode-opt input:checked ~ .mode-opt__help { display: block; }')
        // …and a link in that guidance has to LOOK like a link
        && (bool) preg_match('/\.mode-opt__help a[^{]*\{[^}]*color: var\(--accent\)/', $css);
})());

check('p24/ui-fix: the radio and its label are wired together, so the label is a hit target', (static function () use ($basePath, $p24ctlSeed, $view): bool {
    $db = migratedDb($basePath);
    [, , , $ctl, , ] = $p24ctlSeed($db, 'modelabel@x.com');
    $body = $ctl->index()->body();

    return str_contains($body, 'id="mode-manual"')
        && str_contains($body, 'for="mode-manual"')
        && str_contains($body, 'id="mode-auto"')
        && str_contains($body, 'for="mode-auto"')
        // one form at a time (empty-state OR populated), so the ids stay unique
        && substr_count($body, 'id="mode-manual"') === 1;
})());

check('p24/ui-fix: with nothing in the library, a day says so and points at the Library', (static function () use ($basePath, $argonHash, $view): bool {
    // the state the normal seed never produces: a brand-new operator who has not
    // uploaded anything yet must still be able to find the next step
    $db = migratedDb($basePath);
    $now = gmdate(NOW_ISO);
    [$user, $ws] = seedUser($db, 'emptylib@x.com', $argonHash, 'EMPTYLIB');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    (new SlotRepository($db))->add($ctx, ((int) gmdate('N') % 7) + 1, '09:00', null, $now, 'manual');
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    // deliberately NO seedReadyVideo()

    $body = makePlanController($db, $ctx, $view)->index()->body();

    return str_contains($body, 'No videos yet.')
        && str_contains($body, 'Add one to your library')
        && str_contains($body, 'href="/library"')
        // and it must NOT offer a picker with nothing in it
        && !str_contains($body, 'name="asset_id"');
})());

check('p24/ui-fix: the guidance reads in plain words in both languages, with no jargon', (static function () use ($basePath): bool {
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';
    $keys = ['plan.mode_manual_help', 'plan.mode_manual_help_link', 'plan.mode_auto_help', 'plan.mode_auto_help_cost'];

    foreach ($keys as $k) {
        if (!array_key_exists($k, $en) || !array_key_exists($k, $tr)) {
            return false;
        }
        foreach (['slot', 'occurrence', 'assign', 'render_review', 'pipeline'] as $jargon) {
            if (str_contains(strtolower($en[$k]), $jargon)) {
                return false;
            }
        }
    }

    return str_contains($tr['plan.mode_manual_help'], 'Kütüphane')
        && str_contains($en['plan.mode_manual_help'], 'Library');
})());

// ─────────────────────────────────────────────────────────────────────────────
// Phase 25 — TASK 0: RISK SPIKE (no product code yet).
//
// The whole feature rests on ONE claim: a human-edited caption reaches the
// publish adapter as the EDITED text, and the mandatory AI disclosure is still
// on it — because the disclosure is composed at publish time and was never part
// of the caption body an operator can touch.
//
// Proven with the code that already exists: the edit is simulated by writing
// straight into jobs.result_json (which is exactly where a real edit will land),
// so if this fails the plan's storage decision is wrong and Task 1 must not start.
echo "== p25/task0: RISK SPIKE — an edited caption reaches the adapter, disclosure intact ==\n";

/**
 * Drive a distribution run to its approval gate with a connected Instagram
 * account and a spy provider, and hand back everything the spike needs.
 *
 * @return array{0: Database, 1: int, 2: int, 3: int, 4: SpyPublishProvider, 5: Engine, 6: Worker, 7: WorkspaceContext}
 */
$p25rig = static function (string $basePath, string $argonHash, string $email, View $view): array {
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, $email, $argonHash, 'P25 ' . $email);
    $now = '2026-08-24T09:00:00Z';
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Spike clip');
    $db->run(
        "INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
         VALUES (?, 'instagram', '@spike', 'zacct_spike25', 'connected', 'ok', ?, ?)",
        [$ws, $now, $now],
    );

    $clock = $now;
    $spy = new SpyPublishProvider();
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock, null, false, $spy);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    return [$db, $user, $ws, $runId, $spy, $engine, $worker, $ctx];
};

check('p25/task0: an edited caption is what reaches the adapter, with the AI disclosure still on it', (static function () use ($basePath, $argonHash, $view, $p25rig): bool {
    [$db, $user, $ws, $runId, $spy, $engine, $worker, $ctx] = $p25rig($basePath, $argonHash, 'p25-spike@example.com', $view);

    // The media is AI-labelled (a full pipeline's TTS would set this; a spike
    // states it directly, because WHY the label is required is not what is
    // under test — that the edit cannot strip it, is).
    $cc = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    if ($cc === null) {
        return false;
    }
    $ccResult = json_decode((string) $cc['result_json'], true);
    $ccResult['ai_label_required'] = true;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($ccResult), (int) $cc['id']]);

    // THE EDIT — written exactly where a real one will land, and deliberately
    // containing no disclosure of any kind.
    $edited = 'A caption a human actually wrote. No disclosure anywhere in this body.';
    $capJob = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'caption_generation'", [$runId]);
    $capResult = json_decode((string) $capJob['result_json'], true);
    $aiOriginal = (string) ($capResult['captions']['instagram'] ?? '');
    $capResult['captions']['instagram'] = $edited;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($capResult), (int) $capJob['id']]);

    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    if ($review === null || $engine->approve($ctx, (int) $review['id'], $user, 'p25-spike@example.com', null) !== Decision::Ok) {
        return false;
    }
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $req = $spy->requests[0] ?? null;
    if (!$req instanceof PublishRequest) {
        return false;
    }
    $disclosure = 'Made with AI';

    return str_contains($req->caption, $edited)                       // the EDIT reached the adapter…
        && $aiOriginal !== '' && !str_contains($req->caption, $aiOriginal)  // …and the AI original did NOT
        && str_ends_with(rtrim($req->caption), $disclosure)           // the disclosure is still the LAST line
        && $req->aiLabelApplied === true                              // and the label is still asserted
        && (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id = ? AND ai_label_applied = 1", [$runId])['n'] === 1;
})());

check('p25/task0: an UNEDITED run is untouched — the AI caption publishes exactly as it does today', (static function () use ($basePath, $argonHash, $view, $p25rig): bool {
    // The regression baseline the whole phase must preserve.
    [$db, $user, $ws, $runId, $spy, $engine, $worker, $ctx] = $p25rig($basePath, $argonHash, 'p25-baseline@example.com', $view);

    $capJob = $db->one("SELECT result_json FROM jobs WHERE run_id = ? AND type = 'caption_generation'", [$runId]);
    $aiCaption = (string) (json_decode((string) $capJob['result_json'], true)['captions']['instagram'] ?? '');

    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'p25-baseline@example.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $req = $spy->requests[0] ?? null;

    return $req instanceof PublishRequest
        && $aiCaption !== ''
        && $req->caption === $aiCaption          // no disclosure appended: this run is not AI-labelled
        && $req->aiLabelApplied === false;
})());

echo "== p25/gate: what an edit has to pass before it can be saved ==\n";

$p25limits = new \Kuyash\Content\PlatformLimits(require $basePath . '/config/platforms.php');

check('p25/gate: the character count measures what is actually SENT — body, disclosure and tags', (static function () use ($p25limits): bool {
    // mirrors ZernioPublishExecutor::withDisclosure then ZernioPublishProvider::postPayload
    $assembled = $p25limits->assemble('Hello', ['#a', '#b'], 'Made with AI');

    return $assembled === "Hello\nMade with AI\n\n#a #b"
        && $p25limits->measure('instagram', 'Hello', ['#a', '#b'], 'Made with AI')['chars'] === mb_strlen($assembled)
        // …and the body alone would have understated it
        && $p25limits->measure('instagram', 'Hello', [], '')['chars'] === 5;
})());

check('p25/gate: an unknown platform reports no opinion rather than a false "fine"', (static function () use ($p25limits): bool {
    $m = $p25limits->measure('mastodon', str_repeat('x', 9000), [], '');

    return $m['known'] === false && $m['over_chars'] === false && $m['over_tags'] === false;
})());

/** ContentGate over a real workspace, with a real SlopScorer. */
$p25gate = static function (Database $db) use ($basePath): \Kuyash\Compliance\ContentGate {
    return new \Kuyash\Compliance\ContentGate(
        new SlopScorer($db),
        new \Kuyash\Content\PlatformLimits(require $basePath . '/config/platforms.php'),
    );
};

check('p25/gate: a length over the configured limit only WARNS — the number is unverified', (static function () use ($basePath, $argonHash, $p25gate): bool {
    // LOCKED DECISION: config/platforms.php values are not verified against any
    // platform's documentation, so refusing a save on them would be the system
    // asserting something it does not know.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'p25-limit@x.com', $argonHash, 'P25LIMIT');
    $long = str_repeat('a', 3000);

    $v = $p25gate($db)->judge($ws, 1, ['instagram' => $long], [], ['instagram']);
    $keys = array_column($v['warnings'], 'key');

    return $v['status'] === CompliancePolicy::WARN     // warn, NOT block
        && $v['reasons'] === []
        && in_array('content.too_long', $keys, true)
        && $v['limits']['instagram']['over_chars'] === true;
})());

check('p25/gate: an empty caption on a CONNECTED channel blocks — that is missing content, not a limit', (static function () use ($basePath, $argonHash, $p25gate): bool {
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'p25-empty@x.com', $argonHash, 'P25EMPTY');

    $blocked = $p25gate($db)->judge($ws, 1, ['instagram' => '   ', 'tiktok' => 'fine'], [], ['instagram', 'tiktok']);
    // …but an empty caption for a channel that is NOT connected is nobody's problem
    $ok = $p25gate($db)->judge($ws, 1, ['instagram' => 'fine', 'youtube' => ''], [], ['instagram']);

    return $blocked['status'] === CompliancePolicy::BLOCK
        && array_column($blocked['reasons'], 'key') === ['content.empty_caption']
        && $ok['status'] !== CompliancePolicy::BLOCK;
})());

check('p25/gate: an edit that is a near-duplicate of recent content is BLOCKED by the same rule the generator faced', (static function () use ($basePath, $argonHash, $p25gate): bool {
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'p25-slop@x.com', $argonHash, 'P25SLOP');
    $now = gmdate(NOW_ISO);
    $db->run("INSERT INTO workflows (workspace_id,name,template,nodes_json,created_at,updated_at) VALUES (?,'W','distribution','[]',?,?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $text = 'A one pan dinner you can make in thirty seconds flat with three cheap ingredients';
    // a previous run in this workspace already published essentially this text
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at) VALUES (?,?,'library','[]','completed',?,?,?)", [$ws, $wf, $user, $now, $now]);
    $old = $db->lastInsertId();
    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at,result_json) VALUES (?,?,'CAPTION',2,'caption_generation','ready',?,?,?)",
        [$ws, $old, $now, $now, json_encode(['captions' => ['instagram' => $text]])]);

    $v = $p25gate($db)->judge($ws, $old + 1, ['instagram' => $text], [], ['instagram']);

    return $v['status'] === CompliancePolicy::BLOCK
        && array_column($v['reasons'], 'key') === ['content.too_similar']
        && $v['slop']['score'] >= CompliancePolicy::SLOP_BLOCK
        && $v['policy'] === CompliancePolicy::VERSION;   // the SAME policy, not a second one
})());

check('p25/gate: typing the disclosure into the body is allowed, noted, and never doubled', (static function () use ($basePath, $argonHash, $p25gate): bool {
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'p25-disc@x.com', $argonHash, 'P25DISC');

    $v = $p25gate($db)->judge($ws, 1, ['instagram' => "My video\nMade with AI"], [], ['instagram'], '', 'Made with AI');
    $composed = \Kuyash\Publish\Disclosure::compose("My video\nMade with AI", 'Made with AI');

    return in_array('content.disclosure_typed', array_column($v['warnings'], 'key'), true)
        && $v['status'] !== CompliancePolicy::BLOCK
        && substr_count($composed, 'Made with AI') === 1;   // deduped
})());

echo "== p25/store: where an edit is written, and when it is allowed at all ==\n";

/** A distribution run parked at its approval gate, with a connected account. */
$p25run = static function (Database $db, string $email) use ($argonHash): array {
    $now = gmdate(NOW_ISO);
    [$user, $ws] = seedUser($db, $email, $argonHash, 'P25 ' . $email);
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    (new WorkflowRepository($db, new WorkflowValidator()))->ensureDefaults($ctx);
    $wf = (new WorkflowRepository($db, new WorkflowValidator()))->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Editable clip');
    $db->run("INSERT INTO accounts (workspace_id,platform,handle,external_ref,status,health,created_at,updated_at)
              VALUES (?, 'instagram', '@ed', 'zacct_ed', 'connected', 'ok', ?, ?)", [$ws, $now, $now]);
    $clock = $now;
    $spy = new SpyPublishProvider();
    [$engine, $worker] = makeRig($db, new MockExecutor(), $clock, null, false, $spy);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    return [$user, $ws, $ctx, $runId, $engine, $worker, $spy];
};

check('p25/store: an edit replaces what publish reads, and keeps what Kuyash wrote', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'store-basic@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);

    $before = $rev->forRun($ctx, $runId);
    $aiCaption = $before['captions']['instagram'] ?? '';
    $edited = ['instagram' => 'Human words.', 'tiktok' => 'Human words two.', 'youtube' => 'Human words three.'];

    $saved = $rev->save($ctx, $runId, $edited, ['#one'], $before['hash'], $user, 'store-basic@x.com', ['status' => 'pass'], gmdate(NOW_ISO));
    $after = $rev->forRun($ctx, $runId);

    return $saved
        && $aiCaption !== '' && $aiCaption !== 'Human words.'
        && $after['captions']['instagram'] === 'Human words.'      // publish reads THIS
        && $after['captions_ai']['instagram'] === $aiCaption        // …and the AI original survives
        && $after['hashtags'] === ['#one']
        && $after['edited'] === true
        && (int) $after['edit']['by'] === $user;
})());

check('p25/store: a second edit does not overwrite the AI original with the first edit', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId] = $p25run($db, 'store-twice@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $first = $rev->forRun($ctx, $runId);
    $ai = $first['captions']['instagram'];

    $rev->save($ctx, $runId, ['instagram' => 'edit one'], [], $first['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));
    $mid = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'edit two'], [], $mid['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));
    $end = $rev->forRun($ctx, $runId);

    return $end['captions']['instagram'] === 'edit two' && $end['captions_ai']['instagram'] === $ai;
})());

check('p25/store: a stale form is refused rather than silently overwriting the other tab', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId] = $p25run($db, 'store-race@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);       // both tabs load this

    $first = $rev->save($ctx, $runId, ['instagram' => 'tab one'], [], $loaded['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));
    $second = $rev->save($ctx, $runId, ['instagram' => 'tab two'], [], $loaded['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));

    return $first && !$second
        && $rev->forRun($ctx, $runId)['captions']['instagram'] === 'tab one';
})());

check('p25/store: once it is publishing, the text is read-only', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'store-locked@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $editableBefore = $loaded['editable'] === true;

    $db->run("INSERT INTO jobs (workspace_id,run_id,node,step,type,status,run_after,created_at) VALUES (?,?,'PUBLISH',9,'publish','processing',?,?)",
        [$ws, $runId, gmdate(NOW_ISO), gmdate(NOW_ISO)]);

    $after = $rev->forRun($ctx, $runId);
    $refused = $rev->save($ctx, $runId, ['instagram' => 'too late'], [], $loaded['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));

    return $editableBefore
        && $after['editable'] === false && $after['locked_reason'] === 'publishing'
        && !$refused;
})());

check('p25/store: another workspace cannot read or change this run\'s text', (static function () use ($basePath, $p25run, $argonHash): bool {
    $db = migratedDb($basePath);
    [$user, , , $runId] = $p25run($db, 'store-iso-a@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $ctxA = new WorkspaceContext($db);

    [, $wsB] = seedUser($db, 'store-iso-b@x.com', $argonHash, 'ISO B');
    $ctxB = new WorkspaceContext($db);
    $ctxB->set($wsB);

    $invisible = $rev->forRun($ctxB, $runId) === null;
    $refused = !$rev->save($ctxB, $runId, ['instagram' => 'not yours'], [], 'whatever', $user, 'b@x.com', [], gmdate(NOW_ISO));

    return $invisible && $refused;
})());

check('p25/store: operator input is cleaned — control characters out, tags normalized, newlines kept', (static function (): bool {
    $caps = \Kuyash\Content\ContentRevision::cleanCaptions([
        'instagram' => "line one\u{0007}\nline two   with   spaces",
        'bogus' => 123,
    ]);
    $tags = \Kuyash\Content\ContentRevision::cleanHashtags('  #One two, ###three  #one  !!! ');

    return $caps['instagram'] === "line one\nline two with spaces"   // newlines survive, control char gone
        && !array_key_exists('bogus', $caps)
        && $tags === ['#One', '#two', '#three', '#one'];             // '#one' ≠ '#One': dedupe is exact
})());

echo "== p25/publish: the drift guard, and the promise that unedited runs did not change ==\n";

check('p25/publish: text changed without passing the gate never reaches the platform', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId, $engine, $worker, $spy] = $p25run($db, 'pub-drift@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'gated text'], [], $loaded['hash'], $user, 'e@x.com', ['status' => 'pass'], gmdate(NOW_ISO));

    // …and then something writes around the gate (a bug, a stray script)
    $capJob = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'caption_generation'", [$runId]);
    $cap = json_decode((string) $capJob['result_json'], true);
    $cap['captions']['instagram'] = 'text nobody checked';
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($cap), (int) $capJob['id']]);

    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'e@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $publish = $db->one("SELECT status, error_message, retry_count FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);
    $unverified = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'content.edit_unverified'", [$ws]);

    return $spy->requests === []                                   // nothing was sent
        && (int) $db->one("SELECT COUNT(*) AS n FROM posts WHERE run_id = ?", [$runId])['n'] === 0
        && (string) $publish['status'] === 'failed'
        && str_contains((string) $publish['error_message'], 'without passing the compliance check')
        // dead-lettered on the FIRST attempt: Engine labels a non-retryable
        // failure and stops, instead of walking the retry ladder toward a live
        // account (retry_count is the attempt that failed, so 1, not max_retries)
        && str_starts_with((string) $publish['error_message'], 'non-retryable:')
        && (int) $publish['retry_count'] === 1
        && $unverified !== null;
})());

check('p25/publish: a gate-approved edit publishes, still carrying the AI disclosure', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId, $engine, $worker, $spy] = $p25run($db, 'pub-ok@x.com');
    // the media is AI-labelled
    $cc = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    $ccr = json_decode((string) $cc['result_json'], true);
    $ccr['ai_label_required'] = true;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($ccr), (int) $cc['id']]);

    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'Edited, no disclosure typed here'], ['#tag'], $loaded['hash'], $user, 'e@x.com', ['status' => 'pass'], gmdate(NOW_ISO));

    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'e@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }

    $req = $spy->requests[0] ?? null;

    return $req instanceof PublishRequest
        && str_contains($req->caption, 'Edited, no disclosure typed here')
        && str_ends_with(rtrim($req->caption), 'Made with AI')     // the edit could not strip it
        && $req->aiLabelApplied === true
        && $req->hashtags === ['#tag'];
})());

check('p25/publish: an UNEDITED run behaves exactly as it did before Phase 25', (static function () use ($basePath, $p25run): bool {
    // REGRESSION LOCK. No `edit` block → none of the new guard runs.
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId, $engine, $worker, $spy] = $p25run($db, 'pub-baseline@x.com');
    $capJob = $db->one("SELECT result_json FROM jobs WHERE run_id = ? AND type = 'caption_generation'", [$runId]);
    $cap = json_decode((string) $capJob['result_json'], true);
    $aiCaption = (string) $cap['captions']['instagram'];
    $noEditBlock = !array_key_exists('edit', $cap) && !array_key_exists('captions_ai', $cap);

    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'e@x.com', null);
    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $req = $spy->requests[0] ?? null;

    return $noEditBlock
        && $req instanceof PublishRequest
        && $req->caption === $aiCaption
        && (string) $db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId])['status'] === 'published';
})());

check('p25/publish: the native TikTok/YouTube AI flags come from the media, not from the text', (static function () use ($basePath, $argonHash): bool {
    // An edit changes the caption; it must not be able to move a platform flag.
    $req = new PublishRequest('tiktok', '@t', 'ref', 'k', true, null, 1, 'anything a human typed', [], 1);
    $reqYt = new PublishRequest('youtube', '@y', 'ref', 'k2', true, null, 1, 'anything a human typed', [], 1);
    $off = new PublishRequest('tiktok', '@t', 'ref', 'k3', false, null, 1, 'Made with AI typed by hand', [], 1);

    $psd = static function (PublishRequest $r): array {
        $m = new ReflectionMethod(ZernioPublishProvider::class, 'platformSpecificData');
        $m->setAccessible(true);

        return $m->invoke(
            (new ReflectionClass(ZernioPublishProvider::class))->newInstanceWithoutConstructor(),
            $r,
        );
    };

    return $psd($req) === ['videoMadeWithAi' => true]
        && $psd($reqYt) === ['containsSyntheticMedia' => true]
        // typing the words does NOT set the flag — the flag is about the media
        && $psd($off) === [];
})());

echo "== p25/ui: the editor on the approval card and the run screen ==\n";

/** A ContentController wired the way the web binding wires it. */
function makeContentController(Database $db, WorkspaceContext $ctx, ?\Kuyash\Content\DraftStash $drafts = null): \Kuyash\Controllers\ContentController
{
    global $basePath;
    $limits = new \Kuyash\Content\PlatformLimits(require $basePath . '/config/platforms.php');

    return new \Kuyash\Controllers\ContentController(
        new \Kuyash\Content\ContentRevision($db),
        new \Kuyash\Compliance\ContentGate(new SlopScorer($db), $limits),
        new AccountRepository($db),
        $db,
        $ctx,
        new Auth($db, new LoginThrottle($db), $ctx),
        new Flash(),
        new EventLog($db),
        new WorkspaceSettings($db),
        $drafts ?? new \Kuyash\Content\DraftStash(),
    );
}

check('p25/ui: saving through the controller stores the edit and records who did it', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'ctl-save@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);

    $_POST = [
        'content_hash' => $loaded['hash'],
        'back' => 'queue',
        'caption' => ['instagram' => 'Mine.', 'tiktok' => 'Mine too.', 'youtube' => 'And mine.'],
        'hashtags' => 'alpha beta',
    ];
    $res = makeContentController($db, $ctx)->save(['id' => (string) $runId]);
    $_POST = [];

    $after = $rev->forRun($ctx, $runId);
    $event = $db->one("SELECT kind, level FROM events WHERE workspace_id = ? AND key = 'content.edited'", [$ws]);

    return $res->status() === 303
        && $after['captions']['instagram'] === 'Mine.'
        && $after['hashtags'] === ['#alpha', '#beta']
        && (string) $after['edit']['by_email'] === 'ctl-save@x.com'
        && $event !== null
        // no new events.kind was invented — it maps onto one that already exists
        && in_array((string) $event['kind'], ['transition', 'compliance', 'guardrail'], true);
})());

check('p25/ui: a blocked edit changes nothing and says which rule stopped it', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'ctl-block@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $before = $loaded['captions']['instagram'];

    $_POST = [
        'content_hash' => $loaded['hash'],
        'caption' => ['instagram' => '   ', 'tiktok' => 'x', 'youtube' => 'y'],  // instagram IS connected
        'hashtags' => '',
    ];
    makeContentController($db, $ctx)->save(['id' => (string) $runId]);
    $_POST = [];

    $after = $rev->forRun($ctx, $runId);
    $audited = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'content.edit_blocked'", [$ws]);

    return $after['captions']['instagram'] === $before   // untouched
        && $after['edited'] === false
        && $audited !== null;
})());

check('p25/ui: an edit made AFTER approval keeps the approval and is recorded as its own fact', (static function () use ($basePath, $p25run): bool {
    // The approval was a real decision at a real time — it is not rewritten. That
    // the text moved afterwards is a separate, louder record.
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId, $engine] = $p25run($db, 'ctl-after@x.com');
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'ctl-after@x.com', '2099-01-01T09:00:00Z');
    $approvalBefore = $db->one('SELECT id, mode, decided_by, decided_at FROM approvals WHERE run_id = ?', [$runId]);

    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $_POST = [
        'content_hash' => $loaded['hash'],
        'caption' => ['instagram' => 'Changed my mind.', 'tiktok' => 'a', 'youtube' => 'b'],
        'hashtags' => '',
    ];
    makeContentController($db, $ctx)->save(['id' => (string) $runId]);
    $_POST = [];

    $approvalAfter = $db->one('SELECT id, mode, decided_by, decided_at FROM approvals WHERE run_id = ?', [$runId]);
    $ev = $db->one("SELECT level FROM events WHERE workspace_id = ? AND key = 'content.edited_after_approval'", [$ws]);

    return $approvalAfter == $approvalBefore                       // the approval record is untouched
        && (string) $approvalAfter['mode'] === 'manual'
        && (int) $approvalAfter['decided_by'] === $user
        && $ev !== null && (string) $ev['level'] === 'warn'        // …and the edit is flagged, not hidden
        && $rev->forRun($ctx, $runId)['captions']['instagram'] === 'Changed my mind.';
})());

check('p25/ui: an auto-approved run that a person edited shows BOTH facts, and never "approved by you"', (static function () use ($basePath, $view): bool {
    // The compliance agent approved the render; a human later changed the words.
    // Two true things — neither may be rendered as the other.
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';
    $tpl = (string) file_get_contents($basePath . '/templates/runs/show.php');
    $editor = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');

    return str_contains($en['content.edited_badge'], 'you edited it')
        && !str_contains(strtolower($en['content.edited_badge']), 'approved')
        && array_key_exists('content.edited_after_approval', $tr)
        // the approval badge still branches on the STORED mode, untouched by this
        // phase — an auto record renders the agent, a manual one renders the person
        && str_contains($tpl, "\$isAuto = (\$approval['mode'] ?? 'manual') === 'auto'")
        && str_contains($tpl, 'digest.approved_by_agent')
        && str_contains($tpl, 'runs.approved_by_you')
        // …and the editor never claims an approval of any kind
        && !str_contains($editor, 'approved_by');
})());

check('p25/ui: the editor is offered on the publish gate, never on a script draft', (static function () use ($basePath, $p25run, $view): bool {
    $db = migratedDb($basePath);
    [, , $ctx, $runId] = $p25run($db, 'ctl-where@x.com');
    $editor = makeTextEditorView($db);

    // a run at render_review HAS text to edit
    $has = $editor->forRun($ctx, $runId) !== null;

    // a run that has not reached CAPTION yet has nothing to edit, and must not
    // pretend otherwise — jobs are enqueued one at a time, so a fresh run has
    // only its first step
    $fresh = $db->one("SELECT id FROM runs WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$ctx->id()]);
    $db->run("INSERT INTO runs (workspace_id,workflow_id,entity_type,nodes_json,status,created_by,created_at,updated_at)
              SELECT workspace_id, workflow_id, entity_type, nodes_json, 'running', created_by, ?, ? FROM runs WHERE id = ?",
        [gmdate(NOW_ISO), gmdate(NOW_ISO), (int) $fresh['id']]);
    $none = $editor->forRun($ctx, $db->lastInsertId()) === null;

    return $has && $none;
})());

check('p25/ui: the character count on screen is the count of what is actually sent', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'ctl-count@x.com');
    // make it AI-labelled so Instagram carries the notice
    $cc = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    $ccr = json_decode((string) $cc['result_json'], true);
    $ccr['ai_label_required'] = true;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($ccr), (int) $cc['id']]);

    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'Body.', 'tiktok' => 'Body.', 'youtube' => 'Body.'], ['#a'], $loaded['hash'], $user, 'e@x.com', [], gmdate(NOW_ISO));

    $view = makeTextEditorView($db)->forRun($ctx, $runId);
    // "Body." + "\n" + "Made with AI" + "\n\n" + "#a"
    $expected = mb_strlen("Body.\nMade with AI\n\n#a");

    return $view['disclosure'] === 'Made with AI'
        && $view['limits']['instagram']['chars'] === $expected
        // TikTok gets the flag, not the words — so its count is shorter
        && $view['limits']['tiktok']['chars'] === mb_strlen("Body.\n\n#a");
})());


echo "== p25/gap: the window between approval and publish ==\n";

check('p25/store: the text stays editable while the final video is still rendering', (static function () use ($basePath, $p25run): bool {
    // Approving does not end the chance to fix a typo. final_render renders the
    // VIDEO and never reads the text, the publish job is not born until it
    // finishes, and nothing has reached a platform — so closing the editor here
    // would tell the operator "you approved it, now you may not change it" for a
    // stretch that nothing on the screen explains.
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId, $engine] = $p25run($db, 'gap-open@x.com');
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'gap-open@x.com', null);

    $render = $db->one("SELECT status FROM jobs WHERE run_id = ? AND type = 'final_render'", [$runId]);
    $publish = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'publish'", [$runId]);
    $rev = new \Kuyash\Content\ContentRevision($db);

    return $render !== null && (string) $render['status'] === 'queued'
        && $publish === null                          // the gap this test is about
        && $rev->lockReason($ws, $runId) === null;    // and it is still editable
})());

check('p25/store: a run that was stopped is read-only and is never called published', (static function () use ($basePath, $p25run): bool {
    // "Already published" on a cancelled run is a false publication claim, on the
    // one screen whose whole job is to be exact about what went out.
    $db = migratedDb($basePath);
    [$user, $ws, , $runId, $engine] = $p25run($db, 'gap-stop@x.com');
    $engine->cancelRun($ws, $runId, 'gap-stop@x.com', 'plan.changed_mind');

    $en = require $basePath . '/lang/en.php';
    $reason = (new \Kuyash\Content\ContentRevision($db))->lockReason($ws, $runId);

    return $reason === 'run_stopped'
        && array_key_exists('content.locked_run_stopped', $en)
        && !str_contains(strtolower($en['content.locked_run_stopped']), 'publish')
        // …and the second, contradicting sentence is gone entirely
        && !array_key_exists('content.read_only', $en);
})());

check('p25/gate: a count near the limit is flagged, with the same threshold the screen uses', (static function (): bool {
    $lim = new \Kuyash\Content\PlatformLimits([
        'limits' => ['instagram' => ['caption_chars' => 100, 'hashtags' => 10]],
        'warn_at' => 0.9,
    ]);
    $near = $lim->measure('instagram', str_repeat('a', 95), []);
    $fine = $lim->measure('instagram', str_repeat('a', 10), []);

    return $near['near_chars'] === true && $near['over_chars'] === false
        && $near['near_chars_at'] === 90 && $near['near_tags_at'] === 9
        && $fine['near_chars'] === false;
})());

check('p25/ui: two waiting posts each drive their OWN counters', (static function () use ($basePath): bool {
    // /queue renders one editor per waiting post. A document-wide lookup made the
    // second card write the FIRST card's numbers, and count the first card's tags.
    $js = (string) file_get_contents($basePath . '/public/assets/js/app.js');

    return !str_contains($js, "document.querySelector('[data-count-of")
        && !str_contains($js, "document.querySelector('[data-count-tags")
        && str_contains($js, "editor.querySelectorAll('[data-count-of]')")
        && str_contains($js, "editor.querySelector('[data-count-tags]')")
        // …and no server value is spliced into a selector string, where one
        // quote would make it a SyntaxError and kill both counters silently
        && !str_contains($js, "'[data-count-of=\"' +");
})());

check('p25/ui: approving with unsaved text asks first, and says so without scripting', (static function () use ($basePath): bool {
    // The trap this phase would otherwise create: two sibling forms, and clicking
    // the wrong one first publishes the AI draft and throws your words away.
    $queue = (string) file_get_contents($basePath . '/templates/queue/index.php');
    $js = (string) file_get_contents($basePath . '/public/assets/js/app.js');
    $en = require $basePath . '/lang/en.php';

    return str_contains($queue, 'data-approve-card')
        && str_contains($queue, 'data-needs-saved-text')
        && str_contains($queue, 'View::t($unsavedKey)')           // visible with JS off
        && str_contains($js, "form[data-needs-saved-text]")
        && str_contains($js, "'data-dirty'")
        // …and once an edit IS saved, the warning stops claiming the AI's text
        // would publish — the last saved version would
        && str_contains($queue, "\$alreadyEdited ? 'content.unsaved_edited' : 'content.unsaved'")
        && str_contains($en['content.unsaved_edited'], 'last saved')
        && array_key_exists('content.unsaved_confirm_edited', $en);
})());

check('p25/ui: finished text can still be selected and copied, and the counts reach a screen reader', (static function () use ($basePath): bool {
    $tpl = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');

    $js = (string) file_get_contents($basePath . '/public/assets/js/app.js');

    return str_contains($tpl, "'readonly'")
        && !str_contains($tpl, "'disabled'")   // disabled text cannot be focused or copied
        && substr_count($tpl, 'aria-describedby') >= 2
        // the count is reachable from the field at any time; it INTERRUPTS only
        // when it crosses a limit, instead of reading out on every keystroke
        && !str_contains($tpl, 'aria-live')
        && str_contains($js, "el.setAttribute('aria-live', 'polite')")
        && str_contains($js, "el.removeAttribute('aria-live')");
})());

check('p25/ui: the tag field states its own count, and the notice names its platform', (static function () use ($basePath): bool {
    $tpl = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');
    $en = require $basePath . '/lang/en.php';

    return str_contains($tpl, 'data-count-tags-of')
        && str_contains($tpl, "View::t('content.tags_count'")
        && str_contains($tpl, "'content.count_note_plain' : 'content.count_note'")  // says WHAT is counted
        && str_contains($en['content.disclosure_locked'], '{platform}')  // and WHERE the notice lands
        && str_contains($tpl, "View::t('content.disclosure_off')");      // required but switched off
})());

check('p25/ui: the native-flag line names only platforms whose switch is actually on', (static function () use ($basePath, $p25run): bool {
    // "TikTok and YouTube get the same notice" is an assurance. Printing it for a
    // platform whose disclosure toggle is OFF is the same false promise the
    // Instagram line was already fixed for.
    $db = migratedDb($basePath);
    [, $ws, $ctx, $runId] = $p25run($db, 'native-flag@x.com');
    $cc = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    $ccr = json_decode((string) $cc['result_json'], true);
    $ccr['ai_label_required'] = true;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($ccr), (int) $cc['id']]);

    $all = makeTextEditorView($db)->forRun($ctx, $runId)['text']['native_disclosure'];

    // switch TikTok off — it must drop out of the sentence
    $db->run('UPDATE workspaces SET ai_disclose_tiktok = 0 WHERE id = ?', [$ws]);
    $afterOff = makeTextEditorView($db)->forRun($ctx, $runId)['text']['native_disclosure'];

    return in_array('tiktok', $all, true) && in_array('youtube', $all, true)
        && !in_array('instagram', $all, true)          // Instagram spends characters instead
        && !in_array('tiktok', $afterOff, true)
        && in_array('youtube', $afterOff, true);
})());

check('p25/ui: the run screen does not print the same caption twice', (static function () use ($basePath): bool {
    // The editor above already shows the caption and the tags, current and
    // editable. The record card below repeated them under more technical names.
    $tpl = (string) file_get_contents($basePath . '/templates/runs/show.php');

    return str_contains($tpl, '$showsText = !$editorShown;')
        && str_contains($tpl, '<?php if ($showsText && $captions !== []): ?>')
        && str_contains($tpl, '<?php if ($showsText && $hashtags !== []): ?>');
})());


echo "== p25/keep: a refused save must not also destroy the writing ==\n";

check('p25/keep: text the gate refused is handed back, not thrown away', (static function () use ($basePath, $p25run): bool {
    // A block ends in a redirect (POST → redirect → GET), and the GET re-renders
    // from what is STORED — so without the stash the operator loses all three
    // bodies AND their tags because ONE of them was empty. On the screen whose
    // whole purpose is letting a person write the post, that is the worst
    // possible answer to "you are close, change one thing".
    $db = migratedDb($basePath);
    // every fixture DB restarts its ids at 1, so a stash left by an earlier
    // controller test would look like this run's — the session is per PROCESS
    unset($_SESSION['_content_draft']);
    [, , $ctx, $runId] = $p25run($db, 'keep-block@x.com');
    $drafts = new \Kuyash\Content\DraftStash();
    $view = makeTextEditorView($db, $drafts);
    $loaded = $view->forRun($ctx, $runId)['text'];

    $_POST = [
        'content_hash' => $loaded['hash'],
        // instagram is connected in this fixture, so an empty body BLOCKS…
        'caption' => ['instagram' => '', 'tiktok' => 'Kept tiktok words', 'youtube' => 'Kept youtube words'],
        'hashtags' => '#kept #words',
    ];
    makeContentController($db, $ctx, $drafts)->save(['id' => (string) $runId]);
    $_POST = [];

    // …nothing is stored…
    $stored = (new \Kuyash\Content\ContentRevision($db))->forRun($ctx, $runId);
    $storedUnchanged = $stored['captions'] === $loaded['captions'] && $stored['edited'] === false;

    // …and the next page load shows what was typed, so it can be fixed
    $back = $view->forRun($ctx, $runId)['text'];
    $handedBack = $back['captions']['tiktok'] === 'Kept tiktok words'
        && $back['captions']['youtube'] === 'Kept youtube words'
        && $back['hashtags'] === ['#kept', '#words']
        && ($back['unsaved'] ?? false) === true
        // the hash still describes what is STORED, so the next submit races the
        // right version and unsaved text is never presented as saved
        && $back['hash'] === $loaded['hash']
        && $back['edited'] === false;

    // one-shot: a second load is back to the stored text
    $again = $view->forRun($ctx, $runId)['text'];

    return $storedUnchanged && $handedBack && $again['captions'] === $loaded['captions'];
})());

check('p25/keep: a page showing held text arms both unsaved guards from the server', (static function () use ($basePath): bool {
    // The operator has just been refused and is looking at their own words in
    // the boxes. `data-dirty` is otherwise set by a keystroke that has already
    // happened — so without this, Approve fires with no confirm and navigating
    // away discards the draft in silence: the trap this phase closed, reopened
    // through its own recovery path.
    $tpl = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');
    $js = (string) file_get_contents($basePath . '/public/assets/js/app.js');

    return str_contains($tpl, "(\$text['unsaved'] ?? false) === true ? ' data-dirty=\"1\"' : ''")
        // …and clearing the flag on submit touches ONLY the editor submitted:
        // saving one queue card must not silence the other card's guard
        && str_contains($js, 'function clean(el) {')
        && str_contains($js, "clean(e.target.closest('.textedit'))");
})());

check('p25/keep: a held draft never surfaces on a different post', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [, , $ctx, $runId] = $p25run($db, 'keep-scope@x.com');
    $drafts = new \Kuyash\Content\DraftStash();
    $wsId = $ctx->id();

    // a different run, same workspace
    $drafts->keep($wsId, $runId + 12345, ['instagram' => 'someone else\'s words'], ['#nope']);
    $otherRun = makeTextEditorView($db, $drafts)->forRun($ctx, $runId)['text'];

    // …and the SAME run number in a different workspace, which is a real case:
    // run ids restart per workspace, so the run alone is not an identity
    $drafts->keep($wsId + 1, $runId, ['instagram' => 'another workspace\'s words'], ['#nope']);
    $otherWs = makeTextEditorView($db, $drafts)->forRun($ctx, $runId)['text'];

    return $otherRun['captions']['instagram'] !== 'someone else\'s words'
        && ($otherRun['unsaved'] ?? false) === false
        && $otherWs['captions']['instagram'] !== 'another workspace\'s words'
        && ($otherWs['unsaved'] ?? false) === false;
})());

echo "== p25/record: what the log says happened ==\n";

check('p25/record: a finished run describes the AI notice from what it DID, not from today\'s settings', (static function () use ($basePath, $p25run): bool {
    // Flipping the Settings toggle must not rewrite what an already-published
    // post's record claims about the notice it carried.
    $db = migratedDb($basePath);
    [, $ws, $ctx, $runId] = $p25run($db, 'record-hist@x.com');
    $cc = $db->one("SELECT id, result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    $ccr = json_decode((string) $cc['result_json'], true);
    $ccr['ai_label_required'] = true;
    $db->run('UPDATE jobs SET result_json = ? WHERE id = ?', [json_encode($ccr), (int) $cc['id']]);

    // while it can still go out, the screen answers "what would happen now"
    $open = makeTextEditorView($db)->forRun($ctx, $runId);
    $openAsserts = $open['disclosure'] !== '' && $open['text']['native_disclosure'] !== [];

    // once it is over, it asserts nothing — the post rows carry the history
    $db->run("UPDATE runs SET status = 'completed' WHERE id = ?", [$runId]);
    $done = makeTextEditorView($db)->forRun($ctx, $runId);

    return $openAsserts
        && $done['text']['editable'] === false
        && $done['disclosure'] === ''
        && $done['text']['native_disclosure'] === []
        && ($done['text']['disclosure_suppressed'] ?? false) === false;
})());

check('p25/record: putting the AI\'s words back is logged as a restore, not as an edit', (static function () use ($basePath, $p25run): bool {
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'record-restore@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, array_map(static fn (string $c): string => $c . ' mine', $loaded['captions']), ['#mine'], $loaded['hash'], $user, 'r@x.com', ['status' => 'pass', 'policy' => 'kuyash-v1'], gmdate(NOW_ISO));

    $_POST = [];
    makeContentController($db, $ctx)->restore(['id' => (string) $runId]);
    $_POST = [];

    $restored = $db->one("SELECT id FROM events WHERE workspace_id = ? AND key = 'content.restored'", [$ws]);
    $after = $rev->forRun($ctx, $runId);

    return $restored !== null
        && $after['edited'] === false                 // the record says nobody's words are in there
        && $after['captions'] === $loaded['captions'];
})());

check('p25/record: a PASSING edit is audited too, with its score and policy', (static function () use ($basePath, $p25run): bool {
    // "Every compliance decision writes an audit entry" is the rule — it does
    // not say "every failure". A clean edit used to leave no trace of the score
    // it was judged on, and the verdict kept on the job row is overwritten by
    // the next edit.
    $db = migratedDb($basePath);
    [, $ws, $ctx, $runId] = $p25run($db, 'record-pass@x.com');
    $loaded = (new \Kuyash\Content\ContentRevision($db))->forRun($ctx, $runId);
    $_POST = [
        'content_hash' => $loaded['hash'],
        'caption' => ['instagram' => 'A clean, different sentence about pans.', 'tiktok' => 'x', 'youtube' => 'y'],
        'hashtags' => '#clean',
    ];
    makeContentController($db, $ctx)->save(['id' => (string) $runId]);
    $_POST = [];

    $row = $db->one("SELECT kind, level, params_json FROM events WHERE workspace_id = ? AND key = 'content.edit_checked'", [$ws]);
    $params = $row === null ? [] : (array) json_decode((string) $row['params_json'], true);

    return $row !== null
        && (string) $row['kind'] === 'compliance'
        && (string) $params['policy'] === 'kuyash-v1'
        && array_key_exists('slop', $params);
})());

check('p25/record: the compliance chip describes the text that will PUBLISH, not the draft', (static function () use ($basePath, $p25run): bool {
    // The chip sits beside the button that publishes. After an edit, the
    // compliance_check score belongs to the GENERATED draft — a number about the
    // wrong text, in the most consequential place on the screen. ContentGate
    // judged the edit at save time with the same scorer and thresholds, so that
    // verdict is the one to show.
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId] = $p25run($db, 'badge@x.com');
    $view = makeTextEditorView($db);

    // no edit yet → no override, so the card keeps rendering compliance_check
    $before = $view->forRun($ctx, $runId)['text']['badge'];
    $beforeDirect = $view->badgeFor($ctx, $runId);

    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'Rewritten by hand.'], ['#x'], $loaded['hash'], $user, 'b@x.com', [
        'status' => 'warn',
        'policy' => 'kuyash-v1',
        'slop' => ['score' => 0.61, 'history_runs' => 3],
        'warnings' => [], 'reasons' => [],
    ], gmdate(NOW_ISO));

    $after = $view->forRun($ctx, $runId)['text']['badge'];
    $afterDirect = $view->badgeFor($ctx, $runId);   // the dashboard's way in

    // the compliance_check job itself is NOT rewritten — it stays a truthful
    // record of what WAS scored at that point in the chain
    $cc = $db->one("SELECT result_json FROM jobs WHERE run_id = ? AND type = 'compliance_check'", [$runId]);
    $ccr = (array) json_decode((string) $cc['result_json'], true);

    return $before === null && $beforeDirect === null
        && is_array($after) && $after['status'] === 'warn' && abs($after['slop'] - 0.61) < 0.0001
        && $afterDirect == $after                        // both surfaces read the same value
        && ($ccr['status'] ?? null) !== 'warn';          // the step's own record is untouched
})());

check('p25/record: "warned" and "too similar" are kept apart on the chip', (static function () use ($basePath, $p25run): bool {
    // A warning about a TAG COUNT rendered as a similarity chip read
    // "similarity to your recent posts 0.00" — it named the wrong check and
    // printed a number that meant nothing, on the chip beside the publish
    // button. The similarity chip may only appear when similarity is what
    // actually crossed the line.
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId] = $p25run($db, 'badge-split@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $view = makeTextEditorView($db);

    $save = static function (array $verdict) use ($rev, $ctx, $runId, $user, $view): array {
        $loaded = $rev->forRun($ctx, $runId);
        $rev->save($ctx, $runId, ['instagram' => 'Body ' . $verdict['slop']['score']], ['#t'], $loaded['hash'], $user, 's@x.com', $verdict, gmdate(NOW_ISO));

        return $view->forRun($ctx, $runId)['text']['badge'];
    };

    // warned, but the score is nowhere near the similarity threshold
    $tagWarn = $save([
        'status' => 'warn', 'policy' => 'kuyash-v1', 'reasons' => [],
        'warnings' => [['key' => 'content.too_many_tags', 'params' => []]],
        'slop' => ['score' => 0.18, 'history_runs' => 3],
    ]);
    // warned BECAUSE it is close to recent posts
    $slopWarn = $save([
        'status' => 'warn', 'policy' => 'kuyash-v1', 'reasons' => [],
        'warnings' => [['key' => 'content.similar', 'params' => []]],
        'slop' => ['score' => 0.61, 'history_runs' => 3],
    ]);

    return $tagWarn['status'] === 'warn' && $tagWarn['similar'] === false
        && $slopWarn['status'] === 'warn' && $slopWarn['similar'] === true
        // …and the threshold is the SAME one the generator faced, not a new number
        && 0.61 >= \Kuyash\Compliance\CompliancePolicy::SLOP_WARN
        && 0.18 < \Kuyash\Compliance\CompliancePolicy::SLOP_WARN;
})());

check('p25/record: "was this checked?" is answered the same whether or not it was edited', (static function () use ($basePath): bool {
    // The chip must not appear only after an edit — that would make being
    // checked look like a consequence of editing. An edit changes WHICH verdict
    // applies, never WHETHER the post was checked.
    $tpl = (string) file_get_contents($basePath . '/templates/runs/show.php');
    $editor = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');

    return str_contains($tpl, "\$job['type'] === 'compliance_check'")
        && str_contains($tpl, "'slop' => \$complianceResult['checks']['slop']['score'] ?? null")
        // …and the partial falls back to that verdict when there is no edit
        && str_contains($editor, "is_array(\$generatedCompliance ?? null)")
        && str_contains($editor, "\$badge['edited'] ? 'queue.similarity_edited' : 'queue.similarity'")
        // the compliance verdict stays OUT of the generated-content map, which
        // drives a card about content, not about verdicts
        && str_contains($tpl, 'that map drives the "Generated content"');
})());

check('p25/record: the card never states a bare "passed" from the draft once the text was edited', (static function () use ($basePath): bool {
    // The chip was fixed and this SENTENCE was not: "Compliance: passed", four
    // lines lower, unqualified, a few pixels above the publish button — the
    // draft's verdict, contradicting the chip that speaks for the edited text.
    $queue = (string) file_get_contents($basePath . '/templates/queue/index.php');

    return str_contains($queue, "\$cNote = (\$cEdited ?? false) ? null :")
        && str_contains($queue, "if (\$cNote === 'pass' || \$cNote === 'pass_with_ai_label')")
        // …and the AI label survives an edit, because it follows the MEDIA — it
        // is keyed on the requirement, not on a status a slop warning outranks
        && str_contains($queue, "if (\$job['result']['ai_label_required'] ?? false)")
        // one vocabulary for one check, whichever card you are looking at
        && (require $basePath . '/lang/en.php')['queue.compliance_pass'] === 'checks passed';
})());

check('p25/record: the chip never invents an edit, and never softens a block', (static function () use ($basePath): bool {
    // "one thing to check — ON THE TEXT YOU EDITED" is reachable on the run
    // screen for a run nobody edited (the chip renders for every run now), so
    // the catch-all needs an unedited wording. And a compliance BLOCK stopped
    // the run — rendering it as a note would understate it on the record.
    $en = require $basePath . '/lang/en.php';
    $queue = (string) file_get_contents($basePath . '/templates/queue/index.php');
    $dash = (string) file_get_contents($basePath . '/templates/dashboard.php');
    $editor = (string) file_get_contents($basePath . '/templates/partials/text-editor.php');

    $branches = static fn (string $t): bool => str_contains($t, "'queue.checks_note_edited' : 'queue.checks_note'")
        && str_contains($t, "View::t('queue.checks_blocked')");

    return !str_contains($en['queue.checks_note'], 'edited')
        && str_contains($en['queue.checks_note_edited'], 'edited')
        && $branches($queue) && $branches($dash) && $branches($editor);
})());

check('p25/seed: the seeded audit lines name a person, not a placeholder', (static function () use ($basePath): bool {
    // A `static` closure that does not import $email binds null, and
    // I18n::interpolate leaves a non-scalar as the literal token — so the audit
    // line added to stand behind the compliance chip rendered as
    // "Post text edited by {user}" on /logs and in every run timeline.
    $seed = (string) file_get_contents($basePath . '/bin/visual-seed.php');
    $uses = (bool) preg_match('/\$db->transaction\(static function \(Database \$db\) use \(([^)]*)\)/', $seed, $m);

    return $uses && str_contains($m[1], '$email')
        && str_contains($seed, "'user' => \$email");
})());

check('p25/record: the chip says which text it measured, without jargon', (static function () use ($basePath): bool {
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';
    $queue = (string) file_get_contents($basePath . '/templates/queue/index.php');
    $dash = (string) file_get_contents($basePath . '/templates/dashboard.php');

    return str_contains($en['queue.similarity_edited'], 'text you edited')
        && str_contains($tr['queue.similarity_edited'], 'düzenlediğin metne göre')
        // the internal word for it never reaches a person
        && !array_key_exists('queue.slop_label', $en)
        && !str_contains($queue, 'slop_label') && !str_contains($dash, 'slop_label')
        && !str_contains(strtolower($en['queue.similarity']), 'slop')
        // …and both cards branch on the same derived value
        && str_contains($queue, "\$job['text']['text']['badge']")
        && str_contains($dash, "\$job['badge']");
})());

echo "== p25/write: the write itself cannot half-happen ==\n";

check('p25/write: the edit hash covers only what was actually stored', (static function () use ($basePath, $p25run): bool {
    // The hash is what publish re-checks. Stamping it over hashtags that were
    // never written makes publish refuse the run permanently AND record the
    // operator as having tampered with text they never touched.
    $db = migratedDb($basePath);
    [$user, $ws, $ctx, $runId] = $p25run($db, 'write-hash@x.com');
    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $rev->save($ctx, $runId, ['instagram' => 'Stored body.'], ['#a', '#b'], $loaded['hash'], $user, 'w@x.com', ['status' => 'pass'], gmdate(NOW_ISO));

    $after = $rev->forRun($ctx, $runId);
    $expected = \Kuyash\Content\ContentRevision::hash($after['captions'], $after['hashtags']);

    return $after['edit'] !== null
        && hash_equals($expected, (string) $after['edit']['hash'])
        // the writer takes the lock up front, so a read-then-write collision
        // with the worker cannot surface as "database is locked"
        && str_contains(
            (string) file_get_contents($basePath . '/src/Content/ContentRevision.php'),
            'immediateTransaction',
        );
})());

check('p25/write: an edit made while the final video renders still reaches the platform', (static function () use ($basePath, $p25run): bool {
    // The reason the window was widened. Approve, let final_render be queued,
    // edit THEN, and the publish job born afterwards must carry the new words
    // and clear the hash guard rather than dead-lettering the run.
    $db = migratedDb($basePath);
    [$user, , $ctx, $runId, $engine, $worker, $spy] = $p25run($db, 'write-gap@x.com');
    $review = $db->one("SELECT id FROM jobs WHERE run_id = ? AND type = 'render_review' AND status = 'awaiting_approval'", [$runId]);
    $engine->approve($ctx, (int) $review['id'], $user, 'w@x.com', null);

    $rev = new \Kuyash\Content\ContentRevision($db);
    $loaded = $rev->forRun($ctx, $runId);
    $saved = $rev->save($ctx, $runId, ['instagram' => 'Changed while it rendered.'], ['#late'], $loaded['hash'], $user, 'w@x.com', ['status' => 'pass'], gmdate(NOW_ISO));

    for ($i = 0; $i < 40 && $worker->tick(); $i++) {
    }
    $req = $spy->requests[0] ?? null;
    $run = $db->one('SELECT status FROM runs WHERE id = ?', [$runId]);

    return $saved
        && $req instanceof PublishRequest
        && $req->caption === 'Changed while it rendered.'
        && $req->hashtags === ['#late']
        && (string) $run['status'] === 'completed';   // NOT dead-lettered as tampering
})());


echo "== fix/dashboard: the plan line can never take the whole screen down ==\n";

check('fix/dashboard: a broken plan read leaves the rest of the dashboard standing', (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
    // The real failure, reproduced: a database behind on its migrations has no
    // slot_occurrences, so the plan read throws. It answered 500 for every
    // workspace that had a publishing time and stayed fine for every workspace
    // that had none — which is why it read as an unrelated fault and survived a
    // route sweep that only looked at status codes.
    //
    // The plan is ONE line on that page. The KPIs, the approvals waiting, the
    // accounts and the balance have nothing to do with it.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'dash-guard@x.com', $argonHash, 'Dash guard');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = gmdate(NOW_ISO);
    // a workspace WITH a publishing time — the only kind that reached the read
    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $db->run('DROP TABLE slot_occurrences');

    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
    $cockpit = new \Kuyash\Workflow\Cockpit(
        $db,
        new AssetCache($db, $paths),
        new CreditLedger($db),
        new UsageRepository($db),
        new AccountRepository($db),
        new \Kuyash\Workflow\JobRepository($db),
        new \Kuyash\Publish\PlanBoard(new OccurrenceRepository($db)),
        new WorkspaceSettings($db),
    );

    $snap = $cockpit->snapshot($ctx, $now);   // must NOT throw

    return is_array($snap)
        // its OWN state — not zeroed (a number nobody took) and not null
        // (which is what "this workspace has no plan" looks like, and would
        // make the screen tell a planned workspace it has nothing planned)
        && $snap['planWeek'] === ['unavailable' => true]
        // …and everything that has nothing to do with the plan is still there
        && is_array($snap['kpis']) && is_array($snap['business'])
        && array_key_exists('awaiting', $snap) && array_key_exists('accounts', $snap);
})());

check('fix/dashboard: a healthy workspace with a publishing time still gets its plan line', (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
    // The guard must not swallow the working case: with the table present, the
    // line is real. Otherwise the fix would be indistinguishable from deleting
    // the feature.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'dash-ok@x.com', $argonHash, 'Dash ok');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = gmdate(NOW_ISO);
    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );

    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
    $cockpit = new \Kuyash\Workflow\Cockpit(
        $db,
        new AssetCache($db, $paths),
        new CreditLedger($db),
        new UsageRepository($db),
        new AccountRepository($db),
        new \Kuyash\Workflow\JobRepository($db),
        new \Kuyash\Publish\PlanBoard(new OccurrenceRepository($db)),
        new WorkspaceSettings($db),
    );

    $snap = $cockpit->snapshot($ctx, $now);

    return is_array($snap['planWeek'])
        && array_key_exists('planned', $snap['planWeek'])
        && $snap['planWeek']['planned'] === 0;   // honest zero: nothing materialized yet
})());

check('fix/dashboard: a plan that could not be read never reads as "nothing planned"', (static function () use ($basePath): bool {
    // The failure state and the no-plan state must not share wording. The
    // dashboard's queue-empty line says "approved videos publish straight away",
    // which is true of a workspace with no plan and false of one whose plan
    // simply could not be read — and the band's own absence already means
    // "nothing planned", so a failed read must not borrow that either.
    $tpl = (string) file_get_contents($basePath . '/templates/dashboard.php');
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';

    return str_contains($tpl, "\$planUnreadable => 'cockpit.next_publish_unknown'")
        && str_contains($tpl, "View::t('cockpit.plan_unreadable')")
        // the unknown wording claims neither direction
        && !str_contains(strtolower($en['cockpit.next_publish_unknown']), 'straight away')
        // …and the band says the count is MISSING, not zero
        && str_contains($en['cockpit.plan_unreadable'], 'not zero')
        && str_contains($tr['cockpit.plan_unreadable'], 'sıfır değil');
})());

check('fix/dashboard: a broken accounts read does not become "no accounts connected"', (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
    // account_metrics is the second-newest table on this page, so it is the next
    // one to go missing on a database behind on its migrations — the same way
    // slot_occurrences was. An empty list is what "No accounts connected yet" is
    // rendered from, so a failed read must NOT return one: it would tell an
    // operator with three live channels that they have none.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'dash-acct@x.com', $argonHash, 'Dash acct');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = gmdate(NOW_ISO);
    $db->run(
        "INSERT INTO accounts (workspace_id, platform, handle, status, health, connected_at, created_at, updated_at)
         VALUES (?, 'instagram', '@real', 'connected', 'ok', ?, ?, ?)",
        [$ws, $now, $now, $now],
    );
    $db->run('DROP TABLE account_metrics');

    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
    $cockpit = new \Kuyash\Workflow\Cockpit(
        $db,
        new AssetCache($db, $paths),
        new CreditLedger($db),
        new UsageRepository($db),
        new AccountRepository($db),
        new \Kuyash\Workflow\JobRepository($db),
    );

    $snap = $cockpit->snapshot($ctx, $now);   // must NOT throw

    return is_array($snap)
        && $snap['accounts'] === null          // its own state, never []
        && is_array($snap['kpis'])             // …and the rest of the page stands
        && array_key_exists('awaiting', $snap);
})());

check('fix/dashboard: a healthy accounts read still returns the cards', (static function () use ($basePath, $argonHash, $TEST_MEDIA_ROOT): bool {
    // The guard must not be indistinguishable from deleting the card.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'dash-acct-ok@x.com', $argonHash, 'Dash acct ok');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = gmdate(NOW_ISO);
    $db->run(
        "INSERT INTO accounts (workspace_id, platform, handle, status, health, connected_at, created_at, updated_at)
         VALUES (?, 'instagram', '@real', 'connected', 'ok', ?, ?, ?)",
        [$ws, $now, $now, $now],
    );

    $paths = new MediaPaths(['asset' => "$TEST_MEDIA_ROOT/a", 'cache' => "$TEST_MEDIA_ROOT/c", 'render' => "$TEST_MEDIA_ROOT/r", 'work' => "$TEST_MEDIA_ROOT/w"]);
    $cockpit = new \Kuyash\Workflow\Cockpit(
        $db, new AssetCache($db, $paths), new CreditLedger($db), new UsageRepository($db),
        new AccountRepository($db), new \Kuyash\Workflow\JobRepository($db),
    );
    $snap = $cockpit->snapshot($ctx, $now);

    return is_array($snap['accounts']) && count($snap['accounts']) === 1
        && (string) $snap['accounts'][0]['handle'] === '@real';
})());

check('fix/dashboard: the three account states are three DIFFERENT sentences', (static function () use ($basePath): bool {
    $tpl = (string) file_get_contents($basePath . '/templates/dashboard.php');
    $en = require $basePath . '/lang/en.php';
    $tr = require $basePath . '/lang/tr.php';

    return str_contains($tpl, "\$cockpit['accounts'] === null")
        && str_contains($tpl, "\$cockpit['accounts'] === []")
        && str_contains($tpl, "View::t('dash.accounts_unreadable')")
        // "could not be read" must not be worded as "you have none"
        && str_contains($en['dash.accounts_unreadable'], 'not the same as having none')
        && str_contains($tr['dash.accounts_unreadable'], 'hiç hesabın olmadığı anlamına gelmez');
})());

check('fix/dashboard: the plan read is scoped to one workspace at every join', (static function () use ($basePath): bool {
    // Tenant isolation on the read this bug ran through — asserted rather than
    // assumed, because it is the query a dashboard runs for whoever is logged in.
    $sql = (string) file_get_contents($basePath . '/src/Publish/OccurrenceRepository.php');

    return str_contains($sql, 'WHERE o.workspace_id = ? AND o.publish_at >= ? AND o.publish_at < ?')
        && str_contains($sql, 'JOIN publish_slots s ON s.id = o.slot_id AND s.workspace_id = o.workspace_id')
        && str_contains($sql, 'LEFT JOIN assets a ON a.id = o.asset_id AND a.workspace_id = o.workspace_id')
        && str_contains($sql, 'LEFT JOIN runs r ON r.id = o.run_id AND r.workspace_id = o.workspace_id');
})());

check('fix/dashboard: the health harness reads the BODY, not just the status code', (static function () use ($basePath): bool {
    // A status-only sweep reported 200 for a dashboard that was a stack trace,
    // because the debug error page IS a response. This is the check that stops
    // that reading as "all green" again.
    $harness = (string) file_get_contents($basePath . '/bin/health.php');

    return str_contains($harness, 'FAILURE_MARKERS')
        && str_contains($harness, "'SQLSTATE['")
        && str_contains($harness, "'no such table'")
        && str_contains($harness, 'str_contains($res[\'body\'], $marker)')
        // and it authenticates, because every screen worth checking is behind auth
        && str_contains($harness, "'/login'")
        && str_contains($harness, 'exit($failures === 0 ? 0 : 1)')
        // …with credentials from the environment, never a copy baked into a
        // committed script
        && !str_contains($harness, 'SmokePassword123')
        && str_contains($harness, "getenv('HEALTH_PASSWORD')");
})());



echo "== fix/plan: a dead run must not hold a day hostage ==\n";

check('fix/plan: a day whose run compliance cancelled can be cleared and reused', (static function () use ($basePath, $argonHash, $view): bool {
    // Engine::cancelRun answers AlreadyDecided for a run that is ALREADY over,
    // which the clear path read as "too late" — so a day pointing at a run
    // compliance had killed could never be freed, and that date was lost.
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'plan-stuck@x.com', $argonHash, 'Plan stuck');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    $now = gmdate(NOW_ISO);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 4 * 86400);

    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $slot = (int) $db->lastInsertId();
    $wfRepo = new WorkflowRepository($db, new WorkflowValidator());
    $wfRepo->ensureDefaults($ctx);
    $wf = $wfRepo->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Stuck clip');
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);
    // compliance kills it, exactly as the live 3-second clip was killed
    $engine->cancelRun($ws, $runId, 'compliance', 'run.blocked_by_compliance');

    $db->run(
        "INSERT INTO slot_occurrences (workspace_id, slot_id, local_date, publish_at, mode, status, asset_id, run_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'manual', 'assigned', ?, ?, ?, ?)",
        [$ws, $slot, substr($future, 0, 10), $future, $asset, $runId, $now, $now],
    );
    $cell = (int) $db->lastInsertId();

    makePlanController($db, $ctx, $view)->unassign(['id' => (string) $cell]);

    $after = $db->one('SELECT status, run_id FROM slot_occurrences WHERE id = ?', [$cell]);

    return (string) $after['status'] === 'open' && $after['run_id'] === null;
})());

check('fix/plan: "publishes immediately" is only said where no publishing time exists', (static function () use ($basePath, $argonHash): bool {
    // The predicate behind the /accounts banner. A PAUSED slot must read as no
    // plan, because a paused slot holds nothing — and another workspace's slot
    // must not make this one look scheduled.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'slots-has@x.com', $argonHash, 'Slots has');
    [, $other] = seedUser($db, 'slots-other@x.com', $argonHash, 'Slots other');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $now = gmdate(NOW_ISO);
    $slots = new SlotRepository($db);

    $none = $slots->hasAny($ctx);

    // a neighbour's publishing time must not count as ours
    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$other, $now, $now],
    );
    $stillNone = $slots->hasAny($ctx);

    // a PAUSED time of our own holds nothing, so it is not a plan either
    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 2, '10:00', 0, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $paused = $slots->hasAny($ctx);

    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 3, '11:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $enabled = $slots->hasAny($ctx);

    return $none === false && $stillNone === false && $paused === false && $enabled === true;
})());

check('fix/plan: a day that actually PUBLISHED can never be cleared', (static function () use ($basePath, $argonHash, $view): bool {
    // `Nodes::RUN_TERMINAL` counts `completed`, and a completed run may well have
    // published — while the occurrence stays `assigned`, because the calendar
    // derives "published" from the run rather than copying it. Clearing such a
    // day would blank the only place the operator sees that a post went out on
    // that date. The post row and the audit trail would survive; the calendar
    // would be the thing that lied.
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'plan-published@x.com', $argonHash, 'Plan published');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    $now = gmdate(NOW_ISO);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 4 * 86400);

    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $slot = (int) $db->lastInsertId();
    $wfRepo = new WorkflowRepository($db, new WorkflowValidator());
    $wfRepo->ensureDefaults($ctx);
    $wf = $wfRepo->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'Published clip');
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);
    $db->run("UPDATE runs SET status = 'completed' WHERE id = ?", [$runId]);
    $db->run(
        "INSERT INTO accounts (workspace_id, platform, handle, status, health, connected_at, created_at, updated_at)
         VALUES (?, 'instagram', '@p', 'connected', 'ok', ?, ?, ?)",
        [$ws, $now, $now, $now],
    );
    $acct = (int) $db->lastInsertId();
    $db->run(
        "INSERT INTO posts (workspace_id, run_id, account_id, platform, status, ai_label_applied, idempotency_key, created_at, updated_at)
         VALUES (?, ?, ?, 'instagram', 'published', 0, ?, ?, ?)",
        [$ws, $runId, $acct, "run:{$runId}:acct:{$acct}:publish", $now, $now],
    );
    $db->run(
        "INSERT INTO slot_occurrences (workspace_id, slot_id, local_date, publish_at, mode, status, asset_id, run_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'manual', 'assigned', ?, ?, ?, ?)",
        [$ws, $slot, substr($future, 0, 10), $future, $asset, $runId, $now, $now],
    );
    $cell = (int) $db->lastInsertId();

    makePlanController($db, $ctx, $view)->unassign(['id' => (string) $cell]);
    $after = $db->one('SELECT status, run_id FROM slot_occurrences WHERE id = ?', [$cell]);

    // the day still says a post went out on it
    return (string) $after['status'] === 'assigned' && (int) $after['run_id'] === $runId;
})());

check('fix/plan: the Clear button is actually OFFERED on a stopped day', (static function () use ($basePath): bool {
    // The controller change was worthless while the template hid the button for
    // exactly the state it was written for — the fix has to reach the screen,
    // not just the code path.
    $tpl = (string) file_get_contents($basePath . '/templates/plan/index.php');

    return str_contains($tpl, "\$cell['state'] !== PlanBoard::PUBLISHED && \$cell['state'] !== PlanBoard::MISSED): ?>")
        && !str_contains($tpl, "\$cell['state'] !== PlanBoard::STOPPED");
})());

check('fix/plan: a day whose publish is IN FLIGHT is still refused', (static function () use ($basePath, $argonHash, $view): bool {
    // The other half of "already decided": that one really is past the point of
    // no return, and the day must stay exactly as it is.
    $db = migratedDb($basePath);
    [$user, $ws] = seedUser($db, 'plan-inflight@x.com', $argonHash, 'Plan inflight');
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $_SESSION['auth_user_id'] = $user;
    $now = gmdate(NOW_ISO);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 4 * 86400);

    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, mode, created_at, updated_at)
         VALUES (?, 1, '09:00', 1, 'manual', ?, ?)",
        [$ws, $now, $now],
    );
    $slot = (int) $db->lastInsertId();
    $wfRepo = new WorkflowRepository($db, new WorkflowValidator());
    $wfRepo->ensureDefaults($ctx);
    $wf = $wfRepo->findByTemplate($ctx, 'distribution');
    $asset = seedReadyVideo($db, $ws, 'In-flight clip');
    $clock = $now;
    [$engine] = makeRig($db, new MockExecutor(), $clock);
    $runId = $engine->startRun($ctx, (int) $wf['id'], $asset, $user);
    // a publish already going out
    $db->run(
        "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, run_after, created_at)
         VALUES (?, ?, 'PUBLISH', 9, 'publish', 'processing', '{}', ?, ?)",
        [$ws, $runId, $now, $now],
    );
    $db->run(
        "INSERT INTO slot_occurrences (workspace_id, slot_id, local_date, publish_at, mode, status, asset_id, run_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'manual', 'assigned', ?, ?, ?, ?)",
        [$ws, $slot, substr($future, 0, 10), $future, $asset, $runId, $now, $now],
    );
    $cell = (int) $db->lastInsertId();

    makePlanController($db, $ctx, $view)->unassign(['id' => (string) $cell]);
    $after = $db->one('SELECT status FROM slot_occurrences WHERE id = ?', [$cell]);

    return (string) $after['status'] === 'assigned';   // untouched
})());

echo "== demo-seed: the showcase top-up cannot lie or run by accident ==\n";

check('demo-seed: both scripts refuse to write without an explicit confirmation', (static function () use ($basePath): bool {
    $seed = (string) file_get_contents($basePath . '/bin/demo-seed.php');
    $down = (string) file_get_contents($basePath . '/bin/demo-teardown.php');

    return str_contains($seed, "in_array('--yes', \$args, true)")
        && str_contains($seed, "PHP_SAPI !== 'cli'")
        && str_contains($down, "in_array('--yes', \$args, true)")
        && str_contains($down, "PHP_SAPI !== 'cli'")
        // …and teardown offers a way to see the damage before doing it
        && str_contains($down, "--dry-run")
        // The seed puts five REAL approval gates on screen. A human-approved run
        // bypasses the daily cap and the kill switch and fans out to every
        // connected channel, so live publishing is refused UP FRONT rather than
        // warned about in a trailing line of an otherwise successful summary.
        && str_contains($seed, "--live-publish-ok")
        && strpos($seed, "zernio.mock") < strpos($seed, '$seed->run(')
        // …and an auto-approving workspace is refused before anything is written
        && str_contains($seed, "--auto-mode-ok")
        && strpos($seed, "--auto-mode-ok") < strpos($seed, '$seed->run(');
})());

check('demo-seed: it never writes a follower count, and never publishes for real', (static function () use ($basePath): bool {
    // A follower count is the one field that flips an account card out of its
    // "sample" branch and makes every figure on it read as measured. The seed
    // must never write one — not for a real channel, and not for a demo one.
    $src = (string) file_get_contents($basePath . '/src/Demo/ShowcaseSeed.php');

    // reading it is how the seed AVOIDS a provider-backed channel; what it must
    // never do is write one (every write here goes through an associative
    // column list, so a write would read `'followers_count' =>`)
    return !preg_match("/'followers_count'\s*=>/", $src)
        && !preg_match('/followers_count\s*=/', $src)
        // the audit log is append-only, so nothing here may append to it
        && !preg_match('/INSERT INTO events/i', $src)
        && !str_contains($src, "'events'")
        // every publish it writes is already finished and already mock
        && !preg_match("/'status' => '(queued|processing|scheduled|publishing)'/", $src)
        && str_contains($src, "MOCK_POST_PREFIX = 'zp_'");
})());

check('demo-seed: a seeded clip stores the duration the FILE has, measured', (static function () use ($basePath, $TEST_MEDIA_ROOT): bool {
    // BEHAVIOURAL, on purpose. The source-text checks below could not have
    // caught the defect this replaces: the script declared 22.0s for a copy of
    // a 3-second fixture, and duration_s is the value ComplianceCheckExecutor
    // checks the 15-45s band against — so a 3-second video would have carried
    // an audit record certifying it passed at 22 seconds.
    $fixture = $basePath . '/tools/visual/fixtures/preview.mp4';
    if (!is_file($fixture)) {
        return true;   // no fixture on this machine; nothing to assert
    }
    $probe = new \Kuyash\Library\MediaProbe();
    $measured = $probe->probe($fixture, 'video');

    // whatever MediaProbe says the fixture is, ffprobe must agree — this is the
    // seam the seeder now relies on instead of a literal
    $bin = '/opt/homebrew/bin/ffprobe';
    if (!is_file($bin)) {
        return $measured['duration_s'] !== null;
    }
    $out = (string) shell_exec(
        escapeshellarg($bin) . ' -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($fixture),
    );

    return $measured['duration_s'] !== null
        && abs((float) $out - (float) $measured['duration_s']) < 0.05
        // …and the fixture really is OUTSIDE the band, which is why the seeder
        // has to build a second clip rather than relabel this one
        && (float) $measured['duration_s'] < \Kuyash\Compliance\CompliancePolicy::DURATION_MIN_S;
})());

check('demo-seed: a workspace that does not exist writes nothing at all', (static function () use ($basePath): bool {
    // It used to print the workspace, create its media directory, copy two files
    // and only THEN die on the foreign key — leaving orphan litter behind.
    $db = migratedDb($basePath);
    $threw = throws(
        static fn () => (new \Kuyash\Demo\ShowcaseSeed($db))->run(999, gmdate(NOW_ISO)),
        RuntimeException::class,
    );

    return $threw && (int) $db->one('SELECT COUNT(*) n FROM demo_seed_manifest')['n'] === 0;
})());

check('health: it will not hand credentials to an arbitrary host', (static function () use ($basePath): bool {
    // The base URL comes from argv and this script POSTs a password to it.
    $h = (string) file_get_contents($basePath . '/bin/health.php');

    return str_contains($h, 'parse_url($base)')
        && str_contains($h, "in_array(\$scheme, ['http', 'https'], true)")
        && str_contains($h, 'refusing to send credentials in the clear')
        && str_contains($h, "CURLOPT_PROTOCOLS_STR => 'http,https'")
        && str_contains($h, "CURLOPT_REDIR_PROTOCOLS_STR => 'http,https'");
})());

check('cockpit: the workflow join is workspace-scoped like the rest of the house', (static function () use ($basePath): bool {
    $c = (string) file_get_contents($basePath . '/src/Workflow/Cockpit.php');

    return substr_count($c, 'JOIN workflows w ON w.id = r.workflow_id AND w.workspace_id = r.workspace_id') === 2
        && !preg_match('/JOIN workflows w ON w\.id = r\.workflow_id(?! AND w\.workspace_id)/', $c);
})());

echo "== poster: a still frame per video, and the route that serves it ==\n";

/**
 * The poster layer had NO coverage at all: both MediaController constructions in
 * this file passed null for $posters, so the only branch the suite ever ran was
 * the "no poster service" 404. Tenant isolation, the photo redirect, the
 * content-addressed naming and the never-throws contract were all unexercised
 * while the route was live.
 */
$posterRoot = tempDir('poster');
foreach (['asset', 'cache', 'render', 'work'] as $posterStore) {
    @mkdir($posterRoot . '/' . $posterStore, 0775, true);
}
$posterPaths = new MediaPaths([
    'asset' => $posterRoot . '/asset', 'cache' => $posterRoot . '/cache',
    'render' => $posterRoot . '/render', 'work' => $posterRoot . '/work',
]);
$posterFfmpeg = new \Kuyash\Media\Ffmpeg('/nonexistent/ffmpeg', '/nonexistent/ffprobe', 5);
$poster = new \Kuyash\Media\AssetPoster($posterFfmpeg, $posterPaths);

check('gate: the media check cannot be blinded by a lazy image', (static function () use ($basePath): bool {
    // The first version filtered on `i.complete && i.naturalWidth === 0`. A lazy
    // <img> that never BEGAN loading has complete === false, so it was excluded —
    // and that is the only case that matters: at 375 a tall grid put eight real
    // posters below the lazy threshold, they never painted, and the gate called
    // the blank tiles green. The screenshot was not evidence.
    $h = (string) file_get_contents($basePath . '/tools/visual/shot.mjs');

    // the FILTER, not any mention: the comment above it quotes the old
    // expression on purpose, so a naive substring check trips on the prose
    return str_contains($h, "i.loading = 'eager'")                        // forces them in
        && str_contains($h, 'imgs.filter(i => i.naturalWidth === 0)')     // judges on paint
        && !str_contains($h, 'imgs.filter(i => i.complete')
        // and the forcing happens BEFORE the capture, so the PNG shows what loaded
        && strpos($h, 'brokenMediaExpr') < strpos($h, 'Page.captureScreenshot');
})());

check('poster: the name is derived from the CONTENT, not the row id', (static function () use ($poster): bool {
    $a = ['workspace_id' => 1, 'sha256' => str_repeat('a', 64)];
    $b = ['workspace_id' => 1, 'sha256' => str_repeat('b', 64)];

    // same bytes → same poster (a duplicate upload reuses it); different bytes →
    // different poster; and the name is a valid media name, so it can never
    // escape its store
    return \Kuyash\Media\AssetPoster::nameFor($a) === \Kuyash\Media\AssetPoster::nameFor($a + ['id' => 99])
        && \Kuyash\Media\AssetPoster::nameFor($a) !== \Kuyash\Media\AssetPoster::nameFor($b)
        && preg_match('/^[0-9a-f]{32}\.jpg$/', \Kuyash\Media\AssetPoster::nameFor($a)) === 1;
})());

check('poster: ensure() returns null instead of throwing when ffmpeg is absent', (static function () use ($poster): bool {
    // a poster is decoration — it must never be able to fail an upload
    return $poster->ensure([
        'id' => 1, 'workspace_id' => 1, 'kind' => 'video',
        'stored_name' => str_repeat('c', 32) . '.mp4', 'sha256' => str_repeat('a', 64),
    ]) === null;
})());

check('poster: a poster-layer fault degrades to "no poster", it does not throw', (static function () use ($posterFfmpeg): bool {
    // MediaPaths::pathFor() CREATES the store dir and throws when it cannot —
    // an unwritable storage/cache, a full disk, a read-only mount. pathFor() and
    // exists() sat OUTSIDE ensure()'s guard, so that fault reached a grid render
    // and, worse, the ingest catch that deletes the just-stored asset file.
    $broken = new MediaPaths([
        'asset' => '/proc/nonexistent/asset', 'cache' => '/proc/nonexistent/cache',
        'render' => '/proc/nonexistent/render', 'work' => '/proc/nonexistent/work',
    ]);
    $p = new \Kuyash\Media\AssetPoster($posterFfmpeg, $broken);
    $asset = ['id' => 1, 'workspace_id' => 1, 'kind' => 'video',
        'stored_name' => str_repeat('c', 32) . '.mp4', 'sha256' => str_repeat('a', 64)];

    return $p->pathFor($asset) === null && $p->exists($asset) === false && $p->ensure($asset) === null;
})());

check('poster route: another tenant\'s asset is a 404, never a file', (static function () use ($basePath, $posterPaths, $posterFfmpeg): bool {
    $db = migratedDb($basePath);
    $now = gmdate(NOW_ISO);
    [, $wsA] = seedUser($db, 'pa@x.com', 'h', 'A');
    [, $wsB] = seedUser($db, 'pb@x.com', 'h', 'B');
    $sha = str_repeat('d', 64);
    $db->run(
        "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
                             size_bytes, sha256, tags, status, created_at, updated_at)
         VALUES (?, 'video', 'own', 'A clip', 'a.mp4', ?, 'video/mp4', 1, ?, '[]', 'ready', ?, ?)",
        [$wsA, str_repeat('e', 32) . '.mp4', $sha, $now, $now],
    );
    $assetId = $db->lastInsertId();

    // a real poster on disk for workspace A
    $p = new \Kuyash\Media\AssetPoster($posterFfmpeg, $posterPaths);
    $path = $p->pathFor(['workspace_id' => $wsA, 'sha256' => $sha]);
    @mkdir(dirname((string) $path), 0775, true);
    file_put_contents((string) $path, 'jpegbytes');

    $repo = new AssetRepository($db);
    $ctx = new WorkspaceContext($db);
    // the asset store is irrelevant here — poster() never touches it
    $ctl = new MediaController($repo, new AssetStorage(sys_get_temp_dir()), localStorageManager(sys_get_temp_dir()), $ctx, $p, 300);

    $ctx->set($wsA);
    $mine = $ctl->poster(['id' => (string) $assetId])->status();
    $ctx->set($wsB);
    $theirs = $ctl->poster(['id' => (string) $assetId])->status();

    return $mine === 200 && $theirs === 404;
})());

check('poster route: a missing poster is a 404, and a photo is its own poster', (static function () use ($basePath, $posterPaths, $posterFfmpeg): bool {
    $db = migratedDb($basePath);
    $now = gmdate(NOW_ISO);
    [, $ws] = seedUser($db, 'pc@x.com', 'h', 'C');
    foreach ([['video', str_repeat('1', 32) . '.mp4', str_repeat('7', 64)],
              ['photo', str_repeat('2', 32) . '.jpg', str_repeat('8', 64)]] as [$kind, $name, $sha]) {
        $db->run(
            "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
                                 size_bytes, sha256, tags, status, created_at, updated_at)
             VALUES (?, ?, 'own', 'x', 'x', ?, 'video/mp4', 1, ?, '[]', 'ready', ?, ?)",
            [$ws, $kind, $name, $sha, $now, $now],
        );
    }
    $p = new \Kuyash\Media\AssetPoster($posterFfmpeg, $posterPaths);
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);
    $ctl = new MediaController(new AssetRepository($db), new AssetStorage(sys_get_temp_dir()), localStorageManager(sys_get_temp_dir()), $ctx, $p, 300);

    $videoMiss = $ctl->poster(['id' => '1']);
    $photo = $ctl->poster(['id' => '2']);

    return $videoMiss->status() === 404
        && $photo->status() === 302
        && ($photo->headers()['Location'] ?? '') === '/media/2';
})());

check('poster route: the cache TTL matches its peers, because the URL is id-keyed', (static function () use ($basePath): bool {
    // The FILE is content-addressed; the URL is not — assets.id has no
    // AUTOINCREMENT, so SQLite reuses a freed rowid. A day-long cache would paint
    // a DELETED clip's frame as a new asset's preview and quietly undo the
    // poster unlink on delete.
    $src = (string) file_get_contents($basePath . '/src/Controllers/MediaController.php');

    return !str_contains($src, 'max-age=86400')
        && substr_count($src, "'Cache-Control' => 'private, max-age=3600'") === 2;
})());

check('queue: ONE malformed result_json cannot hide the jobs after it', (static function () use ($basePath): bool {
    // SQLite's json_extract raises on non-JSON text, and PDO-sqlite's fetchAll()
    // returns the rows read BEFORE the error WITHOUT throwing — so the approval
    // queue would silently end at the bad row. Failing short and quiet is the one
    // outcome this read may not have.
    $db = migratedDb($basePath);
    $now = gmdate(NOW_ISO);
    [$user, $ws] = seedUser($db, 'jq@x.com', 'h', 'JQ');
    $db->run("INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
              VALUES (?, 'W', 'distribution', '[]', ?, ?)", [$ws, $now, $now]);
    $wf = $db->lastInsertId();
    $db->run("INSERT INTO runs (workspace_id, workflow_id, entity_type, nodes_json, status, created_by, created_at, updated_at)
              VALUES (?, ?, 'library', '[]', 'awaiting_approval', ?, ?, ?)", [$ws, $wf, $user, $now, $now]);
    $run = $db->lastInsertId();

    // good, MALFORMED, good — in that order, so a truncation is visible
    foreach (['{"library_asset_id":1}', 'not json at all', '{"library_asset_id":2}'] as $i => $json) {
        $db->run(
            "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, result_json, run_after, created_at)
             VALUES (?, ?, 'PUBLISH', ?, 'render_review', 'awaiting_approval', '{}', ?, ?, ?)",
            [$ws, $run, $i + 1, $json, $now, $now],
        );
    }

    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);

    return count((new JobRepository($db))->awaitingApproval($ctx)) === 3;
})());

check('ingest: a poster failure NEVER costs the upload its file', (static function () use ($basePath): bool {
    // The blast radius of the fix: ensure() used to sit inside the catch that
    // deletes the just-stored file and rethrows, while the row was already
    // committed — producing exactly the orphan that catch exists to prevent. A
    // refactor can silently undo this by moving the call back inside, so the
    // invariant is pinned here rather than left to the comment.
    $db = migratedDb($basePath);
    [, $ws] = seedUser($db, 'ing@x.com', 'h', 'ING');
    $root = tempDir('ingest-poster');
    // rename(), not move_uploaded_file(): there is no real upload here
    $storage = new AssetStorage($root, static fn (string $f, string $t): bool => rename($f, $t));
    $ctx = new WorkspaceContext($db);
    $ctx->set($ws);

    // a poster service whose store cannot be created — ensure() must degrade
    $broken = new \Kuyash\Media\AssetPoster(
        new \Kuyash\Media\Ffmpeg('/nonexistent/ffmpeg', '/nonexistent/ffprobe', 5),
        new MediaPaths(['asset' => '/proc/no/a', 'cache' => '/proc/no/c', 'render' => '/proc/no/r', 'work' => '/proc/no/w']),
    );
    $ingest = new AssetIngest(
        new AssetValidator((array) (require $basePath . '/config/library.php')['allowed'], 200_000_000, 20_000_000),
        new MediaProbe(),
        $storage,
        new AssetRepository($db),
        localStorageManager($root),
        10,
        32,
        $broken,
    );

    $tmp = $root . '/upload.mp4';
    file_put_contents($tmp, file_get_contents($basePath . '/tools/visual/fixtures/preview.mp4'));
    $id = $ingest->ingest($ctx, new UploadedFile('clip.mp4', $tmp, filesize($tmp), UPLOAD_ERR_OK), 'own', 'Clip', '');

    $row = $db->one('SELECT stored_name FROM assets WHERE id = ?', [$id]);

    // the row exists AND its bytes are still on disk
    return $row !== null && is_file($storage->path($ws, (string) $row['stored_name']));
})());

echo "== demo/showcase: the seed is reversible, inert and marked ==\n";

/**
 * A workspace with REAL content of every kind the seed also writes — the rows
 * that must still be byte-identical after a teardown. Without a real row in the
 * same table, "teardown removed the demo rows" proves nothing about whether it
 * would have removed a real one standing next to it.
 *
 * @return array{0: Database, 1: int, 2: int, 3: string}
 */
$demoWorld = static function (string $basePath, string $now) use ($argonHash): array {
    $db = migratedDb($basePath);
    [$userId, $wsId] = seedUser($db, 'demo-owner@x.com', $argonHash, 'Demo WS');
    $db->run("UPDATE workspaces SET timezone = 'Europe/Istanbul' WHERE id = ?", [$wsId]);

    // a REAL connected account with a REAL follower count
    $db->run(
        "INSERT INTO accounts (workspace_id, platform, handle, external_ref, status, health,
                               followers_count, followers_synced_at, created_at, updated_at)
         VALUES (?, 'instagram', '@real.channel', '6a2f250a5f7d1751abb4803a', 'connected', 'ok', 7, ?, ?, ?)",
        [$wsId, $now, $now, $now],
    );
    // …and a MOCK one that is nonetheless CONNECTED. This is the shape the dev
    // database actually has, and the shape the first postTarget() lost to: it
    // filtered on provenance and never on status, so this row won and demo posts
    // landed on a channel whose per-account daily cap is live.
    $db->run(
        "INSERT INTO accounts (workspace_id, platform, handle, external_ref, status, health, created_at, updated_at)
         VALUES (?, 'tiktok', '@mock.connected', 'zacct_deadbeefcafe', 'connected', 'ok', ?, ?)",
        [$wsId, $now, $now],
    );
    $db->run(
        "INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
         VALUES (?, 'Distribution', 'distribution', '[]', ?, ?)",
        [$wsId, $now, $now],
    );
    $wf = $db->lastInsertId();
    // a REAL asset, a REAL run with a REAL job, a REAL publishing time, REAL money
    $db->run(
        "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
                             size_bytes, sha256, duration_s, width, height, aspect, tags, status, created_at, updated_at)
         VALUES (?, 'video', 'own', 'My own clip', 'own.mp4', ?, 'video/mp4', 10, ?, 22.0, 1080, 1920, '9:16', '[]', 'ready', ?, ?)",
        [$wsId, str_repeat('e', 32) . '.mp4', str_repeat('f', 64), $now, $now],
    );
    $db->run(
        "INSERT INTO runs (workspace_id, workflow_id, entity_type, entity_id, nodes_json, status, created_by, created_at, updated_at)
         VALUES (?, ?, 'library', 1, '[]', 'completed', ?, ?, ?)",
        [$wsId, $wf, $userId, $now, $now],
    );
    $realRun = $db->lastInsertId();
    $db->run(
        "INSERT INTO jobs (workspace_id, run_id, node, step, type, status, payload_json, run_after, created_at)
         VALUES (?, ?, 'PUBLISH', 1, 'publish', 'published', '{}', ?, ?)",
        [$wsId, $realRun, $now, $now],
    );
    $db->run(
        "INSERT INTO publish_slots (workspace_id, weekday, time_hhmm, enabled, created_at, updated_at, mode)
         VALUES (?, 1, '09:00', 1, ?, ?, 'manual')",
        [$wsId, $now, $now],
    );
    $db->run(
        "INSERT INTO credit_transactions (workspace_id, type, amount_cents, reason, created_at)
         VALUES (?, 'grant', 5000, 'real top-up', ?)",
        [$wsId, $now],
    );
    $db->run(
        "INSERT INTO usage_events (workspace_id, run_id, job_id, provider, category, cost_cents, created_at)
         VALUES (?, ?, 1, 'openai', 'ai_text', 42, ?)",
        [$wsId, $realRun, $now],
    );

    return [$db, $wsId, $userId, $now];
};

/** Full-database fingerprint: every row of every table the seed can touch. */
$demoFingerprint = static function (Database $db): string {
    $tables = ['users', 'workspaces', 'workspace_users', 'accounts', 'account_metrics', 'assets',
        'workflows', 'runs', 'jobs', 'posts', 'approvals', 'renders', 'publish_slots',
        'slot_occurrences', 'usage_events', 'credit_transactions', 'trends', 'trend_config', 'events'];
    $out = [];
    foreach ($tables as $t) {
        foreach ($db->all("SELECT * FROM {$t}") as $row) {
            // The demo account's password hash is Argon2id over fresh random
            // bytes, so its SALT differs every run BY DESIGN — that is what makes
            // the account unloginable. Comparing it would assert that a random
            // value is stable. Its presence is still compared; only the digest
            // is redacted.
            if (array_key_exists('password_hash', $row)) {
                $row['password_hash'] = $row['password_hash'] === null ? null : '<hash>';
            }
            $out[] = $t . ':' . json_encode($row, JSON_THROW_ON_ERROR);
        }
    }
    sort($out);

    return hash('sha256', implode("\n", $out));
};

/** A media factory that writes real (tiny) files and reports what it wrote. */
$demoMedia = new class implements \Kuyash\Demo\MediaFactory {
    public function clip(string $target, int $seconds, int $variant = 0): ?array
    {
        return $this->write($target, 'video/mp4', (float) $seconds - 0.04, 1080, 1920, 'v' . $variant);
    }

    public function still(string $target, int $index = 0): ?array
    {
        // distinct bytes per index, like the real factory's frame seek — two
        // stills that hash the same are a collision under two different titles
        return $this->write($target, 'image/jpeg', null, 1080, 1920, (string) $index);
    }

    public function stillFrom(string $source, string $target): bool
    {
        // derived from THE SOURCE's bytes, so a test can tell a poster cut from
        // the render apart from one cut from somewhere else — which is the whole
        // point of this method existing
        if (!is_file($source)) {
            return false;
        }

        return file_put_contents($target, 'still-of:' . hash_file('sha256', $source)) !== false;
    }

    /** @return array<string, mixed>|null */
    private function write(string $target, string $mime, ?float $duration, int $w, int $h, string $salt = ''): ?array
    {
        $bytes = str_repeat('x', 64) . $salt;
        if (file_put_contents($target, $bytes) === false) {
            return null;
        }

        return ['path' => $target, 'duration_s' => $duration, 'width' => $w, 'height' => $h,
            'aspect' => '9:16', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'mime' => $mime];
    }
};

/** Paths under a throwaway media root. */
$demoPaths = static function (string $root): MediaPaths {
    foreach (['asset', 'cache', 'render', 'work'] as $store) {
        @mkdir($root . '/' . $store, 0775, true);
    }

    return new MediaPaths([
        'asset' => $root . '/asset', 'cache' => $root . '/cache',
        'render' => $root . '/render', 'work' => $root . '/work',
    ]);
};

$demoNow = gmdate(NOW_ISO);

// ── R1: reversibility ───────────────────────────────────────────────────────

check('demo/r1: teardown restores the database to the byte it was seeded from', (static function () use (
    $basePath, $demoWorld, $demoFingerprint, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1');
    $before = $demoFingerprint($db);

    $seed = new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia);
    $seed->run($wsId, $demoNow);

    // the demo really is there
    $seeded = $demoFingerprint($db) !== $before
        && (int) $db->one("SELECT COUNT(*) n FROM assets WHERE title LIKE '[SAMPLE]%'")['n'] === 10
        && (int) $db->one('SELECT COUNT(*) n FROM runs')['n'] === 9
        // …and the REAL rows are untouched WHILE it is there
        && (int) $db->one("SELECT followers_count FROM accounts WHERE handle = '@real.channel'")['followers_count'] === 7
        && (int) $db->one("SELECT COUNT(*) n FROM publish_slots WHERE time_hhmm = '09:00'")['n'] === 1;

    $teardown = new \Kuyash\Demo\ShowcaseTeardown($db);
    $teardown->run();

    return $seeded
        && $demoFingerprint($db) === $before
        && $teardown->manifest()->isEmpty();
})());

check('demo/r1: every file the seed places is tracked and removed', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-files');

    $seed = new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia);
    $seed->run($wsId, $demoNow);

    $tracked = $seed->manifest()->files();
    $onDisk = array_merge(
        glob($root . '/asset/*/*') ?: [],
        glob($root . '/render/*/*') ?: [],
    );
    sort($tracked);
    sort($onDisk);
    $allTracked = $tracked === $onDisk && $tracked !== [];

    (new \Kuyash\Demo\ShowcaseTeardown($db))->run();

    return $allTracked
        && (glob($root . '/asset/*/*') ?: []) === []
        && (glob($root . '/render/*/*') ?: []) === [];
})());

check('demo/r1: a leftover media file from an earlier demo never silences the seed', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // A database replaced out of band (the visual gate wipes and re-creates one)
    // leaves the previous demo's files behind under the same deterministic paths.
    // The seed used to treat every one of those as occupied and skip the item —
    // producing a library of nothing, a queue of nothing and a calendar of
    // nothing, while still listing rows and reading like a success.
    $root = tempDir('demo-r1-leftover');
    $paths = $demoPaths($root);

    [$first, $wsA] = $demoWorld($basePath, $demoNow);
    (new \Kuyash\Demo\ShowcaseSeed($first, $paths, $demoMedia))->run($wsA, $demoNow);
    $files = (new \Kuyash\Demo\SeedManifest($first))->files();
    $stillThere = $files !== [] && array_reduce($files, static fn (bool $c, string $f): bool => $c && is_file($f), true);

    // a brand-new database, the same media root, the same workspace id
    [$second, $wsB] = $demoWorld($basePath, $demoNow);
    $report = (new \Kuyash\Demo\ShowcaseSeed($second, $paths, $demoMedia))->run($wsB, $demoNow);

    return $stillThere
        && $wsA === $wsB
        && ($report['counts']['assets'] ?? 0) === 10
        && ($report['counts']['runs'] ?? 0) === 8
        && $report['notes'] === [];
})());

check('demo/r1: a file a real asset row claims is never overwritten', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-claimed');
    $paths = $demoPaths($root);

    // find the path the seed WOULD use for its first clip, and hand it to a real
    // asset row belonging to somebody else
    (new \Kuyash\Demo\ShowcaseSeed($db, $paths, $demoMedia))->run($wsId, $demoNow);
    $first = (new \Kuyash\Demo\SeedManifest($db))->files()[0];
    (new \Kuyash\Demo\ShowcaseTeardown($db))->run();

    @mkdir(dirname($first), 0775, true);
    file_put_contents($first, 'someone-elses-bytes');
    $db->run(
        "INSERT INTO assets (workspace_id, kind, type, title, original_filename, stored_name, mime,
                             size_bytes, sha256, tags, status, created_at, updated_at)
         VALUES (?, 'video', 'own', 'Not a demo clip', 'x.mp4', ?, 'video/mp4', 19, ?, '[]', 'ready', ?, ?)",
        [$wsId, basename($first), str_repeat('a', 64), $demoNow, $demoNow],
    );

    $report = (new \Kuyash\Demo\ShowcaseSeed($db, $paths, $demoMedia))->run($wsId, $demoNow);

    return file_get_contents($first) === 'someone-elses-bytes'
        && ($report['counts']['assets'] ?? 0) === 9
        && $report['notes'] !== [];
})());

check('demo/r1: seeding twice does not double anything', (static function () use (
    $basePath, $demoWorld, $demoFingerprint, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-idem');
    $paths = $demoPaths($root);

    (new \Kuyash\Demo\ShowcaseSeed($db, $paths, $demoMedia))->run($wsId, $demoNow);
    $once = $demoFingerprint($db);
    $onceRows = (int) $db->one('SELECT COUNT(*) n FROM demo_seed_manifest')['n'];

    // the second run is what bin/demo-seed.php does: undo, then seed again
    (new \Kuyash\Demo\ShowcaseTeardown($db))->run();
    (new \Kuyash\Demo\ShowcaseSeed($db, $paths, $demoMedia))->run($wsId, $demoNow);

    return $demoFingerprint($db) === $once
        && (int) $db->one('SELECT COUNT(*) n FROM demo_seed_manifest')['n'] === $onceRows;
})());

check('demo/r1: teardown also takes the calendar days the product itself added', (static function () use (
    $basePath, $demoWorld, $demoFingerprint, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-cells');
    $before = $demoFingerprint($db);

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // the materializer runs on every worker tick and every plan page view, so a
    // demo publishing time keeps growing new days AFTER the seed finished
    $occ = new OccurrenceRepository($db);
    $later = gmdate(NOW_ISO, (int) strtotime($demoNow) + 8 * 86400);
    (new OccurrenceMaterializer($occ, new SlotResolver()))->materialize(
        $wsId,
        'Europe/Istanbul',
        (new SlotRepository($db))->listForWorkspace($wsId),
        $later,
    );
    $grew = (int) $db->one('SELECT COUNT(*) n FROM slot_occurrences')['n'] > 8;

    (new \Kuyash\Demo\ShowcaseTeardown($db))->run();

    // the REAL time's own new days are not demo rows, so they legitimately
    // remain — the fingerprint check is therefore scoped to the demo's slots
    $demoCellsGone = (int) $db->one(
        'SELECT COUNT(*) n FROM slot_occurrences o
         WHERE NOT EXISTS (SELECT 1 FROM publish_slots s WHERE s.id = o.slot_id)',
    )['n'] === 0;

    return $grew && $demoCellsGone && $demoFingerprint($db) !== $before
        && (int) $db->one('SELECT COUNT(*) n FROM publish_slots')['n'] === 1;
})());

check('demo/r1: a reused rowid is never mistaken for the demo row that freed it', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // SQLite reuses a freed rowid unless the column is AUTOINCREMENT, and none of
    // these are. Delete a [SAMPLE] clip from the Library screen, upload a real
    // one, and the real one can land on the freed id — after which teardown,
    // reading the manifest, would delete the operator's own file.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-rowid');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $manifest = new \Kuyash\Demo\SeedManifest($db);
    // the LAST asset: a still, which no calendar day and no run points at — the
    // same row the Library screen would let a person delete outright
    $assetIds = $manifest->rowIds('assets');
    $victim = $assetIds[count($assetIds) - 1];

    // the operator deletes that demo clip, then uploads their own
    $db->run('DELETE FROM assets WHERE id = ?', [$victim]);
    $later = gmdate(NOW_ISO, (int) strtotime($demoNow) + 3600);
    $db->run(
        "INSERT INTO assets (id, workspace_id, kind, type, title, original_filename, stored_name, mime,
                             size_bytes, sha256, tags, status, created_at, updated_at)
         VALUES (?, ?, 'video', 'own', 'My real upload', 'mine.mp4', ?, 'video/mp4', 5, ?, '[]', 'ready', ?, ?)",
        [$victim, $wsId, str_repeat('9', 32) . '.mp4', str_repeat('b', 64), $later, $later],
    );

    $result = (new \Kuyash\Demo\ShowcaseTeardown($db))->run();
    $survivor = $db->one('SELECT title FROM assets WHERE id = ?', [$victim]);

    return $survivor !== null
        && (string) $survivor['title'] === 'My real upload'
        && $result['kept'] !== []
        && in_array("assets #{$victim}", $result['kept'], true);
})());

check('demo/r1: a pinned run is left WHOLE and everything else still comes out', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // The first version ran one all-or-nothing transaction: the CLI printed
    // "those runs stay, everything else can still be removed" and then the
    // blocked DELETE threw and nothing at all came out. A half-stripped run with
    // its jobs gone is worse than either outcome, so the pinned run keeps its
    // children — and keeps its manifest entries, so a later teardown finishes it.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-partial');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $manifest = new \Kuyash\Demo\SeedManifest($db);
    $pinned = $manifest->rowIds('runs')[0];
    $others = count($manifest->rowIds('runs')) - 1;
    $pinnedJobs = (int) $db->one('SELECT COUNT(*) n FROM jobs WHERE run_id = ?', [$pinned])['n'];

    // the worker sweeps an aged calendar day and appends a guardrail line
    (new EventLog($db))->record($wsId, 'warn', 'guardrail', 'plan.slot_missed', [], $pinned);

    $result = (new \Kuyash\Demo\ShowcaseTeardown($db))->run();

    $pinnedIntact = $db->one('SELECT id FROM runs WHERE id = ?', [$pinned]) !== null
        && (int) $db->one('SELECT COUNT(*) n FROM jobs WHERE run_id = ?', [$pinned])['n'] === $pinnedJobs;
    $othersGone = (int) $db->one(
        "SELECT COUNT(*) n FROM runs r JOIN demo_seed_manifest m ON m.table_name = 'runs' AND m.row_id = r.id",
    )['n'] === 1;
    // …and it is still tracked, so a second pass can finish once the pin is gone
    $stillTracked = in_array("runs #{$pinned}", $result['kept'], true);

    return $others > 0 && $pinnedIntact && $othersGone && $stillTracked
        && ($result['rows']['assets'] ?? 0) > 0;
})());

check('demo/r1: an event on a demo run is reported as a blocker, not hit at delete time', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-block');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $runId = (int) $db->one(
        "SELECT row_id FROM demo_seed_manifest WHERE table_name = 'runs' ORDER BY row_id LIMIT 1",
    )['row_id'];

    $clean = (new \Kuyash\Demo\ShowcaseTeardown($db))->blockers() === [];

    // the audit log is append-only: a row here can never be taken back, which is
    // exactly why the seed writes none and why teardown says so up front
    (new EventLog($db))->record($wsId, 'info', 'transition', 'run.started', [], $runId);
    $reported = (new \Kuyash\Demo\ShowcaseTeardown($db))->blockers();

    return $clean
        && count($reported) === 1
        && str_contains($reported[0], "run #{$runId}")
        && str_contains($reported[0], 'append-only');
})());

check('demo/r1: the seed writes nothing into the append-only audit log', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-events');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    return (int) $db->one('SELECT COUNT(*) n FROM events')['n'] === 0;
})());

// ── R2: inertia ─────────────────────────────────────────────────────────────

check('demo/r2: nothing the seed writes is claimable by the worker', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-claim');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // the claim loop takes 'queued' rows and nothing else
    return (int) $db->one("SELECT COUNT(*) n FROM jobs WHERE status IN ('queued', 'processing')")['n'] === 0
        && (int) $db->one("SELECT COUNT(*) n FROM jobs WHERE status = 'awaiting_approval'")['n'] === 5;
})());

check('demo/r2: a plan tick over the seeded calendar produces nothing and closes nothing', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow, $p24runner
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-plan');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $runsBefore = (int) $db->one('SELECT COUNT(*) n FROM runs')['n'];
    $eventsBefore = (int) $db->one('SELECT COUNT(*) n FROM events')['n'];

    $engine = new Engine($db, new EventLog($db), new WorkflowValidator(), static fn (): string => $demoNow);
    $totals = $p24runner($db, $engine)->tick($demoNow);

    // 'started' is the only path in the product that spends money on its own,
    // and 'swept' is the one that cancels runs and appends guardrail events
    return $totals['started'] === 0
        && $totals['swept'] === 0
        && (int) $db->one('SELECT COUNT(*) n FROM runs')['n'] === $runsBefore
        && (int) $db->one('SELECT COUNT(*) n FROM events')['n'] === $eventsBefore;
})());

check('demo/r2: no automatic calendar day is ever left open for the producer to take', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-auto');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // dueAuto() = mode 'auto' AND status 'open' AND run_id IS NULL
    $open = (int) $db->one(
        "SELECT COUNT(*) n FROM slot_occurrences WHERE mode = 'auto' AND status = 'open' AND run_id IS NULL",
    )['n'];
    // …and the automatic time is switched off, so the materializer creates no more
    $paused = (int) $db->one("SELECT COUNT(*) n FROM publish_slots WHERE mode = 'auto' AND enabled = 1")['n'];
    $hasAuto = (int) $db->one("SELECT COUNT(*) n FROM slot_occurrences WHERE mode = 'auto'")['n'] > 0;

    return $open === 0 && $paused === 0 && $hasAuto;
})());

check('demo/r2: demo publishing never spends a real budget or a real daily cap', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-cap');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // month-to-date is the window the budget cap is enforced against; the real
    // 42-cent charge is the only thing that may be inside it
    $mtd = (int) $db->one(
        'SELECT COALESCE(SUM(cost_cents), 0) c FROM usage_events WHERE workspace_id = ? AND created_at >= ?',
        [$wsId, gmdate('Y-m-01\T00:00:00\Z', (int) strtotime($demoNow))],
    )['c'];

    // the per-account daily cap counts today's published posts on that account
    $realAccount = (int) $db->one("SELECT id FROM accounts WHERE handle = '@real.channel'")['id'];
    $today = (new PublishCounter($db))->publishedToday($wsId, $demoNow, $realAccount);

    // …and no demo post is attributed to ANY connected channel. Provenance is not
    // the property that matters here: connectedFor() — which drives the publish
    // fan-out AND both daily-cap loops — filters on `status`, not on whether the
    // channel is real. A mock-but-connected row is still live machinery.
    $onConnected = (int) $db->one(
        "SELECT COUNT(*) n FROM posts p JOIN accounts a ON a.id = p.account_id
         WHERE a.status = 'connected'",
    )['n'];
    $mockConnected = (int) $db->one("SELECT id FROM accounts WHERE handle = '@mock.connected'")['id'];
    $mockToday = (new PublishCounter($db))->publishedToday($wsId, $demoNow, $mockConnected);

    return $mtd === 42 && $today === 0 && $onConnected === 0 && $mockToday === 0;
})());

check('demo/r2: no auto-approval record is written, so the auto cap is never touched', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // AutoApprovalGate::autoApprovalsToday() counts auto approvals for the
    // workspace across the UTC day against the real daily_post_cap. One seeded
    // row took a live auto-mode workspace from 2-of-2 to 3-of-2 — and when that
    // counter trips, the gate writes `guardrail.daily_cap_reached` with the
    // inflated number into `events`, which is APPEND-ONLY. A seed that refuses
    // to write the audit log must not induce the product to write a false line
    // into it either.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-autocap');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // No `auto` row: that is the one that consumes the cap. The `manual` rows the
    // seed DOES write name a demo account, never the operator — covered by
    // demo/r3 below, and irrelevant to this counter either way.
    return (int) $db->one("SELECT COUNT(*) n FROM approvals WHERE mode = 'auto'")['n'] === 0;
})());

check('demo/r2: not one charge lands in the month the budget cap is enforced against', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r2-month');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $monthStart = gmdate('Y-m-01\T00:00:00\Z', (int) strtotime($demoNow));

    $thisMonth = (int) $db->one(
        "SELECT COUNT(*) n FROM usage_events u
         JOIN demo_seed_manifest m ON m.table_name = 'usage_events' AND m.row_id = u.id
         WHERE u.created_at >= ?",
        [$monthStart],
    )['n'];

    // …and every charge is dated WITH the job that incurred it. The first
    // version scattered them across previous months and attached them to jobs on
    // runs still awaiting approval today — the screen then showed a run paying
    // for AI text three months before that run existed.
    $beforeItsJob = (int) $db->one(
        "SELECT COUNT(*) n FROM usage_events u
         JOIN demo_seed_manifest m ON m.table_name = 'usage_events' AND m.row_id = u.id
         JOIN jobs j ON j.id = u.job_id
         WHERE u.created_at <> j.created_at",
    )['n'];

    return $thisMonth === 0 && $beforeItsJob === 0
        && (int) $db->one("SELECT COUNT(*) n FROM usage_events u
             JOIN demo_seed_manifest m ON m.table_name = 'usage_events' AND m.row_id = u.id")['n'] > 0;
})());

// ── R3: honesty ─────────────────────────────────────────────────────────────

check('demo/r3: every operator-visible string the seed writes carries the marker', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-mark');
    $mark = \Kuyash\Demo\ShowcaseSeed::MARK;

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $manifest = new \Kuyash\Demo\SeedManifest($db);

    // titles: the marker leads the string on purpose — an ellipsis eats the END
    // of a title, so a trailing chip is the part that vanishes at 375px
    foreach ($manifest->rowIds('assets') as $id) {
        $title = (string) $db->one('SELECT title FROM assets WHERE id = ?', [$id])['title'];
        if (!str_starts_with($title, $mark)) {
            return false;
        }
    }

    // captions and hashtags: the text that would actually go out if somebody
    // approved a demo card by mistake
    $seen = 0;
    foreach ($manifest->rowIds('jobs') as $id) {
        $row = $db->one('SELECT type, result_json FROM jobs WHERE id = ?', [$id]);
        $result = json_decode((string) $row['result_json'], true);
        foreach ((array) ($result['captions'] ?? []) as $caption) {
            $seen++;
            if (!str_starts_with((string) $caption, $mark)) {
                return false;
            }
        }
        foreach ((array) ($result['hashtags'] ?? []) as $tag) {
            $seen++;
            if ($tag === '#sample') {
                continue;
            }
        }
        if (($result['hashtags'] ?? null) !== null && ($result['hashtags'][0] ?? '') !== '#sample') {
            return false;
        }
    }

    // …and the charge feed, whose only free-text field is the provider name
    foreach ($manifest->rowIds('usage_events') as $id) {
        $provider = (string) $db->one('SELECT provider FROM usage_events WHERE id = ?', [$id])['provider'];
        if (!str_starts_with($provider, $mark)) {
            return false;
        }
    }

    return $seen > 0 && $manifest->rowIds('usage_events') !== [];
})());

check('demo/r3: a demo channel is never connected, and never carries a follower number', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-acct');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $manifest = new \Kuyash\Demo\SeedManifest($db);

    $ids = $manifest->rowIds('accounts');
    if ($ids === []) {
        return false;
    }
    foreach ($ids as $id) {
        $row = $db->one('SELECT status, followers_count, external_ref FROM accounts WHERE id = ?', [$id]);
        // NOT 'connected' is a safety property, not a cosmetic one: publishing
        // fans out to every connected account, so a connected mock row would
        // attach itself to the operator's next real publish and fail it.
        // followers_count IS NULL is what makes the account card mark every
        // figure it derives with its "sample" chip.
        if ((string) $row['status'] === 'connected'
            || $row['followers_count'] !== null
            || $row['external_ref'] !== null
        ) {
            return false;
        }
    }

    // and the real channel is exactly as it was
    $real = $db->one("SELECT status, followers_count FROM accounts WHERE handle = '@real.channel'");

    return (string) $real['status'] === 'connected' && (int) $real['followers_count'] === 7;
})());

check('demo/r3: a finished run\'s poster is a frame of THAT run\'s video, not of another clip', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-poster');
    $paths = $demoPaths($root);

    (new \Kuyash\Demo\ShowcaseSeed($db, $paths, $demoMedia))->run($wsId, $demoNow);

    $renders = $db->all(
        "SELECT r.workspace_id, r.stored_name, r.poster_name FROM renders r
         JOIN demo_seed_manifest m ON m.table_name = 'renders' AND m.row_id = r.id",
    );
    if ($renders === []) {
        return false;
    }
    foreach ($renders as $render) {
        if ($render['poster_name'] === null) {
            continue;   // no poster is honest; a poster of the WRONG clip is not
        }
        $video = $paths->pathFor('render', (int) $render['workspace_id'], (string) $render['stored_name']);
        $poster = $paths->pathFor('render', (int) $render['workspace_id'], (string) $render['poster_name']);
        // the stub writes the SOURCE's hash into the still, so this reads as
        // "was this frame cut from the file it sits on?"
        if (!is_file($video) || !is_file($poster)) {
            return false;
        }
        if ((string) file_get_contents($poster) !== 'still-of:' . hash_file('sha256', $video)) {
            return false;
        }
    }

    return true;
})());
check('demo/r3: a stored duration is what the factory measured, never what was asked for', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-dur');

    // the stub reports 0.04s less than the request; if the seed stored its own
    // intention instead, every duration would come back a whole number
    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    $rows = $db->all(
        "SELECT a.duration_s FROM assets a
         JOIN demo_seed_manifest m ON m.table_name = 'assets' AND m.row_id = a.id
         WHERE a.kind = 'video'",
    );
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        if (abs(((float) $row['duration_s']) - round((float) $row['duration_s'])) < 0.001) {
            return false;
        }
    }

    // and the compliance row repeats the measurement rather than a target
    $verdict = $db->one(
        "SELECT result_json FROM jobs j
         JOIN demo_seed_manifest m ON m.table_name = 'jobs' AND m.row_id = j.id
         WHERE j.type = 'compliance_check' LIMIT 1",
    );
    $decoded = json_decode((string) $verdict['result_json'], true);
    $stated = (float) $decoded['checks']['format']['duration_s'];
    $asset = (float) $db->one(
        "SELECT a.duration_s FROM assets a
         JOIN demo_seed_manifest m ON m.table_name = 'assets' AND m.row_id = a.id
         WHERE a.kind = 'video' ORDER BY a.id LIMIT 1",
    )['duration_s'];

    return abs($stated - $asset) < 0.001;
})());

check('demo/r3: a stored slop score is what the scorer measured, never a literal', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // Same rule the durations follow, for the same reason: a number on a
    // compliance card cannot carry the [SAMPLE] marker, so it has to be true.
    // The seed used to write hardcoded 0.11-0.24 literals, which drifted from
    // what the product's own scorer computes over the very captions the card
    // shows — by up to 0.06 on the seeded set.
    //
    // The assertion is `history_runs`, not the score itself. A score cannot be
    // re-measured after the fact (each run is judged against the history that
    // existed WHEN it ran — which is how production works too), but the history
    // SIZE is a fingerprint of exactly that: the Nth seeded run must have seen
    // the N runs seeded before it. A literal cannot produce that sequence.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-slop');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    $seen = [];
    foreach ($db->all(
        "SELECT j.run_id, j.result_json FROM jobs j
         JOIN demo_seed_manifest m ON m.table_name = 'jobs' AND m.row_id = j.id
         WHERE j.type = 'compliance_check' ORDER BY j.run_id ASC",
    ) as $row) {
        $slop = json_decode((string) $row['result_json'], true)['checks']['slop'] ?? null;
        if (!is_array($slop) || !isset($slop['score'], $slop['history_runs'])
            || $slop['score'] < 0.0 || $slop['score'] > 1.0
        ) {
            return false;
        }
        $seen[] = (int) $slop['history_runs'];
    }

    // the fixture's own run carries only a publish job, so it adds no history
    $src = (string) file_get_contents($basePath . '/src/Demo/ShowcaseSeed.php');

    return $seen === [0, 1, 2, 3, 4, 5, 6, 7]
        && str_contains($src, 'SlopScorer($this->db))->score(')
        && preg_match("/'history_runs' => \\d/", $src) !== 1
        // …and the thresholds come from the policy, not from a copy of it
        && str_contains($src, 'CompliancePolicy::SLOP_WARN')
        && str_contains($src, 'CompliancePolicy::SLOP_BLOCK');
})());

check('demo/r3: a mock publish is never recorded as money', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-spend');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    // mock work is never real spend (the usage_events rule) — and the demo
    // publishes are all mock
    $publishCharges = (int) $db->one("SELECT COUNT(*) n FROM usage_events WHERE category = 'publish'")['n'];
    // every seeded ledger line names itself in the reason it is listed under
    $unmarked = (int) $db->one(
        "SELECT COUNT(*) n FROM credit_transactions c
         JOIN demo_seed_manifest m ON m.table_name = 'credit_transactions' AND m.row_id = c.id
         WHERE c.reason NOT LIKE ? ",
        [\Kuyash\Demo\ShowcaseSeed::MARK . '%'],
    )['n'];
    // and every demo post is a mock one
    $realLooking = (int) $db->one("SELECT COUNT(*) n FROM posts WHERE external_post_id NOT LIKE 'zp\\_%' ESCAPE '\\'")['n'];

    return $publishCharges === 0 && $unmarked === 0 && $realLooking === 0;
})());

check('demo/r3: a finished publish reports counts the digest can actually read', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // The digest renders "published to {published} of {posts} account(s)" from
    // this result. A boolean where a count belongs rendered as "1 of 0" — a
    // sentence the product itself cannot produce, on the compliance report.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-publish');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    $rows = $db->all(
        "SELECT j.result_json FROM jobs j
         JOIN demo_seed_manifest m ON m.table_name = 'jobs' AND m.row_id = j.id
         WHERE j.type = 'publish'",
    );
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        $r = json_decode((string) $row['result_json'], true);
        if (!is_int($r['posts'] ?? null) || !is_int($r['published'] ?? null)
            || $r['published'] > $r['posts'] || $r['posts'] < 1
        ) {
            return false;
        }
    }

    return true;
})());

check('demo/r3: the approval record names a DEMO account, never the operator', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // Writing `decided_by` = the real operator makes the run page render
    // "Approved by you · <their real email>" for a decision they never made —
    // fabrication, forbidden by name. A demo account fabricates nothing and
    // restores a state the engine can actually reach (render_review = ready is
    // only produced by a path that writes an approval).
    [$db, $wsId, $ownerId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-appr');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    $rows = $db->all(
        "SELECT a.mode, a.decided_by, a.policy_version, u.email, u.name
         FROM approvals a JOIN users u ON u.id = a.decided_by
         JOIN demo_seed_manifest m ON m.table_name = 'approvals' AND m.row_id = a.id",
    );
    if (count($rows) !== 3) {
        return false;
    }
    foreach ($rows as $row) {
        if ((int) $row['decided_by'] === $ownerId) {
            return false;   // the operator never approved these
        }
        if ((string) $row['mode'] !== 'manual' || $row['policy_version'] !== null) {
            return false;   // the 0007 truthfulness invariant for a human record
        }
        // the address says what it is: .invalid can never be a real mailbox
        if (!str_ends_with((string) $row['email'], '@kuyash.invalid')
            || !str_starts_with((string) $row['name'], \Kuyash\Demo\ShowcaseSeed::MARK)
        ) {
            return false;
        }
    }

    // …and that account cannot be logged into
    $hash = (string) $db->one('SELECT password_hash FROM users WHERE email = ?', ['sample.operator@kuyash.invalid'])['password_hash'];

    return !password_verify('', $hash) && !password_verify('password', $hash);
})());

check('demo/r1: teardown takes the demo account with it', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r1-user');
    $before = (int) $db->one('SELECT COUNT(*) n FROM users')['n'];

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);
    $seeded = (int) $db->one('SELECT COUNT(*) n FROM users')['n'] === $before + 1;

    (new \Kuyash\Demo\ShowcaseTeardown($db))->run();

    return $seeded && (int) $db->one('SELECT COUNT(*) n FROM users')['n'] === $before;
})());

check('demo/r3: no ledger row is written, because a balance cannot carry a marker', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // credit_transactions.reason CAN hold the marker, so the ledger list would
    // read honestly — but balanceCents() and totals() are lifetime SUMs with no
    // date filter, and a headline balance has nowhere to put it. On the dev
    // workspace six seeded rows were 72% of displayed lifetime spend.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-ledger');
    $before = (int) $db->one('SELECT COALESCE(SUM(amount_cents), 0) c FROM credit_transactions')['c'];

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    return (int) $db->one('SELECT COALESCE(SUM(amount_cents), 0) c FROM credit_transactions')['c'] === $before
        && (new \Kuyash\Demo\SeedManifest($db))->rowIds('credit_transactions') === [];
})());

check('demo/r3: the charge feed reads newest-first, the way the screen orders it', (static function () use (
    $basePath, $demoWorld, $demoMedia, $demoPaths, $demoNow
): bool {
    // UsageRepository::recentCharges() is `ORDER BY id DESC` — INSERTION order,
    // not timestamp. Seeding the newer month first therefore listed "recent"
    // charges oldest-first on /usage. Row order and clock order have to agree.
    [$db, $wsId] = $demoWorld($basePath, $demoNow);
    $root = tempDir('demo-r3-order');

    (new \Kuyash\Demo\ShowcaseSeed($db, $demoPaths($root), $demoMedia))->run($wsId, $demoNow);

    $rows = $db->all(
        "SELECT u.created_at FROM usage_events u
         JOIN demo_seed_manifest m ON m.table_name = 'usage_events' AND m.row_id = u.id
         ORDER BY u.id DESC",
    );
    if (count($rows) < 2) {
        return false;
    }
    $previous = null;
    foreach ($rows as $row) {
        $at = (string) $row['created_at'];
        if ($previous !== null && $at > $previous) {
            return false;
        }
        $previous = $at;
    }

    return true;
})());

check('demo/showcase: the seed only ever inserts — a real row is never rewritten', (static function () use ($basePath): bool {
    // The reason is structural: an UPDATE to a pre-existing row cannot be undone
    // from a manifest that records ids. Everything outside the demo's own rows
    // is therefore read-only, including the workspace's timezone, approval mode,
    // caps and kill switch.
    $src = (string) file_get_contents($basePath . '/src/Demo/ShowcaseSeed.php');
    preg_match_all('/UPDATE\s+(\w+)\s+SET/i', $src, $m);
    foreach ($m[1] as $table) {
        // the only two updates are on rows this seed created moments earlier:
        // its own publishing time, and its own runs' planned instant
        if (!in_array($table, ['publish_slots', 'runs'], true)) {
            return false;
        }
    }

    return $m[1] !== [] && !preg_match('/UPDATE\s+workspaces\s+SET/i', $src);
})());

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
