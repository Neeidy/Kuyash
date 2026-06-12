<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Response;
use Kuyash\Media\MediaPaths;
use Kuyash\Media\RenderRepository;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Private serving of render artifacts (draft/final MP4 + poster). Tenant-scoped
 * by the session workspace; reuses MediaController's single-range parser so the
 * <video> element seeks/streams correctly. Names come from the DB (validated at
 * write); paths are resolved + re-validated by MediaPaths — user input never
 * reaches a filesystem path (security rule).
 */
final class RenderController
{
    public function __construct(
        private readonly RenderRepository $renders,
        private readonly MediaPaths $paths,
        private readonly WorkspaceContext $workspace,
    ) {
    }

    /** @param array<string, string> $params */
    public function serve(array $params = []): Response
    {
        $render = $this->findRender($params);
        if ($render === null) {
            return $this->notFound();
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

    private function notFound(): Response
    {
        return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
