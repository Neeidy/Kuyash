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
    public static function utcTime(?string $iso): string
    {
        if ($iso === null || strlen($iso) < 19) {
            return '—';
        }

        return substr($iso, 11, 8);
    }
}
