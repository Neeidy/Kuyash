<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use RuntimeException;

/**
 * Domain failure with a user-facing message KEY (same pattern as
 * InvalidUploadException): controllers flash the key, Messages resolves it.
 * The technical detail goes into the exception message for logs only.
 */
final class WorkflowException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : $messageKey);
    }
}
