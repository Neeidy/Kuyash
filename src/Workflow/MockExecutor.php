<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * The mock executor for the remaining NON-media job types: music_note, preview,
 * compliance_check, render_review, publish. The four content types
 * (idea/script/caption/hashtag) are served by ContentExecutor (Phase 5);
 * trend_fetch by TrendExecutor (Phase 6); tts/asset_fetch/assembly/final_render
 * by the real Media executors (Phase 7). This class no longer produces content,
 * trends, audio, visuals or renders. Outputs are deterministic, no network.
 *
 * Honesty rules baked in:
 * - provider is always 'mock'; costCents stays null (mock work is never
 *   presented as real spend).
 * - compliance_check always passes and is stamped 'mock-v0' — real scoring,
 *   warn and block arrive in Phase 9.
 */
final class MockExecutor implements JobExecutor
{
    public function execute(array $job, array $prior): JobResult
    {
        $seed = crc32('run' . $job['run_id'] . '-step' . $job['step']);

        return match ((string) $job['type']) {
            'music_note' => JobResult::ready([
                'mood' => self::pick(['upbeat', 'calm', 'cinematic'], $seed),
                'note' => 'suggestion only — platform-native sounds cannot be published via API',
            ], 'mock'),

            'preview' => JobResult::ready([
                'note' => 'preview is the in-pipeline checkpoint; the reviewable render is the draft',
            ], 'mock'),

            'compliance_check' => JobResult::ready([
                'result' => 'pass',
                'policy' => 'mock-v0',
                'checks' => [
                    'format' => 'pass',
                    'slop' => 'pass',
                    // surface the AI-label requirement the visual carried (Phase 9 enforces)
                    'ai_label' => ($prior['assembly']['ai_label_required'] ?? $prior['asset_fetch']['ai_label_required'] ?? false) ? 'required' : 'n/a',
                ],
                'note' => 'mock policy: always pass — real scoring arrives in Phase 9',
            ], 'mock'),

            // approval gate: carries the DRAFT render (full template) or the
            // library asset (distribution) so the queue can show a real preview
            'render_review' => JobResult::awaitingApproval([
                'summary' => 'Render review: compliance '
                    . ($prior['compliance_check']['result'] ?? 'unknown')
                    . ' (policy ' . ($prior['compliance_check']['policy'] ?? '?') . ')',
                'draft_render_id' => $prior['assembly']['render_id'] ?? null,
                'poster_ref' => $prior['assembly']['poster_ref'] ?? null,
                'library_asset_id' => $prior['asset_fetch']['asset_id'] ?? null,
                'duration_s' => $prior['assembly']['duration_s'] ?? ($prior['asset_fetch']['duration_s'] ?? null),
                'ai_label_required' => (bool) ($prior['assembly']['ai_label_required'] ?? $prior['asset_fetch']['ai_label_required'] ?? false),
            ], 'mock'),

            'publish' => JobResult::published([
                'mode' => 'mock',
                'platforms' => ['instagram', 'tiktok', 'youtube'],
                'final_render_id' => $prior['final_render']['render_id'] ?? null,
                'note' => 'nothing was published — real publishing is Phase 10 (Zernio)',
            ], 'mock'),

            default => JobResult::failed("MockExecutor: unknown job type '{$job['type']}'", 'mock'),
        };
    }

    /** @param list<string> $options */
    private static function pick(array $options, int $seed): string
    {
        return $options[$seed % count($options)];
    }
}
