<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;

/**
 * Self-healing sweep: a processing job whose started_at exceeds its
 * type-based timeout (Nodes::JOB_DEFAULTS) is requeued — or dead-lettered
 * when retries are exhausted, failing its run. No run can hang forever on a
 * crashed worker.
 *
 * Each action is one guarded UPDATE in its own short transaction: if the
 * original worker finishes concurrently, its finalize wins the row and the
 * sweep's update changes 0 rows (calm race-loser path).
 */
final class Watchdog
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
    ) {
    }

    /** @return int number of jobs acted on */
    public function sweep(string $nowIso): int
    {
        $processing = $this->db->all(
            "SELECT * FROM jobs WHERE status = 'processing' AND started_at IS NOT NULL",
        );

        $actions = 0;
        $nowTs = (int) strtotime($nowIso);

        foreach ($processing as $job) {
            $deadline = (int) strtotime((string) $job['started_at']) + Nodes::timeoutFor((string) $job['type']);
            if ($deadline > $nowTs) {
                continue;
            }

            $actions += $this->rescue($job, $nowIso) ? 1 : 0;
        }

        return $actions;
    }

    private function rescue(array $job, string $now): bool
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $newCount = (int) $job['retry_count'] + 1;
        $timeout = Nodes::timeoutFor((string) $job['type']);

        if ($newCount < (int) $job['max_retries']) {
            return $this->db->transaction(function () use ($job, $wsId, $runId, $newCount, $now): bool {
                $updated = $this->db->run(
                    "UPDATE jobs SET status = 'queued', retry_count = ?, worker_id = NULL,
                        started_at = NULL, run_after = ?
                     WHERE id = ? AND workspace_id = ? AND status = 'processing'",
                    [$newCount, $now, $job['id'], $wsId],
                );
                if ($updated->rowCount() === 0) {
                    return false; // the worker finalized it first
                }

                $this->events->record($wsId, 'warn', 'transition', 'watchdog.requeued', [
                    'type' => (string) $job['type'],
                    'retry' => $newCount,
                    'max' => (int) $job['max_retries'],
                    'run' => $runId,
                ], $runId, (int) $job['id']);

                return true;
            });
        }

        return $this->db->transaction(function () use ($job, $wsId, $runId, $newCount, $timeout, $now): bool {
            $updated = $this->db->run(
                "UPDATE jobs SET status = 'failed', retry_count = ?, error_message = ?, finished_at = ?
                 WHERE id = ? AND workspace_id = ? AND status = 'processing'",
                [$newCount, "watchdog: stale after {$timeout}s processing timeout", $now, $job['id'], $wsId],
            );
            if ($updated->rowCount() === 0) {
                return false;
            }

            $this->events->record($wsId, 'error', 'transition', 'watchdog.failed', [
                'type' => (string) $job['type'],
                'run' => $runId,
            ], $runId, (int) $job['id']);

            $this->db->run(
                "UPDATE runs SET status = 'failed', updated_at = ?
                 WHERE id = ? AND workspace_id = ? AND status NOT IN ('completed', 'failed', 'cancelled')",
                [$now, $runId, $wsId],
            );
            $this->events->record($wsId, 'error', 'transition', 'run.failed', [
                'run' => $runId,
                'node' => (string) $job['node'],
            ], $runId);

            return true;
        });
    }
}
