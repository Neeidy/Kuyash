<?php

declare(strict_types=1);

namespace Kuyash\Core;

use RuntimeException;

/**
 * A provider failure that retrying cannot fix (HTTP 401/403 — credentials
 * invalid or forbidden). Thrown by the external adapters (OpenAI text, OpenAI
 * TTS, Pexels) for auth/forbidden responses; because it is NOT the adapter's
 * own domain exception, it slips past the executor's domain catch and reaches
 * Worker::tick, which sees the PermanentFailure marker and dead-letters the job
 * immediately (no backoff). The message carries a status only — never a key,
 * header, or response body.
 */
final class PermanentFailureException extends RuntimeException implements PermanentFailure
{
}
