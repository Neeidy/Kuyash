<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Auth\Auth;
use Kuyash\Library\AssetRepository;
use Kuyash\Publish\PostRepository;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\Nodes;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Workflows (read-only node track + run trigger) and run detail pages.
 * The builder is deliberately read-only in Phase 4 (user decision): settings
 * editing arrives with the real engines (Phase 5+), and mocks ignore settings.
 */
final class WorkflowController
{
    public function __construct(
        private readonly View $view,
        private readonly WorkflowRepository $workflows,
        private readonly RunRepository $runs,
        private readonly JobRepository $jobs,
        private readonly EventLog $events,
        private readonly Engine $engine,
        private readonly AssetRepository $assets,
        private readonly PostRepository $posts,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly \Kuyash\Content\TextEditorView $editor,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $this->workflows->ensureDefaults($this->workspace);

        return Response::html($this->view->render('workflows/index', [
            'title' => 'Workflows — Kuyash',
            'active' => 'workflows',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'workflows' => $this->workflows->listFor($this->workspace),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function show(array $params = []): Response
    {
        $workflow = $this->findWorkflow($params);
        if ($workflow === null) {
            return $this->notFound();
        }
        if ($workflow['template'] === Nodes::TEMPLATE_QUICK_CREATE) {
            return Response::redirect('/quick', 303); // quick_create runs from its own page
        }

        $isDistribution = $workflow['template'] === Nodes::TEMPLATE_DISTRIBUTION;

        return Response::html($this->view->render('workflows/show', [
            'title' => $workflow['name'] . ' — Workflows',
            'active' => 'workflows',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'workflow' => $workflow,
            'readyVideos' => $isDistribution ? $this->assets->readyVideosFor($this->workspace) : [],
            // full runs may pin an optional reference subject (any ready asset)
            'references' => $isDistribution ? [] : $this->assets->readyReferencesFor($this->workspace),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function run(array $params = []): Response
    {
        $workflow = $this->findWorkflow($params);
        if ($workflow === null) {
            return $this->notFound();
        }
        if ($workflow['template'] === Nodes::TEMPLATE_QUICK_CREATE) {
            return Response::redirect('/quick', 303); // quick_create runs from its own page
        }

        $assetRaw = (string) ($_POST['asset_id'] ?? '');
        $assetId = ctype_digit($assetRaw) && $assetRaw !== '' ? (int) $assetRaw : null;
        $refRaw = (string) ($_POST['reference_asset_id'] ?? '');
        $referenceId = ctype_digit($refRaw) && $refRaw !== '' ? (int) $refRaw : null;
        $userId = (int) ($this->auth->user()['id'] ?? 0);

        try {
            $this->engine->startRun($this->workspace, $workflow['id'], $assetId, $userId, null, $referenceId);
        } catch (WorkflowException $e) {
            $this->flash->add('error', $e->messageKey);

            return Response::redirect('/workflows/' . $workflow['id'], 303);
        }

        $this->flash->add('success', 'run.started');

        return Response::redirect('/queue', 303);
    }

    /** @param array<string, string> $params */
    public function showRun(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        $run = ctype_digit($id) ? $this->runs->find($this->workspace, (int) $id) : null;
        if ($run === null) {
            return $this->notFound();
        }

        return Response::html($this->view->render('runs/show', [
            'title' => 'Run #' . $run['id'] . ' — Kuyash',
            'active' => 'queue',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'run' => $run,
            'jobs' => $this->jobs->jobsForRun($this->workspace, $run['id']),
            'timeline' => $this->events->timelineForRun($this->workspace, $run['id']),
            'approvals' => $this->runs->approvalsForRun($this->workspace, $run['id']),
            'posts' => $this->posts->forRun($this->workspace, $run['id']),
            // Phase 25 — the same editor the approval card shows, so the counts
            // and the AI notice cannot differ between the two screens
            'text' => $this->editor->forRun($this->workspace, (int) $run['id']),
        ], 'layout/app'));
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>|null
     */
    private function findWorkflow(array $params): ?array
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return null;
        }

        return $this->workflows->find($this->workspace, (int) $id);
    }

    private function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', ['title' => '404 — Not Found']), 404);
    }
}
