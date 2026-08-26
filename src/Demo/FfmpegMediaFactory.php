<?php

declare(strict_types=1);

namespace Kuyash\Demo;

use Kuyash\Library\MediaProbe;
use Kuyash\Media\Ffmpeg;
use Kuyash\Media\FfmpegException;
use Throwable;

/**
 * The real {@see MediaFactory}: builds demo media with ffmpeg from a committed
 * fixture clip, then probes the result and reports what it ACTUALLY produced.
 *
 * Nothing here trusts the request. `clip($t, 23)` asks ffmpeg for 23 seconds and
 * then measures the file; if the encoder produced 22.98s, 22.98 is what the
 * caller gets and stores. The one bug this design exists to prevent — declaring
 * a duration for a file that does not have it — cannot be written here without
 * deleting the probe.
 */
final class FfmpegMediaFactory implements MediaFactory
{
    public function __construct(
        private readonly string $fixture,
        private readonly MediaProbe $probe,
        // The SERVICE, not a shell string. exec() with escapeshellcmd had no
        // timeout at all (a hung ffmpeg hung the seed forever), quoted the binary
        // wrongly so a path with a space word-split, and defaulted to bare
        // 'ffmpeg' from $PATH instead of the configured FFMPEG_BIN the rest of
        // the system uses. Ffmpeg gives the arg array, the wall-clock kill and
        // the configured binary for free.
        private readonly Ffmpeg $ffmpeg,
    ) {
    }

    public function available(): bool
    {
        return is_file($this->fixture) && $this->ffmpeg->available();
    }

    public function clip(string $target, int $seconds, int $variant = 0): ?array
    {
        if (!$this->available() || $seconds < 1) {
            return null;
        }

        // -stream_loop -1 repeats the short fixture until -t cuts it, so any
        // requested length is reachable from one small committed file.
        //
        // The hue rotation is what stops a demo library from being ten copies of
        // the same purple gradient — with posters on, identical frames made four
        // different clips indistinguishable in the grid. It shifts the LOOK of
        // synthetic test footage; it does not pretend the footage is something
        // else, and every title using it still leads with the [SAMPLE] marker.
        $ok = $this->run([
            '-stream_loop', '-1',
            '-i', $this->fixture,
            '-t', (string) $seconds,
            '-vf', sprintf('hue=h=%d:s=%s', ($variant * 41) % 360, $variant % 2 === 0 ? '1.05' : '0.85'),
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

        // -ss before -i seeks: a different offset per call means a different
        // frame, so two stills are never the same bytes under two names. The
        // fixture is short, so the offset stays inside it.
        $ok = $this->run([
            '-ss', number_format(max(0, $index) % 3 * 0.7, 2, '.', ''),
            '-i', $this->fixture,
            '-frames:v', '1', '-q:v', '3',
            $target,
        ]);

        return $ok ? $this->measure($target, 'photo', 'image/jpeg') : null;
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
