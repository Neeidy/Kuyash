<?php

declare(strict_types=1);

namespace Kuyash\Publish;

/**
 * Deterministic mock publisher — the default state of the integration
 * (mock-first rule). NO network, NO randomness: the outcome is a pure function
 * of the request, so tests and the offline smoke are reproducible.
 *
 * It simulates all eight modes the doc-gate (zernio-notes.md) requires. A normal
 * account publishes successfully; the failure/async modes are provoked by a
 * marker in the handle, so a single provider can exercise every path without a
 * configuration switch:
 *
 *   handle contains 'reject'    → platform rejection (terminal)
 *   handle contains 'authfail'  → auth failure       (terminal + account reauth)
 *   handle contains 'ratelimit' → 429 throttle       (transient, job backs off)
 *   handle contains 'timeout'   → transport timeout  (transient, throws)
 *   handle contains 'async'     → accepted; live state confirmed by webhook or
 *                                 the reconciliation poll (status())
 *   otherwise                   → published live now
 *
 * external_post_id is derived from the idempotency key (stable across retries
 * and webhook deliveries), so duplicate-webhook + reconciliation tests can
 * address the same post deterministically.
 */
final class MockPublishProvider implements PublishProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        $handle = strtolower($request->handle);
        $postId = self::postId($request->idempotencyKey);

        if (str_contains($handle, 'timeout')) {
            throw new PublishProviderException('mock: transport timeout publishing to ' . $request->platform);
        }
        if (str_contains($handle, 'authfail')) {
            return PublishOutcome::authFailed('account authorization expired (mock 401)');
        }
        if (str_contains($handle, 'ratelimit')) {
            return PublishOutcome::rateLimited('rate limited (mock 429)');
        }
        if (str_contains($handle, 'reject')) {
            return PublishOutcome::rejected('platform rejected the media (mock policy violation)');
        }
        if (str_contains($handle, 'async')) {
            // provider accepted it; the live state arrives via webhook, or the
            // reconciler polls status() if that webhook is lost.
            return PublishOutcome::accepted($postId);
        }

        return PublishOutcome::published($postId, self::url($request->platform, $postId));
    }

    /**
     * Deterministic mock account list (no network): one active account per
     * platform with a stable, format-valid id, so the connect/sync flow resolves
     * offline. Mirrors the real adapter's vendor-neutral shape.
     *
     * @return list<array{external_ref: string, platform: string, username: string, display_name: string, active: bool}>
     */
    public function accounts(?string $platform = null): array
    {
        $out = [];
        foreach (AccountRepository::PLATFORMS as $p) {
            if ($platform !== null && $platform !== $p) {
                continue;
            }
            $out[] = [
                'external_ref' => substr(hash('sha256', 'mockacct|' . $p), 0, 24),
                'platform' => $p,
                'username' => 'demo_' . $p,
                'display_name' => 'Demo ' . ucfirst($p),
                'active' => true,
            ];
        }

        return $out;
    }

    /**
     * Deterministic mock audience + per-post engagement (no network). Exercises
     * the FULL metrics path — follower AND per-post engagement — so the snapshot
     * chore and its storage are covered offline. Values are a pure function of
     * the account, so re-running produces identical rows.
     *
     * @return list<array{external_ref: string, platform: string, username: string, followers: int|null, has_analytics: bool, posts: list<array{external_post_id: string, views: int|null, likes: int|null, comments: int|null, shares: int|null}>, raw: array<string, mixed>}>
     */
    public function accountMetrics(?string $platform = null, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach ($this->accounts($platform) as $a) {
            $seed = crc32('mockmetrics|' . $a['external_ref']);
            $posts = [];
            for ($i = 0; $i < 3; $i++) {
                $s = crc32('mockpost|' . $a['external_ref'] . '|' . $i);
                $posts[] = [
                    'external_post_id' => 'zp_' . substr(hash('sha256', $a['external_ref'] . '|' . $i), 0, 16),
                    'views' => 400 + ($s % 9_600),
                    'likes' => 20 + (($s >> 4) % 900),
                    'comments' => 1 + (($s >> 8) % 120),
                    'shares' => ($s >> 12) % 240,
                ];
            }
            $out[] = [
                'external_ref' => $a['external_ref'],
                'platform' => $a['platform'],
                'username' => $a['username'],
                'followers' => 900 + ($seed % 24_000),
                'has_analytics' => true,
                'posts' => $posts,
                'raw' => ['source' => 'mock', 'window' => ['from' => $from, 'to' => $to]],
            ];
        }

        return $out;
    }

    public function status(string $externalPostId): PublishOutcome
    {
        // an accepted post converges to live on poll (the lost-webhook path);
        // platform/derived from the id so the url is stable.
        $platform = str_contains($externalPostId, 'tiktok') ? 'tiktok'
            : (str_contains($externalPostId, 'youtube') ? 'youtube' : 'instagram');

        return PublishOutcome::published($externalPostId, self::url($platform, $externalPostId));
    }

    private static function postId(string $idempotencyKey): string
    {
        return 'zp_' . substr(hash('sha256', $idempotencyKey), 0, 16);
    }

    private static function url(string $platform, string $postId): string
    {
        return 'https://' . $platform . '.example/p/' . $postId;
    }
}
