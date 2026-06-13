<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Core\Database;
use Kuyash\Workflow\JobResult;

/**
 * The single write path into the usage ledger. Called by Engine::finalize from
 * INSIDE the finalize transaction (plain run(), joining the caller's open
 * transaction on the same PDO connection — never opens its own, the
 * short-transaction rule holds: no external calls).
 *
 * TRUTHFULNESS (core compliance value): a row is written ONLY when the job
 * returned a real, non-null cost. Mock providers and cache hits report a null
 * cost → NOTHING is recorded; no fake spend ever appears as real. Unmapped job
 * types (free/local work: trends, assembly, render) record nothing either.
 *
 * Idempotent per job: the usage_events UNIQUE(job_id) makes a re-finalized or
 * re-enqueued job a no-op (INSERT OR IGNORE), and the matching credit_transactions
 * spend is written only when the usage_event was newly inserted.
 *
 * Non-throwing by construction: INSERT OR IGNORE (never a UNIQUE violation),
 * cost clamped to satisfy the cost_cents >= 0 CHECK, category/unit_type drawn
 * only from the validated config — so record() cannot roll back the
 * otherwise-successful finalize transaction it runs inside.
 */
final class UsageRecorder
{
    /**
     * @param array{categories: array<string, string>, unit_types: array<string, string>} $config
     */
    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {
    }

    /**
     * @param array<string, mixed> $job the finalized job row (carries workspace_id, run_id, id, type)
     */
    public function record(array $job, JobResult $result, string $now): void
    {
        if ($result->costCents === null || $result->costCents <= 0) {
            return; // mock / cache hit / free provider / sub-cent rounded to $0 → no spend, no row (truthful)
        }

        $type = (string) $job['type'];
        $category = $this->config['categories'][$type] ?? null;
        if ($category === null) {
            return; // unmapped type (free/local) — jobs.cost_cents still holds the rollup
        }

        $cost = (int) $result->costCents; // guaranteed > 0 above; satisfies the cost_cents >= 0 CHECK
        $unitType = $this->config['unit_types'][$category] ?? null;
        $wsId = (int) $job['workspace_id'];
        $runId = $job['run_id'] === null ? null : (int) $job['run_id'];
        $jobId = (int) $job['id'];

        // model + units are NULL in V1 (provider + category + cost captured
        // truthfully; counts are a Phase 13 follow-up through the executor seam)
        $inserted = $this->db->run(
            'INSERT OR IGNORE INTO usage_events
                (workspace_id, run_id, job_id, provider, category, model, units, unit_type, cost_cents, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)',
            [$wsId, $runId, $jobId, (string) ($result->provider ?? 'unknown'), $category, $unitType, $cost, $now],
        );
        if ($inserted->rowCount() === 0) {
            return; // already recorded for this job (retry / re-finalize) — at-most-once
        }

        // mirror the spend into the money ledger (signed negative so balance =
        // SUM works); the partial UNIQUE(ref_job_id) is a second idempotency guard
        $this->db->run(
            "INSERT OR IGNORE INTO credit_transactions
                (workspace_id, type, amount_cents, reason, ref_run_id, ref_job_id, created_at)
             VALUES (?, 'spend', ?, ?, ?, ?, ?)",
            [$wsId, -$cost, $category, $runId, $jobId, $now],
        );
    }
}
