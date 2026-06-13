<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Guardrail enforcement point B: wraps the publish executor (mock in Phase 9;
 * Phase 10 swaps the INNER executor for Zernio — this gate survives the swap).
 *
 * Only AUTO-APPROVED runs are gated — guardrails constrain autonomy, never a
 * human decision (a manually approved publish always passes through):
 *   - kill switch ON          → defer (short re-check; flipping it back off
 *                               releases the queue within minutes)
 *   - daily post cap reached  → defer to the next UTC midnight
 *
 * A deferral is a HALT, not a failure: JobResult::deferred() sends the job
 * back to 'queued' with a future run_after and NO retry_count increment.
 */
final class PublishGateExecutor implements JobExecutor
{
    private const KILL_SWITCH_RECHECK_S = 300;

    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly JobExecutor $inner,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    public function execute(array $job, array $prior): JobResult
    {
        if ((string) $job['type'] !== 'publish') {
            return $this->inner->execute($job, $prior);
        }

        $wsId = (int) $job['workspace_id'];
        if (!$this->runWasAutoApproved($wsId, (int) $job['run_id'])) {
            return $this->inner->execute($job, $prior); // human-approved: no autonomy to constrain
        }

        $ws = $this->db->one(
            'SELECT kill_switch, daily_post_cap FROM workspaces WHERE id = ?',
            [$wsId],
        );
        if ($ws === null) {
            return JobResult::failed('publish gate: workspace not found', 'kuyash-compliance');
        }

        if ((int) $ws['kill_switch'] === 1) {
            return JobResult::deferred('kill_switch', self::KILL_SWITCH_RECHECK_S);
        }

        $now = ($this->clock)();
        $published = $this->publishedToday($wsId, $now);
        if ($published >= (int) $ws['daily_post_cap']) {
            return JobResult::deferred('daily_cap', $this->secondsToNextUtcMidnight($now));
        }

        return $this->inner->execute($job, $prior);
    }

    private function runWasAutoApproved(int $wsId, int $runId): bool
    {
        return $this->db->one(
            "SELECT id FROM approvals
             WHERE workspace_id = ? AND run_id = ? AND mode = 'auto' AND decision = 'approved'",
            [$wsId, $runId],
        ) !== null;
    }

    /** Posts that actually went out today (UTC). $accountId = Phase 10 seam. */
    public function publishedToday(int $workspaceId, string $now, ?int $accountId = null): int
    {
        $dayStart = substr($now, 0, 10) . 'T00:00:00Z';
        $nextDay = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($dayStart) + 86400);
        $row = $this->db->one(
            "SELECT COUNT(*) AS n FROM jobs
             WHERE workspace_id = ? AND type = 'publish' AND status = 'published'
               AND finished_at >= ? AND finished_at < ?",
            [$workspaceId, $dayStart, $nextDay],
        );

        return (int) ($row['n'] ?? 0);
    }

    private function secondsToNextUtcMidnight(string $now): int
    {
        $ts = (int) strtotime($now);
        $midnight = (int) strtotime(substr($now, 0, 10) . 'T00:00:00Z') + 86400;

        return max(60, $midnight - $ts);
    }
}
