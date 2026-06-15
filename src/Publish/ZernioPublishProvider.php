<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Http\BlobClient;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpResponse;
use Kuyash\Http\HttpTransportException;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\RenderRepository;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Throwable;

/**
 * Real Zernio publish adapter (Phase 10). Implements PublishProvider; core never
 * sees a Zernio body — this class translates the vendor contract into the internal
 * PublishOutcome (adapter rule). EVERY field/path below is taken VERBATIM from the
 * live OpenAPI spec (https://zernio.com/openapi.yaml) + the platform guides — no
 * invented field (see .claude/docs/zernio-notes.md + ADR-021).
 *
 * Flow per target (one connected account):
 *   1. POST /v1/media/presign {filename, contentType}  → {uploadUrl, publicUrl}
 *   2. PUT the Kuyash render bytes to uploadUrl (no auth header — presigned)
 *   3. POST /v1/posts {content, mediaItems:[{url:publicUrl,type:"video"}],
 *      platforms:[{platform, accountId, platformSpecificData}], publishNow|scheduledFor+timezone}
 *   4. map the response → PublishOutcome (published / accepted / rejected / auth-failed / rate-limited)
 *
 * AI disclosure: the per-platform decision is resolved UPSTREAM (executor, toggle-
 * gated). Here we only translate request.aiLabelApplied into the platform's NATIVE
 * field where one exists — YouTube `containsSyntheticMedia`, TikTok `videoMadeWithAi`.
 * Instagram has NO native field, so the executor appends a caption line instead and
 * this adapter sets nothing for IG (truthful; see ADR-021).
 *
 * Auth: Authorization: Bearer <ZERNIO_API_KEY>. Base from config (ZERNIO_ENDPOINT).
 * 429 → bounded backoff+retry; the error envelope {error, code, reason} maps to the
 * outcome taxonomy; a transport throw / 5xx is a transient PublishProviderException.
 */
final class ZernioPublishProvider implements PublishProvider
{
    /** Bounded retry on a 429 throttle (defensive; the queue also backs off). */
    private const RATE_LIMIT_RETRIES = 2;
    private const RATE_LIMIT_BACKOFF_MS = 500;

    private readonly string $base;
    private readonly string $apiKey;
    private readonly int $timeout;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly BlobClient $blob,
        private readonly RenderRepository $renders,
        private readonly StorageManager $storage,
        private readonly MediaPaths $paths,
        array $config,
        /** sleeper seam so tests don't actually wait on backoff */
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->base = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->timeout = (int) ($config['timeout'] ?? 30);
    }

    public function name(): string
    {
        return 'zernio';
    }

    public function publish(PublishRequest $request): PublishOutcome
    {
        if ($this->base === '' || $this->apiKey === '') {
            throw new PublishProviderException('Zernio is not configured (ZERNIO_ENDPOINT / ZERNIO_API_KEY missing).');
        }
        if ($request->externalRef === null) {
            return PublishOutcome::rejected('account is not fully connected at Zernio (no account id)');
        }

        // 1) resolve the render to a local readable file, then 2) presign + 3) upload
        $local = $this->localRenderPath($request);
        $media = $this->presign(basename($local));
        $this->upload($media['uploadUrl'], $local);

        // 4) create the post (per-account; one platform per request)
        $payload = $this->postPayload($request, $media['publicUrl']);
        $response = $this->postWithRateLimitRetry('/posts', $payload);

        return $this->mapPostResponse($response, $request->platform);
    }

    public function status(string $externalPostId): PublishOutcome
    {
        $response = $this->get('/posts/' . rawurlencode($externalPostId));
        if ($response->status >= 500) {
            throw new PublishProviderException('Zernio status: transient ' . $response->status);
        }

        return $this->mapPostResponse($response, null);
    }

    /**
     * READ-ONLY: list connected accounts (GET /v1/accounts), optionally filtered by
     * platform. Used by the enable-time verification + account health — NOT publish.
     * Returns the vendor-neutral shape the rest of Kuyash already speaks.
     *
     * @return list<array{external_ref: string, platform: string, username: string, display_name: string, active: bool}>
     */
    public function accounts(?string $platform = null): array
    {
        $path = '/accounts' . ($platform !== null ? '?platform=' . rawurlencode($platform) : '');
        $response = $this->get($path);
        if ($response->status !== 200) {
            throw new PublishProviderException($this->errorMessage($response, 'list accounts'));
        }
        $body = $this->decode($response);
        $out = [];
        foreach ((array) ($body['accounts'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $out[] = [
                'external_ref' => (string) ($a['_id'] ?? ''),
                'platform' => (string) ($a['platform'] ?? ''),
                'username' => (string) ($a['username'] ?? ''),
                'display_name' => (string) ($a['displayName'] ?? ''),
                'active' => (bool) ($a['isActive'] ?? false),
            ];
        }

        return $out;
    }

    // ── media ───────────────────────────────────────────────────────────────

    /** Resolve the render row → a LOCAL path ffmpeg/curl can read (staging from R2 if needed). */
    private function localRenderPath(PublishRequest $request): string
    {
        if ($request->renderId === null) {
            throw new PublishProviderException('Zernio publish: no render to upload');
        }
        $render = $this->renders->find($request->workspaceId, $request->renderId);
        if ($render === null) {
            throw new PublishProviderException('Zernio publish: render not found for this workspace');
        }
        $name = (string) $render['stored_name'];
        $disk = (string) ($render['storage_disk'] ?? 'local');
        if ($disk === 'local') {
            return $this->paths->resolve($this->paths->ref('render', $request->workspaceId, $name));
        }
        // remote-disk render → stage a local copy for upload
        $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'mp4';
        $dest = $this->paths->pathFor('cache', $request->workspaceId, $this->paths->newName($ext));
        $this->storage->disk($disk)->getToLocal(StorageKey::make('render', $request->workspaceId, $name), $dest);

        return $dest;
    }

    /**
     * POST /v1/media/presign {filename, contentType} → {uploadUrl, publicUrl, key, expiresIn}.
     *
     * @return array{uploadUrl: string, publicUrl: string}
     */
    private function presign(string $filename): array
    {
        $response = $this->post('/media/presign', [
            'filename' => $filename,
            'contentType' => 'video/mp4',
        ]);
        if ($response->status < 200 || $response->status >= 300) {
            throw new PublishProviderException($this->errorMessage($response, 'presign'));
        }
        $body = $this->decode($response);
        $uploadUrl = (string) ($body['uploadUrl'] ?? '');
        $publicUrl = (string) ($body['publicUrl'] ?? '');
        if ($uploadUrl === '' || $publicUrl === '') {
            throw new PublishProviderException('Zernio presign: missing uploadUrl/publicUrl');
        }

        return ['uploadUrl' => $uploadUrl, 'publicUrl' => $publicUrl];
    }

    /** PUT the render to the presigned URL (no auth header — the URL is pre-signed). */
    private function upload(string $uploadUrl, string $localPath): void
    {
        try {
            $status = $this->blob->upload($uploadUrl, ['Content-Type' => 'video/mp4'], $localPath, $this->timeout);
        } catch (HttpTransportException $e) {
            throw new PublishProviderException('Zernio media upload failed (transport)');
        }
        if ($status < 200 || $status >= 300) {
            throw new PublishProviderException('Zernio media upload failed (HTTP ' . $status . ')');
        }
    }

    // ── post payload + response mapping ───────────────────────────────────────

    /**
     * Build the POST /v1/posts body. Fields per the live OpenAPI request schema.
     *
     * @return array<string, mixed>
     */
    private function postPayload(PublishRequest $request, string $publicUrl): array
    {
        $content = $request->caption;
        if ($request->hashtags !== []) {
            $content = rtrim($content) . "\n\n" . implode(' ', $request->hashtags);
        }

        $platform = [
            'platform' => $request->platform,
            'accountId' => (string) $request->externalRef,
        ];
        $psd = $this->platformSpecificData($request);
        if ($psd !== []) {
            $platform['platformSpecificData'] = $psd;
        }

        $payload = [
            'content' => $content,
            'mediaItems' => [['url' => $publicUrl, 'type' => 'video']],
            'platforms' => [$platform],
        ];

        // YouTube requires a human title (defaults to "Untitled Video" otherwise) —
        // derive a sane one from the caption's first line, capped at 100 chars.
        if ($request->platform === 'youtube') {
            $payload['title'] = $this->youtubeTitle($request->caption);
        }

        if ($request->scheduledFor !== null) {
            $payload['scheduledFor'] = $request->scheduledFor;
            $payload['timezone'] = 'UTC';
        } else {
            $payload['publishNow'] = true;
        }

        return $payload;
    }

    /**
     * Per-platform settings. The AI fields are the NATIVE disclosure flags the spec
     * defines; we set them ONLY when the (toggle-gated) request says to disclose:
     *   YouTube → containsSyntheticMedia ; TikTok → videoMadeWithAi.
     * Instagram has no native AI field (the caption already carries the disclosure).
     *
     * @return array<string, mixed>
     */
    private function platformSpecificData(PublishRequest $request): array
    {
        // NOTE (verbatim spec): InstagramPlatformData.contentType enum is [story] ONLY —
        // Reels are AUTO-DETECTED from a 9:16 <90s video (Kuyash's exact format), so we
        // do NOT send contentType; shareToFeed cross-posts the Reel to the main feed.
        return match ($request->platform) {
            'instagram' => ['shareToFeed' => true],
            'youtube' => $request->aiLabelApplied ? ['containsSyntheticMedia' => true] : [],
            'tiktok' => $request->aiLabelApplied ? ['videoMadeWithAi' => true] : [],
            default => [],
        };
    }

    private function youtubeTitle(string $caption): string
    {
        $firstLine = trim(strtok($caption, "\n") ?: '');

        return mb_substr($firstLine !== '' ? $firstLine : 'Kuyash Short', 0, 100);
    }

    /** Map a /v1/posts (or /v1/posts/{id}) response to the outcome taxonomy. */
    private function mapPostResponse(HttpResponse $response, ?string $platform): PublishOutcome
    {
        // success envelope: { message, post: { _id, status, platforms:[PlatformTarget] } }
        // PlatformTarget (verbatim spec): {platform, status, platformPostUrl, errorMessage, errorCategory}
        if ($response->status >= 200 && $response->status < 300) {
            $post = (array) ($this->decode($response)['post'] ?? []);
            $postId = (string) ($post['_id'] ?? '');
            $result = $this->platformResult((array) ($post['platforms'] ?? []), $platform);
            $pStatus = strtolower((string) ($result['status'] ?? ($post['status'] ?? '')));
            $url = (string) ($result['platformPostUrl'] ?? '');

            return match (true) {
                $pStatus === 'published' && $url !== '' => PublishOutcome::published($postId, $url),
                $pStatus === 'published' => PublishOutcome::published($postId, ''),
                $pStatus === 'failed' => $this->platformFailure($result),
                // pending / publishing → async; webhook or reconciler confirms
                default => PublishOutcome::accepted($postId !== '' ? $postId : 'zernio:unknown'),
            };
        }

        // top-level error envelope: { error, code, reason } — a request-level failure
        // (bad key / payment / validation). Terminal, except 429/5xx.
        return match (true) {
            $response->status === 429 => PublishOutcome::rateLimited($this->errorMessage($response, 'publish')),
            $response->status >= 500 => throw new PublishProviderException('Zernio publish: transient ' . $response->status),
            default => PublishOutcome::rejected($this->errorMessage($response, 'publish')),
        };
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, mixed>
     */
    private function platformResult(array $results, ?string $platform): array
    {
        foreach ($results as $r) {
            if (!is_array($r)) {
                continue;
            }
            if ($platform === null || (string) ($r['platform'] ?? '') === $platform) {
                return $r;
            }
        }

        return $results[0] ?? [];
    }

    /**
     * A failed PlatformTarget → terminal outcome. The spec's `errorCategory`
     * (auth_expired / user_content / platform_rejected / …) is the programmatic
     * signal: auth_expired flags the account for reauth (AUTH_FAILED); everything
     * else is a plain platform rejection. `errorMessage` carries the human text.
     *
     * @param array<string, mixed> $result a PlatformTarget with status=failed
     */
    private function platformFailure(array $result): PublishOutcome
    {
        $message = (string) ($result['errorMessage'] ?? 'platform rejected the post');
        if ((string) ($result['errorCategory'] ?? '') === 'auth_expired') {
            return PublishOutcome::authFailed($message);
        }

        return PublishOutcome::rejected($message);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): HttpResponse
    {
        try {
            return $this->http->post($this->base . $path, $this->headers(), $this->json($body), $this->timeout);
        } catch (HttpTransportException $e) {
            throw new PublishProviderException('Zernio request failed (transport)');
        }
    }

    private function get(string $path): HttpResponse
    {
        try {
            return $this->http->get($this->base . $path, $this->headers(), $this->timeout);
        } catch (HttpTransportException $e) {
            throw new PublishProviderException('Zernio request failed (transport)');
        }
    }

    /**
     * POST with a bounded retry on 429 (the spec exposes X-RateLimit-* but
     * HttpResponse intentionally hides headers, so we use a fixed backoff).
     *
     * @param array<string, mixed> $body
     */
    private function postWithRateLimitRetry(string $path, array $body): HttpResponse
    {
        $response = $this->post($path, $body);
        $attempt = 0;
        while ($response->status === 429 && $attempt < self::RATE_LIMIT_RETRIES) {
            $this->sleep(self::RATE_LIMIT_BACKOFF_MS * ($attempt + 1));
            $attempt++;
            $response = $this->post($path, $body);
        }

        return $response;
    }

    private function sleep(int $ms): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }
        usleep($ms * 1000);
    }

    /** Associative header map — CurlHttpClient/CurlBlobClient build "Name: Value" lines from it. @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(HttpResponse $response): array
    {
        try {
            $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** Sanitized, human-readable error from the {error, code, reason} envelope (never leaks the key/body). */
    private function errorMessage(HttpResponse $response, string $action): string
    {
        $body = $this->decode($response);
        $msg = (string) ($body['error'] ?? '');
        $code = (string) ($body['code'] ?? '');
        $reason = (string) ($body['reason'] ?? '');
        $detail = trim($msg . ($code !== '' ? " [{$code}]" : '') . ($reason !== '' ? " ({$reason})" : ''));

        return "Zernio {$action} failed (HTTP {$response->status})" . ($detail !== '' ? ': ' . $detail : '');
    }
}
