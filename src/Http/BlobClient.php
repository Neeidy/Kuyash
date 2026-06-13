<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * Streaming HTTP transport for large media — the part the buffering HttpClient
 * seam (post/get, RETURNTRANSFER) deliberately can't do. Used by the R2 adapter
 * (file-handle PUT, capped sink GET, DELETE/HEAD) and by the Pexels stock
 * download (capped sink GET). CurlBlobClient is the real implementation; tests
 * inject a fake so every branch runs with ZERO network.
 *
 * Same security posture as CurlHttpClient: $url and $headers may carry a
 * signature/credential, so a transport failure surfaces ONLY the curl error
 * string — never the URL, headers, or body.
 */
interface BlobClient
{
    /**
     * PUT, streaming the file at $sourcePath as the request body (no buffering).
     * Returns the HTTP status. Throws HttpTransportException on a transport error.
     *
     * @param array<string, string> $headers
     */
    public function upload(string $url, array $headers, string $sourcePath, int $timeoutSeconds): int;

    /**
     * Stream the response body to $destPath, HARD-CAPPED at $maxBytes — the
     * transfer is aborted (and the partial file removed) the moment the cap is
     * exceeded, so a hostile/oversized object can't balloon memory or disk.
     * Returns bytes written. Throws HttpTransportException on transport error or
     * cap breach.
     *
     * @param array<string, string> $headers
     */
    public function download(string $method, string $url, array $headers, string $destPath, int $maxBytes, int $timeoutSeconds): int;

    /**
     * A small/bodyless signed request (DELETE, HEAD, metadata GET). Returns the
     * status plus selected response headers (e.g. Content-Length). Throws
     * HttpTransportException on a transport error.
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, int $timeoutSeconds): BlobResult;
}
