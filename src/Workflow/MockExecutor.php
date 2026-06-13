<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * The mock executor for the remaining NON-media job types: music_note, preview,
 * render_review, publish. The four content types (idea/script/caption/hashtag)
 * are served by ContentExecutor (Phase 5); trend_fetch by TrendExecutor
 * (Phase 6); tts/asset_fetch/assembly/final_render by the real Media executors
 * (Phase 7); compliance_check by ComplianceCheckExecutor (Phase 9). Publish is
 * wrapped by PublishGateExecutor (Phase 9 guardrails) with THIS as the inner
 * mock until Zernio arrives (Phase 10). Outputs are deterministic, no network.
 *
 * Honesty rule baked in: provider is always 'mock'; costCents stays null
 * (mock work is never presented as real spend).
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

            // approval gate: carries the DRAFT render (full template) or the
            // library asset (distribution) so the queue can show a real preview,
            // plus the compliance verdict so the queue card explains the risk
            'render_review' => JobResult::awaitingApproval([
                'summary' => 'Render review: compliance '
                    . ($prior['compliance_check']['status'] ?? 'unknown')
                    . ' (policy ' . ($prior['compliance_check']['policy'] ?? '?') . ')',
                'draft_render_id' => $prior['assembly']['render_id'] ?? null,
                'poster_ref' => $prior['assembly']['poster_ref'] ?? null,
                'library_asset_id' => $prior['asset_fetch']['asset_id'] ?? null,
                'duration_s' => $prior['assembly']['duration_s'] ?? ($prior['asset_fetch']['duration_s'] ?? null),
                'compliance' => [
                    'status' => $prior['compliance_check']['status'] ?? 'unknown',
                    'policy' => $prior['compliance_check']['policy'] ?? '?',
                    'slop_score' => $prior['compliance_check']['checks']['slop']['score'] ?? null,
                ],
                'ai_label_required' => (bool) ($prior['compliance_check']['ai_label_required']
                    ?? $prior['assembly']['ai_label_required']
                    ?? $prior['asset_fetch']['ai_label_required']
                    ?? false),
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
