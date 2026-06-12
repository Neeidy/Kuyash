<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Closure;

/**
 * Orchestrates the trend cache: read-through with a TTL, refresh on expiry,
 * degrade gracefully on provider failure, and record quota for real providers.
 * Used by BOTH the web page (TrendController) and the worker (TrendExecutor) —
 * one place owns the freshness/degradation policy, scoped by a raw workspace_id.
 *
 * Policy (never blocks the pipeline):
 *  - cache within TTL → serve it (fresh), no provider call.
 *  - cache expired / forced / cold → call the provider:
 *      success → replace cache, record quota (real only), serve fresh.
 *      failure → serve the last cached batch as STALE, or empty + error.
 */
final class TrendService
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    private readonly Closure $clock;

    /**
     * @param array<string, mixed> $config trends config (cache_ttl_seconds, limit, quota_units)
     */
    public function __construct(
        private readonly TrendProvider $provider,
        private readonly TrendRepository $repo,
        private readonly QuotaCounter $quota,
        private readonly array $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate(self::ISO);
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    /**
     * The trend feed for (workspace, niche, region). $force bypasses the TTL
     * (the "Refresh" action) but still degrades to stale cache on failure.
     */
    public function feed(int $workspaceId, string $niche, string $region, bool $force = false): TrendFeed
    {
        $now = ($this->clock)();
        $ttl = max(0, (int) ($this->config['cache_ttl_seconds'] ?? 21600));
        $cached = $this->repo->cached($workspaceId, $niche, $region);

        if (!$force && $cached !== [] && $this->withinTtl((string) $cached[0]['fetched_at'], $now, $ttl)) {
            return new TrendFeed($cached, TrendFeed::FRESH, (string) $cached[0]['fetched_at'], (string) $cached[0]['source']);
        }

        try {
            $limit = max(1, (int) ($this->config['limit'] ?? 8));
            $results = $this->provider->fetch($niche, $region, $limit);
        } catch (TrendProviderException $e) {
            if ($cached !== []) {
                // honest degradation: last-known data, flagged stale, with the reason
                return new TrendFeed($cached, TrendFeed::STALE, (string) $cached[0]['fetched_at'], (string) $cached[0]['source'], $e->getMessage());
            }

            // cold cache + provider down: empty, with the reason (never blocks)
            return new TrendFeed([], TrendFeed::EMPTY, null, $this->provider->name(), $e->getMessage());
        }

        $this->repo->replace($workspaceId, $niche, $region, $results, $now);
        $this->recordQuota($workspaceId, $now);

        return new TrendFeed($this->repo->cached($workspaceId, $niche, $region), TrendFeed::FRESH, $now, $this->provider->name());
    }

    private function recordQuota(int $workspaceId, string $now): void
    {
        $name = $this->provider->name();
        if ($name === 'mock') {
            return; // mock work is never recorded against quota
        }

        $units = (int) (($this->config['quota_units'] ?? [])[$name] ?? 0);
        $this->quota->record($workspaceId, $name, $units, $now);
    }

    private function withinTtl(string $fetchedAt, string $now, int $ttlSeconds): bool
    {
        $then = strtotime($fetchedAt);
        $current = strtotime($now);
        if ($then === false || $current === false) {
            return false; // unparsable clock → treat as expired, never as fresh
        }

        return ($current - $then) < $ttlSeconds;
    }
}
