<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Response;
use Kuyash\Content\TextEditorView;
use Kuyash\Core\View;
use Kuyash\Workflow\Cockpit;
use Kuyash\Workflow\WorkerHeartbeat;
use Kuyash\Workspace\WorkspaceContext;
use RuntimeException;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly Cockpit $cockpit,
        // Phase 25: the approval cards here are the same posts the queue shows,
        // so their compliance chip has to answer the same question — what does
        // the text that will publish score? Optional so existing constructions
        // stay valid; null just leaves the generated verdict rendering.
        private readonly ?TextEditorView $editor = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            // unreachable behind the route guard; kept as a fail-closed backstop
            return Response::redirect('/login');
        }

        // membership-scoped lookup: the tenant-isolation pattern in action
        $workspace = $this->workspace->workspaceForUser($this->workspace->id(), (int) $user['id']);
        if ($workspace === null) {
            throw new RuntimeException('Session workspace has no membership for this user.');
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $cockpit = $this->cockpit->snapshot($this->workspace, $now);
        $cockpit['awaiting'] = $this->withBadges($cockpit['awaiting'] ?? []);

        return Response::html($this->view->render('dashboard', [
            'title' => 'Dashboard — Kuyash',
            'active' => 'dashboard',
            'email' => (string) $user['email'],
            'name' => (string) ($user['name'] ?? ''),
            'workspaceName' => $workspace['name'],
            'role' => $workspace['role'],
            'csrfField' => $this->csrf->field(),
            'workerAlive' => $this->heartbeat->isAlive($now),
            'cockpit' => $cockpit,
        ], 'layout/app'));
    }

    /**
     * Attach the "what does the outgoing text score" chip to each approval card.
     * Null for a run nobody edited — the card then renders the compliance_check
     * verdict, which is still exactly right.
     *
     * @param list<array<string, mixed>> $jobs
     *
     * @return list<array<string, mixed>>
     */
    private function withBadges(array $jobs): array
    {
        if ($this->editor === null) {
            return $jobs;
        }
        foreach ($jobs as $i => $job) {
            $jobs[$i]['badge'] = (string) ($job['type'] ?? '') === 'render_review'
                ? $this->editor->badgeFor($this->workspace, (int) ($job['run_id'] ?? 0))
                : null;
        }

        return $jobs;
    }
}
