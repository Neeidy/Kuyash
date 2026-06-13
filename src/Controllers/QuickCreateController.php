<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Library\AssetIngest;
use Kuyash\Library\AssetRepository;
use Kuyash\Library\InvalidUploadException;
use Kuyash\Library\UploadedFile;
use Kuyash\Usage\CostEstimator;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\Nodes;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Quick Create (Phase 12): photo + prompt → AI image-to-video → the existing
 * compliance + publish tail. The dedicated entry surface for the quick_create
 * template (which is kept out of the generic /workflows builder). The page shows
 * the live estimated credit cost and the mandatory AI-label notice; the POST
 * ingests an uploaded photo (or accepts a picked ready one) and starts the run
 * — where the Phase 11 pre-flight budget gate refuses an over-budget run before
 * any spend.
 */
final class QuickCreateController
{
    private const MAX_PROMPT = 300; // matches WorkflowValidator::MAX_STRING_LENGTH

    public function __construct(
        private readonly View $view,
        private readonly AssetRepository $assets,
        private readonly AssetIngest $ingest,
        private readonly WorkflowRepository $workflows,
        private readonly Engine $engine,
        private readonly CostEstimator $estimator,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        /** @var array<string, mixed> library config (allowlist + caps) */
        private readonly array $libraryConfig,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $this->workflows->ensureDefaults($this->workspace);
        $estimate = $this->estimator->estimateRun(
            Nodes::TEMPLATE_QUICK_CREATE,
            Nodes::defaultNodes(Nodes::TEMPLATE_QUICK_CREATE),
        );

        return Response::html($this->view->render('quick/index', [
            'title' => 'Quick Create — Kuyash',
            'active' => 'quick',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'photos' => $this->assets->readyPhotosFor($this->workspace),
            'estimateCents' => (int) $estimate['total_cents'],
            'maxPrompt' => self::MAX_PROMPT,
            'photoLabel' => $this->photoLabel(),
            'maxPhotoLabel' => Format::bytes((int) $this->libraryConfig['max_photo_bytes']),
            'acceptAttr' => $this->photoAccept(),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function create(array $params = []): Response
    {
        $workflow = $this->workflows->findByTemplate($this->workspace, Nodes::TEMPLATE_QUICK_CREATE);
        if ($workflow === null) {
            // ensureDefaults seeds it; a missing row means a real setup problem
            $this->workflows->ensureDefaults($this->workspace);
            $workflow = $this->workflows->findByTemplate($this->workspace, Nodes::TEMPLATE_QUICK_CREATE);
            if ($workflow === null) {
                return $this->back('error', 'quick.no_workflow');
            }
        }

        $prompt = trim((string) ($_POST['prompt'] ?? ''));
        if ($prompt === '') {
            return $this->back('error', 'quick.prompt_required');
        }
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            return $this->back('error', 'quick.prompt_too_long');
        }

        $photoId = $this->resolvePhoto();
        if (is_string($photoId)) {
            return $this->back('error', $photoId); // a message key
        }

        $userId = (int) ($this->auth->user()['id'] ?? 0);
        try {
            $this->engine->startRun(
                $this->workspace,
                (int) $workflow['id'],
                null,           // no library video
                $userId,
                null,           // no trend
                $photoId,       // the reference photo to animate
                $prompt,
            );
        } catch (WorkflowException $e) {
            return $this->back('error', $e->messageKey);
        }

        $this->flash->add('success', 'quick.started');

        return Response::redirect('/queue', 303);
    }

    /**
     * Resolve the reference photo: an uploaded file (ingested as a library photo)
     * takes precedence, else a picked ready photo id. Returns the photo asset id,
     * or a message-key string on failure.
     */
    private function resolvePhoto(): int|string
    {
        $file = UploadedFile::fromArray($_FILES['photo'] ?? []);
        if ($file->tmpPath !== '' && is_uploaded_file($file->tmpPath)) {
            try {
                $id = $this->ingest->ingest($this->workspace, $file, 'own', '', '');
            } catch (InvalidUploadException $e) {
                return $e->messageKey;
            }
            $asset = $this->assets->find($this->workspace, $id);
            if ($asset === null || (string) $asset['kind'] !== 'photo') {
                // a non-photo upload (e.g. a video) is not animatable here — drop the
                // just-created row so the failed attempt does not litter the Library
                $this->assets->delete($this->workspace, $id);

                return 'quick.photo_not_image';
            }

            return $id;
        }

        $pick = (string) ($_POST['photo_id'] ?? '');
        if ($pick === '' || !ctype_digit($pick)) {
            return 'quick.photo_required';
        }
        $asset = $this->assets->find($this->workspace, (int) $pick);
        if ($asset === null || (string) $asset['kind'] !== 'photo' || (string) $asset['status'] !== 'ready') {
            return 'quick.photo_invalid';
        }

        return (int) $pick;
    }

    /** Accepted photo extensions + mimes for the file input (from the allowlist). */
    private function photoAccept(): string
    {
        $accept = [];
        foreach ((array) $this->libraryConfig['allowed'] as $ext => [$mime, $kind]) {
            if ($kind === 'photo') {
                $accept['.' . $ext] = true;
                $accept[$mime] = true;
            }
        }

        return implode(',', array_keys($accept));
    }

    private function photoLabel(): string
    {
        $exts = [];
        foreach ((array) $this->libraryConfig['allowed'] as $ext => [, $kind]) {
            if ($kind === 'photo') {
                $exts[] = strtoupper((string) $ext);
            }
        }

        return implode('/', array_values(array_unique($exts)));
    }

    private function back(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/quick', 303);
    }
}
