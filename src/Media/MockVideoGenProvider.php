<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Default AI-video provider: turns the still reference photo into a REAL 9:16
 * mp4 with ffmpeg zoompan (a slow Ken-Burns push-in) — offline, deterministic,
 * NO network. provider='mock', costCents=null (mock work is never presented as
 * real spend — truthful, like MockStockProvider/MockTtsProvider).
 *
 * The point is a real, downstream-renderable clip the compliance + publish tail
 * can process, not photographic generation. A prompt of EXACTLY the FAIL_SENTINEL
 * triggers the error path (so the failure branch is testable without a real
 * provider). The clip is produced at full geometry; final_render normalizes it.
 */
final class MockVideoGenProvider implements VideoGenProvider
{
    /** A prompt of exactly this string forces a generation failure (tests). */
    public const FAIL_SENTINEL = '__mock_videogen_fail__';

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly int $width = 1080,
        private readonly int $height = 1920,
        private readonly int $fps = 24,
    ) {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function clipExtension(): string
    {
        return 'mp4';
    }

    public function generateFromImage(
        string $imagePath,
        string $prompt,
        float $durationSeconds,
        string $targetPath,
    ): VideoResult {
        if ($prompt === self::FAIL_SENTINEL) {
            throw new VideoGenProviderException('mock image-to-video generation failed (sentinel prompt)');
        }

        $duration = max(1.0, min(60.0, $durationSeconds));
        $frames = max(1, (int) round($duration * $this->fps));

        // Single still input (no -loop): zoompan emits $frames frames from the
        // one frame, which avoids the per-input-frame "zoom jump". Pre-scale+crop
        // fills 9:16 before the push-in; s= fixes the output geometry.
        $zoompan = sprintf(
            'scale=%1$d:%2$d:force_original_aspect_ratio=increase,crop=%1$d:%2$d,'
            . "zoompan=z='min(zoom+0.0010,1.25)':d=%3\$d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%1\$dx%2\$d:fps=%4\$d",
            $this->width,
            $this->height,
            $frames,
            $this->fps,
        );

        try {
            $this->ffmpeg->run([
                '-i', $imagePath,
                '-vf', $zoompan,
                '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p',
                '-t', (string) $duration, '-r', (string) $this->fps,
                $targetPath,
            ]);
        } catch (FfmpegException $e) {
            throw new VideoGenProviderException('mock image-to-video generation failed: ' . $e->getMessage());
        }

        return new VideoResult($this->width, $this->height, $duration, null, 'mock', ['motion' => 'kenburns']);
    }
}
