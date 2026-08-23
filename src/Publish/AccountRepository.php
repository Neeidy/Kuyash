<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Social accounts (publish targets). Web-facing reads/writes take a
 * WorkspaceContext and filter by workspace_id (tenant isolation at query
 * level). Worker-side calls (the publish executor / reconciler, which have no
 * session) take a raw workspace_id carried on the claimed job row — mirroring
 * EventLog. NO tokens are ever stored: an account is a reference + health.
 */
final class AccountRepository
{
    public const PLATFORMS = ['instagram', 'tiktok', 'youtube'];

    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * All accounts for the UI, newest first, with the default reference asset's
     * title resolved for display.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, int $limit = 100): array
    {
        return array_map(self::shape(...), $this->db->all(
            // defense-in-depth: the reference-title JOIN is also workspace-scoped,
            // so a stray asset id can never surface another tenant's title.
            'SELECT a.*, asset.title AS reference_title
             FROM accounts a
             LEFT JOIN assets asset ON asset.id = a.default_reference_asset_id AND asset.workspace_id = a.workspace_id
             WHERE a.workspace_id = ?
             ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$ctx->id()],
        ));
    }

    /** @return array<string, mixed>|null null = not found OR other tenant's account */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM accounts WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Connected accounts a publish job fans out to (worker side: raw ws id).
     *
     * @return list<array<string, mixed>>
     */
    public function connectedFor(int $workspaceId): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM accounts WHERE workspace_id = ? AND status = 'connected' ORDER BY id ASC",
            [$workspaceId],
        ));
    }

    /**
     * Attach an account from a completed connect flow. Returns its id.
     *
     * IDEMPOTENT per (workspace, platform, handle): re-connecting an account
     * Kuyash already knows REVIVES that row instead of appending a second one.
     * The blind INSERT this replaced is what produced stale duplicate cards —
     * disconnect() only flips status, so every reconnect used to fork a new row.
     * Handles are matched the way sync() matches them (case- and @-insensitive),
     * and migration 0015 adds the matching UNIQUE index as a backstop.
     *
     * external_ref is the provider account reference — NOT a token.
     */
    public function connect(WorkspaceContext $ctx, string $platform, string $handle, string $externalRef, string $now): int
    {
        $existing = $this->db->one(
            "SELECT id FROM accounts
             WHERE workspace_id = ? AND platform = ?
               AND lower(ltrim(handle, '@')) = lower(ltrim(?, '@'))
             ORDER BY (status = 'connected') DESC, id DESC
             LIMIT 1",
            [$ctx->id(), $platform, $handle],
        );

        if ($existing !== null) {
            $id = (int) $existing['id'];
            // refresh the display handle too: the operator may have reconnected
            // as '@Ai.Neeidy' where we stored 'ai.neeidy'
            $this->db->run(
                "UPDATE accounts SET status = 'connected', health = 'ok', external_ref = ?, handle = ?,
                    connected_at = ?, updated_at = ?
                 WHERE id = ? AND workspace_id = ?",
                [$externalRef, $handle, $now, $now, $id, $ctx->id()],
            );

            return $id;
        }

        $this->db->run(
            "INSERT INTO accounts (workspace_id, platform, handle, external_ref, status, health,
                connected_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'connected', 'ok', ?, ?, ?)",
            [$ctx->id(), $platform, $handle, $externalRef, $now, $now, $now],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Store the resolved provider account id (the value publish() sends as
     * accountId). Used by connect + the "Sync from Zernio" reconcile to replace a
     * stale/fabricated ref with the real one. Tenant-scoped; true when changed.
     */
    public function setExternalRef(WorkspaceContext $ctx, int $id, string $externalRef, string $now): bool
    {
        return $this->db->run(
            'UPDATE accounts SET external_ref = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ? AND external_ref != ?',
            [$externalRef, $now, $id, $ctx->id(), $externalRef],
        )->rowCount() > 0;
    }

    /**
     * Store the REAL audience number reported by the provider (sync + the daily
     * snapshot chore both call this). Tenant-scoped; true when the value changed.
     * A provider that does not report a follower count leaves the column NULL —
     * the card then renders its honest "no audience data" state instead of a
     * fabricated figure.
     */
    public function setFollowers(WorkspaceContext $ctx, int $id, int $followers, string $now): bool
    {
        return $this->db->run(
            'UPDATE accounts SET followers_count = ?, followers_synced_at = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ?
               AND (followers_count IS NULL OR followers_count != ?)',
            [$followers, $now, $now, $id, $ctx->id(), $followers],
        )->rowCount() > 0;
    }

    /** Disconnect: tenant-scoped status flip. Returns true when a row changed. */
    public function disconnect(WorkspaceContext $ctx, int $id): bool
    {
        return $this->db->run(
            "UPDATE accounts SET status = 'disconnected', updated_at = ?
             WHERE id = ? AND workspace_id = ? AND status != 'disconnected'",
            [gmdate(self::ISO), $id, $ctx->id()],
        )->rowCount() > 0;
    }

    /**
     * Set the per-account default reference asset — must be a READY asset of the
     * same workspace (tenant-scoped existence check). Returns false if invalid.
     */
    public function setDefaultReference(WorkspaceContext $ctx, int $id, int $assetId): bool
    {
        $asset = $this->db->one(
            "SELECT id FROM assets WHERE id = ? AND workspace_id = ? AND status = 'ready'",
            [$assetId, $ctx->id()],
        );
        if ($asset === null) {
            return false;
        }

        return $this->db->run(
            'UPDATE accounts SET default_reference_asset_id = ?, updated_at = ?
             WHERE id = ? AND workspace_id = ?',
            [$assetId, gmdate(self::ISO), $id, $ctx->id()],
        )->rowCount() > 0;
    }

    public function clearDefaultReference(WorkspaceContext $ctx, int $id): bool
    {
        return $this->db->run(
            'UPDATE accounts SET default_reference_asset_id = NULL, updated_at = ?
             WHERE id = ? AND workspace_id = ?',
            [gmdate(self::ISO), $id, $ctx->id()],
        )->rowCount() > 0;
    }

    /**
     * Worker side: an auth failure on this account marks it for re-auth (the
     * health signal Kuyash keeps instead of a token). Scoped by the raw ws id.
     */
    public function markReauthNeeded(int $workspaceId, int $accountId, string $now): void
    {
        $this->db->run(
            "UPDATE accounts SET status = 'reauth_needed', health = 'degraded', updated_at = ?
             WHERE id = ? AND workspace_id = ?",
            [$now, $accountId, $workspaceId],
        );
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
        $row['default_reference_asset_id'] = $row['default_reference_asset_id'] === null
            ? null
            : (int) $row['default_reference_asset_id'];
        // NULL stays NULL: "the provider never reported an audience", which the
        // UI must show as an honest gap rather than 0 followers.
        $row['followers_count'] = ($row['followers_count'] ?? null) === null
            ? null
            : (int) $row['followers_count'];

        return $row;
    }
}
