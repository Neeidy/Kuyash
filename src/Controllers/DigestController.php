<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Compliance\DigestReport;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Workspace\WorkspaceContext;

/**
 * The daily digest page (Phase 9): what the compliance agent did autonomously
 * on one UTC date — auto-approvals, auto-publishes, guardrail events, the
 * current quality score. ?date=YYYY-MM-DD, default today (UTC); a malformed
 * date falls back to today rather than erroring (it is a read-only report).
 */
final class DigestController
{
    public function __construct(
        private readonly View $view,
        private readonly DigestReport $digest,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $date = $this->validDate((string) ($_GET['date'] ?? ''));

        return Response::html($this->view->render('digest/index', [
            'title' => 'Daily digest — Kuyash',
            'active' => 'digest',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'digest' => $this->digest->forDate($this->workspace, $date),
            'today' => gmdate('Y-m-d'),
        ], 'layout/app'));
    }

    private function validDate(string $raw): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $raw;
        }

        return gmdate('Y-m-d');
    }
}
