<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\PublishCounter;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Guardrail enforcement point B: wraps the publish executor (Phase 10's
 * ZernioPublishExecutor; the gate survives the inner swap). Only AUTO-APPROVED
 * runs are gated — guardrails constrain autonomy, never a human decision (a
 * manually approved publish always passes straight through):
 *   - kill switch ON          → defer (short re-check; flipping it off releases
 *                               the queue within minutes)
 *   - per-account daily cap    → defer to the next UTC midnight when ANY connected
 *                               target account already hit its cap. A run
 *                               distributes to all targets as a unit; deferring
 *                               the whole job is safe because the publish
 *                               executor is idempotent per (run,account) — when
 *                               the job later runs, already-published targets are
 *                               skipped. (Per-account subset scheduling = follow-up.)
 *
 * The per-account count comes from the unified PublishCounter (the `posts`
 * table) — the single source of truth shared with the publish site. A deferral
 * is a HALT, not a failure: JobResult::deferred() returns the job to 'queued'
 * with a future run_after and NO retry_count increment.
 */
final class PublishGateExecutor implements JobExecutor
{
    private const KILL_SWITCH_RECHECK_S = 300;

    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly JobExecutor $inner,
        private readonly PublishCounter $counter,
        private readonly AccountRepository $accounts,
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
        $cap = (int) $ws['daily_post_cap'];
        foreach ($this->accounts->connectedFor($wsId) as $account) {
            if ($this->counter->publishedToday($wsId, $now, (int) $account['id']) >= $cap) {
                return JobResult::deferred('daily_cap', $this->secondsToNextUtcMidnight($now));
            }
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

    private function secondsToNextUtcMidnight(string $now): int
    {
        $ts = (int) strtotime($now);
        $midnight = (int) strtotime(substr($now, 0, 10) . 'T00:00:00Z') + 86400;

        return max(60, $midnight - $ts);
    }
}
