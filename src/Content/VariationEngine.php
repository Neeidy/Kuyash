<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Seeded variation — the compliance-critical core of "no template-identical
 * slop". A deterministic seed (run_id + step) selects a hook, a pacing
 * pattern, an opener and a CTA from independent pools, so the SAME run is
 * reproducible while DIFFERENT runs diverge measurably.
 *
 * Picks are decorrelated by shifting the seed per dimension, so two runs that
 * happen to share a hook still differ in pacing/opener/CTA. similarity() is a
 * reusable Jaccard metric (Phase 9 slop scoring will build on it).
 *
 * "asset shuffle" (varying which library/stock clips are chosen) is deferred
 * to Phase 7, where asset selection first exists.
 */
final class VariationEngine
{
    /** Hook scaffolds; {topic} is filled by the provider. Kept distinct on purpose. */
    private const HOOKS = [
        'Stop scrolling — {topic} in 15 seconds.',
        'Nobody tells you this about {topic}.',
        'I tried {topic} so you don\'t have to.',
        'The {topic} mistake almost everyone makes.',
        'Here\'s what changed how I think about {topic}.',
        'You\'re doing {topic} wrong — here\'s the fix.',
        '3 things about {topic} I wish I knew sooner.',
        'This {topic} trick feels illegal to know.',
        'Why {topic} is easier than you think.',
        'Watch this before you try {topic}.',
        'The fastest way to get {topic} right.',
        '{topic}, but actually simple this time.',
    ];

    private const PACING = [
        'fast-cut', 'slow-build', 'list-of-three', 'question-first',
        'problem-solution', 'before-after',
    ];

    private const OPENERS = [
        'Open mid-action.', 'Start on the result, then rewind.',
        'Lead with the boldest claim.', 'Begin with a question.',
        'Drop the viewer into the problem.',
    ];

    private const CTAS = [
        'Save this for later.', 'Follow for part two.',
        'Try it and tell me how it goes.', 'Link in bio for the full version.',
        'Comment your take below.', 'Share with someone who needs it.',
    ];

    /**
     * @return array{hook: string, pacing: string, opener: string, cta: string}
     */
    public function variant(int $seed, string $topic = ''): array
    {
        $seed = abs($seed);
        $hook = self::HOOKS[$seed % count(self::HOOKS)];
        if ($topic !== '') {
            $hook = str_replace('{topic}', $topic, $hook);
        }

        return [
            'hook' => $hook,
            // independent dimensions: shift the seed so picks don't move together
            'pacing' => self::PACING[($seed >> 3) % count(self::PACING)],
            'opener' => self::OPENERS[($seed >> 6) % count(self::OPENERS)],
            'cta' => self::CTAS[($seed >> 9) % count(self::CTAS)],
        ];
    }

    public function hook(int $seed, string $topic = ''): string
    {
        return $this->variant($seed, $topic)['hook'];
    }

    public static function hookPoolSize(): int
    {
        return count(self::HOOKS);
    }

    /**
     * Jaccard similarity over lowercased word tokens (0 = disjoint, 1 = equal).
     * Used by tests to PROVE variation lowers overlap, and by Phase 9 scoring.
     */
    public static function similarity(string $a, string $b): float
    {
        $tokenize = static function (string $s): array {
            $s = strtolower($s);
            $words = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique($words));
        };

        $setA = $tokenize($a);
        $setB = $tokenize($b);
        if ($setA === [] && $setB === []) {
            return 1.0;
        }

        $intersect = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));

        return $union === 0 ? 0.0 : $intersect / $union;
    }
}
