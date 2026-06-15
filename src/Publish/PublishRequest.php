<?php

declare(strict_types=1);

namespace Kuyash\Publish;

/**
 * The ONE internal shape a publish request takes across the provider seam
 * (adapter rule: core builds this, the adapter translates it into the vendor's
 * payload — core never speaks vendor). Carries no tokens: the account is
 * identified by its Zernio reference (externalRef), whose OAuth credentials
 * live at Zernio, never here.
 */
final class PublishRequest
{
    /**
     * @param non-empty-string      $platform        instagram | tiktok | youtube
     * @param string                $handle          the account handle (display + mock routing)
     * @param ?string               $externalRef     Zernio account id (null only on a half-connected account)
     * @param string                $idempotencyKey  per-(run,account) key — dedup at the vendor too
     * @param bool                  $aiLabelApplied  set the platform AI label/content flag (truthful: only when required)
     * @param ?string               $scheduledFor    ISO-8601 UTC publish time, or null = now
     * @param ?int                  $renderId        the final render to publish (null in distribution = library asset)
     * @param string                $caption         per-platform caption (already variation-controlled + AI-disclosure-resolved upstream)
     * @param list<string>          $hashtags        per-platform hashtags
     * @param int                   $workspaceId     tenant scope — the real adapter needs it to resolve the render file (mock ignores it)
     */
    public function __construct(
        public readonly string $platform,
        public readonly string $handle,
        public readonly ?string $externalRef,
        public readonly string $idempotencyKey,
        public readonly bool $aiLabelApplied = false,
        public readonly ?string $scheduledFor = null,
        public readonly ?int $renderId = null,
        public readonly string $caption = '',
        public readonly array $hashtags = [],
        public readonly int $workspaceId = 0,
    ) {
    }
}
