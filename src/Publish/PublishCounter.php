<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;

/**
 * The single source of truth for "posts that went out today" (UTC) — the
 * per-account daily-cap counter that unifies the two Phase-9 count points. Both
 * the publish gate (per-account, at the publish site) and any read-side display
 * call THIS instead of each rolling its own SQL. Counts the truthful target
 * table (`posts`), not jobs: one published post = one thing that actually went
 * live to one account.
 *
 * $accountId null = workspace-wide (every account); set = that one account.
 */
final class PublishCounter
{
    public function __construct(private readonly Database $db)
    {
    }

    public function publishedToday(int $workspaceId, string $now, ?int $accountId = null): int
    {
        $dayStart = substr($now, 0, 10) . 'T00:00:00Z';
        $nextDay = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($dayStart) + 86400);

        $sql = "SELECT COUNT(*) AS n FROM posts
                WHERE workspace_id = ? AND status = 'published'
                  AND posted_at >= ? AND posted_at < ?";
        $params = [$workspaceId, $dayStart, $nextDay];
        if ($accountId !== null) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }

        return (int) ($this->db->one($sql, $params)['n'] ?? 0);
    }
}
