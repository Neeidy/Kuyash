<?php

declare(strict_types=1);

namespace Kuyash\Http;

/**
 * Real streaming cURL transport for the BlobClient seam. Constructed ONLY on the
 * real-R2 / real-Pexels-download path (flag-OFF by default), so the default/test
 * paths never load it. Mirrors CurlHttpClient's hardening — TLS verification
 * pinned, redirects banned, errors reduced to the curl string (never the signed
 * URL or headers).
 *
 * - upload(): CURLOPT_UPLOAD reads the body straight from a file handle — a
 *   200 MB video is never held in a PHP string.
 * - download(): a write callback streams chunks to disk and ABORTS the moment
 *   $maxBytes is exceeded (returning a short count makes cURL fail the transfer),
 *   then the partial file is removed.
 */
final class CurlBlobClient implements BlobClient
{
    public function upload(string $url, array $headers, string $sourcePath, int $timeoutSeconds): int
    {
        $handle = @fopen($sourcePath, 'rb');
        if ($handle === false) {
            throw new HttpTransportException('Blob upload could not open source file');
        }

        $size = filesize($sourcePath);
        $ch = curl_init();
        curl_setopt_array($ch, $this->baseOptions($url, $headers, $timeoutSeconds) + [
            CURLOPT_UPLOAD => true,      // streams the body from CURLOPT_INFILE as a PUT
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $size === false ? -1 : $size,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($handle);

        if ($errno !== 0) {
            throw new HttpTransportException("Blob upload transport error ({$errno}): {$error}");
        }

        return $status;
    }

    public function download(string $method, string $url, array $headers, string $destPath, int $maxBytes, int $timeoutSeconds): int
    {
        $out = @fopen($destPath, 'wb');
        if ($out === false) {
            throw new HttpTransportException('Blob download could not open destination file');
        }

        $written = 0;
        $capped = false;
        $ch = curl_init();
        curl_setopt_array($ch, $this->baseOptions($url, $headers, $timeoutSeconds) + [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_WRITEFUNCTION => function ($_ch, string $chunk) use ($out, &$written, &$capped, $maxBytes): int {
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    $capped = true;

                    return -1; // short write → cURL aborts the transfer
                }
                fwrite($out, $chunk);

                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($out);

        if ($capped) {
            @unlink($destPath);
            throw new HttpTransportException("Blob download exceeded the {$maxBytes}-byte cap");
        }
        if ($errno !== 0) {
            @unlink($destPath);
            throw new HttpTransportException("Blob download transport error ({$errno}): {$error}");
        }
        if ($status < 200 || $status >= 300) {
            @unlink($destPath);
            throw new HttpTransportException("Blob download failed (HTTP {$status})");
        }

        return $written;
    }

    public function send(string $method, string $url, array $headers, int $timeoutSeconds): BlobResult
    {
        $responseHeaders = [];
        $ch = curl_init();
        $options = $this->baseOptions($url, $headers, $timeoutSeconds) + [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ];
        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        // send() is for small/bodyless metadata replies (HEAD/DELETE) — cap the
        // buffered body so a misbehaving endpoint can't balloon a PHP string
        $options[CURLOPT_MAXFILESIZE] = 1_048_576; // 1 MiB
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new HttpTransportException("Blob request transport error ({$errno}): {$error}");
        }

        return new BlobResult($status, $responseHeaders, is_string($body) ? $body : '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<int, mixed>
     */
    private function baseOptions(string $url, array $headers, int $timeoutSeconds): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        return [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 10),
            CURLOPT_FOLLOWLOCATION => false,    // a signed request must never chase a redirect
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // HTTPS only: the Pexels download URL is external data — deny file://,
            // gopher:// and friends (defense-in-depth vs SSRF via a spoofed link)
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
    }
}
