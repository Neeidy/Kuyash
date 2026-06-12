<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * Real cURL transport (ext-curl, already a project dependency). Constructed
 * ONLY when a real provider is selected (OPENAI_MOCK=false + key), so the
 * default/test paths never load it. On a transport failure it throws
 * HttpTransportException with the curl error string — never the request body
 * or headers.
 */
final class CurlHttpClient implements HttpClient
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        return $this->send($url, $headers, $timeoutSeconds, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
        ]);
    }

    public function get(string $url, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->send($url, $headers, $timeoutSeconds, [CURLOPT_HTTPGET => true]);
    }

    /**
     * Shared transport: the same TLS pinning, redirect ban and error handling
     * for every verb — the only difference is the method-specific options.
     *
     * SECURITY: $url may carry a credential in its query string (e.g. the
     * YouTube Data API ?key=). It must NEVER be logged or surfaced in an
     * exception — only the transport error string is, which never echoes $url.
     *
     * @param array<string, string> $headers
     * @param array<int, mixed>     $methodOptions
     */
    private function send(string $url, array $headers, int $timeoutSeconds, array $methodOptions): HttpResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $methodOptions + [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_FOLLOWLOCATION => false,    // no redirect-based key exfiltration
            CURLOPT_SSL_VERIFYPEER => true,     // pin TLS verification (defense-in-depth:
            CURLOPT_SSL_VERIFYHOST => 2,        // a global override can't silently weaken it)
        ]);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $responseBody === false) {
            // curl_error is a transport description (e.g. "Operation timed out"),
            // safe to surface — it never echoes our request headers/body
            throw new HttpTransportException("HTTP transport error ({$errno}): {$error}");
        }

        return new HttpResponse($status, (string) $responseBody);
    }
}
