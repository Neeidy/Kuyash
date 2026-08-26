<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Response;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Media\AssetPoster;
use Kuyash\Storage\StorageException;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Private media serving. The tenant check runs FIRST (find() is workspace-scoped
 * → another tenant's id is 404 before anything else). Then the provider is
 * resolved PER OBJECT from storage_disk: an R2-located object 302-redirects to a
 * short-TTL presigned GET (content-type + disposition pinned); a local object is
 * streamed with single-range support — Safari refuses <video> without 206
 * responses and Chrome needs ranges to seek. Multi-range → full 200 (RFC 9110).
 */
final class MediaController
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly AssetStorage $storage,
        private readonly StorageManager $disks,
        private readonly WorkspaceContext $workspace,
        private readonly ?AssetPoster $posters = null,
        private readonly int $presignTtl = 300,
    ) {
    }

    /**
     * The still frame for a library video.
     *
     * SERVES ONLY WHAT EXISTS — it never extracts. Posters are made at ingest, by
     * bin/backfill-posters.php, and by the demo seed; running ffmpeg here would
     * put a video decode inside a page load, and on a single-threaded dev server
     * a library of ten would block on itself. A miss is a 404 and the template
     * falls back to its gradient, which is why this is safe to be lazy about.
     *
     * A photo is its own poster, so it redirects to the asset itself rather than
     * duplicating the bytes.
     *
     * @param array<string, string> $params
     */
    public function poster(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        // tenant-scoped find, exactly like serve() — a poster is content too
        $asset = ctype_digit($id) ? $this->assets->find($this->workspace, (int) $id) : null;
        if ($asset === null || $this->posters === null) {
            return self::missing();
        }
        if ((string) $asset['kind'] === 'photo') {
            return Response::redirect('/media/' . (int) $asset['id']);
        }

        $path = $this->posters->pathFor($asset);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size === 0) {
            return self::missing();
        }

        return Response::file($path, 200, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            // content-addressed by the asset's sha256: the bytes behind this URL
            // can never change, so it is safe to hold on to
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Length' => (string) $size,
        ]);
    }

    private static function missing(): Response
    {
        return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** @param array<string, string> $params */
    public function serve(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        $asset = ctype_digit($id) ? $this->assets->find($this->workspace, (int) $id) : null;
        if ($asset === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // tenant already verified by the scoped find() above — only now is a URL
        // minted. An R2 object redirects; a local one falls through to streaming.
        $redirect = $this->maybeRedirect(
            (string) ($asset['storage_disk'] ?? 'local'),
            StorageKey::make('asset', (int) $asset['workspace_id'], (string) $asset['stored_name']),
            (string) $asset['mime'],
        );
        if ($redirect !== null) {
            return $redirect;
        }

        $path = $this->storage->path($asset['workspace_id'], (string) $asset['stored_name']);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            error_log("Kuyash: asset row #{$asset['id']} has no file on disk");

            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $baseHeaders = [
            // Content-Type comes from the DB (validated at upload) — never re-sniffed
            'Content-Type' => (string) $asset['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
            // direct navigation to /media/{id} renders inert: kills residual
            // polyglot-file risk on top of nosniff + fixed Content-Type
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $range = self::parseRange((string) ($_SERVER['HTTP_RANGE'] ?? ''), $size);

        if ($range === 'invalid') {
            return new Response('', 416, $baseHeaders + ['Content-Range' => "bytes */{$size}"]);
        }

        if ($range === null) {
            return Response::file($path, 200, $baseHeaders + ['Content-Length' => (string) $size]);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return Response::file($path, 206, $baseHeaders + [
            'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
        ], $start, $length);
    }

    /**
     * Resolve the per-object provider; if it serves remotely, return a 302 to a
     * short-TTL presigned GET (content-type + disposition pinned in the presign),
     * else null so the caller streams locally. A misconfigured disk fails closed
     * (404 + log) — never an unguarded 500 to the client.
     */
    private function maybeRedirect(string $disk, string $key, string $mime): ?Response
    {
        try {
            $url = $this->disks->disk($disk)->temporaryUrl($key, $this->presignTtl, [
                'response-content-type' => $mime,
                'response-content-disposition' => 'inline',
            ]);
        } catch (StorageException $e) {
            error_log('Kuyash: storage disk resolve failed — ' . $e->getMessage());

            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if ($url === null) {
            return null; // local provider → stream
        }

        // no-store: the URL is short-lived; a cache must not pin an expiring link
        return new Response('', 302, ['Location' => $url, 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Parse a single-range header against a known size. Pure — unit-tested
     * directly. Returns [start, end] inclusive, null for "serve full 200"
     * (no/multi/malformed range) or 'invalid' for unsatisfiable ranges (416).
     *
     * @return array{0: int, 1: int}|string|null
     */
    public static function parseRange(string $header, int $size): array|string|null
    {
        if ($header === '' || $size <= 0 || !str_starts_with($header, 'bytes=')) {
            return null;
        }

        $spec = substr($header, 6);
        if (str_contains($spec, ',')) {
            return null; // multi-range → full 200 (legal per RFC 9110)
        }

        if (preg_match('/^(\d*)-(\d*)$/', trim($spec), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            // suffix form: last N bytes
            $suffix = (int) $m[2];
            if ($suffix === 0) {
                return 'invalid';
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return 'invalid';
        }

        return [$start, $end];
    }
}
