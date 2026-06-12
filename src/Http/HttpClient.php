<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * The outbound-HTTP seam. CurlHttpClient is the real implementation; tests
 * inject a fake so provider error handling is exercised with ZERO network.
 *
 * Implementations MUST throw HttpTransportException when no response arrives
 * (timeout/connection). A non-2xx response is NOT an exception here — it is a
 * valid HttpResponse the caller inspects (so providers map status → behavior).
 *
 * @param array<string, string> $headers
 */
interface HttpClient
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse;
}
