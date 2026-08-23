<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Publish\PostRepository;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * The weekly publishing plan (Phase 23) — its own screen rather than a card at
 * the bottom of Settings, because "when do my videos go out" is a thing the
 * operator opens on purpose, not a preference they set once.
 *
 * A slot publishes NOTHING by itself. It is a template: approving a render can
 * send it to the next matching slot, which resolves to one UTC instant and then
 * travels the existing path (runs.publish_after → the queue's run_after gate).
 * That is why this screen shows the next queued publish too — the plan is the
 * intent, the queue is the fact.
 */
final class PlanController
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly View $view,
        private readonly SlotRepository $slots,
        private readonly SlotResolver $resolver,
        private readonly WorkspaceSettings $settings,
        private readonly PostRepository $posts,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $now = gmdate(self::ISO);
        // resolved once, not per slot: each call re-queries and re-scans tzdata
        $zone = $this->settings->timezone($wsId);

        return Response::html($this->view->render('plan/index', [
            'title' => 'Weekly plan — Kuyash',
            'active' => 'plan',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'timezone' => $zone,
            'timezones' => timezone_identifiers_list(),
            'slots' => array_map(function (array $slot) use ($zone, $now): array {
                $slot['next_at'] = $this->resolver->nextOccurrence(
                    $zone,
                    (int) $slot['weekday'],
                    (string) $slot['time_hhmm'],
                    $now,
                );

                return $slot;
            }, $this->slots->listFor($this->workspace)),
            // what is ACTUALLY queued — a plan is not a promise that something
            // is scheduled, so the two are shown separately
            'nextScheduled' => $this->posts->nextScheduled($this->workspace, $now),
        ], 'layout/app'));
    }

    /**
     * The timezone the plan is written in. Scheduling stays UTC throughout; this
     * decides what "Mon 09:00" means and keeps that wall-clock time across
     * daylight-saving shifts.
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

    /** @param array<string, string> $params */
    public function addSlot(array $params = []): Response
    {
        $weekday = (string) ($_POST['weekday'] ?? '');
        $time = trim((string) ($_POST['time_hhmm'] ?? ''));

        // A slot is workspace-wide in this phase. The schema keeps an account
        // column for per-channel plans later, but nothing reads it yet — so the
        // UI offers no control that would silently do nothing, and a narrowing
        // value posted by hand is REJECTED rather than quietly widened to
        // "every account".
        if (array_key_exists('account_id', $_POST) && trim((string) $_POST['account_id']) !== '') {
            return $this->back('error', 'slots.invalid');
        }

        if (!ctype_digit($weekday)
            || $this->slots->add($this->workspace, (int) $weekday, $time, null, gmdate(self::ISO)) === null
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
     * Pause/resume without losing the slot — a paused time disappears from the
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
        $this->slots->setEnabled($this->workspace, (int) $id, !$slot['enabled'], gmdate(self::ISO));

        return $this->back('success', $slot['enabled'] ? 'slots.paused' : 'slots.resumed');
    }

    private function back(string $type, string $messageKey): Response
    {
        $this->flash->add($type, $messageKey);

        return Response::redirect('/plan', 303);
    }
}
