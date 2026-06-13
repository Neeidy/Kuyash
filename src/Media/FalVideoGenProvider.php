<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Http\HttpClient;

/**
 * Real AI image-to-video adapter — a DOC-GATED flag-off STUB (mirrors
 * ZernioPublishProvider). Constructed only when VIDEO_MOCK=false + a key is set
 * (see bindings/core.php), but it still makes NO live call: generateFromImage()
 * throws "doc-gated" BEFORE the transport is ever touched, because the items in
 * .claude/docs/ai-video-notes.md (aggregator endpoints, image-upload + submit +
 * poll payloads, model id, pricing-per-second, output-fetch, error/format) are
 * not supplied (integration rule: never hallucinate an external API; if docs are
 * unknown, the integration stays blocked).
 *
 * The HttpClient seam is held so wiring the real fal.ai-class aggregator later is
 * one class + one config line, not a re-architecture. Real generation is
 * minutes-long (submit/poll) — that async machinery is intentionally out of V1;
 * the mock is synchronous. fal.ai chosen as the candidate aggregator to avoid
 * vendor lock-in (Kling/Sora/Veo/Wan behind one API).
 */
final class FalVideoGenProvider implements VideoGenProvider
{
    private const GATED = 'AI image-to-video is doc-gated: real generation is BLOCKED until the items in '
        . '.claude/docs/ai-video-notes.md (endpoints, submit/poll payloads, model, per-second pricing, '
        . 'output fetch) are supplied. Set VIDEO_MOCK=true.';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'fal';
    }

    public function clipExtension(): string
    {
        return 'mp4';
    }

    public function generateFromImage(
        string $imagePath,
        string $prompt,
        float $durationSeconds,
        string $targetPath,
    ): VideoResult {
        throw new VideoGenProviderException(self::GATED);
    }
}
