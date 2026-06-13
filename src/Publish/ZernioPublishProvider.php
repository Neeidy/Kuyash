<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Http\HttpClient;

/**
 * Real Zernio adapter — a DOC-GATED flag-off STUB. It is constructed only when
 * ZERNIO_MOCK=false (see bindings/core.php), but it still makes NO live call:
 * every method throws "doc-gated" because none of the 12 required items in
 * .claude/docs/zernio-notes.md (auth, endpoints, payloads, webhook signature,
 * rate limits, …) are supplied. The HttpClient seam is held so that wiring the
 * real calls later is one class, not a re-architecture — but the throw happens
 * BEFORE the transport is ever touched (integration rule: never hallucinate an
 * external API; if docs are unknown, the integration stays blocked).
 */
final class ZernioPublishProvider implements PublishProvider
{
    private const GATED = 'Zernio integration is doc-gated: real publishing is BLOCKED until the 12 '
        . 'items in .claude/docs/zernio-notes.md are supplied. Set ZERNIO_MOCK=true.';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'zernio';
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        throw new PublishProviderException(self::GATED);
    }

    public function status(string $externalPostId): PublishOutcome
    {
        throw new PublishProviderException(self::GATED);
    }
}
