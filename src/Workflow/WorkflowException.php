<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use RuntimeException;

/**
 * Domain failure with a user-facing message KEY (same pattern as
 * InvalidUploadException): controllers flash the key, Messages resolves it.
 * The technical detail goes into the exception message for logs only.
 *
 * Not final: Phase 11's BudgetExceededException is a WorkflowException so the
 * existing startRun catch sites flash its key with no change, while remaining a
 * distinct, assertable type.
 */
class WorkflowException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : $messageKey);
    }
}
