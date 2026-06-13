<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Kuyash\Core\Database;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `compliance_check` job type (replaces the MockExecutor
 * "always pass, mock-v0" stub). Runs the kuyash-v1 policy checks:
 *
 * - ai_label: AI visuals OR synthetic narration (any TTS — a mock-produced
 *   voice is still synthetic) → the platform AI label is required. Never
 *   blocks; it changes the status to pass_with_ai_label.
 * - format: 15–45 s duration and 9:16 aspect, read from the run's rendered
 *   draft (full template) or the source asset metadata (distribution). Missing
 *   metadata → 'unknown', recorded, NEVER blocks — only definite violations do.
 * - slop: max Jaccard vs the workspace's recent runs; warn ≥ 0.55, block ≥ 0.80.
 *
 * The job result is the full audit record (every check, score, reason and the
 * policy version) per the compliance-policy audit rule. A block is still a
 * COMPLETED check (status 'ready'), not a job failure — retrying would waste
 * work; the Engine's compliance branch cancels the run instead.
 */
final class ComplianceCheckExecutor implements JobExecutor
{
    public const PROVIDER = 'kuyash-compliance';

    public function __construct(
        private readonly Database $db,
        private readonly SlopScorer $slop,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];

        $aiLabel = $this->aiLabelCheck($prior);
        $format = $this->formatCheck($wsId, $runId, $prior);
        $slopScore = $this->slop->score($wsId, $runId, SlopScorer::candidateText($prior));
        $slop = $this->slopCheck($slopScore);

        $reasons = [];
        if ($format['status'] === CompliancePolicy::BLOCK) {
            $reasons = [...$reasons, ...$format['reasons']];
        }
        if ($slop['status'] === CompliancePolicy::BLOCK) {
            $reasons[] = sprintf(
                'near-duplicate of recent content (similarity %.2f, block at %.2f)',
                $slop['score'],
                CompliancePolicy::SLOP_BLOCK,
            );
        }

        $status = match (true) {
            $reasons !== [] => CompliancePolicy::BLOCK,
            $slop['status'] === CompliancePolicy::WARN => CompliancePolicy::WARN,
            $aiLabel['required'] => CompliancePolicy::PASS_WITH_AI_LABEL,
            default => CompliancePolicy::PASS,
        };

        return JobResult::ready([
            'status' => $status,
            'policy' => CompliancePolicy::VERSION,
            'checks' => [
                'ai_label' => $aiLabel,
                'format' => $format,
                'slop' => $slop,
            ],
            'reasons' => $reasons,
            'ai_label_required' => $aiLabel['required'],
        ], self::PROVIDER);
    }

    /**
     * @param array<string, array<string, mixed>> $prior
     *
     * @return array{required: bool, reasons: list<string>}
     */
    private function aiLabelCheck(array $prior): array
    {
        $reasons = [];
        if ((bool) ($prior['assembly']['ai_label_required'] ?? $prior['asset_fetch']['ai_label_required'] ?? false)) {
            $reasons[] = 'ai_visuals';
        }
        // ANY narration produced by the tts step is a synthetic voice — the
        // artifact is synthetic regardless of which provider rendered it.
        if (is_string($prior['tts']['audio_ref'] ?? null) && ($prior['tts']['audio_ref'] ?? '') !== '') {
            $reasons[] = 'synthetic_voice';
        }

        return ['required' => $reasons !== [], 'reasons' => $reasons];
    }

    /**
     * Duration + aspect from the most truthful source available: the run's
     * rendered draft (renders row, full template) else the resolved source
     * asset (distribution). Partial metadata judges what it can see; nothing
     * measurable → 'unknown' (recorded, never blocks).
     *
     * @param array<string, array<string, mixed>> $prior
     *
     * @return array{status: string, duration_s: ?float, width: ?int, height: ?int, source: string, reasons: list<string>}
     */
    private function formatCheck(int $wsId, int $runId, array $prior): array
    {
        $duration = null;
        $width = null;
        $height = null;
        $source = 'none';

        $render = $this->db->one(
            'SELECT width, height, duration_s FROM renders
             WHERE workspace_id = ? AND run_id = ? ORDER BY id DESC LIMIT 1',
            [$wsId, $runId],
        );
        if ($render !== null) {
            $source = 'render';
            $duration = $render['duration_s'] === null ? null : (float) $render['duration_s'];
            $width = $render['width'] === null ? null : (int) $render['width'];
            $height = $render['height'] === null ? null : (int) $render['height'];
        } else {
            $d = $prior['asset_fetch']['duration_s'] ?? null;
            if (is_numeric($d)) {
                $duration = (float) $d;
                $source = 'asset';
            }
            $assetId = $prior['asset_fetch']['asset_id'] ?? null;
            if (is_int($assetId) || ctype_digit((string) $assetId)) {
                $asset = $this->db->one(
                    'SELECT width, height FROM assets WHERE id = ? AND workspace_id = ?',
                    [(int) $assetId, $wsId],
                );
                if ($asset !== null) {
                    $source = 'asset';
                    $width = $asset['width'] === null ? null : (int) $asset['width'];
                    $height = $asset['height'] === null ? null : (int) $asset['height'];
                }
            }
        }

        $reasons = [];
        $measured = false;
        if ($duration !== null) {
            $measured = true;
            $min = CompliancePolicy::DURATION_MIN_S - CompliancePolicy::DURATION_TOLERANCE_S;
            $max = CompliancePolicy::DURATION_MAX_S + CompliancePolicy::DURATION_TOLERANCE_S;
            if ($duration < $min || $duration > $max) {
                $reasons[] = sprintf(
                    'duration %.1fs outside the %.0f-%.0fs band',
                    $duration,
                    CompliancePolicy::DURATION_MIN_S,
                    CompliancePolicy::DURATION_MAX_S,
                );
            }
        }
        if ($width !== null && $height !== null && $height > 0) {
            $measured = true;
            if (abs($width / $height - CompliancePolicy::ASPECT) > CompliancePolicy::ASPECT_TOLERANCE) {
                $reasons[] = sprintf('aspect %dx%d is not 9:16 vertical', $width, $height);
            }
        }

        $status = match (true) {
            $reasons !== [] => CompliancePolicy::BLOCK,
            $measured => CompliancePolicy::PASS,
            default => 'unknown',
        };

        return [
            'status' => $status,
            'duration_s' => $duration,
            'width' => $width,
            'height' => $height,
            'source' => $source,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array{score: float, history_runs: int} $slopScore
     *
     * @return array{status: string, score: float, history_runs: int, warn_at: float, block_at: float}
     */
    private function slopCheck(array $slopScore): array
    {
        $status = match (true) {
            $slopScore['score'] >= CompliancePolicy::SLOP_BLOCK => CompliancePolicy::BLOCK,
            $slopScore['score'] >= CompliancePolicy::SLOP_WARN => CompliancePolicy::WARN,
            default => CompliancePolicy::PASS,
        };

        return [
            'status' => $status,
            'score' => $slopScore['score'],
            'history_runs' => $slopScore['history_runs'],
            'warn_at' => CompliancePolicy::SLOP_WARN,
            'block_at' => CompliancePolicy::SLOP_BLOCK,
        ];
    }
}
