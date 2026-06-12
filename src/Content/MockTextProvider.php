<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Default content provider: deterministic, seeded, OFFLINE. Richer than the
 * Phase 4 stubs — real per-platform captions, a structured script with a
 * computed word count + duration, and seeded hooks — while keeping the
 * contracts Phase 4 relied on (idea references the trend, script pauses for
 * approval). provider='mock', costCents=null (mock spend is never real spend).
 */
final class MockTextProvider implements TextProvider
{
    private const WORDS_PER_SECOND = 2.5; // ~150 wpm speaking pace

    public function __construct(
        private readonly VariationEngine $variation,
        private readonly PromptLibrary $prompts,
    ) {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function generate(string $kind, array $context, int $seed): TextResult
    {
        $topic = Sanitizer::clean((string) ($context['topic'] ?? 'an evergreen topic'), 120);
        $variant = $this->variation->variant($seed, $topic);

        $data = match ($kind) {
            'idea' => [
                'idea' => 'Angle on "' . $topic . '": ' . $variant['opener'],
                'hook' => $variant['hook'],
                'format' => '15-45s vertical',
            ],
            'script' => $this->script($context, $topic, $variant),
            'caption' => ['captions' => $this->captions($topic, $variant, $seed)],
            'hashtag' => ['hashtags' => $this->hashtags($topic, $seed)],
            // align with the real provider: an unknown kind fails loudly, never
            // a silent empty result
            default => throw new TextProviderException("MockTextProvider: unsupported content kind '{$kind}'"),
        };

        return new TextResult($data, 'mock', $this->prompts->version($kind), null, null);
    }

    /**
     * @param array<string, mixed>                                             $context
     * @param array{hook: string, pacing: string, opener: string, cta: string} $variant
     *
     * @return array{script: string, word_count: int, estimated_duration_s: float}
     */
    private function script(array $context, string $topic, array $variant): array
    {
        $hook = Sanitizer::clean((string) ($context['hook'] ?? $variant['hook']), 200);
        $idea = Sanitizer::clean((string) ($context['idea'] ?? ''), 200);

        $body = $hook . "\n\n"
            . $variant['opener'] . ' '
            . ($idea !== '' ? $idea . '. ' : '')
            . 'Here is the quick version about ' . $topic . '. '
            . 'Beat one sets it up, beat two shows the turn, beat three lands the takeaway '
            . '(' . $variant['pacing'] . " pacing).\n\n"
            . $variant['cta'];

        $wordCount = count(preg_split('/\s+/', trim($body), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return [
            'script' => $body,
            'word_count' => $wordCount,
            'estimated_duration_s' => round($wordCount / self::WORDS_PER_SECOND, 1),
        ];
    }

    /**
     * @param array{hook: string, pacing: string, opener: string, cta: string} $variant
     *
     * @return array<string, string> distinct caption per platform
     */
    private function captions(string $topic, array $variant, int $seed): array
    {
        $flavor = [
            'instagram' => 'Save this ✦ ',
            'tiktok' => 'wait for it… ',
            'youtube' => 'Full breakdown: ',
        ];

        $captions = [];
        foreach (PromptLibrary::platforms() as $i => $platform) {
            $v = $this->variation->variant($seed + $i + 1, $topic);
            $captions[$platform] = ($flavor[$platform] ?? '')
                . $topic . ' — ' . rtrim($v['opener'], '.') . '. ' . $v['cta'];
        }

        return $captions;
    }

    /** @return list<string> at least 3 deduped hashtags */
    private function hashtags(string $topic, int $seed): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($topic), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tags = [];
        foreach ($words as $w) {
            if (strlen($w) >= 3) {
                $tags[] = '#' . $w;
            }
        }

        $staples = ['#shorts', '#reels', '#fyp', '#tips', '#howto'];
        $tags[] = $staples[abs($seed) % count($staples)];
        $tags[] = $staples[abs($seed >> 4) % count($staples)];
        $tags[] = '#viral';

        return array_values(array_slice(array_unique($tags), 0, 8));
    }
}
