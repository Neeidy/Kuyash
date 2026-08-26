<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Weekly publish slots (Phase 23). Templates only — a slot never publishes
 * anything by itself; it produces the instant an approval writes into
 * runs.publish_after, after which the existing queue gate does the work.
 *
 * Tenant isolation: every read and write filters by workspace_id, and the
 * optional account narrowing is validated against the same workspace so a slot
 * can never point at another tenant's channel.
 */
final class SlotRepository
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    /** A weekly plan is a handful of times, not a schedule engine. */
    public const MAX_PER_WORKSPACE = 50;

    /** Set by add() so the caller can tell a duplicate from bad input. */
    private ?string $lastAddFailure = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Does this workspace publish on a schedule at all?
     *
     * A screen that says "approved renders publish immediately" is making a
     * claim about HOW things go out, and it is false wherever a publishing time
     * exists — so the screens that say it have to be able to ask.
     */
    public function hasAny(WorkspaceContext $ctx): bool
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS n FROM publish_slots WHERE workspace_id = ? AND enabled = 1',
            [$ctx->id()],
        );

        return (int) ($row['n'] ?? 0) > 0;
    }

    /**
     * Slots for the picker/settings, ordered as a week reads.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, bool $enabledOnly = false): array
    {
        return $this->listForWorkspace($ctx->id(), $enabledOnly);
    }

    /**
     * The same list addressed by workspace id — the WORKER face (Phase 24). The
     * queue process is sessionless by design, so the plan chore cannot take a
     * WorkspaceContext; it iterates workspaces and passes ids, exactly as
     * DailySnapshot does.
     *
     * @return list<array<string, mixed>>
     */
    public function listForWorkspace(int $workspaceId, bool $enabledOnly = false): array
    {
        $sql = 'SELECT s.*, a.handle AS account_handle, a.platform AS account_platform
                FROM publish_slots s
                LEFT JOIN accounts a ON a.id = s.account_id AND a.workspace_id = s.workspace_id
                WHERE s.workspace_id = ?'
            . ($enabledOnly ? ' AND s.enabled = 1' : '')
            . ' ORDER BY s.weekday ASC, s.time_hhmm ASC, s.id ASC';

        return array_map(self::shape(...), $this->db->all($sql, [$workspaceId]));
    }

    /**
     * Add a publishing time. Returns the new id, or null when it could not be
     * created — call lastAddFailure() to learn WHICH of the two it was: bad
     * input, or a time that already exists (the UNIQUE index makes adding twice
     * a no-op). Reporting a duplicate as "invalid" was a Phase 23 follow-up.
     *
     * $mode: 'manual' = you assign your own video to that day; 'auto' = Kuyash
     * produces one ahead of the time, into the approval queue (Phase 24).
     */
    public function add(WorkspaceContext $ctx, int $weekday, string $hhmm, ?int $accountId, string $now, string $mode = 'manual'): ?int
    {
        $this->lastAddFailure = null;
        if ($weekday < 1 || $weekday > 7
            || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm) !== 1
            || !in_array($mode, ['manual', 'auto'], true)
        ) {
            $this->lastAddFailure = 'invalid';

            return null;
        }
        // A weekly plan is a handful of times. The cap stops an authenticated
        // operator (or a stuck script) from growing a list that every /settings
        // and /queue render then resolves one by one.
        $count = $this->db->one('SELECT COUNT(*) AS n FROM publish_slots WHERE workspace_id = ?', [$ctx->id()]);
        if ((int) ($count['n'] ?? 0) >= self::MAX_PER_WORKSPACE) {
            $this->lastAddFailure = 'too_many';

            return null;
        }
        if ($accountId !== null) {
            // tenant check: narrowing to an account of ANOTHER workspace is rejected
            $account = $this->db->one(
                'SELECT id FROM accounts WHERE id = ? AND workspace_id = ?',
                [$accountId, $ctx->id()],
            );
            if ($account === null) {
                $this->lastAddFailure = 'invalid';

                return null;
            }
        }

        $inserted = $this->db->run(
            'INSERT OR IGNORE INTO publish_slots (workspace_id, account_id, weekday, time_hhmm, mode, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
            [$ctx->id(), $accountId, $weekday, $hhmm, $mode, $now, $now],
        )->rowCount();

        if ($inserted === 0) {
            $this->lastAddFailure = 'duplicate';

            return null;
        }

        return $this->db->lastInsertId();
    }

    /** Why the last add() returned null: 'invalid' | 'duplicate' | 'too_many' | null. */
    public function lastAddFailure(): ?string
    {
        return $this->lastAddFailure;
    }

    /**
     * Switch who fills this time. Only the SLOT changes; calendar cells that
     * already carry content keep the mode they were created with (the snapshot
     * rule), and the materializer refreshes the empty ones.
     */
    public function setMode(WorkspaceContext $ctx, int $id, string $mode, string $now): bool
    {
        if (!in_array($mode, ['manual', 'auto'], true)) {
            return false;
        }

        return $this->db->run(
            'UPDATE publish_slots SET mode = ?, updated_at = ? WHERE id = ? AND workspace_id = ?',
            [$mode, $now, $id, $ctx->id()],
        )->rowCount() > 0;
    }

    /**
     * Remove a publishing time, and the calendar days it produced.
     *
     * The days MUST go with it: slot_occurrences.slot_id is a real foreign key,
     * so deleting the time while its days still point at it fails outright — and
     * a day has no meaning once the time it belongs to is gone. The record of
     * what was cancelled survives in the append-only event log, which is where
     * an audit trail belongs; the calendar is a working surface, not an archive.
     *
     * One transaction: the two deletes are a single decision.
     *
     * Tenant-scoped; true when a row was actually removed.
     */
    public function remove(WorkspaceContext $ctx, int $id): bool
    {
        $wsId = $ctx->id();

        return $this->db->transaction(function () use ($id, $wsId): bool {
            // Defence in depth: refuse outright while any day still points at a
            // run. The caller is supposed to cancel those first (with the
            // operator's confirmation); if it ever forgets, deleting here would
            // strand a run holding a past publish_after — which the queue reads
            // as "publish now". Refusing is the safe direction.
            $live = $this->db->one(
                'SELECT COUNT(*) AS n FROM slot_occurrences
                 WHERE slot_id = ? AND workspace_id = ? AND run_id IS NOT NULL',
                [$id, $wsId],
            );
            if ((int) ($live['n'] ?? 0) > 0) {
                return false;
            }

            $this->db->run(
                'DELETE FROM slot_occurrences WHERE slot_id = ? AND workspace_id = ?',
                [$id, $wsId],
            );

            return $this->db->run(
                'DELETE FROM publish_slots WHERE id = ? AND workspace_id = ?',
                [$id, $wsId],
            )->rowCount() > 0;
        });
    }

    /** Enable/disable without losing the slot. Tenant-scoped. */
    public function setEnabled(WorkspaceContext $ctx, int $id, bool $enabled, string $now): bool
    {
        return $this->db->run(
            'UPDATE publish_slots SET enabled = ?, updated_at = ? WHERE id = ? AND workspace_id = ?',
            [$enabled ? 1 : 0, $now, $id, $ctx->id()],
        )->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM publish_slots WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['weekday'] = (int) $row['weekday'];
        $row['enabled'] = (int) $row['enabled'] === 1;
        $row['account_id'] = ($row['account_id'] ?? null) === null ? null : (int) $row['account_id'];
        $row['mode'] = ((string) ($row['mode'] ?? 'manual')) === 'auto' ? 'auto' : 'manual';

        return $row;
    }

    /** Current UTC timestamp in the app's ISO format. */
    public static function now(): string
    {
        return gmdate(self::ISO);
    }
}
