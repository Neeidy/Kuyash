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

    /** @param array<string, mixed> $result */
    private function __construct(
        public readonly string $status,
        public readonly array $result = [],
        public readonly ?string $errorMessage = null,
        public readonly ?int $costCents = null,
        public readonly ?string $provider = null,
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
}
