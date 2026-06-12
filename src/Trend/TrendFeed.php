<?php

declare(strict_types=1);

namespace Kuyash\Trend;

/**
 * What TrendService hands back: the trend rows plus an HONEST freshness signal.
 * The UI renders freshness verbatim — cached data is never shown as live.
 *
 * - items: display rows (id, topic, score, format, source, niche, region, raw)
 * - freshness: 'fresh' (within TTL or just fetched) | 'stale' (provider failed,
 *   serving last-known cache) | 'empty' (no cache and the provider failed)
 * - fetchedAt: ISO timestamp of the served batch, or null when empty
 * - source: the provider tag the rows came from
 * - error: a sanitized provider-failure reason when degraded, else null
 */
final class TrendFeed
{
    public const FRESH = 'fresh';
    public const STALE = 'stale';
    public const EMPTY = 'empty';

    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly string $freshness,
        public readonly ?string $fetchedAt,
        public readonly string $source,
        public readonly ?string $error = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
