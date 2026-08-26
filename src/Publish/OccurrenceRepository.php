<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Slot occurrences — the calendar cells of the weekly plan (Phase 24).
 *
 * A `publish_slots` row is a weekly TEMPLATE ("Mon 09:00"); an occurrence is one
 * concrete day of it, and it is the only thing content can be bound to.
 *
 * TENANCY follows the house rule (see TrendRepository): the WEB path passes a
 * WorkspaceContext, the WORKER path passes a plain workspace id — the worker is
 * sessionless and must stay that way. Either way EVERY statement below carries
 * `workspace_id = ?`; there is no unscoped read or write in this class.
 *
 * STATE: only 'open' | 'assigned' | 'skipped' live here. "preparing / waiting
 * for you / scheduled / published" are NOT stored — they are read from the run
 * and its jobs (Phase 22/23's rule: read the real job gate, not the plan). One
 * state machine, not two.
 */
final class OccurrenceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // ── materialization ─────────────────────────────────────────────────────

    /**
     * Create one calendar cell. Idempotent by (slot, local_date): the chore runs
     * every few minutes and must never produce a second cell for the same day.
     *
     * @return bool true when a NEW row was created
     */
    public function materialize(
        int $workspaceId,
        int $slotId,
        string $localDate,
        string $publishAt,
        string $mode,
        string $now,
    ): bool {
        return $this->db->run(
            'INSERT OR IGNORE INTO slot_occurrences
                (workspace_id, slot_id, local_date, publish_at, mode, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, \'open\', ?, ?)',
            [$workspaceId, $slotId, $localDate, $publishAt, $mode, $now, $now],
        )->rowCount() > 0;
    }

    /**
     * Re-resolve a still-untouched cell after the slot or the workspace timezone
     * moved. Deliberately limited to `open` rows: an occurrence that already has
     * content attached is a commitment, and moving it silently is exactly the
     * surprise this codebase refuses (the operator is shown those instead).
     */
    public function refreshOpen(
        int $workspaceId,
        int $slotId,
        string $localDate,
        string $publishAt,
        string $mode,
        string $now,
    ): bool {
        return $this->db->run(
            "UPDATE slot_occurrences SET publish_at = ?, mode = ?, updated_at = ?
             WHERE workspace_id = ? AND slot_id = ? AND local_date = ?
               AND status = 'open' AND run_id IS NULL
               AND (publish_at != ? OR mode != ?)",
            [$publishAt, $mode, $now, $workspaceId, $slotId, $localDate, $publishAt, $mode],
        )->rowCount() > 0;
    }

    /**
     * Drop cells whose time is long past (retention). Cross-tenant by design —
     * this is the Maintenance chore, not a tenant read; every row is deleted by
     * its own age, and nothing is ever selected for one workspace using another
     * workspace's id.
     */
    public function pruneBefore(string $cutoffIso): int
    {
        // Never drop a cell whose run is still going: a run parked at approval for
        // longer than the retention window would otherwise lose its plan record
        // entirely — no day, no reason, nothing to explain it.
        return $this->db->run(
            "DELETE FROM slot_occurrences
             WHERE publish_at < ?
               AND (run_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM runs r
                                   WHERE r.id = slot_occurrences.run_id
                                     AND r.status NOT IN ('completed', 'failed', 'cancelled')))",
            [$cutoffIso],
        )->rowCount();
    }

    // ── reads ───────────────────────────────────────────────────────────────

    /**
     * The calendar window, each cell carrying enough of its run/job/post state
     * for a read-model to derive what the operator sees — without storing that
     * state a second time.
     *
     * @return list<array<string, mixed>> ascending by instant
     */
    public function window(WorkspaceContext $ctx, string $fromIso, string $toIso): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT o.*,
                    s.weekday, s.time_hhmm, s.enabled AS slot_enabled, s.mode AS slot_mode,
                    a.title AS asset_title, a.duration_s AS asset_duration,
                    r.status AS run_status,
                    (SELECT j.status FROM jobs j
                      WHERE j.run_id = o.run_id AND j.workspace_id = o.workspace_id
                        AND j.type = 'publish' ORDER BY j.id DESC LIMIT 1) AS publish_status,
                    (SELECT j.run_after FROM jobs j
                      WHERE j.run_id = o.run_id AND j.workspace_id = o.workspace_id
                        AND j.type = 'publish' ORDER BY j.id DESC LIMIT 1) AS publish_run_after,
                    (SELECT j.id FROM jobs j
                      WHERE j.run_id = o.run_id AND j.workspace_id = o.workspace_id
                        AND j.status = 'awaiting_approval' ORDER BY j.id DESC LIMIT 1) AS awaiting_job_id,
                    (SELECT j.type FROM jobs j
                      WHERE j.run_id = o.run_id AND j.workspace_id = o.workspace_id
                        AND j.status = 'awaiting_approval' ORDER BY j.id DESC LIMIT 1) AS awaiting_job_type,
                    (SELECT COUNT(*) FROM posts p
                      WHERE p.run_id = o.run_id AND p.workspace_id = o.workspace_id) AS post_count,
                    (SELECT COUNT(*) FROM posts p
                      WHERE p.run_id = o.run_id AND p.workspace_id = o.workspace_id
                        AND p.status = 'published') AS published_count
             FROM slot_occurrences o
             JOIN publish_slots s ON s.id = o.slot_id AND s.workspace_id = o.workspace_id
             LEFT JOIN assets a ON a.id = o.asset_id AND a.workspace_id = o.workspace_id
             LEFT JOIN runs r ON r.id = o.run_id AND r.workspace_id = o.workspace_id
             WHERE o.workspace_id = ? AND o.publish_at >= ? AND o.publish_at < ?
             ORDER BY o.publish_at ASC, o.id ASC",
            [$ctx->id(), $fromIso, $toIso],
        ));
    }

    /**
     * The status of a run this workspace owns, or null when it owns no such run.
     *
     * Tenant-scoped like every other read here: a caller must never learn the
     * state of another workspace's run, not even as a yes/no.
     */
    public function runStatus(int $workspaceId, int $runId): ?string
    {
        $row = $this->db->one(
            'SELECT status FROM runs WHERE id = ? AND workspace_id = ?',
            [$runId, $workspaceId],
        );

        return $row === null ? null : (string) $row['status'];
    }

    /** @return array<string, mixed>|null */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT o.*, s.weekday, s.time_hhmm, s.enabled AS slot_enabled
             FROM slot_occurrences o
             JOIN publish_slots s ON s.id = o.slot_id AND s.workspace_id = o.workspace_id
             WHERE o.id = ? AND o.workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Auto cells inside their production lead window and not started yet.
     * WORKER face — plain workspace id, no session.
     *
     * @return list<array<string, mixed>>
     */
    public function dueAuto(int $workspaceId, string $nowIso, string $horizonIso): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM slot_occurrences
             WHERE workspace_id = ? AND mode = 'auto' AND status = 'open' AND run_id IS NULL
               AND publish_at > ? AND publish_at <= ?
             ORDER BY publish_at ASC, id ASC",
            [$workspaceId, $nowIso, $horizonIso],
        ));
    }

    /**
     * Cells whose time has passed beyond the grace window and that still have
     * not produced a published post — the sweep's input. WORKER face.
     *
     * @return list<array<string, mixed>>
     */
    public function overdue(int $workspaceId, string $graceCutoffIso): array
    {
        // A cell that PUBLISHED is not overdue — it worked. Without this the
        // sweep closed every successful planned post as 'missed' and wrote a
        // guardrail warning for it, so the audit log gained a false failure for
        // every post that went out as planned.
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM slot_occurrences o
             WHERE o.workspace_id = ? AND o.status IN ('open', 'assigned') AND o.publish_at <= ?
               AND NOT EXISTS (
                   SELECT 1 FROM posts p
                   WHERE p.run_id = o.run_id AND p.workspace_id = o.workspace_id
                     AND p.status = 'published'
               )
             ORDER BY o.publish_at ASC, o.id ASC",
            [$workspaceId, $graceCutoffIso],
        ));
    }

    /**
     * Cells of one slot that are past 'open' — what makes removing or re-timing
     * a slot a decision rather than a click (E9/E10).
     *
     * @return list<array<string, mixed>>
     */
    public function committedForSlot(WorkspaceContext $ctx, int $slotId): array
    {
        // NO time filter. A cell whose time passed minutes ago can still hold a
        // live run with a queued publish (the sweep only closes it after the
        // grace window). Filtering on `publish_at > now` hid exactly those from
        // the confirmation prompt, and removing the time then deleted the cell
        // while its run kept a past publish_after — which the queue reads as
        // "publish now". Anything carrying work is committed, whatever its time.
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM slot_occurrences
             WHERE workspace_id = ? AND slot_id = ?
               AND (run_id IS NOT NULL OR status = 'assigned')
             ORDER BY publish_at ASC, id ASC",
            [$ctx->id(), $slotId],
        ));
    }

    /**
     * How many cells of each slot still carry work — so the screen can label the
     * remove button for what it will actually do, instead of asking the operator
     * to confirm a sentence about one video while cancelling several.
     *
     * @return array<int, int> slot_id → count
     */
    public function committedCountsBySlot(WorkspaceContext $ctx): array
    {
        $out = [];
        foreach ($this->db->all(
            "SELECT slot_id, COUNT(*) AS n FROM slot_occurrences
             WHERE workspace_id = ? AND (run_id IS NOT NULL OR status = 'assigned')
             GROUP BY slot_id",
            [$ctx->id()],
        ) as $row) {
            $out[(int) $row['slot_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Forget a video on every day that is DONE with it — published, swept, or
     * simply past. The reference is what the foreign key holds onto, and holding
     * it forever turned deleting an ordinary old video into a 500. The day keeps
     * its status and reason; it just no longer names the file.
     *
     * Days still WAITING on that video are untouched, so
     * plannedUsesOfAsset() still refuses those deletions.
     */
    public function forgetAssetOnFinishedDays(WorkspaceContext $ctx, int $assetId, string $nowIso): int
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET asset_id = NULL, updated_at = ?
             WHERE workspace_id = ? AND asset_id = ?
               AND NOT (status = 'assigned' AND publish_at > ?)",
            [$nowIso, $ctx->id(), $assetId, $nowIso],
        )->rowCount();
    }

    /**
     * The calendar day each of these runs was planned for, keyed by run id.
     * Lets the approval queue say "this one is for Monday 09:00" instead of
     * offering a picker for a question the plan already answered.
     *
     * @param list<int> $runIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function byRunIds(WorkspaceContext $ctx, array $runIds): array
    {
        $runIds = array_values(array_unique(array_filter($runIds, static fn (int $id): bool => $id > 0)));
        if ($runIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));

        $out = [];
        foreach ($this->db->all(
            "SELECT o.*, s.time_hhmm FROM slot_occurrences o
             JOIN publish_slots s ON s.id = o.slot_id AND s.workspace_id = o.workspace_id
             WHERE o.workspace_id = ? AND o.run_id IN ({$placeholders})",
            array_merge([$ctx->id()], $runIds),
        ) as $row) {
            $shaped = self::shape($row);
            $out[(int) $shaped['run_id']] = $shaped;
        }

        return $out;
    }

    /**
     * How many upcoming days this video is standing on. Used to refuse deleting
     * a library video that the calendar still points at — a planned day whose
     * content vanished is a broken promise, not a tidy-up.
     */
    public function plannedUsesOfAsset(WorkspaceContext $ctx, int $assetId, string $nowIso): int
    {
        $row = $this->db->one(
            "SELECT COUNT(*) AS n FROM slot_occurrences
             WHERE workspace_id = ? AND asset_id = ? AND status = 'assigned' AND publish_at > ?",
            [$ctx->id(), $assetId, $nowIso],
        );

        return (int) ($row['n'] ?? 0);
    }

    // ── guarded transitions ─────────────────────────────────────────────────

    /**
     * Take the cell BEFORE starting any work: a guarded open→assigned move, so a
     * double-submitted form (or two workers) can only win once. The run is
     * attached afterwards, and a failure releases the cell again.
     */
    public function reserve(int $workspaceId, int $id, ?int $assetId, string $now): bool
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET status = 'assigned', asset_id = ?, skip_reason = NULL, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'open' AND run_id IS NULL",
            [$assetId, $now, $id, $workspaceId],
        )->rowCount() > 0;
    }

    public function attachRun(int $workspaceId, int $id, int $runId, string $now): bool
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET run_id = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'assigned' AND run_id IS NULL",
            [$runId, $now, $id, $workspaceId],
        )->rowCount() > 0;
    }

    /**
     * Drop the run pointer from every cell of one slot, after those runs have
     * been cancelled. The cell keeps its `skipped` status and reason (the record
     * of what happened), but no longer points at a run — which is what lets the
     * publishing time itself be removed.
     */
    public function detachRunsForSlot(int $workspaceId, int $slotId, string $now): int
    {
        return $this->db->run(
            'UPDATE slot_occurrences SET run_id = NULL, updated_at = ?
             WHERE slot_id = ? AND workspace_id = ? AND run_id IS NOT NULL',
            [$now, $slotId, $workspaceId],
        )->rowCount();
    }

    /** Back to an empty cell — used when the work could not start, or was cancelled. */
    public function release(int $workspaceId, int $id, string $now): bool
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET status = 'open', asset_id = NULL, run_id = NULL,
                    skip_reason = NULL, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'assigned'",
            [$now, $id, $workspaceId],
        )->rowCount() > 0;
    }

    /**
     * Note WHY production has not started, without closing the cell.
     *
     * A paused plan or a full daily cap may well clear before the time arrives,
     * so declaring the cell missed now would be a lie. Instead the reason is
     * recorded while the cell stays open: the calendar can say "waiting — daily
     * limit reached", a later tick clears it by simply succeeding, and if the
     * time does pass the sweep closes it with this reason instead of a vague one.
     *
     * Returns true only when the reason CHANGED, so a chore running every five
     * minutes does not write the same audit line all day (the same discipline
     * Engine::finalizeDeferred uses).
     */
    public function noteBlocked(int $workspaceId, int $id, string $reason, string $now): bool
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET skip_reason = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status = 'open' AND run_id IS NULL
               AND (skip_reason IS NULL OR skip_reason != ?)",
            [$reason, $now, $id, $workspaceId, $reason],
        )->rowCount() > 0;
    }

    /**
     * Record, truthfully, that this cell produced nothing and why. Never a
     * silent gap: the reason is shown on the calendar and counted in the digest.
     */
    public function markSkipped(int $workspaceId, int $id, string $reason, string $now): bool
    {
        return $this->db->run(
            "UPDATE slot_occurrences SET status = 'skipped', skip_reason = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status IN ('open', 'assigned')",
            [$reason, $now, $id, $workspaceId],
        )->rowCount() > 0;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['slot_id'] = (int) $row['slot_id'];
        $row['asset_id'] = $row['asset_id'] === null ? null : (int) $row['asset_id'];
        $row['run_id'] = $row['run_id'] === null ? null : (int) $row['run_id'];
        if (array_key_exists('weekday', $row)) {
            $row['weekday'] = (int) $row['weekday'];
        }
        if (array_key_exists('slot_enabled', $row)) {
            $row['slot_enabled'] = (int) $row['slot_enabled'] === 1;
        }

        return $row;
    }
}
