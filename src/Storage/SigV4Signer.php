<?php

declare(strict_types=1);

namespace Kuyash\Storage;

/**
 * Hand-rolled AWS Signature Version 4 (HMAC-SHA256 chain) — no AWS SDK, no
 * Composer dependency (pure-php rule). Covers both header-signed requests
 * (PUT/GET/DELETE/HEAD) and query-signed presigned GET URLs, which is all the
 * R2 (S3-compatible) adapter needs.
 *
 * Deterministic by design: the caller passes the timestamp, so the signer is a
 * pure function of its inputs — unit-tested against the published AWS
 * "get-vanilla" known-answer vector (see tests).
 *
 * SECURITY: the secret key lives only inside this object; it is never returned,
 * logged, or placed in a header value — only the derived signature is.
 */
final class SigV4Signer
{
    private const ALGO = 'AWS4-HMAC-SHA256';

    /** SHA-256 of the empty string — the payload hash for bodyless GET/DELETE/HEAD. */
    public const EMPTY_PAYLOAD_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** Sentinel payload hash for streamed bodies over HTTPS (S3 PUT). */
    public const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region = 'auto',
        private readonly string $service = 's3',
    ) {
    }

    /**
     * Header-signed request. $headers MUST already contain 'host' and
     * 'x-amz-date' (and 'x-amz-content-sha256' for S3) — the signer signs
     * exactly the header set it is given.
     *
     * @param array<string, string> $headers
     *
     * @return array{authorization: string, signed_headers: string, signature: string}
     */
    public function signRequest(
        string $method,
        string $path,
        string $canonicalQuery,
        array $headers,
        string $payloadHash,
        string $amzDateTime,
    ): array {
        $date = substr($amzDateTime, 0, 8);
        $scope = $this->credentialScope($date);

        [$canonicalHeaders, $signedHeaders] = self::canonicalHeaders($headers);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            self::canonicalUri($path),
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $signature = $this->signature($date, $this->stringToSign($amzDateTime, $scope, $canonicalRequest));

        $authorization = self::ALGO
            . ' Credential=' . $this->accessKeyId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        return ['authorization' => $authorization, 'signed_headers' => $signedHeaders, 'signature' => $signature];
    }

    /**
     * Query-signed presigned GET URL. Makes NO request — the signature is
     * embedded so the URL can be handed to a browser (302 redirect). Only `host`
     * is signed; the payload hash is the UNSIGNED-PAYLOAD sentinel (S3 presign
     * convention). $extraQuery pins response-* overrides into the signed query.
     *
     * @param array<string, string> $extraQuery
     */
    public function presignGet(string $host, string $path, int $expires, array $extraQuery, string $amzDateTime): string
    {
        $date = substr($amzDateTime, 0, 8);
        $scope = $this->credentialScope($date);

        // response-* and any caller params are signed alongside the X-Amz-* set
        $query = $extraQuery + [
            'X-Amz-Algorithm' => self::ALGO,
            'X-Amz-Credential' => $this->accessKeyId . '/' . $scope,
            'X-Amz-Date' => $amzDateTime,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = self::canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            'GET',
            self::canonicalUri($path),
            $canonicalQuery,
            'host:' . trim($host) . "\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);

        $signature = $this->signature($date, $this->stringToSign($amzDateTime, $scope, $canonicalRequest));

        return 'https://' . $host . self::canonicalUri($path) . '?' . $canonicalQuery
            . '&X-Amz-Signature=' . $signature;
    }

    private function credentialScope(string $date): string
    {
        return $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
    }

    private function stringToSign(string $amzDateTime, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [self::ALGO, $amzDateTime, $scope, hash('sha256', $canonicalRequest)]);
    }

    private function signature(string $date, string $stringToSign): string
    {
        return bin2hex($this->hmac($this->signingKey($date), $stringToSign));
    }

    private function signingKey(string $date): string
    {
        $kDate = $this->hmac('AWS4' . $this->secretAccessKey, $date);
        $kRegion = $this->hmac($kDate, $this->region);
        $kService = $this->hmac($kRegion, $this->service);

        return $this->hmac($kService, 'aws4_request');
    }

    private function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{0: string, 1: string} [canonicalHeaders (trailing \n per row), signedHeaders]
     */
    private static function canonicalHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = trim((string) $value);
        }
        ksort($normalized, SORT_STRING);

        $canonical = '';
        foreach ($normalized as $name => $value) {
            $canonical .= $name . ':' . $value . "\n";
        }

        return [$canonical, implode(';', array_keys($normalized))];
    }

    /** RFC 3986 canonical query: encode each key/value, sort by encoded key. */
    private static function canonicalQuery(array $params): string
    {
        $encoded = [];
        foreach ($params as $k => $v) {
            $encoded[rawurlencode((string) $k)] = rawurlencode((string) $v);
        }
        ksort($encoded, SORT_STRING);

        $pairs = [];
        foreach ($encoded as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        return implode('&', $pairs);
    }

    /** Encode each path segment (RFC 3986) but keep the '/' separators. */
    private static function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return implode('/', array_map(static fn (string $s): string => rawurlencode($s), explode('/', $path)));
    }
}
