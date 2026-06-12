<?php

declare(strict_types=1);

namespace Kuyash\Library;

use RuntimeException;

/**
 * Upload rejection carrying an i18n-ready message KEY (resolved to text by
 * the controller's message map, never shown raw).
 */
final class InvalidUploadException extends RuntimeException
{
    public function __construct(public readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }
}
