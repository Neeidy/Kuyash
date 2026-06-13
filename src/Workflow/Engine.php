<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Closure;
use Kuyash\Compliance\AutoApprovalGate;
use Kuyash\Compliance\GateDecision;
use Kuyash\Core\Database;
use Kuyash\Usage\PreflightGate;
use Kuyash\Usage\UsageRecorder;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Run lifecycle: start, advance (one-job-at-a-time pointer over the run's
 * immutable nodes_json snapshot), approve/reject/retry.
 *
 * Concurrency model (the whole trick): every state transition is ONE guarded
 * UPDATE (`WHERE status = expected`) checked via rowCount inside a short
 * transaction, and every transition writes its event row in the SAME
 * transaction. Web (approve), worker (finalize) and watchdog (requeue) can
 * race freely — losers see 0 changed rows and take the calm
 * "already decided/claimed" path. No DAG, no dependency graph: "next" is a
 * pure function of the snapshot chain and the finished job's step.
 *
 * Web-facing methods take WorkspaceContext (fail-closed tenant scoping);
 * finalize() is the worker path — sessionless, scoped by the workspace_id
 * carried on the claimed job row, re-applied in every WHERE.
 */
final class Engine
{
    private const ISO = 'Y-m-d\TH:i:s\Z';
    private const BACKOFF_BASE_S = 5;

    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly EventLog $events,
        private readonly WorkflowValidator $validator,
        ?Closure $clock = null,
        private readonly ?AutoApprovalGate $autoGate = null,
        private readonly ?UsageRecorder $usage = null,
        private readonly ?PreflightGate $preflight = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate(self::ISO);
    }

    /**
     * Validate + create run and its first job in one short transaction.
     * Distribution runs REQUIRE a ready library video asset; full runs start
     * from a trend — either a specific cached trend ("create from trend",
     * entity_id = trends.id) or none (the worker fetches the niche's top trend).
     *
     * @return int the new run id
     *
     * @throws WorkflowException with a message key the controller can flash
     */
    public function startRun(
        WorkspaceContext $ctx,
        int $workflowId,
        ?int $assetId,
        int $userId,
        ?int $trendId = null,
        ?int $referenceAssetId = null,
        ?string $prompt = null,
    ): int {
        $wsId = $ctx->id();

        $workflow = $this->db->one(
            'SELECT * FROM workflows WHERE id = ? AND workspace_id = ?',
            [$workflowId, $wsId],
        );
        if ($workflow === null) {
            throw new WorkflowException('workflow.not_found');
        }

        $nodes = json_decode((string) $workflow['nodes_json'], true);
        $errors = $this->validator->validate((string) $workflow['template'], $nodes);
        if ($errors !== []) {
            throw new WorkflowException('run.invalid_workflow', implode('; ', $errors));
        }

        // the run snapshots nodes_json verbatim by default; quick_create rewrites
        // it to carry the prompt (below) before the snapshot is taken
        $runNodesJson = (string) $workflow['nodes_json'];

        if ($workflow['template'] === Nodes::TEMPLATE_DISTRIBUTION) {
            if ($assetId === null) {
                throw new WorkflowException('run.asset_required');
            }
            $asset = $this->db->one(
                "SELECT id FROM assets WHERE id = ? AND workspace_id = ? AND kind = 'video' AND status = 'ready'",
                [$assetId, $wsId],
            );
            if ($asset === null) {
                throw new WorkflowException('run.asset_not_ready');
            }
            $entityType = 'library';
            $entityId = $assetId;
            $referenceAssetId = null; // distribution uses the library entity itself
        } elseif ($workflow['template'] === Nodes::TEMPLATE_QUICK_CREATE) {
            // Quick Create (Phase 12): a ready PHOTO reference is REQUIRED; the
            // prompt is the creative input and rides in the run's VISUALS settings
            // snapshot (no new column). entity_type = 'quick_create'.
            if ($referenceAssetId === null) {
                throw new WorkflowException('run.reference_not_ready');
            }
            $reference = $this->db->one(
                "SELECT id FROM assets WHERE id = ? AND workspace_id = ? AND kind = 'photo' AND status = 'ready'",
                [$referenceAssetId, $wsId],
            );
            if ($reference === null) {
                throw new WorkflowException('run.reference_not_ready');
            }
            $cleanPrompt = mb_substr(trim((string) $prompt), 0, 300);
            if ($cleanPrompt === '') {
                throw new WorkflowException('quick.prompt_required');
            }
            $entityType = 'quick_create';
            $entityId = null;
            $nodes = $this->injectVisualsPrompt(is_array($nodes) ? $nodes : [], $cleanPrompt);
            // re-validate the rewritten snapshot (defense: a drifted prompt must
            // never start a run); then snapshot the prompted nodes for this run
            $errors = $this->validator->validate(Nodes::TEMPLATE_QUICK_CREATE, $nodes);
            if ($errors !== []) {
                throw new WorkflowException('run.invalid_workflow', implode('; ', $errors));
            }
            $runNodesJson = json_encode($nodes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } else {
            $entityType = 'trend';
            $entityId = null;
            if ($trendId !== null) {
                // "create from trend": pin the run to a specific cached trend.
                // Tenant-scoped existence check — a missing/foreign id is rejected.
                $trend = $this->db->one(
                    'SELECT id FROM trends WHERE id = ? AND workspace_id = ?',
                    [$trendId, $wsId],
                );
                if ($trend === null) {
                    throw new WorkflowException('trend.not_found');
                }
                $entityId = $trendId;
            }
            // reference-asset model (Phase 7): an optional per-run reference
            // subject — tenant-scoped, must be a ready asset.
            if ($referenceAssetId !== null) {
                $reference = $this->db->one(
                    "SELECT id FROM assets WHERE id = ? AND workspace_id = ? AND status = 'ready'",
                    [$referenceAssetId, $wsId],
                );
                if ($reference === null) {
                    throw new WorkflowException('run.reference_not_ready');
                }
            }
        }

        // pass full node ENTRIES (not bare ids) so expansion is source-aware: a
        // quick_create VISUALS(source=ai) becomes an ai_video job, every other
        // VISUALS source resolves through asset_fetch.
        $chain = Nodes::expand(is_array($nodes) ? $nodes : []);
        $first = $chain[0];
        $now = ($this->clock)();

        // Pre-flight budget gate (Phase 11, hard block): refuse a run whose
        // estimated cost would push MTD spend past the cap BEFORE any row is
        // created — so an over-budget run leaves no half-started state. No cap
        // set → no-op. Throws BudgetExceededException (a WorkflowException, so
        // the controllers flash 'run.budget_exceeded' unchanged).
        $this->preflight?->check($wsId, (string) $workflow['template'], is_array($nodes) ? $nodes : [], $now);

        return $this->db->transaction(function () use (
            $workflow, $wsId, $entityType, $entityId, $referenceAssetId, $runNodesJson, $first, $now, $userId
        ): int {
            $this->db->run(
                'INSERT INTO runs (workspace_id, workflow_id, entity_type, entity_id, reference_asset_id,
                    nodes_json, status, current_node, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, \'running\', ?, ?, ?, ?)',
                [
                    $wsId,
                    $workflow['id'],
                    $entityType,
                    $entityId,
                    $referenceAssetId,
                    $runNodesJson,
                    $first['node'],
                    $userId,
                    $now,
                    $now,
                ],
            );
            $runId = $this->db->lastInsertId();

            $this->events->record($wsId, 'info', 'transition', 'run.started', [
                'run' => $runId,
                'workflow' => (string) $workflow['name'],
                'template' => (string) $workflow['template'],
            ], $runId);

            $this->insertJob($wsId, $runId, $first, $entityType, $entityId, $userId, $now);

            return $runId;
        });
    }

    /**
     * Worker path: write an executed job's outcome and advance the run —
     * result + next-job enqueue + runs.current_node/status all in ONE short
     * transaction. The guard checks status AND worker identity: if the
     * watchdog requeued the row mid-execute (worker_id reset to NULL, or a
     * second worker re-claimed it), the stale finalize matches zero rows and
     * the result is discarded — the live attempt owns the job.
     */
    public function finalize(array $job, JobResult $result): void
    {
        $now = ($this->clock)();

        $this->db->transaction(function () use ($job, $result, $now): void {
            match ($result->status) {
                JobResult::STATUS_READY,
                JobResult::STATUS_PUBLISHED => $this->finalizeSuccess($job, $result, $now),
                JobResult::STATUS_AWAITING_APPROVAL => $this->finalizeAwaiting($job, $result, $now),
                JobResult::STATUS_DEFERRED => $this->finalizeDeferred($job, $result, $now),
                default => $this->finalizeFailure($job, (string) $result->errorMessage, $now, $result->retryable),
            };
        });
    }

    /**
     * Approve an awaiting job: truthful record + resume the chain. A render_review
     * approval may carry an optional $scheduledFor (ISO-8601 UTC): a future time
     * is stored on runs.publish_after so the publish job (inserted two steps
     * later, after final_render) defers via run_after and fires when due. Past /
     * malformed / null = publish immediately.
     */
    public function approve(WorkspaceContext $ctx, int $jobId, int $userId, string $userEmail, ?string $scheduledFor = null): Decision
    {
        $wsId = $ctx->id();
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND workspace_id = ?', [$jobId, $wsId]);
        if ($job === null) {
            return Decision::NotFound;
        }

        $now = ($this->clock)();

        return $this->db->transaction(function () use ($wsId, $job, $userId, $userEmail, $now, $scheduledFor): Decision {
            $claimed = $this->db->run(
                "UPDATE jobs SET status = 'ready', finished_at = ?
                 WHERE id = ? AND workspace_id = ? AND status = 'awaiting_approval'",
                [$now, $job['id'], $wsId],
            );
            if ($claimed->rowCount() === 0) {
                return Decision::AlreadyDecided;
            }

            $publishAfter = (string) $job['type'] === 'render_review'
                ? $this->futureOrNull($scheduledFor, $now)
                : null;
            if ($publishAfter !== null) {
                $this->db->run(
                    "UPDATE runs SET publish_after = ?, updated_at = ? WHERE id = ? AND workspace_id = ?",
                    [$publishAfter, $now, $job['run_id'], $wsId],
                );
            }

            $this->recordApproval($wsId, $job, 'approved', $userId, $now);
            $this->events->record($wsId, 'info', 'transition', 'approval.approved', [
                'node' => (string) $job['node'],
                'user' => $userEmail,
                'run' => (int) $job['run_id'],
            ], (int) $job['run_id'], (int) $job['id']);

            $this->advance($wsId, $job, $now);

            return Decision::Ok;
        });
    }

    /** Reject an awaiting job: truthful record + cancel the run (Phase 4: reject = cancel). */
    public function reject(WorkspaceContext $ctx, int $jobId, int $userId, string $userEmail): Decision
    {
        $wsId = $ctx->id();
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND workspace_id = ?', [$jobId, $wsId]);
        if ($job === null) {
            return Decision::NotFound;
        }

        $now = ($this->clock)();

        return $this->db->transaction(function () use ($wsId, $job, $userId, $userEmail, $now): Decision {
            $claimed = $this->db->run(
                "UPDATE jobs SET status = 'cancelled', finished_at = ?
                 WHERE id = ? AND workspace_id = ? AND status = 'awaiting_approval'",
                [$now, $job['id'], $wsId],
            );
            if ($claimed->rowCount() === 0) {
                return Decision::AlreadyDecided;
            }

            $this->recordApproval($wsId, $job, 'rejected', $userId, $now);
            $this->events->record($wsId, 'info', 'transition', 'approval.rejected', [
                'node' => (string) $job['node'],
                'user' => $userEmail,
                'run' => (int) $job['run_id'],
            ], (int) $job['run_id'], (int) $job['id']);

            $this->db->run(
                "UPDATE runs SET status = 'cancelled', updated_at = ?
                 WHERE id = ? AND workspace_id = ? AND status NOT IN ('completed', 'failed', 'cancelled')",
                [$now, $job['run_id'], $wsId],
            );
            $this->events->record($wsId, 'warn', 'transition', 'run.cancelled', [
                'run' => (int) $job['run_id'],
            ], (int) $job['run_id']);

            return Decision::Ok;
        });
    }

    /** Manual retry of a dead-lettered job: reset counters and requeue. */
    public function retryJob(WorkspaceContext $ctx, int $jobId, int $userId, string $userEmail): Decision
    {
        $wsId = $ctx->id();
        $job = $this->db->one('SELECT * FROM jobs WHERE id = ? AND workspace_id = ?', [$jobId, $wsId]);
        if ($job === null) {
            return Decision::NotFound;
        }

        $now = ($this->clock)();

        return $this->db->transaction(function () use ($wsId, $job, $userEmail, $now): Decision {
            $reset = $this->db->run(
                "UPDATE jobs SET status = 'queued', retry_count = 0, error_message = NULL,
                    worker_id = NULL, run_after = ?, started_at = NULL, finished_at = NULL
                 WHERE id = ? AND workspace_id = ? AND status = 'failed'",
                [$now, $job['id'], $wsId],
            );
            if ($reset->rowCount() === 0) {
                return Decision::AlreadyDecided;
            }

            $this->db->run(
                "UPDATE runs SET status = 'running', current_node = ?, updated_at = ?
                 WHERE id = ? AND workspace_id = ? AND status = 'failed'",
                [$job['node'], $now, $job['run_id'], $wsId],
            );

            $this->events->record($wsId, 'info', 'transition', 'job.manual_retry', [
                'type' => (string) $job['type'],
                'user' => $userEmail,
                'run' => (int) $job['run_id'],
            ], (int) $job['run_id'], (int) $job['id']);

            return Decision::Ok;
        });
    }

    /* ---------- finalize branches (already inside the caller's tx) ---------- */

    private function finalizeSuccess(array $job, JobResult $result, string $now): void
    {
        $wsId = (int) $job['workspace_id'];
        $status = $result->status; // 'ready' | 'published'

        $updated = $this->db->run(
            "UPDATE jobs SET status = ?, result_json = ?, finished_at = ?, cost_cents = ?, provider = ?
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND worker_id = ?",
            [
                $status,
                json_encode($result->result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $now,
                $result->costCents,
                $result->provider,
                $job['id'],
                $wsId,
                $job['worker_id'],
            ],
        );
        if ($updated->rowCount() === 0) {
            return; // watchdog requeued (or another worker re-claimed) — the live attempt owns the row
        }

        // Phase 11: ledger the real spend (if any) atomically with the cost_cents
        // write. Mock/cache = null cost → nothing recorded (truthful).
        $this->usage?->record($job, $result, $now);

        $runId = (int) $job['run_id'];
        $key = $status === JobResult::STATUS_PUBLISHED ? 'job.published' : 'job.finished';
        $this->events->record($wsId, 'info', 'transition', $key, [
            'type' => (string) $job['type'],
            'node' => (string) $job['node'],
            'run' => $runId,
        ], $runId, (int) $job['id']);

        if ($job['type'] === 'compliance_check' && !$this->recordComplianceOutcome($job, $result, $now)) {
            return; // blocked: run cancelled, the chain never advances
        }

        $this->advance($wsId, $job, $now);
    }

    /**
     * Compliance verdict → audit events (+ run cancel on block). The check job
     * itself stays 'ready' — a block is a completed judgement, not a job
     * failure (retrying it would re-spend work for the same verdict).
     * Returns false when the run may NOT advance (block).
     */
    private function recordComplianceOutcome(array $job, JobResult $result, string $now): bool
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $status = (string) ($result->result['status'] ?? 'pass');
        $params = [
            'status' => $status,
            'policy' => (string) ($result->result['policy'] ?? '?'),
            'slop' => $result->result['checks']['slop']['score'] ?? null,
            'run' => $runId,
        ];
        $reasons = $result->result['reasons'] ?? [];
        $reasonText = implode('; ', array_filter((array) $reasons, is_string(...)));

        if ($status === 'block') {
            $this->events->record($wsId, 'error', 'compliance', 'compliance.blocked', $params + [
                'reason' => $reasonText,
            ], $runId, (int) $job['id']);

            // block = run cancelled with reasons (no revise-loop in V1); the
            // user starts a corrected run
            $this->db->run(
                "UPDATE runs SET status = 'cancelled', updated_at = ?
                 WHERE id = ? AND workspace_id = ? AND status NOT IN ('completed', 'failed', 'cancelled')",
                [$now, $runId, $wsId],
            );
            $this->events->record($wsId, 'warn', 'transition', 'run.blocked_by_compliance', [
                'run' => $runId,
                'reason' => $reasonText,
            ], $runId);

            return false;
        }

        if ($status === 'warn') {
            // warn advances — the gate guarantees a warned render lands in
            // MANUAL review even in Auto mode
            $this->events->record($wsId, 'warn', 'compliance', 'compliance.warned', $params + [
                'reason' => $reasonText,
            ], $runId, (int) $job['id']);

            return true;
        }

        $this->events->record($wsId, 'info', 'compliance', 'compliance.passed', $params, $runId, (int) $job['id']);

        return true;
    }

    private function finalizeAwaiting(array $job, JobResult $result, string $now): void
    {
        $wsId = (int) $job['workspace_id'];

        // cost_cents written here too: a real script generation already spent
        // money before this approval pause — recording it is the honest path
        $updated = $this->db->run(
            "UPDATE jobs SET status = 'awaiting_approval', result_json = ?, provider = ?, cost_cents = ?
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND worker_id = ?",
            [
                json_encode($result->result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $result->provider,
                $result->costCents,
                $job['id'],
                $wsId,
                $job['worker_id'],
            ],
        );
        if ($updated->rowCount() === 0) {
            return;
        }

        // Phase 11: a real script generation already spent money before this
        // approval pause — ledger it now (same honest path as cost_cents above).
        $this->usage?->record($job, $result, $now);

        $runId = (int) $job['run_id'];

        // Auto mode (Phase 9): consult the gate before the pause becomes
        // externally visible. We own the row (the guarded UPDATE above just
        // claimed it), so gate side-effects (deny guardrail events, fallback
        // flip) are recorded exactly once, atomically with this finalize.
        if ((string) $job['type'] === 'render_review' && $this->autoGate !== null) {
            $decision = $this->autoGate->evaluate($job, $now);
            if ($decision->approve) {
                $this->finalizeAutoApproved($job, $decision, $now);

                return;
            }
        }

        $this->db->run(
            "UPDATE runs SET status = 'awaiting_approval', current_node = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'running'",
            [$job['node'], $now, $runId, $wsId],
        );

        $this->events->record($wsId, 'info', 'transition', 'job.awaiting_approval', [
            'type' => (string) $job['type'],
            'node' => (string) $job['node'],
            'run' => $runId,
        ], $runId, (int) $job['id']);
    }

    /**
     * The gate said yes: complete the review job, write the TRUTHFUL auto
     * record (mode 'auto', NO user, policy version + score snapshot — the
     * 0007 CHECK enforces this shape), and resume the chain. Runs inside the
     * caller's finalize transaction.
     */
    private function finalizeAutoApproved(array $job, GateDecision $decision, string $now): void
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];

        $this->db->run(
            "UPDATE jobs SET status = 'ready', finished_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'awaiting_approval'",
            [$now, $job['id'], $wsId],
        );

        $this->recordApproval(
            $wsId,
            $job,
            'approved',
            null,
            $now,
            'auto',
            $decision->policyVersion,
            json_encode($decision->score, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $this->events->record($wsId, 'info', 'compliance', 'approval.auto_approved', [
            'node' => (string) $job['node'],
            'policy' => (string) $decision->policyVersion,
            'score' => $decision->score['quality']['score'] ?? null,
            'run' => $runId,
        ], $runId, (int) $job['id']);

        $this->advance($wsId, $job, $now);
    }

    /**
     * Guardrail deferral (Phase 9): the job returns to 'queued' with a future
     * run_after and NO retry_count increment — a halt is not a failure, so it
     * can never dead-letter. The reason is kept in error_message ("deferred: …")
     * for the queue UI; the guardrail event fires only when the reason CHANGES
     * (a kill-switch publish re-deferring every few minutes must not spam the
     * audit feed).
     */
    private function finalizeDeferred(array $job, JobResult $result, string $now): void
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $reason = (string) $result->errorMessage;
        $message = 'deferred: ' . $reason;

        $updated = $this->db->run(
            "UPDATE jobs SET status = 'queued', worker_id = NULL, started_at = NULL,
                run_after = ?, error_message = ?
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND worker_id = ?",
            [$this->later($now, $result->deferSeconds), $message, $job['id'], $wsId, $job['worker_id']],
        );
        if ($updated->rowCount() === 0) {
            return;
        }

        if ((string) ($job['error_message'] ?? '') !== $message) {
            $this->events->record($wsId, 'warn', 'guardrail', 'guardrail.publish_deferred', [
                'type' => (string) $job['type'],
                'reason' => $reason,
                'run' => $runId,
            ], $runId, (int) $job['id']);
        }
    }

    /**
     * Failed attempt: requeue with exponential backoff while retries remain
     * (run_after = now + 2^retry_count × 5s), otherwise dead-letter the job
     * and fail the run.
     *
     * $retryable=false (a PermanentFailure, e.g. HTTP 401/403 auth) skips the
     * backoff entirely and dead-letters on the FIRST attempt — retrying an
     * unfixable credential error would only waste the budget. The dead-lettered
     * job is still manually retriable once the operator fixes the key.
     */
    private function finalizeFailure(array $job, string $errorMessage, string $now, bool $retryable = true): void
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $newCount = (int) $job['retry_count'] + 1;

        if ($retryable && $newCount < (int) $job['max_retries']) {
            $delay = (2 ** $newCount) * self::BACKOFF_BASE_S;
            $updated = $this->db->run(
                "UPDATE jobs SET status = 'queued', retry_count = ?, error_message = ?,
                    worker_id = NULL, run_after = ?
                 WHERE id = ? AND workspace_id = ? AND status = 'processing' AND worker_id = ?",
                [$newCount, $errorMessage, $this->later($now, $delay), $job['id'], $wsId, $job['worker_id']],
            );
            if ($updated->rowCount() === 0) {
                return;
            }

            $this->events->record($wsId, 'warn', 'transition', 'job.requeued', [
                'type' => (string) $job['type'],
                'retry' => $newCount,
                'max' => (int) $job['max_retries'],
                'run' => $runId,
            ], $runId, (int) $job['id']);

            return;
        }

        // a non-retryable dead-letter is labelled so the queue UI explains why
        // it never backed off (truthful: "this could not be retried")
        $deadMessage = $retryable ? $errorMessage : 'non-retryable: ' . $errorMessage;

        $updated = $this->db->run(
            "UPDATE jobs SET status = 'failed', retry_count = ?, error_message = ?, finished_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'processing' AND worker_id = ?",
            [$newCount, $deadMessage, $now, $job['id'], $wsId, $job['worker_id']],
        );
        if ($updated->rowCount() === 0) {
            return;
        }

        $this->events->record($wsId, 'error', 'transition', 'job.failed', [
            'type' => (string) $job['type'],
            'error' => $deadMessage,
            'retryable' => $retryable,
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
    }

    /* ---------- pointer advance (already inside the caller's tx) ---------- */

    /**
     * Enqueue the job after $finishedJob from the run's snapshot chain, or
     * complete the run when the chain is exhausted. Pure pointer arithmetic:
     * chain position = step, "next" = step + 1.
     */
    private function advance(int $wsId, array $finishedJob, string $now): void
    {
        $runId = (int) $finishedJob['run_id'];
        $run = $this->db->one(
            'SELECT * FROM runs WHERE id = ? AND workspace_id = ?',
            [$runId, $wsId],
        );
        if ($run === null) {
            return;
        }
        if (in_array((string) $run['status'], Nodes::RUN_TERMINAL, true)) {
            return; // a terminal run never gets new jobs (cancelled/failed mid-flight)
        }

        $nodes = json_decode((string) $run['nodes_json'], true);
        // full node entries → source-aware expansion (quick_create VISUALS=ai → ai_video)
        $chain = Nodes::expand(is_array($nodes) ? $nodes : []);
        $next = null;
        foreach ($chain as $entry) {
            if ($entry['step'] === (int) $finishedJob['step'] + 1) {
                $next = $entry;
                break;
            }
        }

        if ($next === null) {
            $this->db->run(
                "UPDATE runs SET status = 'completed', updated_at = ?
                 WHERE id = ? AND workspace_id = ? AND status IN ('running', 'awaiting_approval')",
                [$now, $runId, $wsId],
            );
            $this->events->record($wsId, 'info', 'transition', 'run.completed', [
                'run' => $runId,
            ], $runId);

            return;
        }

        $this->db->run(
            "UPDATE runs SET status = 'running', current_node = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status IN ('running', 'awaiting_approval')",
            [$next['node'], $now, $runId, $wsId],
        );

        $this->insertJob(
            $wsId,
            $runId,
            $next,
            $run['entity_type'] === null ? null : (string) $run['entity_type'],
            $run['entity_id'] === null ? null : (int) $run['entity_id'],
            $finishedJob['user_id'] === null ? null : (int) $finishedJob['user_id'],
            $now,
            ($run['publish_after'] ?? null) === null ? null : (string) $run['publish_after'],
        );
    }

    /** @param array{step: int, node: string, type: string} $chainEntry */
    private function insertJob(
        int $wsId,
        int $runId,
        array $chainEntry,
        ?string $entityType,
        ?int $entityId,
        ?int $userId,
        string $now,
        ?string $publishAfter = null,
    ): void {
        // duplicates are dangerous for publish (double-post); the partial
        // unique index enforces at-most-one publish job per run
        $idempotencyKey = $chainEntry['type'] === 'publish' ? "run:{$runId}:publish" : null;

        // a scheduled publish defers on the queue's run_after gate (set at the
        // render_review approval); every other job runs as soon as it is enqueued
        $runAfter = $now;
        if ($chainEntry['type'] === 'publish' && $publishAfter !== null) {
            $runAfter = $this->futureOrNull($publishAfter, $now) ?? $now;
        }

        $this->db->run(
            'INSERT INTO jobs (workspace_id, run_id, node, step, type, user_id, entity_type,
                entity_id, status, payload_json, max_retries, idempotency_key, priority,
                run_after, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'queued\', \'{}\', ?, ?, 100, ?, ?)',
            [
                $wsId,
                $runId,
                $chainEntry['node'],
                $chainEntry['step'],
                $chainEntry['type'],
                $userId,
                $entityType,
                $entityId,
                Nodes::maxRetriesFor($chainEntry['type']),
                $idempotencyKey,
                $runAfter,
                $now,
            ],
        );

        $this->events->record($wsId, 'info', 'transition', 'job.created', [
            'type' => $chainEntry['type'],
            'node' => $chainEntry['node'],
            'run' => $runId,
        ], $runId, $this->db->lastInsertId());
    }

    /**
     * Truthful record writer. Manual: real user, no policy stamp. Auto: no
     * user, policy version + score snapshot. The 0007 schema CHECK rejects any
     * other combination — misrepresentation is a constraint violation.
     */
    private function recordApproval(
        int $wsId,
        array $job,
        string $decision,
        ?int $userId,
        string $now,
        string $mode = 'manual',
        ?string $policyVersion = null,
        ?string $scoreJson = null,
    ): void {
        $this->db->run(
            'INSERT INTO approvals (workspace_id, run_id, job_id, node, decision, mode,
                decided_by, decided_at, policy_version, score_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$wsId, $job['run_id'], $job['id'], $job['node'], $decision, $mode, $userId, $now, $policyVersion, $scoreJson],
        );
    }

    /**
     * Normalize a caller-supplied schedule time: returns the ISO-8601 UTC string
     * only when it parses AND is strictly in the future relative to $now; null
     * otherwise (past / malformed / empty → publish immediately).
     */
    private function futureOrNull(?string $scheduledFor, string $now): ?string
    {
        if ($scheduledFor === null || $scheduledFor === '') {
            return null;
        }
        $ts = strtotime($scheduledFor);
        if ($ts === false || $ts <= (int) strtotime($now)) {
            return null;
        }

        return gmdate(self::ISO, $ts);
    }

    /**
     * Write the Quick Create prompt into the VISUALS node's settings (the run's
     * immutable snapshot is where the AI executor reads it). Length is already
     * clamped to 300 by the caller (validator MAX_STRING_LENGTH).
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function injectVisualsPrompt(array $nodes, string $prompt): array
    {
        foreach ($nodes as $i => $entry) {
            if (is_array($entry) && ($entry['node'] ?? null) === 'VISUALS') {
                $settings = is_array($entry['settings'] ?? null) ? $entry['settings'] : [];
                $settings['prompt'] = $prompt;
                $nodes[$i]['settings'] = $settings;
            }
        }

        return $nodes;
    }

    private function later(string $nowIso, int $seconds): string
    {
        $ts = strtotime($nowIso);
        if ($ts === false) {
            // a broken clock must fail loudly, not collapse backoff into a 1970 hot-loop
            throw new \RuntimeException("Engine clock produced an unparsable timestamp: {$nowIso}");
        }

        return gmdate(self::ISO, $ts + $seconds);
    }
}
