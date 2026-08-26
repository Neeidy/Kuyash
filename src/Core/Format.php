<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Static view-helpers for templates (same pattern as View::e()).
 * Keeps number formatting out of templates so it cannot drift between pages.
 */
final class Format
{
    /** 21.5 → "0:22" · null → "unknown". Pass $precise for "0:22 (21.5s)". */
    public static function duration(?float $seconds, bool $precise = false): string
    {
        if ($seconds === null) {
            return 'unknown';
        }

        $whole = (int) round($seconds);
        $clock = sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);

        return $precise ? sprintf('%s (%.1fs)', $clock, $seconds) : $clock;
    }

    /** 7 → "$0.07" · 1250 → "$12.50" · -120 → "-$1.20" · 0 → "$0.00". */
    public static function cents(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }
        $sign = $cents < 0 ? '-' : '';

        return $sign . '$' . number_format(abs($cents) / 100, 2);
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        return sprintf('%.0f KB', max(1, $bytes / 1024));
    }

    /**
     * Status → chip tone. One map for jobs AND runs so the same status never
     * renders two colors on different pages (color = status rule).
     */
    public static function statusTone(string $status): string
    {
        return match ($status) {
            'processing', 'running' => 'info',
            'awaiting_approval' => 'warn',
            'ready', 'published', 'completed' => 'ok',
            'failed' => 'err',
            default => 'neutral', // queued, cancelled, unknown
        };
    }

    /** '2026-06-12T09:33:11Z' → '09:33:11' (UTC); null/garbage → '—'. */
    /**
     * Split a leading bracket tag off a title: "[SAMPLE] Kitchen" → ['SAMPLE', 'Kitchen'].
     *
     * WHY THIS EXISTS: a title is rendered in cells narrow enough to truncate —
     * a calendar day at 768px gives about 68px — and an ellipsis eats the END of
     * a string. A tag kept inside the title therefore survives truncation while
     * the words it qualifies do not: every occupied day read "[SAMPLE]…" and four
     * different videos looked identical. Rendering the tag as its own chip lets
     * it stay whole at every width AND gives the title back the space it needs.
     *
     * Generic on purpose — it knows about a bracketed prefix, not about any one
     * tag's meaning.
     *
     * @return array{0: string|null, 1: string} [tag without brackets, remaining title]
     */
    public static function splitTag(string $title): array
    {
        if (preg_match('/^\s*\[([^\]]{1,24})\]\s*(.*)$/u', $title, $m) !== 1) {
            return [null, $title];
        }
        $rest = trim($m[2]);

        // A title that is ONLY a tag keeps it as the title — stripping it would
        // leave an empty line where a name should be.
        return $rest === '' ? [null, $title] : [trim($m[1]), $rest];
    }

    public static function utcTime(?string $iso): string
    {
        if ($iso === null || strlen($iso) < 19) {
            return '—';
        }

        return substr($iso, 11, 8);
    }
}
