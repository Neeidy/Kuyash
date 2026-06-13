<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Core\PermanentFailure;
use Throwable;

/**
 * One queue tick: atomic claim → execute OUTSIDE any transaction → finalize
 * in a short guarded transaction (sqlite-queue-notes.md). The claim is a
 * single UPDATE…RETURNING — two workers can never grab the same job.
 *
 * The claim is deliberately GLOBAL (no workspace filter): one queue serves
 * all workspaces. Every subsequent write re-applies the claimed row's
 * workspace_id (worker isolation plane — there is no session here).
 *
 * The process loop (sleep, signals, maintenance cadence) lives in
 * bin/worker.php; tick() is the unit tests drive directly on :memory:.
 */
final class Worker
{
    private const SWEEP_EVERY_BUSY_TICKS = 20;

    private readonly Closure $clock;
    private int $busyTicks = 0;

    public function __construct(
        private readonly Database $db,
        private readonly Engine $engine,
        private readonly ExecutorRegistry $executors,
        private readonly EventLog $events,
        private readonly Watchdog $watchdog,
        private readonly string $workerId,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Process at most one due job. Returns false when the queue is empty —
     * an empty tick also runs the watchdog sweep, as does every ~20th busy
     * tick, so stale jobs are found even under constant load.
     */
    public function tick(): bool
    {
        $now = ($this->clock)();
        $job = $this->claim($now);

        if ($job === null) {
            $this->watchdog->sweep($now);

            return false;
        }

        $this->busyTicks++;
        if ($this->busyTicks % self::SWEEP_EVERY_BUSY_TICKS === 0) {
            $this->watchdog->sweep($now);
        }

        $prior = $this->priorResults($job);

        try {
            $result = $this->executors->for((string) $job['type'])->execute($job, $prior);
        } catch (Throwable $e) {
            // an executor throw is a failed ATTEMPT (retry/backoff), never a worker
            // crash — UNLESS it signals a permanent condition (PermanentFailure,
            // e.g. an HTTP 401/403 auth error from a provider), which dead-letters
            // immediately so the retry budget isn't burned on an unfixable error.
            $message = $e::class . ': ' . $e->getMessage();
            $result = $e instanceof PermanentFailure
                ? JobResult::failedPermanent($message)
                : JobResult::failed($message);
        }

        $this->engine->finalize($job, $result);

        return true;
    }

    /**
     * Atomic claim + its event in one short transaction.
     *
     * @return array<string, mixed>|null
     */
    private function claim(string $now): ?array
    {
        return $this->db->transaction(function () use ($now): ?array {
            $stmt = $this->db->run(
                "UPDATE jobs SET status = 'processing', worker_id = ?, started_at = ?
                 WHERE id = (
                     SELECT id FROM jobs
                     WHERE status = 'queued' AND run_after <= ?
                     ORDER BY priority, id
                     LIMIT 1
                 )
                 RETURNING *",
                [$this->workerId, $now, $now],
            );
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            $this->events->record(
                (int) $row['workspace_id'],
                'info',
                'transition',
                'job.claimed',
                ['type' => (string) $row['type'], 'worker' => $this->workerId, 'run' => (int) $row['run_id']],
                (int) $row['run_id'],
                (int) $row['id'],
            );

            return $row;
        });
    }

    /**
     * Results of this run's finished jobs, keyed by job type — read-only,
     * outside any transaction.
     *
     * @return array<string, array<string, mixed>>
     */
    private function priorResults(array $job): array
    {
        $rows = $this->db->all(
            'SELECT type, result_json FROM jobs
             WHERE run_id = ? AND workspace_id = ? AND step < ? AND result_json IS NOT NULL
             ORDER BY step ASC',
            [$job['run_id'], $job['workspace_id'], $job['step']],
        );

        $prior = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['result_json'], true);
            if (is_array($decoded)) {
                $prior[(string) $row['type']] = $decoded;
            }
        }

        return $prior;
    }
}
