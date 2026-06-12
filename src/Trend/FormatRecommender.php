<?php

declare(strict_types=1);

namespace Kuyash\Trend;

/**
 * Derives a face (on-camera, needs a shooting brief) vs faceless (stock/voice)
 * production recommendation from a trend's niche + topic. Deterministic and
 * explainable — both the mock and the real providers run the SAME rule, so the
 * recommendation never depends on which source produced the trend.
 *
 * This is a research SUGGESTION, not a gate: the awaiting_recording / shooting
 * brief pause flow it feeds is deferred to Phase 6/7 (see phase-6-followups).
 */
final class FormatRecommender
{
    /** Niches where on-camera demonstration tends to outperform faceless edits. */
    private const FACE_NICHES = ['fitness', 'cooking', 'beauty', 'fashion'];

    /** Topic signals that read as a person talking to / showing the camera. */
    private const FACE_SIGNALS = [
        'routine', 'review', 'how to', 'how-to', 'tutorial', 'try', 'recipe',
        'workout', 'haul', 'grwm', 'tips', 'react',
    ];

    public static function recommend(string $niche, string $topic): string
    {
        if (in_array(strtolower(trim($niche)), self::FACE_NICHES, true)) {
            return 'face';
        }

        $haystack = strtolower($topic);
        foreach (self::FACE_SIGNALS as $signal) {
            if (str_contains($haystack, $signal)) {
                return 'face';
            }
        }

        return 'faceless';
    }
}
