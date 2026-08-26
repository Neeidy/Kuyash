<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;
use Throwable;

/**
 * Per-target publish records (one row = one (run, account)). Worker-side writes
 * (the executor, webhook inbox, reconciler) take a raw workspace_id carried on
 * the job/post; web-facing reads take a WorkspaceContext and filter by
 * workspace_id (tenant isolation). Each transition is its own short write — no
 * transaction is ever held across the provider call (sqlite rule).
 */
final class PostRepository
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
    }


    /**
     * Did anything for this run actually go out?
     *
     * Asked before a planned day is cleared: a day that published must keep
     * saying so, because the calendar is where an operator sees that it did.
     * Tenant-scoped like every other read here.
     */
    public function runHasPublished(int $workspaceId, int $runId): bool
    {
        $row = $this->db->one(
            "SELECT COUNT(*) AS n FROM posts
             WHERE run_id = ? AND workspace_id = ? AND status IN ('published', 'publishing')",
            [$runId, $workspaceId],
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /** Existing post for an idempotency key, scoped to the workspace. */
    public function findByKey(int $workspaceId, string $idempotencyKey): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM posts WHERE idempotency_key = ? AND workspace_id = ?',
            [$idempotencyKey, $workspaceId],
        );

        return $row === null ? null : self::shape($row);
    }

    /** Match a webhook/reconcile target by the provider's external id. */
    public function findByExternalId(string $externalPostId): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM posts WHERE external_post_id = ? ORDER BY id DESC LIMIT 1',
            [$externalPostId],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * In-flight posts older than $cutoffIso (no webhook arrived) — the
     * reconciliation worklist. Global (worker sweep), like the watchdog.
     *
     * @return list<array<string, mixed>>
     */
    public function inflightOlderThan(string $cutoffIso, int $limit = 50): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM posts
             WHERE status = 'publishing' AND external_post_id IS NOT NULL AND updated_at < ?
             ORDER BY updated_at ASC LIMIT " . max(1, min(200, $limit)),
            [$cutoffIso],
        ));
    }

    /**
     * Insert a fresh in-flight target. The UNIQUE idempotency index is the
     * backstop against a double-insert race; the executor check-then-inserts on
     * a single-claimed job, so this is normally collision-free.
     *
     * Graceful UNIQUE backstop: if two publish attempts for the same
     * (run, account) ever race past the executor's findByKey pre-check, the
     * loser's INSERT trips the UNIQUE(idempotency_key) index. Rather than throw
     * (which would fail the job and trigger a retry), we treat the existing row
     * as the winner and return its id — the post already exists, so the work is
     * done. Defensive: unreachable under the single-claimed-job invariant today.
     */
    public function insertPublishing(
        int $workspaceId,
        int $runId,
        int $jobId,
        int $accountId,
        string $platform,
        bool $aiLabelApplied,
        ?string $scheduledFor,
        string $idempotencyKey,
        string $now,
    ): int {
        try {
            $this->db->run(
                "INSERT INTO posts (workspace_id, run_id, job_id, account_id, platform, status,
                    ai_label_applied, scheduled_for, idempotency_key, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'publishing', ?, ?, ?, ?, ?)",
                [$workspaceId, $runId, $jobId, $accountId, $platform, $aiLabelApplied ? 1 : 0,
                    $scheduledFor, $idempotencyKey, $now, $now],
            );
        } catch (Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = $this->findByKey($workspaceId, $idempotencyKey);
                if ($existing !== null) {
                    return (int) $existing['id']; // a concurrent attempt won — reuse its post
                }
            }
            throw $e;
        }

        return $this->db->lastInsertId();
    }

    /** SQLSTATE 23000 (integrity constraint) naming the UNIQUE index. */
    private function isUniqueViolation(Throwable $e): bool
    {
        return ($e instanceof \PDOException && (string) $e->getCode() === '23000')
            || str_contains($e->getMessage(), 'UNIQUE constraint');
    }

    /** Re-mark an existing post in-flight (a retry re-attempt of a rate-limited target). */
    public function markPublishing(int $postId, int $jobId, string $now): void
    {
        $this->db->run(
            "UPDATE posts SET status = 'publishing', job_id = ?, error_message = NULL, updated_at = ?
             WHERE id = ?",
            [$jobId, $now, $postId],
        );
    }

    public function markPublished(int $postId, string $externalPostId, string $externalUrl, string $now): void
    {
        $this->db->run(
            "UPDATE posts SET status = 'published', external_post_id = ?, external_url = ?,
                posted_at = ?, error_message = NULL, updated_at = ?
             WHERE id = ? AND status != 'published'",
            [$externalPostId, $externalUrl, $now, $now, $postId],
        );
    }

    /** Accepted async: keep in-flight but record the external id for webhook/reconcile matching. */
    public function markAccepted(int $postId, string $externalPostId, string $now): void
    {
        $this->db->run(
            "UPDATE posts SET status = 'publishing', external_post_id = ?, updated_at = ?
             WHERE id = ? AND status != 'published'",
            [$externalPostId, $now, $postId],
        );
    }

    public function markFailed(int $postId, string $error, string $now): void
    {
        $this->db->run(
            "UPDATE posts SET status = 'failed', error_message = ?, updated_at = ?
             WHERE id = ? AND status NOT IN ('published')",
            [$error, $now, $postId],
        );
    }

    /**
     * Posts for one run (run-detail "Published targets" card), with account
     * handle resolved. Web-facing: tenant-scoped.
     *
     * @return list<array<string, mixed>>
     */
    public function forRun(WorkspaceContext $ctx, int $runId): array
    {
        return array_map(self::shape(...), $this->db->all(
            'SELECT p.*, a.handle AS account_handle
             FROM posts p
             JOIN accounts a ON a.id = p.account_id
             WHERE p.workspace_id = ? AND p.run_id = ?
             ORDER BY p.id ASC',
            [$ctx->id(), $runId],
        ));
    }

    /**
     * The earliest pending publish that will fire in the future — the "Next
     * scheduled" line. Reads the queued publish JOB's run_after (posts do not
     * exist until the publish job runs). Tenant-scoped. null = nothing scheduled.
     *
     * @return array{run_id: int, run_after: string}|null
     */
    public function nextScheduled(WorkspaceContext $ctx, string $now): ?array
    {
        $row = $this->db->one(
            "SELECT run_id, run_after FROM jobs
             WHERE workspace_id = ? AND type = 'publish' AND status = 'queued' AND run_after > ?
             ORDER BY run_after ASC, id ASC LIMIT 1",
            [$ctx->id(), $now],
        );

        return $row === null
            ? null
            : ['run_id' => (int) $row['run_id'], 'run_after' => (string) $row['run_after']];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        foreach (['id', 'workspace_id', 'run_id', 'account_id'] as $col) {
            $row[$col] = (int) $row[$col];
        }
        $row['job_id'] = $row['job_id'] === null ? null : (int) $row['job_id'];
        $row['ai_label_applied'] = (int) $row['ai_label_applied'] === 1;

        return $row;
    }
}
