<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Library\MediaProbe;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\FfmpegException;
use Throwable;

/**
 * Demo media built from COMMITTED stock fixtures — the deterministic, offline
 * path, used by the visual gate.
 *
 * The fixtures are REAL portrait stock footage, not a synthetic gradient. That
 * matters for the gate specifically: while the fixture was a flat wash, every
 * poster the gate screenshotted was a flat wash too, so a screen with no poster
 * at all looked exactly like a screen with one. The gate could not see the bug
 * it was there to catch.
 *
 * Nothing here trusts the request. `clip($t, 23)` asks ffmpeg for 23 seconds and
 * then measures the file; if the encoder produced 22.98s, 22.98 is what the
 * caller gets and stores. The one bug this design exists to prevent — declaring
 * a duration for a file that does not have it — cannot be written here without
 * deleting the probe.
 */
final class FixtureMediaFactory implements MediaFactory
{
    public function __construct(
        /** Directory of committed portrait stock clips, or a single clip path. */
        private readonly string $fixture,
        private readonly MediaProbe $probe,
        // The SERVICE, not a shell string. exec() with escapeshellcmd had no
        // timeout at all (a hung ffmpeg hung the seed forever), quoted the binary
        // wrongly so a path with a space word-split, and reached for its own
        // binary instead of the one the rest of the system is configured with.
        // Ffmpeg gives the arg array, the wall-clock kill, and one resolved
        // binary for free.
        private readonly Ffmpeg $ffmpeg,
    ) {
    }

    public function available(): bool
    {
        return $this->clips() !== [] && $this->ffmpeg->available();
    }

    /**
     * The committed clips, sorted so a given variant always picks the same one —
     * the gate has to be deterministic or a diff means nothing.
     *
     * @return list<string>
     */
    private function clips(): array
    {
        if (is_file($this->fixture)) {
            return [$this->fixture];
        }
        // Two-digit names only, so the sort is numeric-by-accident ("01".."10")
        // and a stray file dropped in the directory cannot silently reorder which
        // clip a given library item gets.
        $found = is_dir($this->fixture)
            ? (glob(rtrim($this->fixture, '/') . '/[0-9][0-9].mp4') ?: [])
            : [];
        sort($found);

        return array_values($found);
    }

    private function sourceFor(int $variant): string
    {
        $clips = $this->clips();

        return $clips[$variant % count($clips)];
    }

    public function clip(string $target, int $seconds, int $variant = 0): ?array
    {
        if (!$this->available() || $seconds < 1) {
            return null;
        }

        // -stream_loop -1 repeats a short fixture until -t cuts it, so any
        // requested length is reachable from a few small committed files.
        //
        // No hue trick any more. That existed to tell ten copies of one gradient
        // apart, which solved the symptom: the clips still looked like nothing.
        // Real footage differs by being different footage.
        $ok = $this->run([
            '-stream_loop', '-1',
            '-i', $this->sourceFor($variant),
            '-t', (string) $seconds,
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-an',
            $target,
        ]);

        return $ok ? $this->measure($target, 'video', 'video/mp4') : null;
    }

    public function still(string $target, int $index = 0): ?array
    {
        if (!$this->available()) {
            return null;
        }

        // a different clip AND a different offset per call, so two stills are
        // never the same bytes under two different titles
        $ok = $this->run([
            '-ss', number_format(max(0, $index) % 3 * 0.7, 2, '.', ''),
            '-i', $this->sourceFor($index),
            '-frames:v', '1', '-q:v', '3',
            $target,
        ]);

        return $ok ? $this->measure($target, 'photo', 'image/jpeg') : null;
    }


    public function stillFrom(string $source, string $target): bool
    {
        if (!$this->ffmpeg->available() || !is_file($source)) {
            return false;
        }
        $this->run(['-ss', '0.5', '-i', $source, '-frames:v', '1', '-q:v', '3', $target]);

        return is_file($target) && filesize($target) > 0;
    }

    /** @return array<string, mixed>|null */
    private function measure(string $path, string $kind, string $mime): ?array
    {
        if (!is_file($path)) {
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

    /** @param list<string> $args */
    private function run(array $args): bool
    {
        try {
            $this->ffmpeg->run($args);

            return true;
        } catch (FfmpegException|Throwable $e) {
            // a demo clip that will not build is a skipped item, not a crash
            fwrite(STDERR, '  ffmpeg: ' . $e->getMessage() . "
");

            return false;
        }
    }

}
