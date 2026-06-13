<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Kuyash\Core\Database;
use Kuyash\Usage\UsageRepository;
use Kuyash\Workflow\EventLog;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * The Auto-mode decision point, consulted by Engine::finalizeAwaiting() for
 * every render_review pause — INSIDE the finalize transaction (every read is
 * local indexed SQLite; the short-transaction rule holds: no external calls).
 *
 * Ordered rules (first match wins):
 *   1. workspace mode ≠ auto            → manual, silent (the default path)
 *   2. kill switch ON                   → manual + guardrail.kill_switch event
 *   3. compliance not pass/pass+label   → manual, silent (warn/block never auto)
 *   4. daily auto-approval cap reached  → manual + guardrail.daily_cap_reached
 *   5. month budget cap reached         → manual + guardrail.budget_cap_reached
 *   6. quality-score breach             → FLIP workspace to Manual (persisted,
 *      audited via guardrail.fallback_to_manual) + manual. Re-enabling Auto is
 *      a human act in /settings.
 *
 * Deny events are written here (same transaction as the pause they explain);
 * the approving branch only RETURNS — the Engine owns the job/approval writes.
 * Count helpers take (?int $accountId = null): the Phase 10 per-account seam.
 */
final class AutoApprovalGate
{
    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
        private readonly WorkspaceSettings $settings,
        private readonly QualityScore $quality,
        private readonly UsageRepository $usage,
    ) {
    }

    /**
     * @param array<string, mixed> $job the render_review job row being finalized
     */
    public function evaluate(array $job, string $now): GateDecision
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $jobId = (int) $job['id'];
        $settings = $this->settings->compliance($wsId);

        if ($settings['approval_mode'] !== 'auto') {
            return GateDecision::manual(GateDecision::PATH_MANUAL_MODE);
        }

        if ($settings['kill_switch']) {
            $this->events->record($wsId, 'warn', 'guardrail', 'guardrail.kill_switch', [
                'run' => $runId,
            ], $runId, $jobId);

            return GateDecision::manual(GateDecision::PATH_KILL_SWITCH);
        }

        $compliance = $this->complianceResult($wsId, $runId);
        if ($compliance === null) {
            return GateDecision::manual(GateDecision::PATH_NO_COMPLIANCE); // fail closed
        }
        $status = (string) ($compliance['status'] ?? '');
        if (!in_array($status, CompliancePolicy::AUTO_APPROVABLE, true)) {
            return GateDecision::manual(GateDecision::PATH_NOT_CLEAN);
        }

        $used = $this->autoApprovalsToday($wsId, $now);
        if ($used >= $settings['daily_post_cap']) {
            $this->events->record($wsId, 'warn', 'guardrail', 'guardrail.daily_cap_reached', [
                'run' => $runId,
                'used' => $used,
                'cap' => $settings['daily_post_cap'],
            ], $runId, $jobId);

            return GateDecision::manual(GateDecision::PATH_DAILY_CAP);
        }

        if ($settings['budget_cap_cents'] !== null) {
            $spent = $this->monthToDateSpendCents($wsId, $now);
            if ($spent >= $settings['budget_cap_cents']) {
                $this->events->record($wsId, 'warn', 'guardrail', 'guardrail.budget_cap_reached', [
                    'run' => $runId,
                    'spent_cents' => $spent,
                    'cap_cents' => $settings['budget_cap_cents'],
                ], $runId, $jobId);

                return GateDecision::manual(GateDecision::PATH_BUDGET_CAP);
            }
        }

        $quality = $this->quality->compute($wsId);
        if ($quality['breach']) {
            // the one persisted state change: auto-fallback to Manual
            $this->settings->setApprovalMode($wsId, 'manual');
            $this->events->record($wsId, 'warn', 'guardrail', 'guardrail.fallback_to_manual', [
                'run' => $runId,
                'score' => $quality['score'],
                'threshold' => CompliancePolicy::QUALITY_BREACH_BELOW,
                'sample' => $quality['sample'],
            ], $runId, $jobId);

            return GateDecision::manual(GateDecision::PATH_QUALITY_BREACH);
        }

        return GateDecision::auto(CompliancePolicy::VERSION, [
            'quality' => $quality,
            'compliance' => [
                'status' => $status,
                'policy' => (string) ($compliance['policy'] ?? CompliancePolicy::VERSION),
                'slop' => $compliance['checks']['slop']['score'] ?? null,
            ],
        ]);
    }

    /** This run's completed compliance_check result, or null (fail closed). */
    private function complianceResult(int $wsId, int $runId): ?array
    {
        $row = $this->db->one(
            "SELECT result_json FROM jobs
             WHERE workspace_id = ? AND run_id = ? AND type = 'compliance_check'
               AND status = 'ready' AND result_json IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [$wsId, $runId],
        );
        if ($row === null) {
            return null;
        }
        $result = json_decode((string) $row['result_json'], true);

        return is_array($result) ? $result : null;
    }

    /** Auto-approvals recorded today (UTC). $accountId is the Phase 10 seam. */
    public function autoApprovalsToday(int $workspaceId, string $now, ?int $accountId = null): int
    {
        $dayStart = substr($now, 0, 10) . 'T00:00:00Z';
        $nextDay = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($dayStart) + 86400);
        $row = $this->db->one(
            "SELECT COUNT(*) AS n FROM approvals
             WHERE workspace_id = ? AND mode = 'auto' AND decision = 'approved'
               AND decided_at >= ? AND decided_at < ?",
            [$workspaceId, $dayStart, $nextDay],
        );

        return (int) ($row['n'] ?? 0);
    }

    /**
     * Month-to-date observed spend. Phase 11 re-points this from
     * SUM(jobs.cost_cents) to the usage_events ledger — the single source of
     * truth — via UsageRepository. Behaviour is unchanged (parity test pins it):
     * jobs.cost_cents stays as the per-job display rollup. $accountId is the
     * Phase 10 seam (per-account budgeting is not in V1 scope).
     */
    public function monthToDateSpendCents(int $workspaceId, string $now, ?int $accountId = null): int
    {
        return $this->usage->monthToDateSpendCents($workspaceId, $now);
    }
}
