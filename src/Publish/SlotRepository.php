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

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Slots for the picker/settings, ordered as a week reads.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, bool $enabledOnly = false): array
    {
        $sql = 'SELECT s.*, a.handle AS account_handle, a.platform AS account_platform
                FROM publish_slots s
                LEFT JOIN accounts a ON a.id = s.account_id AND a.workspace_id = s.workspace_id
                WHERE s.workspace_id = ?'
            . ($enabledOnly ? ' AND s.enabled = 1' : '')
            . ' ORDER BY s.weekday ASC, s.time_hhmm ASC, s.id ASC';

        return array_map(self::shape(...), $this->db->all($sql, [$ctx->id()]));
    }

    /**
     * Add a slot. Returns the new id, or null when the input is invalid or the
     * slot already exists (the UNIQUE index makes adding twice a no-op).
     */
    public function add(WorkspaceContext $ctx, int $weekday, string $hhmm, ?int $accountId, string $now): ?int
    {
        if ($weekday < 1 || $weekday > 7 || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm) !== 1) {
            return null;
        }
        // A weekly plan is a handful of times. The cap stops an authenticated
        // operator (or a stuck script) from growing a list that every /settings
        // and /queue render then resolves one by one.
        $count = $this->db->one('SELECT COUNT(*) AS n FROM publish_slots WHERE workspace_id = ?', [$ctx->id()]);
        if ((int) ($count['n'] ?? 0) >= self::MAX_PER_WORKSPACE) {
            return null;
        }
        if ($accountId !== null) {
            // tenant check: narrowing to an account of ANOTHER workspace is rejected
            $account = $this->db->one(
                'SELECT id FROM accounts WHERE id = ? AND workspace_id = ?',
                [$accountId, $ctx->id()],
            );
            if ($account === null) {
                return null;
            }
        }

        $inserted = $this->db->run(
            'INSERT OR IGNORE INTO publish_slots (workspace_id, account_id, weekday, time_hhmm, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$ctx->id(), $accountId, $weekday, $hhmm, $now, $now],
        )->rowCount();

        return $inserted > 0 ? $this->db->lastInsertId() : null;
    }

    /** Remove a slot. Tenant-scoped; true when a row was actually removed. */
    public function remove(WorkspaceContext $ctx, int $id): bool
    {
        return $this->db->run(
            'DELETE FROM publish_slots WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        )->rowCount() > 0;
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

        return $row;
    }

    /** Current UTC timestamp in the app's ISO format. */
    public static function now(): string
    {
        return gmdate(self::ISO);
    }
}
