<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use RuntimeException;

/**
 * A TRANSIENT publishing failure — a transport timeout, or the doc-gated real
 * client refusing to make a call. The executor treats it as a retryable attempt
 * (the job backs off and retries; idempotency stops already-published targets
 * from re-posting). Terminal per-target outcomes (platform rejection, auth
 * failure) are NOT exceptions — they are PublishOutcome values recorded on the
 * post. The message is already sanitized: never an API key, header, or payload.
 */
final class PublishProviderException extends RuntimeException
{
}
