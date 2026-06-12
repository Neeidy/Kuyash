<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Workspace\WorkspaceContext;
use RuntimeException;

final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
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

        return Response::html($this->view->render('dashboard', [
            'title' => 'Dashboard — Kuyash',
            'active' => 'dashboard',
            'email' => (string) $user['email'],
            'name' => (string) ($user['name'] ?? ''),
            'workspaceName' => $workspace['name'],
            'role' => $workspace['role'],
            'csrfField' => $this->csrf->field(),
        ], 'layout/app'));
    }
}
