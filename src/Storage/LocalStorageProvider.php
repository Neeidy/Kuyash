<?php

declare(strict_types=1);

namespace Kuyash\Storage;

/**
 * The default provider: objects live on local disk under the same roots Phase 3/7
 * already use (storage/{assets,cache,renders}/{ws}/{name}). put()/getToLocal()
 * are no-ops when the object is already in place (the common case: ffmpeg and the
 * uploader write straight to the mapped path), so default deployments behave
 * byte-for-byte as before this seam existed.
 *
 * temporaryUrl() returns null — local objects are streamed by the authed serve
 * route, never redirected.
 */
final class LocalStorageProvider implements StorageProvider
{
    /** @param array{asset: string, cache: string, render: string} $roots */
    public function __construct(private readonly array $roots)
    {
    }

    public function put(string $key, string $localPath, string $contentType): void
    {
        $dest = $this->path($key);
        if ($this->samePath($localPath, $dest)) {
            return; // already written in place — nothing to copy
        }
        $this->ensureDir(dirname($dest));
        if (!@copy($localPath, $dest)) {
            throw new StorageException('Local put failed to copy into place.');
        }
        @chmod($dest, 0640);
    }

    public function getToLocal(string $key, string $destPath): void
    {
        $src = $this->path($key);
        if (!is_file($src)) {
            throw new StorageException('Local object is missing.');
        }
        if ($this->samePath($src, $destPath)) {
            return;
        }
        $this->ensureDir(dirname($destPath));
        if (!@copy($src, $destPath)) {
            throw new StorageException('Local getToLocal failed to copy.');
        }
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);

        return is_file($path) && @unlink($path);
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function size(string $key): ?int
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);

        return $size === false ? null : $size;
    }

    public function temporaryUrl(string $key, int $ttl, array $responseHeaders = []): ?string
    {
        return null; // local objects stream through the authed serve route
    }

    /**
     * Absolute on-disk path for a key. Public so the backfill can read the local
     * source before copying it to a remote disk; the key is fully re-validated.
     */
    public function path(string $key): string
    {
        $parsed = StorageKey::parse($key);

        return $this->roots[$parsed->store] . '/' . $parsed->workspaceId . '/' . $parsed->name;
    }

    private function samePath(string $a, string $b): bool
    {
        $ra = realpath($a);

        return $ra !== false && $ra === realpath($b);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new StorageException("Storage directory cannot be created: {$dir}");
        }
    }
}
