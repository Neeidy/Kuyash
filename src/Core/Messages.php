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
        // Phase 21: humanize the operational tokens before interpolation so the
        // activity feed reads in plain user language (the stored event row keeps
        // the raw params — only the DISPLAY is humanized, audit trail untouched).
        if (isset($params['type']) && is_string($params['type'])) {
            $params['type'] = self::jobType($params['type']);
        }
        if (isset($params['platform']) && is_string($params['platform'])) {
            $params['platform'] = self::platform($params['platform']);
        }
        if (isset($params['slop']) && is_numeric($params['slop'])) {
            $params['slop'] = self::slopPercent((float) $params['slop']);
        }
        if (isset($params['node']) && is_string($params['node'])) {
            $params['node'] = self::node($params['node']);
        }

        return I18n::interpolate(I18n::lookup('event.' . $key) ?? $key, $params);
    }

    /**
     * Canonical pipeline-node id (TREND / VOICE / PUBLISH …) → a plain step label,
     * via the node's primary job type. The canonical names stay only in the
     * dedicated step-graph views; every terse list/feed context shows this label.
     */
    public static function node(string $node): string
    {
        $primary = self::NODE_PRIMARY[$node] ?? null;

        return $primary !== null ? self::jobType($primary) : $node;
    }

    /** Node → its primary job type (mirrors the front of Nodes::NODE_JOBS). */
    private const NODE_PRIMARY = [
        'TREND' => 'trend_fetch', 'IDEA' => 'idea_generation', 'SCRIPT' => 'script_draft',
        'VOICE' => 'tts', 'VISUALS' => 'asset_fetch', 'LIBRARY' => 'asset_fetch',
        'ASSEMBLE' => 'assembly', 'CAPTION' => 'caption_generation', 'HASHTAGS' => 'hashtag_generation',
        'MUSIC NOTE / STYLE' => 'music_note', 'PREVIEW' => 'preview', 'COMPLIANCE' => 'compliance_check',
        'PUBLISH' => 'publish',
    ];

    /** Stored 0..1 similarity → a plain percentage ("61%" / "%61" in TR). */
    private static function slopPercent(float $slop): string
    {
        $pct = $slop <= 1.0 ? (int) round($slop * 100) : (int) round($slop);

        return I18n::locale() === 'tr' ? '%' . $pct : $pct . '%';
    }

    /**
     * Internal job-type enum → plain user-facing label (Phase 21 jargon scrub).
     * A raw type like 'render_review' / 'script_draft' never reaches a chip.
     * Same defensive fallback as status(): unknown type → the raw value.
     */
    public static function jobType(string $type): string
    {
        return I18n::lookup('jobtype.' . $type) ?? $type;
    }

    /**
     * Platform enum → proper display name (Instagram / TikTok / YouTube). The
     * lowercase storage enum never shows in the UI. Unknown → raw value.
     */
    public static function platform(string $platform): string
    {
        return I18n::lookup('platform.' . $platform) ?? $platform;
    }

    /**
     * ISO-8601 UTC timestamp → a plain relative phrase ("4 minutes ago").
     * Storage stays ISO (sortable, unambiguous, audit-grade); only the DISPLAY
     * is humanized, the same split status()/jobType() already apply to enums.
     * A machine timestamp is jargon on a dashboard chip — the operator wants to
     * know how fresh something is, not to parse a Z-suffixed string.
     *
     * $nowIso is injectable so the phrasing is testable without a clock.
     * Unparseable input falls back to the raw value (never a wrong "just now").
     */
    public static function since(string $iso, ?string $nowIso = null): string
    {
        $then = strtotime($iso);
        if ($then === false) {
            return $iso;
        }
        $now = $nowIso === null ? time() : (int) strtotime($nowIso);
        $delta = max(0, $now - $then);

        if ($delta < 60) {
            return I18n::lookup('time.just_now') ?? 'just now';
        }
        if ($delta < 3600) {
            return I18n::t('time.minutes_ago', ['n' => (string) intdiv($delta, 60)]);
        }
        if ($delta < 86400) {
            return I18n::t('time.hours_ago', ['n' => (string) intdiv($delta, 3600)]);
        }

        return I18n::t('time.days_ago', ['n' => (string) intdiv($delta, 86400)]);
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
