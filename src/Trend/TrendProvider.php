<?php

declare(strict_types=1);

namespace Kuyash\Trend;

/**
 * The trend-discovery seam. MockTrendProvider (default) and the real adapters
 * (YouTubeTrendsProvider, GoogleTrendsProvider — behind a flag, default OFF)
 * implement this; TrendService depends only on this interface, so swapping
 * providers is one config line (adapter rule).
 *
 * fetch() returns a list of TrendResult ordered best-first. A provider throws
 * TrendProviderException on an unrecoverable failure (its message is already
 * sanitized — never an API key, header, or raw payload). The caller decides
 * what a failure means (serve stale cache / degrade) — fetch() never blocks.
 */
interface TrendProvider
{
    /**
     * @return list<TrendResult> best-first, at most $limit entries
     *
     * @throws TrendProviderException
     */
    public function fetch(string $niche, string $region, int $limit): array;

    /**
     * The provider tag recorded on cached rows and jobs
     * ('mock' | 'youtube' | 'google_trends'). Lets vendor-blind callers label a
     * source/failure correctly without naming a vendor themselves.
     */
    public function name(): string;
}
