<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Workflow\EventLog;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Event feed, newest first, LIMIT 200, plain reload. Filter chips mirror the
 * Phase 0 demo: all / info / warn / error by level, compliance by kind
 * (compliance + guardrail). No SSE/auto-refresh until jobs are genuinely
 * slow (Phase 7).
 */
final class LogsController
{
    private const FILTERS = ['all', 'info', 'warn', 'error', 'compliance'];

    public function __construct(
        private readonly View $view,
        private readonly EventLog $events,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $filter = (string) ($_GET['f'] ?? 'all');
        if (!in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $events = match ($filter) {
            'info', 'warn', 'error' => $this->events->listFor($this->workspace, level: $filter),
            'compliance' => $this->events->listFor($this->workspace, kinds: ['compliance', 'guardrail']),
            default => $this->events->listFor($this->workspace),
        };

        return Response::html($this->view->render('logs/index', [
            'title' => 'Logs — Kuyash',
            'active' => 'logs',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'events' => $events,
            'filter' => $filter,
            'filters' => self::FILTERS,
        ], 'layout/app'));
    }
}
