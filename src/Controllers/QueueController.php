<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Publish\SlotRepository;
use Kuyash\Publish\SlotResolver;
use Kuyash\Workflow\Decision;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\JobRepository;
use Kuyash\Workflow\RunRepository;
use Kuyash\Workflow\WorkerHeartbeat;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

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
        private readonly SlotRepository $slots,
        private readonly SlotResolver $slotResolver,
        private readonly WorkspaceSettings $settings,
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
            // weekly slots offered on the approval form, each resolved to the
            // next instant it lands on so the operator picks a real moment
            'slots' => $this->slotOptions(gmdate('Y-m-d\TH:i:s\Z')),
            'timezone' => $this->settings->timezone($this->workspace->id()),
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

        $scheduledAt = null;
        if ($action === 'approve') {
            $schedule = $this->requestedSchedule();
            // A scheduling intent that cannot be honoured must NOT silently become
            // "publish now". Publishing is irreversible on a live account, so an
            // unresolvable time stops the approval and says why — the operator
            // decides again, rather than discovering a post that went out early.
            if ($schedule['error'] !== null) {
                return $this->backToQueue('error', $schedule['error']);
            }
            $scheduledAt = $schedule['at'];
        }

        $decision = $action === 'approve'
            ? $this->engine->approve($this->workspace, (int) $id, $userId, $email, $scheduledAt)
            : $this->engine->reject($this->workspace, (int) $id, $userId, $email);

        return match ($decision) {
            Decision::NotFound => $this->notFound(),
            Decision::AlreadyDecided => $this->backToQueue('error', 'approval.already_decided'),
            Decision::Ok => $action === 'approve'
                // name the time back to the operator: a scheduling feature whose
                // confirmation does not mention the schedule is half-built
                ? ($scheduledAt === null
                    ? $this->backToQueue('success', 'approval.approved')
                    : $this->backToQueue('success', 'approval.approved_scheduled', ['when' => Messages::until($scheduledAt)]))
                : $this->backToQueue('success', 'approval.rejected'),
        };
    }

    /**
     * The weekly slots offered on the approval form, each already resolved to
     * the next UTC instant it falls on. Sorted soonest-first so the top option
     * is "the next time this workspace publishes".
     *
     * @return list<array<string, mixed>>
     */
    private function slotOptions(string $nowIso): array
    {
        $zone = $this->settings->timezone($this->workspace->id());
        $options = [];
        foreach ($this->slots->listFor($this->workspace, enabledOnly: true) as $slot) {
            $at = $this->slotResolver->nextOccurrence($zone, (int) $slot['weekday'], (string) $slot['time_hhmm'], $nowIso);
            if ($at !== null) {
                $slot['next_at'] = $at;
                $options[] = $slot;
            }
        }
        usort($options, static fn (array $a, array $b): int => strcmp((string) $a['next_at'], (string) $b['next_at']));

        return $options;
    }

    /**
     * Optional "schedule for" from the approval form. Two ways in:
     *   • a weekly slot (slot_id) — resolved here to the next UTC instant it
     *     lands on, using the workspace timezone, so a repeating plan produces
     *     the same kind of value a hand-picked time does;
     *   • a datetime-local value (YYYY-MM-DDTHH:MM), read in that SAME zone.
     *
     * THREE outcomes, deliberately distinguished — "no schedule requested" and
     * "a schedule was requested but cannot be honoured" must never collapse into
     * the same answer, because the Engine reads a null instant as "publish now"
     * and publishing is irreversible:
     *   ['at' => null,   'error' => null]  nothing asked for → publish on approval
     *   ['at' => ISO,    'error' => null]  a real future instant
     *   ['at' => null,   'error' => key ]  asked for, unresolvable → refuse
     *
     * The Engine still owns the final "is it in the future" check; the horizon
     * and past-time checks here exist so the operator hears about it instead of
     * silently getting an immediate post.
     *
     * @return array{at: string|null, error: string|null}
     */
    private function requestedSchedule(): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $slotId = trim((string) ($_POST['slot_id'] ?? ''));
        if ($slotId !== '') {
            if (!ctype_digit($slotId)) {
                return ['at' => null, 'error' => 'slots.unresolvable'];
            }
            $slot = $this->slots->find($this->workspace, (int) $slotId);
            if ($slot === null || $slot['enabled'] !== true) {
                // the plan changed under them (removed or paused) — do NOT fall
                // through to an immediate publish
                return ['at' => null, 'error' => 'slots.unresolvable'];
            }
            $at = $this->slotResolver->nextOccurrence(
                $this->settings->timezone($this->workspace->id()),
                (int) $slot['weekday'],
                (string) $slot['time_hhmm'],
                $now,
            );

            return $at === null
                ? ['at' => null, 'error' => 'slots.unresolvable']
                : $this->withinHorizon($at, $now);
        }

        $raw = trim((string) ($_POST['scheduled_for'] ?? ''));
        if ($raw === '') {
            return ['at' => null, 'error' => null]; // publish on approval
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw) === 1) {
            // A datetime-local value carries NO zone. It used to be read as UTC,
            // which quietly contradicted the workspace timezone the weekly slots
            // are written in: an operator on UTC+3 typing 09:00 got a 12:00 local
            // publish. It is now interpreted in the SAME zone as the slots — which
            // also means a time that is already past LOCALLY is now caught here
            // rather than becoming an instant publish.
            $at = $this->localToUtc($raw . ':00');

            return $at === null
                ? ['at' => null, 'error' => 'slots.unresolvable']
                : $this->withinHorizon($at, $now);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw) === 1) {
            return $this->withinHorizon($raw, $now); // already carries its zone
        }

        return ['at' => null, 'error' => 'slots.unresolvable'];
    }

    /** Days ahead a publish may be scheduled — beyond this it is a typo, not a plan. */
    private const SCHEDULE_HORIZON_DAYS = 365;

    /**
     * Reject an instant that is already past or absurdly far out. A past time
     * would otherwise reach the Engine, be discarded as "not in the future" and
     * publish immediately — the exact silent downgrade this phase must avoid.
     *
     * @return array{at: string|null, error: string|null}
     */
    private function withinHorizon(string $at, string $now): array
    {
        $when = strtotime($at);
        $from = strtotime($now);
        if ($when === false || $from === false) {
            return ['at' => null, 'error' => 'slots.unresolvable'];
        }
        if ($when <= $from) {
            return ['at' => null, 'error' => 'slots.in_past'];
        }
        if ($when > $from + (self::SCHEDULE_HORIZON_DAYS * 86400)) {
            return ['at' => null, 'error' => 'slots.too_far'];
        }

        return ['at' => $at, 'error' => null];
    }

    /**
     * A zone-less "YYYY-MM-DDTHH:MM:SS" read in the workspace timezone → UTC ISO.
     * Null when unparseable; the caller turns that into a refusal, never into an
     * immediate publish.
     */
    private function localToUtc(string $localIso): ?string
    {
        try {
            $local = new \DateTimeImmutable(
                $localIso,
                new \DateTimeZone($this->settings->timezone($this->workspace->id())),
            );
        } catch (\Throwable) {
            return null;
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @param array<string, scalar|null> $params */
    private function backToQueue(string $type, string $messageKey, array $params = []): Response
    {
        $this->flash->add($type, $messageKey, $params);

        return Response::redirect('/queue', 303);
    }

    private function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', ['title' => '404 — Not Found']), 404);
    }
}
