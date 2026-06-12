<?php

declare(strict_types=1);

namespace Kuyash\Http;

use RuntimeException;

/**
 * Thrown when a request never produces an HTTP response — timeout, DNS,
 * connection refused. The message describes the transport condition only,
 * never the request body or headers (which carry the API key).
 */
final class HttpTransportException extends RuntimeException
{
}
