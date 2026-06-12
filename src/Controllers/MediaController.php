<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Response;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Private media serving with single-range support. Safari refuses to play
 * <video> without 206 responses (it probes with Range: bytes=0-1) and
 * Chrome needs ranges to seek — so this is required, not optional.
 * Multi-range requests are answered with a full 200 (allowed by RFC 9110).
 */
final class MediaController
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly AssetStorage $storage,
        private readonly WorkspaceContext $workspace,
    ) {
    }

    /** @param array<string, string> $params */
    public function serve(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        $asset = ctype_digit($id) ? $this->assets->find($this->workspace, (int) $id) : null;
        if ($asset === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
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
