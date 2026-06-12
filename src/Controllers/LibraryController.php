<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Format;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Library\InvalidUploadException;
use Kuyash\Library\UploadedFile;
use Kuyash\Workspace\WorkspaceContext;

final class LibraryController
{
    /**
     * Message KEYS resolved here (i18n-ready: the future TR pass swaps this
     * map for a dictionary lookup — templates never see raw keys).
     */
    private const MESSAGES = [
        'upload.success' => 'Upload complete — the asset is ready.',
        'upload.too_large' => 'The file exceeds the server upload limit.',
        'upload.video_too_large' => 'The video exceeds the size limit.',
        'upload.photo_too_large' => 'The photo exceeds the size limit.',
        'upload.no_file' => 'Choose a file to upload.',
        'upload.failed' => 'The upload failed — please try again.',
        'upload.empty' => 'The uploaded file is empty.',
        'upload.extension_not_allowed' => 'That file format is not supported.',
        'upload.content_mismatch' => 'The file content does not match its extension.',
        'upload.broken_image' => 'The image file is broken or unreadable.',
        'upload.bad_type' => 'Pick a valid asset type.',
        'asset.deleted' => 'Asset deleted.',
        'asset.delete_failed' => 'The asset could not be deleted.',
    ];

    /** Types creatable from the UI in Phase 3 (stock/ai arrive in later phases). */
    private const UPLOADABLE_TYPES = ['own', 'face'];

    public function __construct(
        private readonly View $view,
        private readonly AssetRepository $assets,
        private readonly AssetIngest $ingest,
        private readonly AssetStorage $storage,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        /** @var array<string, mixed> */
        private readonly array $libraryConfig,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $type = isset($_GET['type']) ? (string) $_GET['type'] : '';
        if (!in_array($type, ['own', 'face', 'stock', 'ai'], true)) {
            $type = '';
        }

        $items = $this->assets->listFor($this->workspace, $q !== '' ? $q : null, $type !== '' ? $type : null);

        return Response::html($this->view->render('library/index', [
            'title' => 'Library — Kuyash',
            'active' => 'library',
            'workspaceName' => $this->workspace->currentName(),
            'items' => $items,
            'q' => $q,
            'type' => $type,
            'csrfField' => $this->csrf->field(),
            'flashes' => $this->resolveFlashes(),
            'maxVideoBytes' => (int) $this->libraryConfig['max_video_bytes'],
            'maxPhotoBytes' => (int) $this->libraryConfig['max_photo_bytes'],
        ] + $this->uploadFormMeta(), 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function upload(array $params = []): Response
    {
        $file = UploadedFile::fromArray($_FILES['file'] ?? []);

        // SAPI-only check lives here, not in the service (CLI testability)
        if ($file->tmpPath === '' || !is_uploaded_file($file->tmpPath)) {
            return $this->backToLibrary('error', 'upload.no_file');
        }

        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, self::UPLOADABLE_TYPES, true)) {
            return $this->backToLibrary('error', 'upload.bad_type');
        }

        try {
            $this->ingest->ingest(
                $this->workspace,
                $file,
                $type,
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['tags'] ?? ''),
            );
        } catch (InvalidUploadException $e) {
            return $this->backToLibrary('error', $e->messageKey);
        }

        return $this->backToLibrary('success', 'upload.success');
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): Response
    {
        $asset = $this->findFromParams($params);
        if ($asset === null) {
            return $this->notFound();
        }

        return Response::html($this->view->render('library/show', [
            'title' => $asset['title'] . ' — Library',
            'active' => 'library',
            'workspaceName' => $this->workspace->currentName(),
            'asset' => $asset,
            'csrfField' => $this->csrf->field(),
            'flashes' => $this->resolveFlashes(),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function delete(array $params = []): Response
    {
        $asset = $this->findFromParams($params);
        if ($asset === null) {
            return $this->notFound();
        }

        // row first (DB is the source of truth), unlink after — an orphan
        // file in a private dir is harmless; a live row pointing at nothing
        // would 500 on /media/{id}
        if (!$this->assets->delete($this->workspace, $asset['id'])) {
            return $this->backToLibrary('error', 'asset.delete_failed');
        }

        if (!$this->storage->delete($asset['workspace_id'], (string) $asset['stored_name'])) {
            error_log("Kuyash: asset file missing or not deletable: {$asset['stored_name']}");
        }

        return $this->backToLibrary('success', 'asset.deleted');
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function findFromParams(array $params): ?array
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return null;
        }

        return $this->assets->find($this->workspace, (int) $id);
    }

    /**
     * Upload-form copy derived from config — the allowlist and caps have one
     * source of truth, so the template can never silently lie about them.
     *
     * @return array<string, string>
     */
    private function uploadFormMeta(): array
    {
        $videoExts = [];
        $photoExts = [];
        $accept = [];
        foreach ((array) $this->libraryConfig['allowed'] as $ext => [$mime, $kind]) {
            $accept['.' . $ext] = true;
            $accept[$mime] = true;
            if ($kind === 'video') {
                $videoExts[] = strtoupper((string) $ext);
            } else {
                $photoExts[] = strtoupper((string) $ext);
            }
        }

        return [
            'acceptAttr' => implode(',', array_keys($accept)),
            'videoLabel' => implode('/', $videoExts),
            'photoLabel' => implode('/', $photoExts),
            'maxVideoLabel' => Format::bytes((int) $this->libraryConfig['max_video_bytes']),
            'maxPhotoLabel' => Format::bytes((int) $this->libraryConfig['max_photo_bytes']),
        ];
    }

    /** @return list<array{type: string, text: string}> */
    private function resolveFlashes(): array
    {
        return array_map(
            static fn (array $f): array => [
                'type' => $f['type'],
                'text' => self::MESSAGES[$f['key']] ?? $f['key'],
            ],
            $this->flash->pull(),
        );
    }

    private function backToLibrary(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/library', 303);
    }

    private function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', ['title' => '404 — Not Found']), 404);
    }
}
