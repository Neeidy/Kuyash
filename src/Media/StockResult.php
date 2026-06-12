<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Facts about the stock clip a provider just wrote. costCents is null for mock
 * (and for Pexels, which is free — quota is tracked separately). meta carries
 * small sanitized provenance (e.g. the Pexels video id / author).
 */
final class StockResult
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly float $durationSeconds,
        public readonly ?int $costCents = null,
        public readonly array $meta = [],
    ) {
    }
}
