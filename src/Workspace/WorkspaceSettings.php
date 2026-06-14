<?php

declare(strict_types=1);

namespace Kuyash\Workspace;

use Kuyash\Core\Database;

/**
 * Per-workspace settings that live on the workspaces row. Phase 7 adds the
 * default avatar pointer (reference-asset model): a pre-selected reference asset
 * used for "face"-format runs when no per-run reference is chosen. Phase 9 adds
 * the compliance settings (approval mode, kill switch, daily post cap, budget
 * cap) — bound in core.php because the worker-side AutoApprovalGate reads them.
 * Tenant-scoped by a raw workspace_id; setters validate their input.
 */
final class WorkspaceSettings
{
    public const CAP_MIN = 1;
    public const CAP_MAX = 10;

    public const NAME_MAX = 60;

    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{approval_mode: string, kill_switch: bool, daily_post_cap: int, budget_cap_cents: ?int}
     */
    public function compliance(int $workspaceId): array
    {
        $row = $this->db->one(
            'SELECT approval_mode, kill_switch, daily_post_cap, budget_cap_cents
             FROM workspaces WHERE id = ?',
            [$workspaceId],
        ) ?? [];

        return [
            'approval_mode' => (string) ($row['approval_mode'] ?? 'manual'),
            'kill_switch' => (int) ($row['kill_switch'] ?? 0) === 1,
            'daily_post_cap' => (int) ($row['daily_post_cap'] ?? 2),
            'budget_cap_cents' => isset($row['budget_cap_cents']) && $row['budget_cap_cents'] !== null
                ? (int) $row['budget_cap_cents']
                : null,
        ];
    }

    /** Returns false on an invalid mode (only 'manual'|'auto' exist). */
    public function setApprovalMode(int $workspaceId, string $mode): bool
    {
        if (!in_array($mode, ['manual', 'auto'], true)) {
            return false;
        }
        $this->db->run(
            'UPDATE workspaces SET approval_mode = ?, updated_at = ? WHERE id = ?',
            [$mode, gmdate(self::ISO), $workspaceId],
        );

        return true;
    }

    public function setKillSwitch(int $workspaceId, bool $on): void
    {
        $this->db->run(
            'UPDATE workspaces SET kill_switch = ?, updated_at = ? WHERE id = ?',
            [$on ? 1 : 0, gmdate(self::ISO), $workspaceId],
        );
    }

    /** Returns false outside the schema's 1–10 band. */
    public function setDailyPostCap(int $workspaceId, int $cap): bool
    {
        if ($cap < self::CAP_MIN || $cap > self::CAP_MAX) {
            return false;
        }
        $this->db->run(
            'UPDATE workspaces SET daily_post_cap = ?, updated_at = ? WHERE id = ?',
            [$cap, gmdate(self::ISO), $workspaceId],
        );

        return true;
    }

    /** NULL = no cap; otherwise must be positive. Returns false when invalid. */
    public function setBudgetCapCents(int $workspaceId, ?int $cents): bool
    {
        if ($cents !== null && $cents <= 0) {
            return false;
        }
        $this->db->run(
            'UPDATE workspaces SET budget_cap_cents = ?, updated_at = ? WHERE id = ?',
            [$cents, gmdate(self::ISO), $workspaceId],
        );

        return true;
    }

    /**
     * Rename the workspace (the name shown in the topbar). ADDITIVE — writes the
     * EXISTING workspaces.name column (no new schema). Trimmed; rejects empty or
     * over-long input (returns false). Tenant-scoped by the raw workspace_id the
     * caller resolved from the session. No collapsed whitespace surprises: inner
     * runs of whitespace are squashed to single spaces so the chip stays tidy.
     */
    public function setName(int $workspaceId, string $name): bool
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            return false;
        }
        $this->db->run(
            'UPDATE workspaces SET name = ?, updated_at = ? WHERE id = ?',
            [$name, gmdate(self::ISO), $workspaceId],
        );

        return true;
    }

    public function avatarAssetId(int $workspaceId): ?int
    {
        $row = $this->db->one('SELECT avatar_asset_id FROM workspaces WHERE id = ?', [$workspaceId]);
        $id = $row['avatar_asset_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** Set the default avatar to a ready asset of this workspace. Returns false if invalid. */
    public function setAvatar(int $workspaceId, int $assetId): bool
    {
        $asset = $this->db->one(
            "SELECT id FROM assets WHERE id = ? AND workspace_id = ? AND status = 'ready'",
            [$assetId, $workspaceId],
        );
        if ($asset === null) {
            return false;
        }

        $this->db->run(
            'UPDATE workspaces SET avatar_asset_id = ?, updated_at = ? WHERE id = ?',
            [$assetId, gmdate(self::ISO), $workspaceId],
        );

        return true;
    }

    public function clearAvatar(int $workspaceId): void
    {
        $this->db->run(
            'UPDATE workspaces SET avatar_asset_id = NULL, updated_at = ? WHERE id = ?',
            [gmdate(self::ISO), $workspaceId],
        );
    }
}
