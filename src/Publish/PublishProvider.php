<?php

declare(strict_types=1);

namespace Kuyash\Publish;

/**
 * The publishing seam. MockPublishProvider (default) and ZernioPublishProvider
 * (real, doc-gated flag-off stub) implement this; ZernioPublishExecutor and the
 * Reconciler depend ONLY on this interface, so swapping providers is one config
 * line (adapter rule). Core never names a vendor.
 */
interface PublishProvider
{
    /**
     * Publish one target (one account). Returns a PublishOutcome (live / async /
     * rejected / auth-failed / rate-limited).
     *
     * @throws PublishProviderException on a TRANSIENT transport failure (retryable)
     */
    public function publish(PublishRequest $request): PublishOutcome;

    /**
     * Poll the current state of a previously-accepted post (reconciliation path,
     * when no webhook arrived). Converges an in-flight post to published/failed.
     *
     * @throws PublishProviderException on a transient transport failure
     */
    public function status(string $externalPostId): PublishOutcome;

    /** Provider tag recorded on the job/post ('mock' | 'zernio'). */
    public function name(): string;

    /**
     * The provider's connected social accounts (read-only). Lets the connect/sync
     * flow resolve a local account to its REAL provider account id — the value
     * publish() expects as the target (`accountId`). Vendor-neutral shape.
     *
     * @return list<array{external_ref: string, platform: string, username: string, display_name: string, active: bool}>
     * @throws PublishProviderException on a transient transport failure
     */
    public function accounts(?string $platform = null): array;

    /**
     * READ-ONLY audience + per-post engagement for the connected accounts. Costs
     * nothing (no generation, no publish) — the daily snapshot chore calls it.
     *
     * TRUTHFULNESS CONTRACT (compliance): every metric is nullable and null means
     * "the provider did not report it" — never zero-as-a-guess. `posts` is an
     * EMPTY list when the provider has no per-post analytics yet; callers must
     * render that as an honest empty state, never as fabricated engagement.
     * `raw` carries the provider's own payload verbatim so a metric that exists
     * but is not yet mapped is preserved rather than silently dropped.
     *
     * @param string|null $from ISO date (YYYY-MM-DD) window start, null = provider default
     * @param string|null $to   ISO date (YYYY-MM-DD) window end, null = provider default
     *
     * @return list<array{
     *     external_ref: string,
     *     platform: string,
     *     username: string,
     *     followers: int|null,
     *     has_analytics: bool,
     *     posts: list<array{external_post_id: string, views: int|null, likes: int|null, comments: int|null, shares: int|null}>,
     *     raw: array<string, mixed>
     * }>
     * @throws PublishProviderException on a transient transport failure
     */
    public function accountMetrics(?string $platform = null, ?string $from = null, ?string $to = null): array;
}
