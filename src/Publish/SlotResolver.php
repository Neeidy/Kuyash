<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turns a weekly slot ("Monday 09:00", written in the workspace timezone) into
 * the next UTC instant it lands on (Phase 23).
 *
 * WHY UTC OUT: everything downstream is UTC — runs.publish_after, the queue's
 * run_after gate, the adapter's scheduledFor. A slot is the only place a local
 * wall-clock time exists, and it is converted once, here.
 *
 * DAYLIGHT SAVING is the reason this is a class and not a sprintf. "Mon 09:00"
 * must stay 09:00 for the operator across a DST shift, which means the UTC
 * instant moves by an hour twice a year. Adding "7 days" to a timestamp does not
 * do that, so the wall-clock time is re-applied after every date shift and the
 * conversion to UTC happens last.
 *
 * This class is pure: it never reads the clock. The caller passes "now", so the
 * behaviour is fully testable, including across a DST boundary.
 */
final class SlotResolver
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    /**
     * The next UTC instant matching this slot, strictly after $nowIso.
     *
     * @param int    $weekday  1 = Monday … 7 = Sunday (ISO-8601, PHP date('N'))
     * @param string $hhmm     'HH:MM' wall-clock time in $timezone
     *
     * @return string|null ISO-8601 UTC, or null when the slot/timezone is invalid
     */
    public function nextOccurrence(string $timezone, int $weekday, string $hhmm, string $nowIso): ?string
    {
        if ($weekday < 1 || $weekday > 7 || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
            return null;
        }

        try {
            $zone = new DateTimeZone($timezone);
            $nowUtc = new DateTimeImmutable($nowIso, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        $local = $nowUtc->setTimezone($zone);
        $candidate = $local->setTime($hour, $minute);

        // move onto the requested weekday, then RE-APPLY the wall-clock time:
        // crossing a DST boundary shifts the clock, and the operator asked for
        // 09:00 local, not "09:00 minus whatever the offset did"
        $shift = ($weekday - (int) $candidate->format('N') + 7) % 7;
        if ($shift > 0) {
            $candidate = $candidate->modify('+' . $shift . ' days')->setTime($hour, $minute);
        }
        if ($candidate <= $local) {
            $candidate = $candidate->modify('+7 days')->setTime($hour, $minute);
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'))->format(self::ISO);
    }

    /**
     * The soonest upcoming instant among several slots — what the picker offers
     * as "next available".
     *
     * @param list<array<string, mixed>> $slots rows carrying weekday + time_hhmm
     */
    public function nextAmong(string $timezone, array $slots, string $nowIso): ?string
    {
        $best = null;
        foreach ($slots as $slot) {
            $at = $this->nextOccurrence($timezone, (int) ($slot['weekday'] ?? 0), (string) ($slot['time_hhmm'] ?? ''), $nowIso);
            if ($at !== null && ($best === null || $at < $best)) {
                $best = $at;
            }
        }

        return $best;
    }

    /** Is this a timezone PHP actually knows? Used to validate operator input. */
    public static function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return false;
        }

        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
