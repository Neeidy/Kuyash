<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * The stock-visual seam. MockStockProvider (DEFAULT — generates a real 9:16
 * ffmpeg clip offline) and PexelsStockProvider (real, behind a flag) implement
 * this; AssetFetchExecutor depends only on this interface (adapter rule).
 *
 * fetchClip() MUST write a 9:16 video clip of roughly $durationSeconds to
 * $targetPath and return a StockResult describing it. Failures throw
 * StockProviderException with a sanitized message (no key/headers).
 */
interface StockProvider
{
    public function fetchClip(string $query, float $durationSeconds, string $targetPath): StockResult;

    /** File extension this provider writes (e.g. 'mp4') — drives the cache name. */
    public function clipExtension(): string;

    /** Provider tag recorded on the job/cache ('mock' | 'pexels'). */
    public function name(): string;
}
