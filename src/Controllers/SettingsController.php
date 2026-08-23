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
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
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
        private readonly SlotRepository $slots,
        private readonly SlotResolver $slotResolver,
        private readonly AccountRepository $accounts,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zone = $this->settings->timezone($wsId);   // hoisted: resolving it per slot re-queried + re-scanned tzdata

        return Response::html($this->view->render('settings/index', [
            'title' => 'Settings — Kuyash',
            'active' => 'settings',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'settings' => $this->settings->compliance($wsId),
            'aiDisclosure' => $this->settings->aiDisclosure($wsId),
            'quality' => $this->quality->compute($wsId),
            'policyVersion' => CompliancePolicy::VERSION,
            'autoUsedToday' => $this->gate->autoApprovalsToday($wsId, $now),
            'spentThisMonthCents' => $this->gate->monthToDateSpendCents($wsId, $now),
            // Phase 23: the weekly publishing plan, each slot shown with the next
            // instant it lands on so the plan is legible, not abstract
            'timezone' => $zone,
            'timezones' => timezone_identifiers_list(),
            'slots' => array_map(function (array $slot) use ($zone, $now): array {
                $slot['next_at'] = $this->slotResolver->nextOccurrence(
                    $zone,
                    (int) $slot['weekday'],
                    (string) $slot['time_hhmm'],
                    $now,
                );

                return $slot;
            }, $this->slots->listFor($this->workspace)),
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

    /**
     * The timezone the weekly plan is written in (Phase 23). Scheduling itself
     * stays UTC; this decides what "Mon 09:00" means and keeps that wall-clock
     * time across daylight-saving shifts.
     *
     * @param array<string, string> $params
     */
    public function saveTimezone(array $params = []): Response
    {
        if (!$this->settings->setTimezone($this->workspace->id(), (string) ($_POST['timezone'] ?? ''))) {
            return $this->back('error', 'slots.timezone_invalid');
        }

        return $this->back('success', 'slots.timezone_saved');
    }

    /**
     * Add a weekly slot. A slot publishes nothing on its own — it is the
     * template an approval turns into one publish time.
     *
     * @param array<string, string> $params
     */
    public function addSlot(array $params = []): Response
    {
        $weekday = (string) ($_POST['weekday'] ?? '');
        $time = trim((string) ($_POST['time_hhmm'] ?? ''));

        // A slot is workspace-wide in this phase. The schema keeps an account
        // column for per-channel plans later, but nothing reads it yet — so the
        // UI does not offer a control that would silently do nothing, and a
        // narrowing value posted by hand is REJECTED rather than quietly widened
        // to "every account".
        if (array_key_exists('account_id', $_POST) && trim((string) $_POST['account_id']) !== '') {
            return $this->back('error', 'slots.invalid');
        }

        if (!ctype_digit($weekday)
            || $this->slots->add($this->workspace, (int) $weekday, $time, null, gmdate('Y-m-d\TH:i:s\Z')) === null
        ) {
            return $this->back('error', 'slots.invalid');
        }

        return $this->back('success', 'slots.added');
    }

    /** @param array<string, string> $params */
    public function removeSlot(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || !$this->slots->remove($this->workspace, (int) $id)) {
            return $this->back('error', 'slots.not_found');
        }

        return $this->back('success', 'slots.removed');
    }

    /**
     * Pause/resume a slot without losing it — a paused slot disappears from the
     * approval picker but keeps its place in the plan.
     *
     * @param array<string, string> $params
     */
    public function toggleSlot(array $params = []): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id)) {
            return $this->back('error', 'slots.not_found');
        }
        $slot = $this->slots->find($this->workspace, (int) $id);
        if ($slot === null) {
            return $this->back('error', 'slots.not_found');
        }
        $this->slots->setEnabled($this->workspace, (int) $id, !$slot['enabled'], gmdate('Y-m-d\TH:i:s\Z'));

        return $this->back('success', $slot['enabled'] ? 'slots.paused' : 'slots.resumed');
    }

    /**
     * Per-platform AI-disclosure toggles (Phase 10). A checkbox sends its name
     * only when checked → absent = OFF. Default ON; turning one off is honored
     * (and audited at publish time when a disclosure is actually suppressed).
     * CSRF is enforced globally; the workspace is the session-resolved tenant.
     *
     * @param array<string, string> $params
     */
    public function saveAiDisclosure(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        foreach (WorkspaceSettings::AI_DISCLOSE_PLATFORMS as $platform) {
            $this->settings->setAiDisclosure($wsId, $platform, isset($_POST['ai_' . $platform]));
        }

        return $this->back('success', 'settings.ai_saved');
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
