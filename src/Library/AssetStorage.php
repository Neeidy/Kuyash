<?php

declare(strict_types=1);

namespace Kuyash\Library;

use Closure;
use RuntimeException;

/**
 * Private on-disk asset storage: storage/assets/{workspace_id}/{32-hex}.{ext}.
 * Names are server-generated, the extension comes from the validated
 * allowlist — user input never reaches a filesystem path (security rule).
 * The mover is injectable: move_uploaded_file() in production (SAPI-only),
 * rename() in tests.
 */
final class AssetStorage
{
    private readonly Closure $mover;

    public function __construct(
        private readonly string $storageRoot,
        ?Closure $mover = null,
    ) {
        $this->mover = $mover ?? static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
    }

    /** Generate the stored name for a validated extension: {32-hex}.{ext}. */
    public function newStoredName(string $validatedExt): string
    {
        return bin2hex(random_bytes(16)) . '.' . $validatedExt;
    }

    /** Move an upload into the workspace directory; returns the absolute path. */
    public function store(int $workspaceId, string $tmpPath, string $storedName): string
    {
        $dir = $this->workspaceDir($workspaceId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Asset directory cannot be created: {$dir}");
        }

        $target = $dir . '/' . $storedName;
        if (($this->mover)($tmpPath, $target) !== true) {
            throw new RuntimeException('Asset move failed');
        }
        @chmod($target, 0640);

        return $target;
    }

    /**
     * Absolute path for a stored asset. Inputs are server-generated, but the
     * shape is enforced anyway (defense-in-depth, audit fix): a future caller
     * or tampered DB row must never be able to traverse out of the root.
     */
    public function path(int $workspaceId, string $storedName): string
    {
        if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $storedName) !== 1) {
            throw new RuntimeException('Invalid stored asset name.');
        }

        return $this->workspaceDir($workspaceId) . '/' . $storedName;
    }

    /** Best-effort delete; returns false when the file was already gone. */
    public function delete(int $workspaceId, string $storedName): bool
    {
        $path = $this->path($workspaceId, $storedName);

        return is_file($path) && @unlink($path);
    }

    private function workspaceDir(int $workspaceId): string
    {
        return $this->storageRoot . '/' . $workspaceId;
    }
}
