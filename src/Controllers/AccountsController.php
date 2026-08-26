<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\RateLimiter;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Library\AssetRepository;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\PostRepository;
use Kuyash\Publish\PublishCounter;
use Kuyash\Publish\PublishProvider;
use Kuyash\Publish\PublishProviderException;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * Social accounts (Phase 10): the connect/disconnect surface and the publish
 * dashboard's data. The connect flow is a faithful TWO-LEG mock OAuth — initiate
 * (authorize screen with a CSRF-equivalent `state` nonce) → provider callback —
 * but stores only an account REFERENCE + health, never a token/password (Zernio
 * owns OAuth). All POSTs go through the global CSRF gate; the GET callback is
 * guarded by the session `state` nonce (the OAuth pattern).
 */
final class AccountsController
{
    private const STATE_KEY = 'oauth_state';

    /** Per-IP throttle bucket for the live account sync. */
    private const RATE_BUCKET = 'accounts_sync';

    public function __construct(
        private readonly View $view,
        private readonly AccountRepository $accounts,
        private readonly PostRepository $posts,
        private readonly AssetRepository $assets,
        private readonly PublishCounter $counter,
        private readonly WorkspaceSettings $settings,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly PublishProvider $publisher,
        private readonly \Kuyash\Publish\SlotRepository $slots,
        // Optional (null = no throttling) so existing constructions stay valid.
        private readonly ?RateLimiter $rateLimiter = null,
        private readonly ?\Kuyash\Media\AssetPoster $posters = null,
        private readonly ?\Kuyash\Core\Database $db = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cap = $this->settings->compliance($this->workspace->id())['daily_post_cap'];

        $accounts = array_map(function (array $a) use ($now, $cap): array {
            $a['published_today'] = $this->counter->publishedToday($this->workspace->id(), $now, (int) $a['id']);
            $a['daily_cap'] = $cap;

            return $a;
        }, $this->accounts->listFor($this->workspace));

        return Response::html($this->view->render('accounts/index', [
            'title' => 'Accounts — Kuyash',
            'active' => 'accounts',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'accounts' => $accounts,
            // frames a SAMPLE card may show (see partials/account-card.php)
            'samplePosters' => ($this->posters !== null && $this->db !== null)
                ? $this->posters->samplePool($this->db, $this->workspace->id(), \Kuyash\Demo\ShowcaseSeed::MARK)
                : [],
            'platforms' => AccountRepository::PLATFORMS,
            'references' => $this->assets->readyReferencesFor($this->workspace),
            'nextScheduled' => $this->posts->nextScheduled($this->workspace, $now),
            // Whether a weekly plan EXISTS. Without it this screen said
            // "approved renders publish immediately" to every workspace,
            // including ones whose posts go out on a schedule — the same lie
            // the dashboard's plan band was fixed for, one screen over.
            'hasPlan' => $this->slots->hasAny($this->workspace),
        ], 'layout/app'));
    }

    /** Leg 1 — the mock provider's authorize screen. @param array<string, string> $params */
    public function connectStart(array $params = []): Response
    {
        $platform = (string) ($params['platform'] ?? '');
        if (!in_array($platform, AccountRepository::PLATFORMS, true)) {
            return $this->back('error', 'account.invalid_platform');
        }

        // CSRF-equivalent for the GET callback: a one-time nonce echoed back in
        // the redirect and compared against the session (the OAuth `state` param)
        $state = bin2hex(random_bytes(16));
        $_SESSION[self::STATE_KEY] = $state;

        return Response::html($this->view->render('accounts/authorize', [
            'title' => 'Connect ' . $platform . ' — Kuyash',
            'active' => 'accounts',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => [],
            'platform' => $platform,
            'state' => $state,
        ], 'layout/app'));
    }

    /** Leg 2 — the provider redirects back here (GET, like a real OAuth callback). */
    public function connectCallback(array $params = []): Response
    {
        $platform = (string) ($_GET['platform'] ?? '');
        $state = (string) ($_GET['state'] ?? '');
        $sessionState = (string) ($_SESSION[self::STATE_KEY] ?? '');
        unset($_SESSION[self::STATE_KEY]); // one-time use

        if (!in_array($platform, AccountRepository::PLATFORMS, true)) {
            return $this->back('error', 'account.invalid_platform');
        }
        if ($sessionState === '' || !hash_equals($sessionState, $state)) {
            return $this->back('error', 'account.connect_failed');
        }

        $handle = $this->cleanHandle((string) ($_GET['handle'] ?? ''), $platform);
        // Resolve the REAL provider account id (the value publish() sends as
        // accountId) from GET /accounts by matching the handle. Only when the
        // account is not reported by the provider (offline mock / not yet at the
        // provider) do we fall back to a placeholder — "Sync from Zernio" corrects it.
        $externalRef = $this->resolveExternalRef($platform, $handle)
            ?? ('zacct_' . bin2hex(random_bytes(6)));
        $this->accounts->connect($this->workspace, $platform, $handle, $externalRef, gmdate('Y-m-d\TH:i:s\Z'));

        return $this->back('success', 'account.connected');
    }

    /** @param array<string, string> $params */
    public function disconnect(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || $this->accounts->find($this->workspace, (int) $id) === null) {
            return $this->back('error', 'account.not_found');
        }
        $this->accounts->disconnect($this->workspace, (int) $id);

        return $this->back('success', 'account.disconnected');
    }

    /** Set / clear the per-account default reference asset. @param array<string, string> $params */
    public function setReference(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || $this->accounts->find($this->workspace, (int) $id) === null) {
            return $this->back('error', 'account.not_found');
        }

        $assetRaw = trim((string) ($_POST['asset_id'] ?? ''));
        if ($assetRaw === '') {
            $this->accounts->clearDefaultReference($this->workspace, (int) $id);

            return $this->back('success', 'account.reference_updated');
        }
        if (!ctype_digit($assetRaw) || !$this->accounts->setDefaultReference($this->workspace, (int) $id, (int) $assetRaw)) {
            return $this->back('error', 'account.reference_invalid');
        }

        return $this->back('success', 'account.reference_updated');
    }

    /**
     * Reconcile every local account against the provider, matched by platform +
     * normalized username. Fixes a stale/placeholder external_ref so publish()
     * sends a valid accountId, and stores the REAL audience number in the same
     * pass (the read also carries followers, so this stays one round trip).
     *
     * A follower count the provider does not report leaves the stored value
     * untouched — never overwritten with a zero. @param array<string, string> $params
     */
    public function sync(array $params = []): Response
    {
        // Throttle FIRST: this is the one authenticated button that makes a live
        // provider call per click, against an undocumented vendor rate limit
        // (a Phase 22 follow-up).
        if ($this->rateLimiter !== null
            && $this->rateLimiter->tooMany(self::RATE_BUCKET, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))
        ) {
            return $this->back('error', 'rate.limited');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $remote = $this->publisher->accountMetrics();
        } catch (PublishProviderException) {
            return $this->back('error', 'account.sync_failed');
        }

        // A mock provider's audience is a deterministic stand-in, and the card
        // renders a stored follower count unmarked (i.e. as measured) — so only a
        // real provider's number may be persisted. Refs still reconcile either way.
        $audienceIsReal = $this->publisher->name() !== 'mock';

        // {platform}|{normalized username} → real provider account id + audience
        $map = [];
        foreach ($remote as $a) {
            $ref = (string) ($a['external_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $key = (string) ($a['platform'] ?? '') . '|' . $this->normalizeHandle((string) ($a['username'] ?? ''));
            $map[$key] = [
                'ref' => $ref,
                'followers' => is_int($a['followers'] ?? null) ? $a['followers'] : null,
            ];
        }

        $updated = 0;
        foreach ($this->accounts->listFor($this->workspace) as $acct) {
            $key = (string) $acct['platform'] . '|' . $this->normalizeHandle((string) $acct['handle']);
            $match = $map[$key] ?? null;
            if ($match === null) {
                continue;
            }
            $changed = $this->accounts->setExternalRef($this->workspace, (int) $acct['id'], $match['ref'], $now);
            if ($audienceIsReal && $match['followers'] !== null
                && $this->accounts->setFollowers($this->workspace, (int) $acct['id'], $match['followers'], $now)
            ) {
                $changed = true;
            }
            if ($changed) {
                $updated++;
            }
        }

        return $this->back($updated > 0 ? 'success' : 'info', $updated > 0 ? 'account.synced' : 'account.sync_none');
    }

    /**
     * The provider account id for a (platform, handle), resolved from the live
     * account list (matched on normalized username). Null when the provider
     * reports no such account, or on a transient error (caller falls back).
     */
    private function resolveExternalRef(string $platform, string $handle): ?string
    {
        $want = $this->normalizeHandle($handle);
        try {
            foreach ($this->publisher->accounts($platform) as $a) {
                if ((string) ($a['platform'] ?? '') === $platform
                    && $this->normalizeHandle((string) ($a['username'] ?? '')) === $want
                    && (string) ($a['external_ref'] ?? '') !== ''
                ) {
                    return (string) $a['external_ref'];
                }
            }
        } catch (PublishProviderException) {
            return null;
        }

        return null;
    }

    /** Compare handles/usernames case- and @-insensitively. */
    private function normalizeHandle(string $h): string
    {
        return strtolower(ltrim(trim($h), '@'));
    }

    /** Sanitize a handle to a safe display string; fall back to a generated one. */
    private function cleanHandle(string $raw, string $platform): string
    {
        $clean = preg_replace('/[^@A-Za-z0-9_.\- ]/', '', $raw) ?? '';
        $clean = trim(substr($clean, 0, 64));
        if ($clean === '') {
            $clean = '@kuyash_' . $platform . '_' . bin2hex(random_bytes(2));
        }

        return $clean;
    }

    private function back(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/accounts', 303);
    }
}
