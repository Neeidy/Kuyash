<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;

/**
 * The one mock executor for all 13 job types (mock-first rule). Outputs are
 * deterministic — seeded by run_id/job_id, no randomness — and exist ONLY in
 * result_json: no files are produced, no asset rows are created, no network
 * is touched.
 *
 * Honesty rules baked in:
 * - provider is always 'mock'; costCents stays null (mock work is never
 *   presented as real spend).
 * - compliance_check always passes and is stamped 'mock-v0' — real scoring,
 *   warn and block arrive in Phase 9.
 * - The single REAL touch: a distribution run's asset_fetch resolves the
 *   run's actual library asset (id, title, duration, ai flag) into
 *   result_json; a fake stock/ai asset row is never fabricated.
 */
final class MockExecutor implements JobExecutor
{
    private const MOCK_TRENDS = [
        '5-minute desk stretches', 'one-pan dinner ideas', 'budget travel hacks',
        'phone photography tricks', 'morning routine reset',
    ];

    private const MOCK_HOOKS = [
        'Stop scrolling — this takes 15 seconds.',
        'Nobody tells you this until it is too late.',
        'I tested it so you do not have to.',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $seed = crc32('run' . $job['run_id'] . '-step' . $job['step']);

        return match ((string) $job['type']) {
            'trend_fetch' => JobResult::ready([
                'trend' => self::pick(self::MOCK_TRENDS, $seed),
                'niche' => 'general',
                'score' => 60 + $seed % 40,
                'source' => 'mock',
            ], 'mock'),

            'idea_generation' => JobResult::ready([
                'idea' => 'Angle on "' . ($prior['trend_fetch']['trend'] ?? 'an evergreen topic') . '"',
                'hook' => self::pick(self::MOCK_HOOKS, $seed),
                'format' => '15-45s vertical',
            ], 'mock'),

            'script_draft' => JobResult::awaitingApproval([
                'script' => self::mockScript($prior, $seed),
                'word_count' => 58,
                'estimated_duration_s' => 28,
            ], 'mock'),

            'tts' => JobResult::ready([
                'voice' => 'mock-voice-1',
                'duration_s' => 28.4,
                'note' => 'no audio file produced (mock)',
            ], 'mock'),

            'asset_fetch' => $this->assetFetch($job),

            'assembly' => JobResult::ready([
                'duration_s' => 29.0,
                'aspect' => '9:16',
                'note' => 'no render produced (mock)',
            ], 'mock'),

            'caption_generation' => JobResult::ready([
                'caption' => 'Mock caption ' . ($seed % 100) . ' — per-platform variants arrive with the real engine.',
                'platforms' => ['instagram', 'tiktok', 'youtube'],
            ], 'mock'),

            'hashtag_generation' => JobResult::ready([
                'hashtags' => ['#mock' . ($seed % 10), '#shorts', '#reels'],
            ], 'mock'),

            'music_note' => JobResult::ready([
                'mood' => self::pick(['upbeat', 'calm', 'cinematic'], $seed),
                'note' => 'suggestion only — platform-native sounds cannot be published via API',
            ], 'mock'),

            'preview' => JobResult::ready([
                'note' => 'no preview file produced (mock)',
            ], 'mock'),

            'compliance_check' => JobResult::ready([
                'result' => 'pass',
                'policy' => 'mock-v0',
                'checks' => ['format' => 'pass', 'slop' => 'pass', 'ai_label' => 'n/a'],
                'note' => 'mock policy: always pass — real scoring arrives in Phase 9',
            ], 'mock'),

            'render_review' => JobResult::awaitingApproval([
                'summary' => 'Render review (mock): compliance '
                    . ($prior['compliance_check']['result'] ?? 'unknown')
                    . ' (policy ' . ($prior['compliance_check']['policy'] ?? '?') . ')',
                'duration_s' => $prior['assembly']['duration_s'] ?? ($prior['asset_fetch']['duration_s'] ?? null),
            ], 'mock'),

            'publish' => JobResult::published([
                'mode' => 'mock',
                'platforms' => ['instagram', 'tiktok', 'youtube'],
                'note' => 'nothing was published — real publishing is Phase 10 (Zernio)',
            ], 'mock'),

            default => JobResult::failed("MockExecutor: unknown job type '{$job['type']}'", 'mock'),
        };
    }

    /**
     * Distribution runs resolve the run's REAL library asset; full runs get a
     * fake stock descriptor in result_json only.
     */
    private function assetFetch(array $job): JobResult
    {
        if (($job['entity_type'] ?? null) !== 'library') {
            return JobResult::ready([
                'source' => 'stock',
                'clips' => ['mock-stock-001', 'mock-stock-002'],
                'note' => 'no files fetched (mock)',
            ], 'mock');
        }

        $asset = $this->db->one(
            "SELECT id, title, duration_s, type FROM assets
             WHERE id = ? AND workspace_id = ? AND kind = 'video' AND status = 'ready'",
            [$job['entity_id'], $job['workspace_id']],
        );

        if ($asset === null) {
            return JobResult::failed('library asset is no longer available', 'mock');
        }

        return JobResult::ready([
            'source' => 'library',
            'asset_id' => (int) $asset['id'],
            'title' => (string) $asset['title'],
            'duration_s' => $asset['duration_s'] === null ? null : (float) $asset['duration_s'],
            'ai_label_required' => $asset['type'] === 'ai',
        ], 'mock');
    }

    /** @param array<string, array<string, mixed>> $prior */
    private static function mockScript(array $prior, int $seed): string
    {
        $hook = $prior['idea_generation']['hook'] ?? self::pick(self::MOCK_HOOKS, $seed);
        $topic = $prior['trend_fetch']['trend'] ?? 'today\'s topic';

        return $hook . "\n\n"
            . 'Here is the mock draft about ' . $topic . '. '
            . 'Three quick beats, one practical takeaway, and a call to action. '
            . "\n\n(Mock script — the real script engine arrives in Phase 5.)";
    }

    /** @param list<string> $options */
    private static function pick(array $options, int $seed): string
    {
        return $options[$seed % count($options)];
    }
}
