<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Workflow\Decision;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkerHeartbeat;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Render queue: approvals first (action needed), then the flat job list and
 * runs. Approve/reject/retry POST through guarded engine transitions — a
 * race loser gets the calm "already decided" flash, never an error page.
 */
final class QueueController
{
    public function __construct(
        private readonly View $view,
        private readonly JobRepository $jobs,
        private readonly RunRepository $runs,
        private readonly Engine $engine,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly WorkerHeartbeat $heartbeat,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        return Response::html($this->view->render('queue/index', [
            'title' => 'Queue — Kuyash',
            'active' => 'queue',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'awaiting' => $this->jobs->awaitingApproval($this->workspace),
            'jobs' => $this->jobs->listFor($this->workspace),
            'runs' => $this->runs->listFor($this->workspace, 20),
            'workerAlive' => $this->heartbeat->isAlive(gmdate('Y-m-d\TH:i:s\Z')),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function approve(array $params = []): Response
    {
        return $this->decide($params, 'approve');
    }

    /** @param array<string, string> $params */
    public function reject(array $params = []): Response
    {
        return $this->decide($params, 'reject');
    }

    /** @param array<string, string> $params */
    public function retry(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->notFound();
        }

        $user = $this->auth->user();
        $decision = $this->engine->retryJob(
            $this->workspace,
            (int) $id,
            (int) ($user['id'] ?? 0),
            (string) ($user['email'] ?? ''),
        );

        return match ($decision) {
            Decision::NotFound => $this->notFound(),
            Decision::AlreadyDecided => $this->backToQueue('error', 'job.retry_not_failed'),
            Decision::Ok => $this->backToQueue('success', 'job.retried'),
        };
    }

    /** @param array<string, string> $params */
    private function decide(array $params, string $action): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->notFound();
        }

        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);
        $email = (string) ($user['email'] ?? '');

        $decision = $action === 'approve'
            ? $this->engine->approve($this->workspace, (int) $id, $userId, $email)
            : $this->engine->reject($this->workspace, (int) $id, $userId, $email);

        return match ($decision) {
            Decision::NotFound => $this->notFound(),
            Decision::AlreadyDecided => $this->backToQueue('error', 'approval.already_decided'),
            Decision::Ok => $this->backToQueue(
                'success',
                $action === 'approve' ? 'approval.approved' : 'approval.rejected',
            ),
        };
    }

    private function backToQueue(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/queue', 303);
    }

    private function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', ['title' => '404 — Not Found']), 404);
    }
}
