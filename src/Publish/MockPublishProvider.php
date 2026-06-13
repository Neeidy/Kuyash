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
