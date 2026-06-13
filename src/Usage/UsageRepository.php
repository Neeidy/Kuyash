<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Core\Database;

/**
 * Read model over usage_events — the single source of truth for spend. Every
 * query is workspace-scoped (tenant isolation at the query level). The
 * month-to-date window matches the Phase 9 gate exactly: events from the first
 * of the current UTC month, inclusive.
 */
final class UsageRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** First instant of $now's UTC month, e.g. '2026-06-12T…' → '2026-06-01T00:00:00Z'. */
    public static function monthStart(string $now): string
    {
        return substr($now, 0, 7) . '-01T00:00:00Z';
    }

    /** Month-to-date observed spend in cents (the enforced budget-cap basis). */
    public function monthToDateSpendCents(int $workspaceId, string $now): int
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(cost_cents), 0) AS spent FROM usage_events
             WHERE workspace_id = ? AND created_at >= ?',
            [$workspaceId, self::monthStart($now)],
        );

        return (int) ($row['spent'] ?? 0);
    }

    /**
     * Month-to-date spend grouped by category (cents), descending. Categories
     * with no spend are omitted.
     *
     * @return array<string, int>
     */
    public function monthToDateByCategory(int $workspaceId, string $now): array
    {
        $rows = $this->db->all(
            'SELECT category, COALESCE(SUM(cost_cents), 0) AS spent FROM usage_events
             WHERE workspace_id = ? AND created_at >= ?
             GROUP BY category ORDER BY spent DESC',
            [$workspaceId, self::monthStart($now)],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['category']] = (int) $row['spent'];
        }

        return $out;
    }

    /** Total recorded usage_events this month (for the empty-state distinction). */
    public function monthToDateEventCount(int $workspaceId, string $now): int
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS n FROM usage_events
             WHERE workspace_id = ? AND created_at >= ?',
            [$workspaceId, self::monthStart($now)],
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Most recent charges (newest first) for the page's charge list.
     *
     * @return list<array<string, mixed>>
     */
    public function recentCharges(int $workspaceId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT id, run_id, job_id, provider, category, model, units, unit_type, cost_cents, created_at
             FROM usage_events WHERE workspace_id = ?
             ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$workspaceId],
        );
    }
}
