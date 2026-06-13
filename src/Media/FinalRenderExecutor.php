<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `final_render` job type — the FULL-RES pass of
 * draft-first rendering, enqueued ONLY after render_review approval (the PUBLISH
 * node now expands render_review → final_render → publish). Re-renders the same
 * inputs at full resolution so the published artifact is the approved content at
 * delivery quality.
 *
 * - full template (TTS audio present): narrated assembly at full geometry.
 * - distribution (no TTS): normalize the library video to 9:16 full-res.
 */
final class FinalRenderExecutor implements JobExecutor
{
    /** @param array{width: int, height: int, preset: string} $finalGeometry */
    public function __construct(
        private readonly AssemblyEngine $engine,
        private readonly array $finalGeometry,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        // Quick Create (ai_video) hands forward the generated clip ref; every
        // other template resolves the visual at asset_fetch. Either is a finished
        // video normalized here to full-res 9:16 (no narration → distribution path).
        $visualRef = $prior['ai_video']['visual_ref'] ?? $prior['asset_fetch']['visual_ref'] ?? null;
        if (!is_string($visualRef) || $visualRef === '') {
            return JobResult::failed('final_render: no visual to render', 'ffmpeg');
        }
        // narrated vs distribution is inferred from TTS-audio presence: the
        // distribution template has no VOICE/tts step, so an absent audio_ref
        // reliably means "distribution". If a full run ever drops VOICE, add an
        // explicit mode signal here (see phase-7-followups).
        $audioRef = $prior['tts']['audio_ref'] ?? null;

        try {
            if (is_string($audioRef) && $audioRef !== '') {
                $render = $this->engine->assembleNarrated(
                    (int) $job['workspace_id'],
                    (int) $job['run_id'],
                    (int) $job['id'],
                    'final',
                    $this->finalGeometry,
                    $visualRef,
                    $audioRef,
                    (string) ($prior['script_draft']['script'] ?? ''),
                );
            } else {
                $render = $this->engine->assembleDistribution(
                    (int) $job['workspace_id'],
                    (int) $job['run_id'],
                    (int) $job['id'],
                    'final',
                    $this->finalGeometry,
                    $visualRef,
                );
            }
        } catch (FfmpegException $e) {
            return JobResult::failed('final render failed: ' . $e->getMessage(), 'ffmpeg');
        }

        return JobResult::ready($render + [
            'final' => true,
            'ai_label_required' => (bool) ($prior['ai_video']['ai_label_required'] ?? $prior['asset_fetch']['ai_label_required'] ?? false),
        ], 'ffmpeg');
    }
}
