<?php

declare(strict_types=1);

namespace Kuyash\Workspace;

use Kuyash\Core\Database;

/**
 * Per-workspace settings that live on the workspaces row. Phase 7 adds the
 * default avatar pointer (reference-asset model): a pre-selected reference asset
 * used for "face"-format runs when no per-run reference is chosen. Tenant-scoped
 * by a raw workspace_id; setting one validates the asset belongs to the tenant.
 */
final class WorkspaceSettings
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
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
