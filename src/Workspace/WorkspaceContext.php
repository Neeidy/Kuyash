<?php

declare(strict_types=1);

namespace Kuyash\Workspace;

use Kuyash\Core\Database;
use RuntimeException;

/**
 * Tenant context for the current request. The active workspace id is
 * resolved at login and stored in the session; id() FAILS CLOSED — it
 * throws when unset rather than ever meaning "all workspaces".
 * Phase 3+ repositories must take this context and filter every tenant
 * query by workspace_id (tenant isolation at query level).
 */
final class WorkspaceContext
{
    private const SESSION_KEY = 'workspace_id';

    public function __construct(private readonly Database $db)
    {
    }

    /** Active workspace id; throws when no workspace is set (fail-closed). */
    public function id(): int
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_int($id) || $id < 1) {
            throw new RuntimeException(
                'No active workspace in session — refusing to run an unscoped tenant query.'
            );
        }

        return $id;
    }

    public function set(int $workspaceId): void
    {
        $_SESSION[self::SESSION_KEY] = $workspaceId;
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Display name of the active workspace (id comes from the session, where
     * it was placed only after a membership check at login).
     */
    public function currentName(): string
    {
        $row = $this->db->one('SELECT name FROM workspaces WHERE id = ?', [$this->id()]);

        return (string) ($row['name'] ?? 'Workspace');
    }

    /**
     * The workspace a user lands in at login: their owner membership first,
     * then the oldest membership.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    public function resolveForUser(int $userId): ?array
    {
        $row = $this->db->one(
            "SELECT w.id, w.name, wu.role
             FROM workspaces w
             JOIN workspace_users wu ON wu.workspace_id = w.id
             WHERE wu.user_id = ?
             ORDER BY (wu.role = 'owner') DESC, wu.id ASC
             LIMIT 1",
            [$userId],
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * Membership-scoped fetch: null unless the user belongs to the workspace.
     * This is the isolation pattern every tenant read must follow.
     *
     * @return array{id: int, name: string, role: string}|null
     */
    public function workspaceForUser(int $workspaceId, int $userId): ?array
    {
        $row = $this->db->one(
            'SELECT w.id, w.name, wu.role
             FROM workspaces w
             JOIN workspace_users wu ON wu.workspace_id = w.id
             WHERE w.id = ? AND wu.user_id = ?',
            [$workspaceId, $userId],
        );

        return $row === null ? null : $this->shape($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, role: string}
     */
    private function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'role' => (string) $row['role'],
        ];
    }
}
