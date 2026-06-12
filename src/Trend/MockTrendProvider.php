<?php

declare(strict_types=1);

namespace Kuyash\Trend;

/**
 * Default trend provider: deterministic, niche-aware, OFFLINE. Returns a
 * believable best-first wall per niche with seeded scores and a format
 * recommendation. provider='mock' — its work is never recorded against quota
 * and never presented as real interest data.
 */
final class MockTrendProvider implements TrendProvider
{
    /** @var array<string, list<string>> */
    private const POOLS = [
        'general' => [
            '5-minute desk stretches', 'one-pan dinner ideas', 'budget travel hacks',
            'phone photography tricks', 'morning routine reset', 'declutter in 10 minutes',
            'cheap meal prep', 'productivity timer method',
        ],
        'fitness' => [
            'beginner mobility routine', '10-minute core workout', 'protein snack ideas',
            'posture fix at your desk', 'walking pace challenge', 'no-equipment leg day',
            'recovery day stretches', 'gym etiquette tips',
        ],
        'cooking' => [
            'one-pan dinner recipe', '3-ingredient dessert', 'meal prep on a budget',
            'air fryer breakfast', 'knife skills basics', 'pantry pasta hack',
            'overnight oats variations', 'sauce that fixes anything',
        ],
        'tech' => [
            'phone battery myths', 'free productivity apps', 'home wifi fixes',
            'AI note-taking workflow', 'keyboard shortcuts you forgot', 'cheap smart home setup',
            'password manager setup', 'laptop cooling tips',
        ],
        'travel' => [
            'carry-on packing system', 'budget city for a weekend', 'airport time-savers',
            'travel scams to avoid', 'overnight train tips', 'offline maps trick',
            'jet lag reset', 'hostel vs hotel math',
        ],
        'finance' => [
            'no-spend week challenge', 'beginner index fund explainer', 'cut a hidden bill',
            'emergency fund first steps', 'cashback that is worth it', '50-30-20 budget demo',
            'side income ideas', 'subscription audit',
        ],
        'beauty' => [
            'minimal makeup routine', 'skincare order basics', 'drugstore dupes',
            'hair frizz fix', 'glasses-friendly makeup', '5-minute morning face',
            'SPF myths', 'nail care at home',
        ],
    ];

    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function name(): string
    {
        return 'mock';
    }

    public function fetch(string $niche, string $region, int $limit): array
    {
        $key = strtolower(trim($niche));
        $pool = self::POOLS[$key] ?? self::POOLS['general'];
        $limit = max(1, min($limit, count($pool)));

        $results = [];
        foreach ($pool as $topic) {
            // deterministic per (niche, region, topic) — same inputs reproduce,
            // different niches/regions vary
            $seed = crc32($key . '|' . strtolower($region) . '|' . $topic);
            $score = 55 + ($seed % 45); // 55..99
            $results[] = new TrendResult(
                $topic,
                $score,
                'mock',
                $key,
                $region,
                FormatRecommender::recommend($key, $topic),
                ['mock' => true],
            );
        }

        usort($results, static fn (TrendResult $a, TrendResult $b): int => $b->score <=> $a->score);

        return array_slice($results, 0, $limit);
    }
}
