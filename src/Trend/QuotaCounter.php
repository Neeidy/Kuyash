<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Core\Database;

/**
 * Daily API quota accounting for rate-limited providers (Phase 5 follow-up).
 * Phase 6 only RECORDS units per provider per UTC day — budget caps and
 * enforcement are Phase 11. Mock providers are never recorded (mock work is
 * never real spend); TrendService skips the record() call for them.
 *
 * Scoped by raw workspace_id (worker-safe). usage_date is the 'YYYY-MM-DD'
 * prefix of the caller's ISO clock, so tests with a fixed clock are deterministic.
 */
final class QuotaCounter
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(int $workspaceId, string $provider, int $units, string $nowIso): void
    {
        if ($units <= 0) {
            return;
        }

        $this->db->run(
            'INSERT INTO api_quota_usage (workspace_id, provider, usage_date, units, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (workspace_id, provider, usage_date)
             DO UPDATE SET units = units + excluded.units, updated_at = excluded.updated_at',
            [$workspaceId, $provider, substr($nowIso, 0, 10), $units, $nowIso],
        );
    }

    public function usageFor(int $workspaceId, string $provider, string $date): int
    {
        $row = $this->db->one(
            'SELECT units FROM api_quota_usage WHERE workspace_id = ? AND provider = ? AND usage_date = ?',
            [$workspaceId, $provider, $date],
        );

        return $row === null ? 0 : (int) $row['units'];
    }

    /**
     * All providers' usage for one UTC day, highest-first (dashboard/UI).
     *
     * @return list<array{provider: string, units: int}>
     */
    public function totalsForDay(int $workspaceId, string $date): array
    {
        return array_map(
            static fn (array $r): array => ['provider' => (string) $r['provider'], 'units' => (int) $r['units']],
            $this->db->all(
                'SELECT provider, units FROM api_quota_usage
                 WHERE workspace_id = ? AND usage_date = ?
                 ORDER BY units DESC, provider ASC',
                [$workspaceId, $date],
            ),
        );
    }
}
