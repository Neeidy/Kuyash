<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * The AI image-to-video seam (Phase 12). MockVideoGenProvider (DEFAULT —
 * animates a still into a real 9:16 clip with ffmpeg, offline) and
 * FalVideoGenProvider (real, behind a flag — doc-gated stub) implement this;
 * AiVideoExecutor depends only on this interface (adapter rule: core never
 * references a vendor; swapping = one adapter class + one config line).
 *
 * generateFromImage() MUST write a 9:16 video clip of roughly $durationSeconds,
 * built from $imagePath under the guidance of $prompt, to $targetPath, and
 * return a VideoResult describing it. Failures throw VideoGenProviderException
 * with a sanitized message (no key/headers).
 *
 * V1 is image-to-video ONLY (single provider, credit-gated): no text-to-video,
 * no avatars, no async submit/poll — the mock is synchronous and the real
 * provider stays doc-gated until its aggregator docs + creds exist.
 */
interface VideoGenProvider
{
    public function generateFromImage(
        string $imagePath,
        string $prompt,
        float $durationSeconds,
        string $targetPath,
    ): VideoResult;

    /** File extension this provider writes (e.g. 'mp4') — drives the cache name. */
    public function clipExtension(): string;

    /** Provider tag recorded on the job/cache/ledger ('mock' | 'fal'). */
    public function name(): string;
}
