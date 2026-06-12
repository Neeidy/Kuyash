<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Safe ffmpeg/ffprobe runner (security rule: ffmpeg execution).
 *
 * - Commands are passed as an ARG ARRAY to proc_open — NEVER a shell string, so
 *   no argument can be interpreted by a shell (no injection surface). PHP runs
 *   the binary directly (no `/bin/sh -c`).
 * - A wall-clock timeout SIGKILLs a runaway/hung process. (ffmpeg is the direct
 *   child here — no shell wrapper — so there is no separate group to reap.)
 * - stderr is captured and truncated for the error message; the full command
 *   line is never surfaced.
 * - Callers pass only server-generated, validated paths (see MediaPaths).
 *
 * The binary paths come from config and are validated once at construction.
 */
final class Ffmpeg
{
    private const STDERR_TAIL = 600;

    public function __construct(
        private readonly string $ffmpegBin,
        private readonly string $ffprobeBin,
        private readonly int $timeoutSeconds = 900,
    ) {
    }

    /** True only when both binaries are present + executable (capability probe). */
    public function available(): bool
    {
        return is_executable($this->ffmpegBin) && is_executable($this->ffprobeBin);
    }

    /**
     * Run ffmpeg with the given args (NO leading binary — added here). $cwd lets
     * the subtitles/sidecar files be referenced by a bare relative name, avoiding
     * filtergraph path-escaping. Throws FfmpegException on non-zero exit/timeout.
     *
     * @param list<string> $args
     */
    public function run(array $args, ?string $cwd = null): void
    {
        $this->exec(array_merge([$this->ffmpegBin, '-nostdin', '-y', '-loglevel', 'error'], $args), $cwd);
    }

    /** Probe a media file's duration in seconds, or null when unknown. */
    public function probeDuration(string $path): ?float
    {
        $out = $this->exec([
            $this->ffprobeBin, '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1', $path,
        ], null, false);

        $value = trim($out);

        return $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
    }

    /** Grab the first frame of $videoPath into $posterPath (jpg). Best-effort. */
    public function grabPoster(string $videoPath, string $posterPath): bool
    {
        try {
            $this->run(['-i', $videoPath, '-frames:v', '1', '-q:v', '3', $posterPath]);

            return is_file($posterPath);
        } catch (FfmpegException) {
            return false; // a missing poster never fails a render
        }
    }

    /**
     * Execute a process from an arg array with a timeout. Returns stdout.
     *
     * @param list<string> $argv
     */
    private function exec(array $argv, ?string $cwd, bool $throwOnFail = true): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new FfmpegException('ffmpeg could not be started');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + $this->timeoutSeconds;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($process, 9);
                $timedOut = true;
                $exit = 124;
                break;
            }
            usleep(50_000); // 50ms — cheap poll, no busy spin
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut) {
            throw new FfmpegException("ffmpeg timed out after {$this->timeoutSeconds}s");
        }
        if ($throwOnFail && $exit !== 0) {
            // tail of stderr only — never the command line / full output
            $tail = trim(substr($stderr, -self::STDERR_TAIL));

            throw new FfmpegException("ffmpeg failed (exit {$exit}): {$tail}");
        }

        return $stdout;
    }
}
