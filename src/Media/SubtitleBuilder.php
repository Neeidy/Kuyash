<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Builds a valid SRT from the script text, timed across the TTS audio duration.
 * Deterministic: the script is chunked into short caption cues (~7 words) and
 * each cue gets a slice of the timeline proportional to its word count. This is
 * SCRIPT-timed, not Whisper-aligned — real word-level alignment is a follow-up
 * (only meaningful with real TTS audio).
 *
 * The SRT is emitted as a sidecar artifact AND muxed as a soft mov_text track.
 * Burned-in captions need an ffmpeg built with libass/libfreetype (the
 * subtitles/drawtext filters) — a documented follow-up.
 */
final class SubtitleBuilder
{
    private const WORDS_PER_CUE = 7;

    public static function build(string $script, float $durationSeconds): string
    {
        $words = preg_split('/\s+/', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return '';
        }

        $duration = max(0.5, $durationSeconds);
        $cues = array_chunk($words, self::WORDS_PER_CUE);
        $totalWords = count($words);

        $srt = '';
        $cursor = 0.0;
        $index = 1;
        foreach ($cues as $cue) {
            $share = count($cue) / $totalWords;
            $start = $cursor;
            $end = min($duration, $cursor + $share * $duration);
            // guard against zero-length cues from rounding
            if ($end <= $start) {
                $end = min($duration, $start + 0.1);
            }

            $srt .= $index . "\n"
                . self::timecode($start) . ' --> ' . self::timecode($end) . "\n"
                . self::sanitizeLine(implode(' ', $cue)) . "\n\n";

            $cursor = $end;
            $index++;
        }

        return $srt;
    }

    private static function timecode(float $seconds): string
    {
        $ms = (int) round($seconds * 1000);
        $h = intdiv($ms, 3_600_000);
        $m = intdiv($ms % 3_600_000, 60_000);
        $s = intdiv($ms % 60_000, 1000);
        $milli = $ms % 1000;

        return sprintf('%02d:%02d:%02d,%03d', $h, $m, $s, $milli);
    }

    /** Keep cue text single-line and free of stray control chars. */
    private static function sanitizeLine(string $text): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;

        return trim($clean);
    }
}
