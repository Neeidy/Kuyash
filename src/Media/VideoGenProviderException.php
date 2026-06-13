<?php

declare(strict_types=1);

namespace Kuyash\Media;

use RuntimeException;

/**
 * A VideoGenProvider failure (generation failed, or the real provider is
 * doc-gated). The message MUST be sanitized — no API key, no auth header
 * (security rule). AiVideoExecutor turns it into a failed JobResult.
 */
final class VideoGenProviderException extends RuntimeException
{
}
