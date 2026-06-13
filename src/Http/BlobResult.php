<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * A small-body blob response (DELETE/HEAD/metadata GET). Headers are exposed
 * lower-cased so callers can read Content-Length without case juggling; the
 * body stays tiny (HEAD has none) — large media never travels as a string,
 * it streams to disk via BlobClient::download().
 */
final class BlobResult
{
    /** @param array<string, string> $headers lower-cased header name => value */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
