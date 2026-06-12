<?php

declare(strict_types=1);

namespace Kuyash\Media;

use RuntimeException;

/**
 * Raised when an ffmpeg/ffprobe invocation fails (non-zero exit or timeout).
 * The message carries a short, sanitized reason — never the full command line
 * (which is server-built anyway) nor secrets. Treated by the worker as a failed
 * job attempt (retry/backoff), never a worker crash.
 */
final class FfmpegException extends RuntimeException
{
}
