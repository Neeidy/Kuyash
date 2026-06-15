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
}
