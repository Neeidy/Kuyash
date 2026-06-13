<?php

declare(strict_types=1);

namespace Kuyash\Storage;

use RuntimeException;

/**
 * Storage-layer failure (bad key, missing object, durable-store error). Messages
 * are operator-facing and MUST never carry a credential or a signature — the R2
 * provider only surfaces an HTTP status/reason, never the key, header or URL.
 */
final class StorageException extends RuntimeException
{
}
