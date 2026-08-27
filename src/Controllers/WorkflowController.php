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
        // optional so every hand-built construction site (and the tests) keeps
        // working; a null poster service simply means the run page shows the
        // video without a still, never a broken <img>
        private readonly ?\Kuyash\Media\AssetPoster $posters = null,
        private readonly ?\Kuyash\Media\RenderRepository $renders = null,
        private readonly ?\Kuyash\Media\MediaPaths $paths = null,
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

    /**
     * The one video this run is about, as a playable source + a still.
     *
     * Order matters and is not arbitrary: the FINAL render first, because on a
     * published run that is the file that actually went out and the page is
     * being read as evidence of it. Only then the draft that was put up for
     * approval, and only then the library clip a distribution run carries
     * instead of a render of its own.
     *
     * Null when the run genuinely has no video yet (it is still in script or
     * voice) — the page says so rather than reserving an empty frame.
     *
     * @param list<array<string, mixed>> $jobs
     * @return array{src: string, poster: string|null}|null
     */
    private function runPreview(array $jobs): ?array
    {
        $result = [];
        foreach ($jobs as $job) {
            $result[(string) ($job['type'] ?? '')] = (array) ($job['result'] ?? []);
        }

        $renderId = $result['final_render']['render_id'] ?? $result['render_review']['draft_render_id'] ?? null;
        if ($renderId !== null) {
            // NO FALL-THROUGH. Once a run names a render, that render is the video
            // this page is about; if its bytes are gone the honest answer is to
            // show nothing. Falling back to asset_fetch would put the raw source
            // clip under the heading "The video" on a run whose status says
            // completed and whose targets card says it was published — the same
            // "this is a frame of a different clip" lie the seed was just fixed
            // for, one layer up.
            return $this->renderPlayable((int) $renderId);
        }

        $assetId = $result['render_review']['library_asset_id'] ?? $result['asset_fetch']['asset_id'] ?? null;
        if ($assetId === null) {
            return null;
        }
        $asset = $this->assets->find($this->workspace, (int) $assetId);
        if ($asset === null || !$this->onDisk('asset', $asset, 'stored_name')) {
            return null;
        }
        // ask before emitting: a poster URL that 404s is worse than no still,
        // because the frame it leaves behind looks like a video that failed
        $hasPoster = $this->posters !== null && $this->posters->exists($asset);

        return [
            'src' => '/media/' . (int) $assetId,
            'poster' => $hasPoster ? '/media/' . (int) $assetId . '/poster' : null,
        ];
    }

    /**
     * A render turned into a playable source, or null when there are no bytes.
     *
     * A row is not a file. The visual fixture carries `renders` rows whose
     * files were never written, and the run page happily emitted a <video> for
     * one — a red broken-media block where the run's own footage should be, and
     * a 404 in the console. The queue card never hit this because it only ever
     * shows a render the pipeline just produced. Checking here is cheaper than
     * finding out from the browser.
     *
     * @return array{src: string, poster: string|null}|null
     */
    private function renderPlayable(int $id): ?array
    {
        $render = $this->renders?->find($this->workspace->id(), $id);
        if ($render === null || !$this->onDisk('render', $render, 'stored_name')) {
            return null;
        }
        $poster = ($render['poster_name'] ?? null) !== null && $this->onDisk('render', $render, 'poster_name')
            ? '/render/' . $id . '/poster'
            : null;

        return ['src' => '/render/' . $id, 'poster' => $poster];
    }

    /**
     * Are this row's bytes actually here?
     *
     * Only answerable for the local disk: a row on remote storage is served by
     * a signed redirect, and reaching across the network to pre-flight a page
     * render would trade a rare broken tile for a slow page on every load. So
     * remote rows are taken at their word, and local rows — the case that was
     * lying — are checked.
     *
     * @param array<string, mixed> $row
     */
    private function onDisk(string $store, array $row, string $nameKey): bool
    {
        $name = (string) ($row[$nameKey] ?? '');
        if ($name === '' || $this->paths === null) {
            return false;
        }
        if ((string) ($row['storage_disk'] ?? 'local') !== 'local') {
            return true;
        }
        // resolve(ref(...)), NOT pathFor(): pathFor ensures the directory exists,
        // so merely ASKING whether a file is here would create a folder tree on
        // a page load. The same read path RenderController uses.
        try {
            $path = $this->paths->resolve($this->paths->ref($store, (int) ($row['workspace_id'] ?? 0), $name));
        } catch (\Throwable $e) {
            return false;   // a name this store will not accept has no bytes here
        }

        return is_file($path) && filesize($path) > 0;
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
            'jobs' => $runJobs = $this->jobs->jobsForRun($this->workspace, $run['id']),
            // Phase: the run page had no picture of the video anywhere on it —
            // not even the published one, on the page whose whole claim is
            // "this went out". See runPreview().
            'preview' => $this->runPreview($runJobs),
            'timeline' => $this->events->timelineForRun($this->workspace, $run['id']),
            'approvals' => $this->runs->approvalsForRun($this->workspace, $run['id']),
            // who is LOOKING — so an approval chip can say "by you" only when it
            // actually was, instead of saying it to everyone
            'viewerId' => $this->auth->user()['id'] ?? null,
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
