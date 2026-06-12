<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `assembly` (ASSEMBLE) job type — the DRAFT pass of
 * draft-first rendering. Composites the looped visual + TTS audio + script-timed
 * subtitles into a low-res 9:16 MP4 (fast preset) for approval at render_review.
 * The full-res render happens only AFTER approval (final_render).
 *
 * ASSEMBLE exists only in the full template; distribution runs have no draft
 * (their deliverable is the existing library video, normalized at final_render).
 */
final class AssemblyExecutor implements JobExecutor
{
    /** @param array{width: int, height: int, preset: string} $draftGeometry */
    public function __construct(
        private readonly AssemblyEngine $engine,
        private readonly array $draftGeometry,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $visualRef = $prior['asset_fetch']['visual_ref'] ?? null;
        $audioRef = $prior['tts']['audio_ref'] ?? null;
        if (!is_string($visualRef) || $visualRef === '') {
            return JobResult::failed('assembly: no visual to render', 'ffmpeg');
        }
        if (!is_string($audioRef) || $audioRef === '') {
            return JobResult::failed('assembly: no narration audio to render', 'ffmpeg');
        }

        try {
            $render = $this->engine->assembleNarrated(
                (int) $job['workspace_id'],
                (int) $job['run_id'],
                (int) $job['id'],
                'draft',
                $this->draftGeometry,
                $visualRef,
                $audioRef,
                (string) ($prior['script_draft']['script'] ?? ''),
            );
        } catch (FfmpegException $e) {
            return JobResult::failed('assembly render failed: ' . $e->getMessage(), 'ffmpeg');
        }

        return JobResult::ready($render + [
            'draft' => true,
            'ai_label_required' => (bool) ($prior['asset_fetch']['ai_label_required'] ?? false),
        ], 'ffmpeg');
    }
}
