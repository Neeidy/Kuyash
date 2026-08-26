<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use DateTimeImmutable;
use DateTimeZone;
use Kuyash\Workspace\WorkspaceContext;
use Throwable;

/**
 * The calendar as the operator sees it (Phase 24) — derive-only, no writes.
 *
 * The important rule lives here: a cell's VISIBLE state is computed from the
 * real run and its real jobs, never copied into the plan's own row. The plan
 * stores only what it owns (open / assigned / skipped); "preparing", "waiting
 * for you", "scheduled" and "published" are facts about the queue, and reading
 * them at render time is what keeps the screen from claiming something the
 * system is not actually doing (Phase 22/23's standing rule).
 */
final class PlanBoard
{
    /** Cell states, in the order they can occur. */
    public const OPEN = 'open';            // manual, nothing assigned yet
    public const AUTO_WAITING = 'auto';    // automatic, not produced yet
    public const BLOCKED = 'blocked';      // automatic, something is stopping production
    public const PAUSED = 'paused';        // its publishing time is paused
    public const PREPARING = 'preparing';  // a run is working on it
    public const AWAITING = 'awaiting';    // it needs a human decision
    public const SCHEDULED = 'scheduled';  // approved, waiting for its time
    public const PUBLISHED = 'published';  // it went out
    public const STOPPED = 'stopped';      // cancelled or failed before publishing
    public const MISSED = 'missed';        // its time passed and nothing went out

    /**
     * Skip reasons that are NOT a failure: the operator's own decision, or a
     * guardrail working as designed. These render as "Stopped", not "Missed".
     */
    private const STOPPED_REASONS = [
        'cancelled', 'compliance_block', 'daily_cap', 'budget_cap',
        'kill_switch', 'plan_paused',
    ];

    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly ?\Kuyash\Media\AssetPoster $posters = null,
    ) {
    }

    /**
     * The next $days local days, each with its cells. Days with no publishing
     * time are still returned, so the calendar reads as a calendar rather than
     * as a list that silently skips quiet days.
     *
     * @return list<array{date: string, weekday: int, is_today: bool, cells: list<array<string, mixed>>}>
     */
    public function calendar(WorkspaceContext $ctx, string $timezone, string $nowIso, int $days = OccurrenceMaterializer::HORIZON_DAYS): array
    {
        try {
            $zone = new DateTimeZone($timezone);
            $cursor = (new DateTimeImmutable($nowIso, new DateTimeZone('UTC')))->setTimezone($zone);
        } catch (Throwable) {
            return [];
        }

        // Start at the beginning of TODAY, local — not at "now". Windowing from
        // the current instant dropped the day a publish was missed on, so the
        // calendar showed "nothing planned" for it and the dashboard's missed
        // counter could never be anything but zero. A day the operator needs an
        // explanation for is the one day that must not vanish.
        $from = $cursor->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $to = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($nowIso) + ($days * 86400));
        $rows = $this->occurrences->window($ctx, $from, $to);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['local_date']][] = $this->cell($row, $timezone, $nowIso);
        }
        $today = $cursor->format('Y-m-d');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            // walk local days, re-reading the date each step so a DST shift
            // cannot skip or repeat one
            $day = $cursor->modify('+' . $i . ' days');
            $date = $day->format('Y-m-d');
            $out[] = [
                'date' => $date,
                'weekday' => (int) $day->format('N'),
                'is_today' => $date === $today,
                'cells' => $byDate[$date] ?? [],
            ];
        }

        return $out;
    }

    /**
     * A one-line summary for the dashboard. Only counts things that are real;
     * a zero stays a zero and is never dressed up.
     *
     * @return array{planned: int, awaiting: int, scheduled: int, missed: int, blocked: int, published: int}
     */
    public function summary(WorkspaceContext $ctx, string $timezone, string $nowIso, int $days = 7): array
    {
        $totals = ['planned' => 0, 'awaiting' => 0, 'scheduled' => 0, 'missed' => 0, 'blocked' => 0, 'published' => 0];
        foreach ($this->calendar($ctx, $timezone, $nowIso, $days) as $day) {
            foreach ($day['cells'] as $cell) {
                $totals['planned']++;
                match ($cell['state']) {
                    self::AWAITING => $totals['awaiting']++,
                    self::SCHEDULED => $totals['scheduled']++,
                    self::MISSED => $totals['missed']++,
                    self::BLOCKED => $totals['blocked']++,
                    self::PUBLISHED => $totals['published']++,
                    default => null,
                };
            }
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $row a window() row
     *
     * @return array<string, mixed>
     */
    private function cell(array $row, string $timezone, string $nowIso): array
    {
        // Once a publish is really queued, ITS gate is the truth — the publish
        // gate can defer a capped post to the next UTC midnight, and a retry
        // backoff moves it too. Showing the plan's original time then would be
        // the exact "read the plan, not the job gate" mistake this class exists
        // to prevent.
        $at = (string) $row['publish_at'];
        if ((string) ($row['publish_status'] ?? '') === 'queued' && ($row['publish_run_after'] ?? null) !== null) {
            $at = (string) $row['publish_run_after'];
        }

        return [
            'id' => (int) $row['id'],
            'slot_id' => (int) $row['slot_id'],
            'at' => $at,
            'time' => self::localTime($at, $timezone),
            // true once its moment has gone by: a past day is a record, not a
            // place to put something
            'is_past' => (string) $row['publish_at'] <= $nowIso,
            // the queue moved it away from the day it was planned for
            'moved' => $at !== (string) $row['publish_at'],
            // the time the operator ASKED for, so a daylight-saving shift that
            // moved it can be shown as the difference it is
            'planned_time' => (string) ($row['time_hhmm'] ?? ''),
            'mode' => (string) $row['mode'],
            'run_id' => $row['run_id'] === null ? null : (int) $row['run_id'],
            'asset_title' => $row['asset_title'] === null ? null : (string) $row['asset_title'],
            // enough for the cell to show WHAT is on that day, not just its name
            'asset_ref' => $row['asset_ref'] === null ? null : (int) $row['asset_ref'],
            'asset_poster' => $this->posters !== null && $row['asset_sha256'] !== null
                && (string) ($row['asset_kind'] ?? '') === 'video'
                && $this->posters->exists([
                    'workspace_id' => (int) $row['workspace_id'],
                    'sha256' => (string) $row['asset_sha256'],
                ]),
            'awaiting_job_id' => $row['awaiting_job_id'] === null ? null : (int) $row['awaiting_job_id'],
            'reason' => ($row['skip_reason'] ?? null) === null ? null : (string) $row['skip_reason'],
            'published_count' => (int) ($row['published_count'] ?? 0),
            'post_count' => (int) ($row['post_count'] ?? 0),
            'state' => self::state($row),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function state(array $row): string
    {
        if ((string) $row['status'] === 'skipped') {
            // A day the operator cleared, or one a guardrail deliberately held
            // back, did not "go wrong" — reporting a working guardrail as a
            // failure is as untruthful as hiding one. Only a day that should
            // have published and did not is a miss.
            return in_array((string) ($row['skip_reason'] ?? ''), self::STOPPED_REASONS, true)
                ? self::STOPPED
                : self::MISSED;
        }

        if ($row['run_id'] === null) {
            if (($row['slot_enabled'] ?? true) === false) {
                return self::PAUSED;
            }
            if (($row['skip_reason'] ?? null) !== null) {
                return self::BLOCKED;
            }

            return (string) $row['mode'] === 'auto' ? self::AUTO_WAITING : self::OPEN;
        }

        // From here the truth lives in the run and its jobs, not in the plan.
        if ((int) ($row['published_count'] ?? 0) > 0 || (string) ($row['publish_status'] ?? '') === 'published') {
            return self::PUBLISHED;
        }
        if (in_array((string) ($row['run_status'] ?? ''), ['cancelled', 'failed'], true)) {
            return self::STOPPED;
        }
        if ((string) ($row['publish_status'] ?? '') === 'queued') {
            return self::SCHEDULED;
        }
        if ($row['awaiting_job_id'] !== null) {
            return self::AWAITING;
        }

        return self::PREPARING;
    }

    private static function localTime(string $iso, string $timezone): string
    {
        try {
            return (new DateTimeImmutable($iso, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone($timezone))
                ->format('H:i');
        } catch (Throwable) {
            return substr($iso, 11, 5);
        }
    }
}
