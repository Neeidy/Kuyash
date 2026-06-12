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
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
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
