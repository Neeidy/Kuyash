<?php

declare(strict_types=1);

namespace Kuyash\Library;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Workspace-scoped asset CRUD. EVERY method takes the WorkspaceContext and
 * filters by workspace_id — no unscoped query path exists (tenant isolation
 * at query level; the context itself fails closed when unset).
 */
final class AssetRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{
     *   kind: string, type: string, title: string, original_filename: string,
     *   stored_name: string, mime: string, size_bytes: int, sha256: string,
     *   duration_s: ?float, width: ?int, height: ?int, aspect: ?string,
     *   tags: list<string>
     * } $data
     * @param string $storageDisk the durable disk the object landed on
     *        ('local'|'r2') — recorded so serving resolves the provider per object
     */
    public function create(WorkspaceContext $ctx, array $data, string $storageDisk = 'local'): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->db->run(
            'INSERT INTO assets (workspace_id, kind, type, title, original_filename,
                stored_name, mime, size_bytes, sha256, duration_s, width, height,
                aspect, tags, storage_disk, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'ready\', ?, ?)',
            [
                $ctx->id(),
                $data['kind'],
                $data['type'],
                $data['title'],
                $data['original_filename'],
                $data['stored_name'],
                $data['mime'],
                $data['size_bytes'],
                $data['sha256'],
                $data['duration_s'],
                $data['width'],
                $data['height'],
                $data['aspect'],
                json_encode($data['tags'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $storageDisk,
                $now,
                $now,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Newest first, optionally filtered by free-text (title + tags, with
     * LIKE wildcards escaped) and/or asset type.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx, ?string $q = null, ?string $type = null): array
    {
        $sql = 'SELECT * FROM assets WHERE workspace_id = ?';
        $params = [$ctx->id()];

        if ($type !== null && $type !== '') {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        if ($q !== null && trim($q) !== '') {
            $escaped = addcslashes(trim($q), '\\%_');
            $sql .= " AND (title LIKE ? ESCAPE '\\' OR tags LIKE ? ESCAPE '\\')";
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        return array_map(self::shape(...), $this->db->all($sql, $params));
    }

    /**
     * Ready video assets — what a distribution run may select (Phase 4
     * run trigger). Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function readyVideosFor(WorkspaceContext $ctx): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM assets
             WHERE workspace_id = ? AND kind = 'video' AND status = 'ready'
             ORDER BY created_at DESC, id DESC",
            [$ctx->id()],
        ));
    }

    /**
     * Ready assets (video OR photo) usable as a reference subject for a full
     * run / workspace avatar (reference-asset model). Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function readyReferencesFor(WorkspaceContext $ctx): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM assets
             WHERE workspace_id = ? AND status = 'ready'
             ORDER BY created_at DESC, id DESC",
            [$ctx->id()],
        ));
    }

    /**
     * Ready PHOTO assets — what Quick Create (Phase 12) animates into an AI clip.
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function readyPhotosFor(WorkspaceContext $ctx): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM assets
             WHERE workspace_id = ? AND kind = 'photo' AND status = 'ready'
             ORDER BY created_at DESC, id DESC",
            [$ctx->id()],
        ));
    }

    /** @return array<string, mixed>|null null = not found OR other tenant's asset */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM assets WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /** Returns true when a row was actually deleted (scoped — cross-tenant is a no-op). */
    public function delete(WorkspaceContext $ctx, int $id): bool
    {
        $stmt = $this->db->run(
            'DELETE FROM assets WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $tags = json_decode((string) $row['tags'], true);
        $row['tags'] = is_array($tags) ? $tags : [];
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];
        $row['size_bytes'] = (int) $row['size_bytes'];
        $row['duration_s'] = $row['duration_s'] === null ? null : (float) $row['duration_s'];
        $row['width'] = $row['width'] === null ? null : (int) $row['width'];
        $row['height'] = $row['height'] === null ? null : (int) $row['height'];
        // derived, never stored (compliance rule: single source of truth)
        $row['ai_label_required'] = $row['type'] === 'ai';

        return $row;
    }
}
