<?php

declare(strict_types=1);

namespace Kuyash\Publish;

/**
 * Turns weekly slot TEMPLATES into concrete calendar cells for the next two
 * weeks (Phase 24).
 *
 * Idempotent by construction: every cell is keyed on (slot, local day) with an
 * INSERT OR IGNORE behind a UNIQUE index, so this may run on every page view
 * AND on every worker chore tick without ever producing a duplicate.
 *
 * Two jobs, deliberately separated:
 *   • create cells that do not exist yet (only for ENABLED slots — a paused
 *     time stops producing new days, which is what "pause" means);
 *   • re-resolve cells that are still EMPTY, so a timezone change or a slot
 *     edit reaches the calendar. Cells with content attached are left alone —
 *     moving a commitment silently is the surprise this codebase refuses.
 *
 * Never reads the clock: "now" is a parameter, so the two-week window and every
 * daylight-saving edge are fully testable.
 */
final class OccurrenceMaterializer
{
    /** How far ahead the calendar is filled. Two weeks fits the plan screen. */
    public const HORIZON_DAYS = 14;

    /** Cells this far past their time are dropped by the maintenance chore. */
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly OccurrenceRepository $occurrences,
        private readonly SlotResolver $resolver,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $slots publish_slots rows for this workspace
     *
     * @return array{created: int, refreshed: int}
     */
    public function materialize(
        int $workspaceId,
        string $timezone,
        array $slots,
        string $nowIso,
        int $horizonDays = self::HORIZON_DAYS,
    ): array {
        $to = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($nowIso) + ($horizonDays * 86400));
        $created = 0;
        $refreshed = 0;

        foreach ($slots as $slot) {
            $slotId = (int) ($slot['id'] ?? 0);
            if ($slotId < 1) {
                continue;
            }
            $mode = ((string) ($slot['mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';
            $enabled = ($slot['enabled'] ?? false) === true || (int) ($slot['enabled'] ?? 0) === 1;

            // A cell is never created in the past: `nowIso` is the exclusive
            // lower bound, so a time that has already gone by today does not
            // reappear as a "planned" cell the operator could still fill.
            $hits = $this->resolver->occurrencesBetween(
                $timezone,
                (int) ($slot['weekday'] ?? 0),
                (string) ($slot['time_hhmm'] ?? ''),
                $nowIso,
                $to,
            );

            foreach ($hits as $hit) {
                if ($enabled && $this->occurrences->materialize(
                    $workspaceId,
                    $slotId,
                    $hit['local_date'],
                    $hit['at'],
                    $mode,
                    $nowIso,
                )) {
                    $created++;
                    continue;
                }
                // Existing row: keep an EMPTY cell in step with its slot (new
                // timezone, edited time, flipped mode). Runs for both enabled
                // and paused slots — a paused slot's already-created empty cells
                // must still tell the truth about when they would fire.
                if ($this->occurrences->refreshOpen(
                    $workspaceId,
                    $slotId,
                    $hit['local_date'],
                    $hit['at'],
                    $mode,
                    $nowIso,
                )) {
                    $refreshed++;
                }
            }
        }

        return ['created' => $created, 'refreshed' => $refreshed];
    }
}
