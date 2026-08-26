<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Library\MediaProbe;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\StockProvider;
use Throwable;

/**
 * Demo media built from REAL stock footage (DEV/demo tooling).
 *
 * WHY THIS REPLACED THE SYNTHETIC ONE. The previous factory looped a committed
 * gradient test clip. Posters extracted from it were, correctly, frames of a
 * gradient — so every "preview" in the product still rendered as a flat wash and
 * the whole point of the poster work was invisible. Hue-shifting the gradient
 * made ten clips distinguishable from each other without making any of them look
 * like video. A frame can only show something if the clip shows something.
 *
 * HONESTY. Pexels footage is real video, and that is exactly why it must stay
 * labelled: every asset built here is titled with the {@see ShowcaseSeed::MARK}
 * prefix, tracked in the manifest, and removed by teardown. The claim the marker
 * makes is "this is demo content in a demo dataset", which is true of a real
 * stock clip in a seeded library just as it was of a gradient.
 *
 * NO SILENT FALLBACK. When the provider cannot deliver, this returns null and
 * the seed says so out loud. Quietly substituting synthetic footage is precisely
 * how the screens came to look finished while being empty.
 */
final class StockMediaFactory implements MediaFactory
{
    /** Scratch clips are fetched once per call and removed in the same call. */
    private const TRIM_PRESET = 'veryfast';

    /**
     * @param list<string> $queries one search term per demo item, in order
     */
    public function __construct(
        private readonly StockProvider $stock,
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaProbe $probe,
        private readonly string $scratchDir,
        private readonly array $queries,
    ) {
    }

    public function available(): bool
    {
        return $this->queries !== [] && $this->ffmpeg->available() && is_dir($this->scratchDir);
    }

    public function clip(string $target, int $seconds, int $variant = 0): ?array
    {
        if (!$this->available() || $seconds < 1) {
            return null;
        }

        $source = $this->fetch($variant, (float) $seconds);
        if ($source === null) {
            return null;
        }

        try {
            // A stock clip is whatever length it is; the demo library needs
            // specific lengths so the 15-45s format band has something on both
            // sides of it. -stream_loop repeats a short clip until -t cuts it.
            $this->ffmpeg->run([
                '-stream_loop', '-1',
                '-i', $source,
                '-t', (string) $seconds,
                '-c:v', 'libx264', '-preset', self::TRIM_PRESET, '-pix_fmt', 'yuv420p', '-an',
                $target,
            ]);
        } catch (Throwable $e) {
            error_log('Kuyash: demo clip trim failed — ' . $e->getMessage());

            return null;
        } finally {
            @unlink($source);
        }

        return $this->measure($target, 'video', 'video/mp4');
    }

    public function still(string $target, int $index = 0): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $source = $this->fetch($index, 4.0);
        if ($source === null) {
            return null;
        }

        try {
            $this->ffmpeg->run(['-ss', '1', '-i', $source, '-frames:v', '1', '-q:v', '3', $target]);
        } catch (Throwable $e) {
            error_log('Kuyash: demo still failed — ' . $e->getMessage());

            return null;
        } finally {
            @unlink($source);
        }

        return $this->measure($target, 'photo', 'image/jpeg');
    }

    /** Download one real portrait clip to scratch; null when the provider cannot. */
    private function fetch(int $variant, float $seconds): ?string
    {
        $query = $this->queries[$variant % count($this->queries)];
        $scratch = $this->scratchDir . '/demo-src-' . bin2hex(random_bytes(6)) . '.' . $this->stock->clipExtension();

        try {
            $this->stock->fetchClip($query, $seconds, $scratch);
        } catch (Throwable $e) {
            // Named, never swallowed: a demo library that silently falls back to
            // synthetic footage is the defect this class exists to remove.
            error_log(sprintf('Kuyash: stock fetch failed for "%s" — %s', $query, $e->getMessage()));
            @unlink($scratch);

            return null;
        }

        return is_file($scratch) && filesize($scratch) > 0 ? $scratch : null;
    }

    /** @return array<string, mixed>|null */
    private function measure(string $path, string $kind, string $mime): ?array
    {
        if (!is_file($path) || filesize($path) === 0) {
            return null;
        }
        $m = $this->probe->probe($path, $kind);

        return [
            'path' => $path,
            'duration_s' => $m['duration_s'],
            'width' => $m['width'],
            'height' => $m['height'],
            'aspect' => $m['aspect'],
            'size_bytes' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
            'mime' => $mime,
        ];
    }
}
