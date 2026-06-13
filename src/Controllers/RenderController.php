<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Response;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\RenderRepository;
use Kuyash\Storage\StorageException;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Private serving of render artifacts (draft/final MP4 + poster). Tenant-scoped
 * by the session workspace; the provider is resolved PER OBJECT from storage_disk
 * — an R2 render 302-redirects to a short-TTL presigned GET, a local render is
 * streamed with single-range support (reusing MediaController's parser so the
 * <video> element seeks). Names come from the DB (validated at write); local
 * paths are re-validated by MediaPaths — user input never reaches a path.
 */
final class RenderController
{
    public function __construct(
        private readonly RenderRepository $renders,
        private readonly MediaPaths $paths,
        private readonly StorageManager $disks,
        private readonly WorkspaceContext $workspace,
        private readonly int $presignTtl = 300,
    ) {
    }

    /** @param array<string, string> $params */
    public function serve(array $params = []): Response
    {
        $render = $this->findRender($params);
        if ($render === null) {
            return $this->notFound();
        }

        // tenant verified by the scoped findRender() above; only now mint a URL
        $redirect = $this->maybeRedirect(
            (string) ($render['storage_disk'] ?? 'local'),
            StorageKey::make('render', (int) $render['workspace_id'], (string) $render['stored_name']),
            (string) $render['mime'],
        );
        if ($redirect !== null) {
            return $redirect;
        }

        // resolve() = read path, no directory side-effect (the file already exists)
        $path = $this->paths->resolve($this->paths->ref('render', (int) $render['workspace_id'], (string) $render['stored_name']));
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            error_log("Kuyash: render #{$render['id']} has no file on disk");

            return $this->notFound();
        }

        $base = [
            'Content-Type' => (string) $render['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $range = MediaController::parseRange((string) ($_SERVER['HTTP_RANGE'] ?? ''), $size);
        if ($range === 'invalid') {
            return new Response('', 416, $base + ['Content-Range' => "bytes */{$size}"]);
        }
        if ($range === null) {
            return Response::file($path, 200, $base + ['Content-Length' => (string) $size]);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        return Response::file($path, 206, $base + [
            'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
        ], $start, $length);
    }

    /** @param array<string, string> $params */
    public function poster(array $params = []): Response
    {
        $render = $this->findRender($params);
        if ($render === null || $render['poster_name'] === null) {
            return $this->notFound();
        }

        $redirect = $this->maybeRedirect(
            (string) ($render['storage_disk'] ?? 'local'),
            StorageKey::make('render', (int) $render['workspace_id'], (string) $render['poster_name']),
            'image/jpeg',
        );
        if ($redirect !== null) {
            return $redirect;
        }

        $path = $this->paths->resolve($this->paths->ref('render', (int) $render['workspace_id'], (string) $render['poster_name']));
        if (!is_file($path)) {
            return $this->notFound();
        }

        return Response::file($path, 200, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Length' => (string) filesize($path),
        ]);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function findRender(array $params): ?array
    {
        $id = $params['id'] ?? '';

        return ctype_digit($id) ? $this->renders->find($this->workspace->id(), (int) $id) : null;
    }

    /**
     * Per-object provider → 302 to a presigned GET (remote) or null (stream
     * locally). Misconfigured disk fails closed (404 + log), never a raw 500.
     */
    private function maybeRedirect(string $disk, string $key, string $mime): ?Response
    {
        try {
            $url = $this->disks->disk($disk)->temporaryUrl($key, $this->presignTtl, [
                'response-content-type' => $mime,
                'response-content-disposition' => 'inline',
            ]);
        } catch (StorageException $e) {
            error_log('Kuyash: render storage disk resolve failed — ' . $e->getMessage());

            return $this->notFound();
        }

        if ($url === null) {
            return null;
        }

        return new Response('', 302, ['Location' => $url, 'Cache-Control' => 'private, no-store']);
    }

    private function notFound(): Response
    {
        return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
