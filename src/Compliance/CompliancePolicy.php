<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

/**
 * The compliance policy constants, versioned IN CODE (locked decision 5):
 * changing any threshold is a policy change and MUST bump VERSION — every
 * compliance result and auto-approval record carries the version it was
 * decided under, so an audit can always reconstruct "which rules applied".
 * These are deliberately NOT per-workspace user settings (no-overbuild).
 */
final class CompliancePolicy
{
    public const VERSION = 'kuyash-v1';

    /* ---- format rules (product invariant: 15–45 s vertical 9:16) ---- */

    public const DURATION_MIN_S = 15.0;
    public const DURATION_MAX_S = 45.0;
    /** Encoder drift allowance — a 14.9 s render is not a violation. */
    public const DURATION_TOLERANCE_S = 0.5;

    /** 9:16 as width/height. */
    public const ASPECT = 0.5625;
    public const ASPECT_TOLERANCE = 0.01;

    /* ---- slop / variation control ---- */

    /** Max-Jaccard vs recent runs: warn at ≥ 0.55 (manual review even in Auto). */
    public const SLOP_WARN = 0.55;
    /** Block at ≥ 0.80 (extreme repetition cancels the run). */
    public const SLOP_BLOCK = 0.80;
    /** History window: the workspace's last N runs with comparable text. */
    public const SLOP_HISTORY_RUNS = 10;

    /* ---- quality score (drives auto-fallback to Manual) ---- */

    public const QUALITY_WEIGHT_SLOP = 0.40;
    public const QUALITY_WEIGHT_BLOCK_RATE = 0.35;
    public const QUALITY_WEIGHT_REJECT_FAIL = 0.25;
    /** slop_avg + block_rate window: the last N compliance checks. */
    public const QUALITY_CHECK_WINDOW = 20;
    /** reject/fail window: rejected reviews + failed publishes over N days. */
    public const QUALITY_REJECT_WINDOW_DAYS = 7;
    /** Breach: score < 60 AND sample ≥ 5 → workspace falls back to Manual. */
    public const QUALITY_BREACH_BELOW = 60;
    public const QUALITY_MIN_SAMPLE = 5;

    /* ---- check result statuses ---- */

    public const PASS = 'pass';
    public const PASS_WITH_AI_LABEL = 'pass_with_ai_label';
    public const WARN = 'warn';
    public const BLOCK = 'block';

    /**
     * Statuses the AutoApprovalGate may auto-approve (locked decision 1,
     * user-confirmed 2026-06-12): a labeled clean render is low-risk because
     * the AI label is applied automatically at publish. warn/block NEVER
     * auto-approve.
     */
    public const AUTO_APPROVABLE = [self::PASS, self::PASS_WITH_AI_LABEL];
}
