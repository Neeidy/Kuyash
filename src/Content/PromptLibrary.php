<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Versioned prompt templates — the single source of prompt text and the
 * version stamp recorded on every content job (result_json.prompt_version +
 * an event). Bumping a prompt = a new version constant, so old runs stay
 * attributable to the exact prompt that produced them (audit trail).
 *
 * messages() builds the OpenAI chat payload (system + user) from SANITIZED
 * context and the seeded variation, asking for strict JSON so the provider
 * can decode a predictable shape. The mock provider only reads version().
 */
final class PromptLibrary
{
    private const VERSIONS = [
        'idea' => 'idea.v1',
        'script' => 'script.v1',
        'caption' => 'caption.v1',
        'hashtag' => 'hashtag.v1',
    ];

    private const PLATFORMS = ['instagram', 'tiktok', 'youtube'];

    public function version(string $kind): string
    {
        return self::VERSIONS[$kind] ?? 'unknown.v0';
    }

    /** @return list<string> */
    public static function platforms(): array
    {
        return self::PLATFORMS;
    }

    /**
     * @param array<string, mixed>                                    $context
     * @param array{hook: string, pacing: string, opener: string, cta: string} $variant
     *
     * @return list<array{role: string, content: string}>
     */
    public function messages(string $kind, array $context, array $variant): array
    {
        $topic = Sanitizer::clean((string) ($context['topic'] ?? 'an evergreen topic'), 120);
        $idea = Sanitizer::clean((string) ($context['idea'] ?? ''), 200);
        $hook = Sanitizer::clean((string) ($context['hook'] ?? $variant['hook']), 200);

        $system = 'You write short-form vertical video content (15-45s, 9:16) for Instagram '
            . 'Reels, TikTok and YouTube Shorts. Be concrete and natural. Avoid hashtags inside '
            . 'scripts. Respond with STRICT JSON only — no prose, no code fences.';

        $user = match ($kind) {
            'idea' => sprintf(
                'Topic: "%s". Propose one angle. Use a "%s" pacing and this opener idea: "%s". '
                . 'Return JSON: {"idea": string, "hook": string, "format": "15-45s vertical"}.',
                $topic,
                $variant['pacing'],
                $variant['opener'],
            ),
            'script' => sprintf(
                'Write a spoken script for: "%s". Angle: "%s". Open with this hook verbatim: "%s". '
                . 'Pacing: "%s". End with: "%s". Three short beats, one takeaway. '
                . 'Return JSON: {"script": string, "word_count": number, "estimated_duration_s": number}.',
                $topic,
                $idea !== '' ? $idea : $topic,
                $hook,
                $variant['pacing'],
                $variant['cta'],
            ),
            'caption' => sprintf(
                'Write one caption PER PLATFORM for: "%s" (angle "%s"). Distinct wording each. '
                . 'Return JSON: {"instagram": string, "tiktok": string, "youtube": string}.',
                $topic,
                $idea !== '' ? $idea : $topic,
            ),
            'hashtag' => sprintf(
                'Suggest 5-8 relevant, non-spammy hashtags for: "%s". '
                . 'Return JSON: {"hashtags": string[]} (each starts with #).',
                $topic,
            ),
            default => 'Return JSON: {}.',
        };

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }
}
