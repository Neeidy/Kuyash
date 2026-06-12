<?php

declare(strict_types=1);

namespace Kuyash\Trend;

/**
 * The single shape that crosses the TrendProvider seam (adapter rule: vendors
 * translate their responses into THIS, core never sees a vendor payload).
 *
 * - topic: the trend phrase (sanitized; safe to render/store)
 * - score: 0..100 relative interest (rank-derived for sources without a metric)
 * - source: 'mock' | 'youtube' | 'google_trends'
 * - format: 'face' | 'faceless' — the recommended production format
 * - raw: small sanitized vendor metadata (channel, traffic, video id) for audit
 */
final class TrendResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $topic,
        public readonly int $score,
        public readonly string $source,
        public readonly string $niche,
        public readonly string $region,
        public readonly string $format,
        public readonly array $raw = [],
    ) {
    }
}
