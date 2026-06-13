<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * What an executor hands back to the engine — the ONLY shape that crosses
 * the executor seam (adapter rule: vendors translate into this, core never
 * sees vendor responses).
 */
final class JobResult
{
    public const STATUS_READY = 'ready';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DEFERRED = 'deferred';

    /** @param array<string, mixed> $result */
    private function __construct(
        public readonly string $status,
        public readonly array $result = [],
        public readonly ?string $errorMessage = null,
        public readonly ?int $costCents = null,
        public readonly ?string $provider = null,
        public readonly int $deferSeconds = 0,
        // false = a permanent failure (e.g. HTTP 401/403): dead-letter at once,
        // skip the backoff/retry budget. Only meaningful when status = failed.
        public readonly bool $retryable = true,
    ) {
    }

    /** @param array<string, mixed> $result */
    public static function ready(array $result, ?string $provider = null, ?int $costCents = null): self
    {
        return new self(self::STATUS_READY, $result, null, $costCents, $provider);
    }

    /**
     * @param array<string, mixed> $result
     *
     * costCents is accepted here too: a real generation (e.g. an OpenAI script)
     * spends money BEFORE the human approval gate, so the spend is recorded on
     * the paused job rather than lost. Mock keeps it null.
     */
    public static function awaitingApproval(array $result, ?string $provider = null, ?int $costCents = null): self
    {
        return new self(self::STATUS_AWAITING_APPROVAL, $result, null, $costCents, $provider);
    }

    /** @param array<string, mixed> $result */
    public static function published(array $result, ?string $provider = null, ?int $costCents = null): self
    {
        return new self(self::STATUS_PUBLISHED, $result, null, $costCents, $provider);
    }

    public static function failed(string $errorMessage, ?string $provider = null): self
    {
        return new self(self::STATUS_FAILED, [], $errorMessage, null, $provider);
    }

    /**
     * A failed attempt that retrying CANNOT fix (HTTP 401/403 auth). The engine
     * dead-letters it immediately — no exponential-backoff requeue — so the run
     * fails fast and the operator can fix credentials, then manually retry.
     */
    public static function failedPermanent(string $errorMessage, ?string $provider = null): self
    {
        return new self(self::STATUS_FAILED, [], $errorMessage, null, $provider, 0, false);
    }

    /**
     * A guardrail HALT, not a failure (Phase 9): the job goes back to 'queued'
     * with run_after = now + $delaySeconds and NO retry_count increment. The
     * reason lands in error_message ("deferred: …") so the queue UI explains
     * the wait truthfully.
     */
    public static function deferred(string $reason, int $delaySeconds): self
    {
        return new self(self::STATUS_DEFERRED, [], $reason, null, null, max(1, $delaySeconds));
    }
}
