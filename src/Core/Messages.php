<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * The shared message-key dictionary. Controllers and the event feed resolve
 * KEYS through this single facade; templates never see raw keys. As of Phase 14
 * the dictionary itself lives in the locale files (lang/en.php + lang/tr.php) and
 * this class is the thin, locale-aware facade over I18n — exactly the "the TR
 * i18n pass replaces one class, mechanically" design the codebase was built for.
 * The public API (text/status/event/resolveFlashes) and every call site are
 * unchanged; only the storage of the strings moved.
 *
 * Key namespaces inside the lang files:
 *   - flash/validation keys are un-prefixed (the contract Flash/controllers use)
 *   - event-feed lines are 'event.<key>'
 *   - status labels are 'status.<status>'
 */
final class Messages
{
    /** Flash / validation text for a key (unknown key → the key itself). */
    public static function text(string $key): string
    {
        return I18n::t($key);
    }

    /**
     * Job/run status → display label. A raw enum never reaches a chip
     * ('awaiting_approval' renders as 'awaiting approval'). Unknown status →
     * the raw value (defensive, matches the pre-i18n behavior).
     */
    public static function status(string $status): string
    {
        return I18n::lookup('status.' . $status) ?? $status;
    }

    /**
     * Resolve an event row's key + params into a display line. Placeholders {x}
     * are substituted from params_json. Unknown keys fall back to the bare key
     * (still interpolated → never a crash on old rows).
     *
     * @param array<string, scalar|null> $params
     */
    public static function event(string $key, array $params): string
    {
        return I18n::interpolate(I18n::lookup('event.' . $key) ?? $key, $params);
    }

    /**
     * Resolve queued flashes into displayable {type, text} pairs.
     *
     * @return list<array{type: string, text: string}>
     */
    public static function resolveFlashes(Flash $flash): array
    {
        return array_map(
            static fn (array $f): array => [
                'type' => $f['type'],
                'text' => self::text($f['key']),
            ],
            $flash->pull(),
        );
    }
}
