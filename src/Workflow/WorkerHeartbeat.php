<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * Liveness signal for the queue worker. The worker writes an ISO timestamp to
 * a small file on each loop (throttled); the web UI reads it to warn when the
 * worker is not running — the whole app is queue-driven, so a stopped worker
 * means nothing progresses, and silent stalling confuses users.
 *
 * A plain file (not a DB row) keeps it out of every tenant query and needs no
 * migration. "now" is injected so the staleness check is testable.
 */
final class WorkerHeartbeat
{
    /** A beat older than this many seconds means "not running". The worker
     *  beats at least every 5s, so 30s tolerates a slow loop without flapping. */
    public const STALE_AFTER_SECONDS = 30;

    public function __construct(private readonly string $path)
    {
    }

    /** Record a beat. Best-effort: a failed write must never crash the worker. */
    public function beat(string $nowIso): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($this->path, $nowIso, LOCK_EX);
    }

    /** The last recorded beat, or null if the worker has never run. */
    public function lastBeat(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = @file_get_contents($this->path);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    /** Seconds since the last beat, or null when there is none / it is unparsable. */
    public function ageSeconds(string $nowIso): ?int
    {
        $last = $this->lastBeat();
        if ($last === null) {
            return null;
        }
        $lastTs = strtotime($last);
        $nowTs = strtotime($nowIso);
        if ($lastTs === false || $nowTs === false) {
            return null;
        }

        return max(0, $nowTs - $lastTs);
    }

    public function isAlive(string $nowIso, int $maxAgeSeconds = self::STALE_AFTER_SECONDS): bool
    {
        $age = $this->ageSeconds($nowIso);

        return $age !== null && $age <= $maxAgeSeconds;
    }
}
