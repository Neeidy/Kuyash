<?php

declare(strict_types=1);

namespace Kuyash\Content;

use RuntimeException;

/**
 * An unrecoverable content-generation failure. The message is ALWAYS safe to
 * surface and log — constructed from a status/reason, never from a raw vendor
 * payload, request headers, or the API key (Phase 4 security follow-up).
 * ContentExecutor turns this into JobResult::failed → Worker backoff/retry.
 */
final class TextProviderException extends RuntimeException
{
}
