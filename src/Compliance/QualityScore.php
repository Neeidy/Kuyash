<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Closure;
use Kuyash\Core\Database;

/**
 * Derive-only quality read-model (NOT persisted — its inputs already live in
 * jobs/approvals; the only persisted state change is the breach FLIP to Manual,
 * which the AutoApprovalGate writes and audits):
 *
 *   risk  = 0.40·slop_avg(last 20 checks)
 *         + 0.35·block_rate(last 20 checks)
 *         + 0.25·reject_fail_rate(7d rejected reviews + failed publishes / totals)
 *   score = round(100·(1−risk))
 *
 * Breach: score < 60 AND sample ≥ 5 (a couple of early checks must never flip
 * a workspace). Computed at every auto-approval attempt and shown in
 * Settings/Digest. The compliance-policy doc gates Phase 9 on exactly this.
 */
final class QualityScore
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate(self::ISO);
    }

    /**
     * @return array{score: int, risk: float, sample: int, slop_avg: float,
     *               block_rate: float, reject_fail_rate: float, breach: bool,
     *               policy: string}
     */
    public function compute(int $workspaceId): array
    {
        [$slopAvg, $blockRate, $sample] = $this->checkWindow($workspaceId);
        $rejectFailRate = $this->rejectFailRate($workspaceId);

        $risk = CompliancePolicy::QUALITY_WEIGHT_SLOP * $slopAvg
            + CompliancePolicy::QUALITY_WEIGHT_BLOCK_RATE * $blockRate
            + CompliancePolicy::QUALITY_WEIGHT_REJECT_FAIL * $rejectFailRate;
        $score = (int) round(100 * (1 - $risk));

        return [
            'score' => $score,
            'risk' => round($risk, 4),
            'sample' => $sample,
            'slop_avg' => round($slopAvg, 4),
            'block_rate' => round($blockRate, 4),
            'reject_fail_rate' => round($rejectFailRate, 4),
            'breach' => $score < CompliancePolicy::QUALITY_BREACH_BELOW
                && $sample >= CompliancePolicy::QUALITY_MIN_SAMPLE,
            'policy' => CompliancePolicy::VERSION,
        ];
    }

    /**
     * slop_avg + block_rate over the workspace's last N completed compliance
     * checks. sample = how many checks the window actually found.
     *
     * @return array{0: float, 1: float, 2: int}
     */
    private function checkWindow(int $workspaceId): array
    {
        $rows = $this->db->all(
            "SELECT result_json FROM jobs
             WHERE workspace_id = ? AND type = 'compliance_check'
               AND status = 'ready' AND result_json IS NOT NULL
             ORDER BY id DESC LIMIT " . CompliancePolicy::QUALITY_CHECK_WINDOW,
            [$workspaceId],
        );

        $sample = 0;
        $slopSum = 0.0;
        $blocks = 0;
        foreach ($rows as $row) {
            $result = json_decode((string) $row['result_json'], true);
            if (!is_array($result)) {
                continue;
            }
            $sample++;
            $slopSum += (float) ($result['checks']['slop']['score'] ?? 0.0);
            if (($result['status'] ?? '') === CompliancePolicy::BLOCK) {
                $blocks++;
            }
        }

        return $sample === 0
            ? [0.0, 0.0, 0]
            : [$slopSum / $sample, $blocks / $sample, $sample];
    }

    /**
     * (rejected render_reviews + failed publishes) / (all render_review
     * decisions + all publish outcomes) over the trailing window. 0 when the
     * window saw nothing (no evidence is not bad evidence).
     */
    private function rejectFailRate(int $workspaceId): float
    {
        $since = $this->daysAgo(CompliancePolicy::QUALITY_REJECT_WINDOW_DAYS);

        $reviews = $this->db->one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(a.decision = 'rejected'), 0) AS rejected
             FROM approvals a
             JOIN jobs j ON j.id = a.job_id
             WHERE a.workspace_id = ? AND j.type = 'render_review' AND a.decided_at >= ?",
            [$workspaceId, $since],
        ) ?? ['total' => 0, 'rejected' => 0];

        $publishes = $this->db->one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(status = 'failed'), 0) AS failed
             FROM jobs
             WHERE workspace_id = ? AND type = 'publish'
               AND status IN ('published', 'failed') AND finished_at >= ?",
            [$workspaceId, $since],
        ) ?? ['total' => 0, 'failed' => 0];

        $total = (int) $reviews['total'] + (int) $publishes['total'];
        if ($total === 0) {
            return 0.0;
        }

        return ((int) $reviews['rejected'] + (int) $publishes['failed']) / $total;
    }

    private function daysAgo(int $days): string
    {
        $ts = strtotime(($this->clock)());
        if ($ts === false) {
            throw new \RuntimeException('QualityScore clock produced an unparsable timestamp');
        }

        return gmdate(self::ISO, $ts - $days * 86400);
    }
}
