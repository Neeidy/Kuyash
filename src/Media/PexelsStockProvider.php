<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Http\BlobClient;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpTransportException;
use Throwable;

/**
 * Real Pexels Videos adapter (GET /videos/search) — OFF by default, selected
 * only when STOCK_MOCK=false and a key is present (see bindings/core.php).
 * Depends on the HttpClient seam (search JSON) and the streaming BlobClient seam
 * (clip download), so tests drive every branch with fakes and ZERO network.
 *
 * Search (JSON) → pick a portrait mp4 video_file → download the clip STREAMING
 * to disk with a hard byte cap (BlobClient::download) — never buffering a whole
 * video into a PHP string. This clears the Phase 7 HARD GATE. Maps the documented
 * response shape — videos[].video_files[] {link, width, height, file_type} —
 * into a StockResult.
 *
 * Honesty + safety: failures become StockProviderException with a status/reason
 * only (never the key, which rides in the Authorization header, or the body).
 * Pexels is free — costCents stays null; the request is counted against quota by
 * the executor.
 */
final class PexelsStockProvider implements StockProvider
{
    private const DEFAULT_ENDPOINT = 'https://api.pexels.com/videos/search';
    private const DEFAULT_MAX_DOWNLOAD_BYTES = 134_217_728; // 128 MiB

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly BlobClient $blob,
        private readonly Ffmpeg $ffmpeg,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'pexels';
    }

    public function clipExtension(): string
    {
        return 'mp4';
    }

    public function fetchClip(string $query, float $durationSeconds, string $targetPath): StockResult
    {
        $file = $this->pickPortraitFile($this->search($query));

        $this->download((string) $file['link'], $targetPath);

        $duration = $this->ffmpeg->probeDuration($targetPath) ?? max(1.0, $durationSeconds);

        return new StockResult(
            (int) ($file['width'] ?? 1080),
            (int) ($file['height'] ?? 1920),
            $duration,
            null,
            ['source' => 'pexels'],
        );
    }

    /** @return array<string, mixed> decoded search response */
    private function search(string $query): array
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $timeout = (int) ($this->config['timeout'] ?? 30);
        $url = $endpoint . '?' . http_build_query([
            'query' => $query !== '' ? $query : 'abstract',
            'orientation' => 'portrait',
            'per_page' => 5,
            'size' => 'medium',
        ]);

        try {
            $response = $this->http->get($url, ['Authorization' => $key], $timeout);
        } catch (HttpTransportException $e) {
            throw new StockProviderException('Pexels request failed: ' . $e->getMessage());
        }

        if ($response->status === 429) {
            throw new StockProviderException('Pexels rate limited (HTTP 429)');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new StockProviderException('Pexels request failed (HTTP ' . $response->status . ')');
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StockProviderException('Pexels response was not valid JSON');
        }
        if (!is_array($decoded)) {
            throw new StockProviderException('Pexels response was not an object');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $search
     *
     * @return array{link: string, width: int, height: int}
     */
    private function pickPortraitFile(array $search): array
    {
        $videos = is_array($search['videos'] ?? null) ? $search['videos'] : [];
        foreach ($videos as $video) {
            $files = is_array($video['video_files'] ?? null) ? $video['video_files'] : [];
            foreach ($files as $f) {
                $link = $f['link'] ?? null;
                $w = (int) ($f['width'] ?? 0);
                $h = (int) ($f['height'] ?? 0);
                $type = (string) ($f['file_type'] ?? '');
                if (is_string($link) && $link !== '' && $h >= $w && $h > 0 && str_contains($type, 'mp4')) {
                    return ['link' => $link, 'width' => $w, 'height' => $h];
                }
            }
        }

        throw new StockProviderException('Pexels returned no usable portrait clip');
    }

    /** Stream the clip to disk with a hard byte cap (no whole-file buffering). */
    private function download(string $link, string $targetPath): void
    {
        if (!str_starts_with($link, 'https://')) {
            throw new StockProviderException('Pexels clip link was not https');
        }

        $cap = (int) ($this->config['max_download_bytes'] ?? self::DEFAULT_MAX_DOWNLOAD_BYTES);

        try {
            // BlobClient::download throws on non-2xx, transport error, OR the cap
            // being exceeded (the transfer aborts + the partial file is removed)
            $this->blob->download('GET', $link, ['Accept' => 'video/mp4'], $targetPath, $cap, (int) ($this->config['timeout'] ?? 30));
        } catch (HttpTransportException $e) {
            throw new StockProviderException('Pexels download failed: ' . $e->getMessage());
        }

        if (!is_file($targetPath) || filesize($targetPath) === 0) {
            @unlink($targetPath);
            throw new StockProviderException('Pexels download was empty');
        }
    }
}
