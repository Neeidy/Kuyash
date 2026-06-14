<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Compliance\AutoApprovalGate;
use Kuyash\Compliance\CompliancePolicy;
use Kuyash\Compliance\QualityScore;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Workflow\EventLog;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * Workspace compliance settings (Phase 9): approval mode, daily post cap,
 * budget cap, kill switch — plus the read-only quality score and "auto slots
 * used today" so flipping to Auto is an informed act. Every change writes a
 * guardrail audit event with the acting user (the compliance rule: settings
 * that govern autonomy are themselves audited).
 */
final class SettingsController
{
    public function __construct(
        private readonly View $view,
        private readonly WorkspaceSettings $settings,
        private readonly QualityScore $quality,
        private readonly AutoApprovalGate $gate,
        private readonly EventLog $events,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return Response::html($this->view->render('settings/index', [
            'title' => 'Settings — Kuyash',
            'active' => 'settings',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'settings' => $this->settings->compliance($wsId),
            'quality' => $this->quality->compute($wsId),
            'policyVersion' => CompliancePolicy::VERSION,
            'autoUsedToday' => $this->gate->autoApprovalsToday($wsId, $now),
            'spentThisMonthCents' => $this->gate->monthToDateSpendCents($wsId, $now),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function save(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $user = $this->auth->user();
        $before = $this->settings->compliance($wsId);

        $mode = (string) ($_POST['approval_mode'] ?? '');
        $capRaw = (string) ($_POST['daily_post_cap'] ?? '');
        $budgetRaw = trim((string) ($_POST['budget_cap_usd'] ?? ''));

        if (!in_array($mode, ['manual', 'auto'], true) || !ctype_digit($capRaw)) {
            return $this->back('error', 'settings.invalid');
        }
        // budget: empty = no cap; otherwise whole USD converted to cents
        $budgetCents = null;
        if ($budgetRaw !== '') {
            if (!ctype_digit($budgetRaw) || (int) $budgetRaw === 0 || strlen($budgetRaw) > 6) {
                return $this->back('error', 'settings.invalid');
            }
            $budgetCents = (int) $budgetRaw * 100;
        }

        if (!$this->settings->setDailyPostCap($wsId, (int) $capRaw)
            || !$this->settings->setBudgetCapCents($wsId, $budgetCents)
            || !$this->settings->setApprovalMode($wsId, $mode)) {
            return $this->back('error', 'settings.invalid');
        }

        if ($before['approval_mode'] !== $mode) {
            // mode changes are audited: enabling Auto (or re-enabling it after
            // a quality fallback) is always a deliberate, attributable human act
            $this->events->record($wsId, 'info', 'guardrail', 'guardrail.approval_mode_changed', [
                'mode' => $mode,
                'user' => (string) ($user['email'] ?? ''),
            ]);
        }

        return $this->back('success', 'settings.saved');
    }

    /**
     * Rename the active workspace (the topbar chip). ADDITIVE: writes the
     * existing workspaces.name column — no new schema. CSRF is enforced globally
     * (public/index.php) and the workspace id is the session-resolved tenant.
     *
     * @param array<string, string> $params
     */
    public function saveName(array $params = []): Response
    {
        $name = (string) ($_POST['workspace_name'] ?? '');

        if (!$this->settings->setName($this->workspace->id(), $name)) {
            return $this->back('error', 'settings.name_invalid');
        }

        return $this->back('success', 'settings.name_saved');
    }

    /** @param array<string, string> $params */
    public function killSwitch(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $user = $this->auth->user();
        $turnOn = ((string) ($_POST['state'] ?? '')) === 'on';

        $this->settings->setKillSwitch($wsId, $turnOn);
        $this->events->record(
            $wsId,
            $turnOn ? 'warn' : 'info',
            'guardrail',
            $turnOn ? 'guardrail.kill_switch_on' : 'guardrail.kill_switch_off',
            ['user' => (string) ($user['email'] ?? '')],
        );

        return $this->back('success', $turnOn ? 'killswitch.on' : 'killswitch.off');
    }

    private function back(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/settings', 303);
    }
}
