<?php

declare(strict_types=1);

namespace Kuyash\Storage;

use Kuyash\Http\BlobClient;
use Kuyash\Http\HttpTransportException;

/**
 * Cloudflare R2 adapter — S3-compatible, addressed path-style at
 * https://{account}.r2.cloudflarestorage.com/{bucket}/{key}, region "auto",
 * service "s3". Real but OFF by default: selected only when STORAGE_DRIVER=r2 and
 * credentials are present (see bindings/core.php). Bucket is PRIVATE — objects
 * are served via short-TTL presigned GETs, never a public ACL.
 *
 * Requests are SigV4-signed (SigV4Signer) and carried by the streaming BlobClient
 * seam, so a real bucket is never touched in tests. First connect to a live
 * bucket is a documented enable-time HARD GATE (smoke before flipping the flag).
 *
 * SECURITY: failures surface only an HTTP status — never the key, the signed URL,
 * or any header (which carry the credential/signature).
 */
final class R2StorageProvider implements StorageProvider
{
    private const AMZ_DATETIME = 'Ymd\THis\Z';

    public function __construct(
        private readonly BlobClient $blob,
        private readonly SigV4Signer $signer,
        private readonly string $host,
        private readonly string $bucket,
        private readonly int $presignTtl = 300,
        private readonly int $maxDownloadBytes = 536_870_912, // 512 MiB
        private readonly int $timeout = 60,
    ) {
    }

    public function put(string $key, string $localPath, string $contentType): void
    {
        // content-length is NOT signed: curl sets it from the file handle
        // (CURLOPT_INFILESIZE) and S3 does not require it in SignedHeaders.
        $amz = $this->now();
        $headers = [
            'host' => $this->host,
            'x-amz-date' => $amz,
            'x-amz-content-sha256' => SigV4Signer::UNSIGNED_PAYLOAD,
            'content-type' => $contentType,
        ];
        $signed = $this->signer->signRequest('PUT', $this->objectPath($key), '', $headers, SigV4Signer::UNSIGNED_PAYLOAD, $amz);

        try {
            $status = $this->blob->upload($this->url($key), $this->withAuth($headers, $signed), $localPath, $this->timeout);
        } catch (HttpTransportException $e) {
            throw new StorageException('R2 put failed: ' . $e->getMessage());
        }
        if ($status < 200 || $status >= 300) {
            throw new StorageException("R2 put failed (HTTP {$status})");
        }
    }

    public function getToLocal(string $key, string $destPath): void
    {
        $headers = $this->bodylessHeaders();
        $signed = $this->signer->signRequest('GET', $this->objectPath($key), '', $headers, SigV4Signer::EMPTY_PAYLOAD_SHA256, $headers['x-amz-date']);

        try {
            $this->blob->download('GET', $this->url($key), $this->withAuth($headers, $signed), $destPath, $this->maxDownloadBytes, $this->timeout);
        } catch (HttpTransportException $e) {
            throw new StorageException('R2 get failed: ' . $e->getMessage());
        }
    }

    public function delete(string $key): bool
    {
        $headers = $this->bodylessHeaders();
        $signed = $this->signer->signRequest('DELETE', $this->objectPath($key), '', $headers, SigV4Signer::EMPTY_PAYLOAD_SHA256, $headers['x-amz-date']);

        try {
            $status = $this->blob->send('DELETE', $this->url($key), $this->withAuth($headers, $signed), $this->timeout)->status;
        } catch (HttpTransportException $e) {
            throw new StorageException('R2 delete failed: ' . $e->getMessage());
        }

        return $status >= 200 && $status < 300;
    }

    public function exists(string $key): bool
    {
        return $this->head($key)->status === 200;
    }

    public function size(string $key): ?int
    {
        $result = $this->head($key);
        if ($result->status !== 200) {
            return null;
        }
        $len = $result->header('content-length');

        return $len !== null && ctype_digit($len) ? (int) $len : null;
    }

    public function temporaryUrl(string $key, int $ttl, array $responseHeaders = []): ?string
    {
        $ttl = $ttl > 0 ? $ttl : $this->presignTtl;

        return $this->signer->presignGet($this->host, $this->objectPath($key), $ttl, $responseHeaders, $this->now());
    }

    private function head(string $key): \Kuyash\Http\BlobResult
    {
        $headers = $this->bodylessHeaders();
        $signed = $this->signer->signRequest('HEAD', $this->objectPath($key), '', $headers, SigV4Signer::EMPTY_PAYLOAD_SHA256, $headers['x-amz-date']);

        try {
            return $this->blob->send('HEAD', $this->url($key), $this->withAuth($headers, $signed), $this->timeout);
        } catch (HttpTransportException $e) {
            throw new StorageException('R2 head failed: ' . $e->getMessage());
        }
    }

    /** @return array<string, string> */
    private function bodylessHeaders(): array
    {
        $amz = $this->now();

        return [
            'host' => $this->host,
            'x-amz-date' => $amz,
            'x-amz-content-sha256' => SigV4Signer::EMPTY_PAYLOAD_SHA256,
        ];
    }

    /**
     * @param array<string, string>                                        $headers
     * @param array{authorization: string, signed_headers: string, signature: string} $signed
     *
     * @return array<string, string>
     */
    private function withAuth(array $headers, array $signed): array
    {
        return $headers + ['Authorization' => $signed['authorization']];
    }

    /** Path-style object path: /{bucket}/{store}/{ws}/{name}. Re-validates the key. */
    private function objectPath(string $key): string
    {
        StorageKey::parse($key);

        return '/' . $this->bucket . '/' . $key;
    }

    private function url(string $key): string
    {
        return 'https://' . $this->host . $this->objectPath($key);
    }

    private function now(): string
    {
        return gmdate(self::AMZ_DATETIME);
    }
}
