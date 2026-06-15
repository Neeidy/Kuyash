<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageProvider;

/**
 * Turns the pipeline's intermediate artifacts into a finished 9:16 MP4 render
 * via REAL ffmpeg, and records the render row. Shared by the draft pass
 * (AssemblyExecutor, low-res) and the final pass (FinalRenderExecutor, full-res)
 * — same logic, different geometry (draft-first rendering).
 *
 * Two modes:
 *  - narrated: visual clip (looped) + TTS audio + script-timed subtitles. The
 *    SRT is written as a sidecar and muxed as a soft mov_text track. Burned-in
 *    captions need a libass/libfreetype ffmpeg build (the subtitles/drawtext
 *    filters, absent on the dev box) — a documented follow-up; controlled by the
 *    media.burn_subtitles flag.
 *  - distribution: an existing library video normalized to 9:16, keeping its own
 *    audio (no TTS / no subtitles).
 *
 * All inputs are server-generated, validated media refs (MediaPaths). ffmpeg
 * runs with a scratch cwd that is always cleaned up.
 */
final class AssemblyEngine
{
    /**
     * @param array{burn_subtitles?: bool} $options
     * @param StorageProvider $storage the DEFAULT durable disk — the finished
     *        render + poster are put() here after ffmpeg writes them locally
     *        (no-op on 'local'); $storageDisk names it for the render row
     */
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaPaths $paths,
        private readonly RenderRepository $renders,
        private readonly StorageProvider $storage,
        private readonly int $fps = 24,
        private readonly array $options = [],
        private readonly string $storageDisk = 'local',
    ) {
    }

    /**
     * Narrated render: looped visual + TTS audio + subtitles.
     *
     * @param array{width: int, height: int, preset: string} $geometry
     *
     * @return array{render_id: int, render_ref: string, poster_ref: ?string, width: int, height: int, duration_s: ?float}
     */
    public function assembleNarrated(
        int $workspaceId,
        int $runId,
        ?int $jobId,
        string $kind,
        array $geometry,
        string $visualRef,
        string $audioRef,
        string $script,
    ): array {
        $work = $this->paths->newWorkDir();

        try {
            $visual = $this->localInput($visualRef);
            $audio = $this->localInput($audioRef);
            $duration = $this->ffmpeg->probeDuration($audio);

            $srt = SubtitleBuilder::build($script, $duration ?? 8.0);
            $hasSubs = trim($srt) !== '';
            if ($hasSubs) {
                file_put_contents($work . '/subs.srt', $srt);
            }

            $name = $this->paths->newName('mp4');
            $out = $this->paths->pathFor('render', $workspaceId, $name);

            $args = ['-stream_loop', '-1', '-i', $visual, '-i', $audio];
            $maps = ['-map', '0:v', '-map', '1:a'];
            $burn = ($this->options['burn_subtitles'] ?? false) === true && $hasSubs;

            $vf = $this->scaleCrop($geometry);
            if ($burn) {
                // only reachable on a build with the subtitles filter (flag-gated)
                $vf .= ",subtitles=subs.srt";
            } elseif ($hasSubs) {
                $args[] = '-i';
                $args[] = 'subs.srt';
                $maps[] = '-map';
                $maps[] = '2';
            }

            $args = array_merge($args, $maps, [
                '-vf', $vf,
                '-c:v', 'libx264', '-preset', $geometry['preset'], '-pix_fmt', 'yuv420p',
                '-r', (string) $this->fps,
                '-c:a', 'aac',
            ]);
            if ($hasSubs && !$burn) {
                $args[] = '-c:s';
                $args[] = 'mov_text';
            }
            $args = array_merge($args, ['-shortest', $out]);

            $this->ffmpeg->run($args, $work);

            return $this->record($workspaceId, $runId, $jobId, $kind, $name, $out, $geometry, $duration);
        } finally {
            $this->paths->cleanupWorkDir($work);
        }
    }

    /**
     * Distribution render: normalize an existing library video to 9:16 full-res,
     * keeping its own audio.
     *
     * @param array{width: int, height: int, preset: string} $geometry
     *
     * @return array{render_id: int, render_ref: string, poster_ref: ?string, width: int, height: int, duration_s: ?float}
     */
    public function assembleDistribution(
        int $workspaceId,
        int $runId,
        ?int $jobId,
        string $kind,
        array $geometry,
        string $visualRef,
    ): array {
        $work = $this->paths->newWorkDir();

        try {
            $visual = $this->localInput($visualRef);
            $name = $this->paths->newName('mp4');
            $out = $this->paths->pathFor('render', $workspaceId, $name);

            $this->ffmpeg->run([
                '-i', $visual,
                '-vf', $this->scaleCrop($geometry),
                '-c:v', 'libx264', '-preset', $geometry['preset'], '-pix_fmt', 'yuv420p',
                '-r', (string) $this->fps,
                '-c:a', 'aac',
                $out,
            ], $work);

            $duration = $this->ffmpeg->probeDuration($out);

            return $this->record($workspaceId, $runId, $jobId, $kind, $name, $out, $geometry, $duration);
        } finally {
            $this->paths->cleanupWorkDir($work);
        }
    }

    /**
     * A guaranteed-LOCAL path for a media ref that ffmpeg can open, in every
     * storage mode. The local file is used as-is when present (byte-identical to
     * before this seam). When it is absent — the object was backfilled to the
     * durable disk (R2) and the local copy evicted by migrate-storage — it is
     * staged back from the default durable disk into its canonical local path
     * (a read-through restore, so later passes reuse it). Honest failure if the
     * object exists on neither disk.
     */
    private function localInput(string $ref): string
    {
        $local = $this->paths->resolve($ref);
        if (is_file($local)) {
            return $local;
        }

        [$store, $ws, $name] = explode(':', $ref, 3);
        $key = StorageKey::make($store, (int) $ws, $name);
        if ($this->storage->exists($key)) {
            $this->storage->getToLocal($key, $local);
            if (is_file($local)) {
                return $local;
            }
        }

        throw new FfmpegException("assembly input missing and unrecoverable: {$ref}");
    }

    /** @param array{width: int, height: int} $g */
    private function scaleCrop(array $g): string
    {
        return "scale={$g['width']}:{$g['height']}:force_original_aspect_ratio=increase,crop={$g['width']}:{$g['height']}";
    }

    /**
     * @param array{width: int, height: int, preset: string} $geometry
     *
     * @return array{render_id: int, render_ref: string, poster_ref: ?string, width: int, height: int, duration_s: ?float}
     */
    private function record(
        int $workspaceId,
        int $runId,
        ?int $jobId,
        string $kind,
        string $name,
        string $outPath,
        array $geometry,
        ?float $duration,
    ): array {
        $posterName = $this->paths->newName('jpg');
        $posterPath = $this->paths->pathFor('render', $workspaceId, $posterName);
        $hasPoster = $this->ffmpeg->grabPoster($outPath, $posterPath);

        $size = is_file($outPath) ? (int) filesize($outPath) : null;

        // ffmpeg can only write local scratch; persist the finished artifacts to
        // the durable disk here (no-op on 'local'). Done before the row is
        // recorded so a remote put failure surfaces instead of recording a row
        // pointing at an object that never landed.
        $this->storage->put(StorageKey::make('render', $workspaceId, $name), $outPath, 'video/mp4');
        if ($hasPoster) {
            $this->storage->put(StorageKey::make('render', $workspaceId, $posterName), $posterPath, 'image/jpeg');
        }

        $renderId = $this->renders->create($workspaceId, $runId, $jobId, [
            'kind' => $kind,
            'stored_name' => $name,
            'poster_name' => $hasPoster ? $posterName : null,
            'width' => $geometry['width'],
            'height' => $geometry['height'],
            'duration_s' => $duration,
            'size_bytes' => $size,
        ], $this->storageDisk);

        return [
            'render_id' => $renderId,
            'render_ref' => $this->paths->ref('render', $workspaceId, $name),
            'poster_ref' => $hasPoster ? $this->paths->ref('render', $workspaceId, $posterName) : null,
            'width' => $geometry['width'],
            'height' => $geometry['height'],
            'duration_s' => $duration,
        ];
    }
}
