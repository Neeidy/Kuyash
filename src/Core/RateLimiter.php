<?php

declare(strict_types=1);

namespace Kuyash\Core;

use Closure;

/**
 * Generic trailing-window rate limiter over the rate_limits table (the same
 * count-in-a-window pattern as Auth\LoginThrottle, generalized to any logical
 * bucket). A (bucket, ip) pair is "over the limit" when it has recorded
 * >= $maxHits hits within the last $windowSeconds.
 *
 * Built for the unauthenticated POST /webhooks/zernio endpoint (Phase 10 LOW
 * follow-up): the HMAC check + 64 KiB body cap bound per-request cost, this
 * bounds request FREQUENCY so a flood of bogus deliveries can't pin the box.
 * The cap is deliberately GENEROUS — a real Zernio webhook never bursts near
 * it; tune it down once the live sending rate is known (production-readiness.md).
 *
 * Rows older than $retentionSeconds are pruned opportunistically on each call
 * (no cron in V1). Not tenant data — no workspace_id.
 */
final class RateLimiter
{
    /** @var Closure(): int epoch-seconds clock (injectable for deterministic tests) */
    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly int $maxHits,
        private readonly int $windowSeconds,
        private readonly int $retentionSeconds = 86400,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Record one hit for (bucket, ip) and report whether the limit was already
     * exceeded BEFORE this hit (so the Nth+1 request in the window is blocked).
     * The hit is recorded even when over the limit, so a sustained flood stays
     * blocked rather than draining out of the window.
     */
    public function tooMany(string $bucket, string $ip): bool
    {
        $now = ($this->clock)();

        // opportunistic retention cleanup — no cron in V1
        $this->db->run(
            'DELETE FROM rate_limits WHERE hit_at < ?',
            [$this->iso($now - $this->retentionSeconds)],
        );

        $row = $this->db->one(
            'SELECT COUNT(*) AS n FROM rate_limits WHERE bucket = ? AND ip = ? AND hit_at >= ?',
            [$bucket, $ip, $this->iso($now - $this->windowSeconds)],
        );
        $count = (int) ($row['n'] ?? 0);

        $this->db->run(
            'INSERT INTO rate_limits (bucket, ip, hit_at) VALUES (?, ?, ?)',
            [$bucket, $ip, $this->iso($now)],
        );

        return $count >= $this->maxHits;
    }

    /** ISO-8601 UTC — lexicographic comparison equals chronological. */
    private function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
