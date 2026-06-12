<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * A minimal HTTP response: status + raw body. Headers are intentionally not
 * surfaced — Phase 5 callers only need the status and the JSON body, and
 * keeping the surface tiny means nothing accidentally logs response headers.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
