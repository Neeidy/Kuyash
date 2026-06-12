<?php

declare(strict_types=1);

namespace Kuyash\Media;

use RuntimeException;

/**
 * Raised by a StockProvider on an unrecoverable failure. The message is always
 * safe to surface (status/reason only) — never an API key, header, or raw body.
 */
final class StockProviderException extends RuntimeException
{
}
