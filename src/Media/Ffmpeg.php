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
 * The binary paths come from config and are resolved once at construction.
 */
final class Ffmpeg
{
    private const STDERR_TAIL = 600;

    private readonly string $ffmpegBin;
    private readonly string $ffprobeBin;

    public function __construct(
        string $ffmpegBin,
        string $ffprobeBin,
        private readonly int $timeoutSeconds = 900,
    ) {
        $this->ffmpegBin = self::resolveBinary($ffmpegBin);
        $this->ffprobeBin = self::resolveBinary($ffprobeBin);
    }

    /**
     * Turn a configured binary name into an absolute path, ONCE.
     *
     * Why this exists: the default used to be one machine's Homebrew path, which
     * is not a default at all anywhere else. A bare `ffmpeg` cannot simply be
     * handed on either — `available()` asks `is_executable()`, and against a bare
     * name that is a relative test against the current working directory, so the
     * whole system would report ffmpeg missing while it sat on PATH. Resolving
     * here keeps every later step working on an absolute path.
     *
     * A value that already contains a separator is taken as given: an operator who
     * writes an explicit path means it.
     *
     * The PATH walk only considers ABSOLUTE entries. An empty entry and `.` both
     * mean the current directory in POSIX, and a relative entry resolves against
     * it, so any of those would let a file named `ffmpeg` sitting in a work or
     * upload directory win the lookup. The candidate must also be a real,
     * executable file. That is all this filter claims: it does not make the
     * accepted directories trustworthy — first match on PATH still wins, so pin
     * FFMPEG_BIN in production.
     *
     * RESOLVE LENIENTLY, EXECUTE STRICTLY. When nothing matches, the value comes
     * back unchanged — and it is {@see assertResolved()} that then refuses it.
     * Returning a bare name is NOT enough on its own: proc_open()'s array form
     * hands argv[0] to execvp(), which runs its own PATH search honouring exactly
     * the `.`/empty/relative entries this walk excludes. Left to libc, a filter
     * here would have been a first opinion silently overruled — with an empty
     * PATH (php-fpm's `clear_env = yes` default) landing straight in the working
     * directory. The resolver's rules have to be the only rules.
     *
     * POSIX only (macOS/Linux), which is what this project targets — no PATHEXT.
     */
    public static function resolveBinary(string $configured): string
    {
        $name = trim($configured);
        if ($name === '' || str_contains($name, DIRECTORY_SEPARATOR)) {
            return $configured;
        }

        // local_only: read the real process environment, never a SAPI request env
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH', true)) as $dir) {
            if ($dir === '' || !str_starts_with($dir, DIRECTORY_SEPARATOR)) {
                continue;
            }
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return $configured;
    }

    /** True only when both binaries are present + executable (capability probe). */
    public function available(): bool
    {
        // A bare name never counts: is_executable() would test it against the
        // CURRENT WORKING DIRECTORY, which is the one answer this class must
        // never give. Unresolved means unavailable.
        return self::isPath($this->ffmpegBin) && is_executable($this->ffmpegBin)
            && self::isPath($this->ffprobeBin) && is_executable($this->ffprobeBin);
    }

    /** Does this value name a location, rather than something for PATH to guess? */
    private static function isPath(string $bin): bool
    {
        return str_contains($bin, DIRECTORY_SEPARATOR);
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
        // "unknown" is this method's contract, and an unresolvable ffprobe is a
        // kind of unknown — callers (PexelsStockProvider) already branch on null.
        // exec() would throw here; it must still refuse to run the bare name, but
        // it is not this method's job to turn that into an exception.
        if (!self::isPath($this->ffprobeBin)) {
            return null;
        }

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
        // EXECUTE STRICTLY. Past this line argv[0] goes to execvp(), which would
        // PATH-search a bare name under rules resolveBinary() deliberately
        // rejects — including the working directory. Refuse instead.
        $binary = $argv[0] ?? '';
        if (!self::isPath($binary)) {
            throw new FfmpegException(
                'ffmpeg/ffprobe was not found on PATH — set FFMPEG_BIN and FFPROBE_BIN to absolute paths',
            );
        }

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
