<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

/**
 * The AutoApprovalGate's verdict for one render_review pause. `approve` is the
 * only branch the Engine acts on; `path` records WHY for tests and debugging
 * (deny guardrail events are written by the gate itself, in the same
 * transaction). An approving decision carries the policy version + score
 * snapshot that the truthful approvals row requires.
 */
final class GateDecision
{
    public const PATH_AUTO = 'auto';
    public const PATH_MANUAL_MODE = 'manual_mode';
    public const PATH_NO_COMPLIANCE = 'no_compliance';
    public const PATH_NOT_CLEAN = 'not_clean';
    public const PATH_KILL_SWITCH = 'kill_switch';
    public const PATH_DAILY_CAP = 'daily_cap';
    public const PATH_BUDGET_CAP = 'budget_cap';
    public const PATH_QUALITY_BREACH = 'quality_breach';

    /** @param array<string, mixed>|null $score */
    private function __construct(
        public readonly bool $approve,
        public readonly string $path,
        public readonly ?string $policyVersion = null,
        public readonly ?array $score = null,
    ) {
    }

    /** @param array<string, mixed> $score */
    public static function auto(string $policyVersion, array $score): self
    {
        return new self(true, self::PATH_AUTO, $policyVersion, $score);
    }

    public static function manual(string $path): self
    {
        return new self(false, $path);
    }
}
