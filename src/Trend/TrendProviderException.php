<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use RuntimeException;

/**
 * Raised by a TrendProvider on an unrecoverable failure. Its message is always
 * safe to surface (a status/reason only) — never an API key, request header, or
 * raw response body. TrendService catches it and degrades to cached/stale data.
 */
final class TrendProviderException extends RuntimeException
{
}
