<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\AssetStorage;
use Kuyash\Library\InvalidUploadException;
use Kuyash\Library\UploadedFile;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

final class LibraryController
{
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
        private readonly WorkspaceSettings $settings,
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
            'isAvatar' => $this->settings->avatarAssetId($this->workspace->id()) === $asset['id'],
            'csrfField' => $this->csrf->field(),
            'flashes' => $this->resolveFlashes(),
        ], 'layout/app'));
    }

    /** Set this asset as the workspace default avatar (reference-asset model). */
    public function setAvatar(array $params = []): Response
    {
        $asset = $this->findFromParams($params);
        if ($asset === null) {
            return $this->notFound();
        }

        $ok = $this->settings->setAvatar($this->workspace->id(), $asset['id']);

        return $this->backToLibrary($ok ? 'success' : 'error', $ok ? 'avatar.updated' : 'avatar.invalid');
    }

    /** Clear the workspace default avatar. */
    public function clearAvatar(array $params = []): Response
    {
        $this->settings->clearAvatar($this->workspace->id());

        return $this->backToLibrary('success', 'avatar.cleared');
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
        // keys now live in the shared Core\Messages dictionary (Phase 4
        // follow-up: third flash consumer triggered the extraction)
        return Messages::resolveFlashes($this->flash);
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
