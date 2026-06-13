<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * One internal shape every VideoGenProvider translates its vendor response into
 * (adapter rule: core never sees a vendor payload). Describes the produced
 * image-to-video clip on disk.
 *
 * costCents is null for the mock and for anything that did not actually spend
 * (truthful spend — mirrors StockResult/TtsResult): only a real provider call
 * that incurred money sets it.
 */
final class VideoResult
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly float $durationSeconds,
        public readonly ?int $costCents,
        public readonly string $model,
        public readonly array $meta = [],
    ) {
    }
}
