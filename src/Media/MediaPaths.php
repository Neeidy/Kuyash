<?php

declare(strict_types=1);

namespace Kuyash\Media;

use RuntimeException;

/**
 * The single authority for produced-media filesystem paths. Media references
 * that cross the job seam in result_json are TAGGED strings ("store:ws:name"),
 * never absolute paths — so nothing leaks a host path into the DB and every
 * resolution re-validates the name shape (defense-in-depth vs a tampered row).
 *
 * Stores: asset (Library uploads, read-only here), cache (content-addressed
 * intermediates), render (draft/final artifacts). Names are server-generated
 * {32-hex}.{ext}; user input never reaches a path (security rule).
 */
final class MediaPaths
{
    /** Same shape AssetStorage enforces: server-generated, traversal-proof. */
    private const NAME_RE = '/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/';
    private const STORES = ['asset', 'cache', 'render'];

    /** @param array{asset: string, cache: string, render: string, work: string} $roots */
    public function __construct(private readonly array $roots)
    {
    }

    public function newName(string $ext): string
    {
        if (preg_match('/^[a-z0-9]{2,5}$/', $ext) !== 1) {
            throw new RuntimeException("Invalid media extension: {$ext}");
        }

        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Build a tagged ref for storage in result_json / DB rows. */
    public function ref(string $store, int $workspaceId, string $name): string
    {
        $this->assertStore($store);
        $this->assertName($name);

        return $store . ':' . $workspaceId . ':' . $name;
    }

    /**
     * Resolve a tagged ref to an absolute path (validates store, ws, name).
     * READ path — never creates a directory.
     */
    public function resolve(string $ref): string
    {
        $parts = explode(':', $ref, 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed media ref.');
        }
        [$store, $ws, $name] = $parts;
        if (!ctype_digit($ws) || (int) $ws < 1) {
            throw new RuntimeException('Malformed media ref workspace.');
        }

        return $this->compute($store, (int) $ws, $name);
    }

    /**
     * Absolute path for (store, ws, name) for a WRITE; creates the ws dir on
     * demand. Reads should use resolve() (no directory side effect).
     */
    public function pathFor(string $store, int $workspaceId, string $name): string
    {
        $path = $this->compute($store, $workspaceId, $name);
        $this->ensureDir(dirname($path));

        return $path;
    }

    /** Validate (store, ws, name) and return the absolute path — no side effects. */
    private function compute(string $store, int $workspaceId, string $name): string
    {
        $this->assertStore($store);
        // NAME_RE is the traversal guard: it forbids '/' and '..' so the concat
        // below can never escape the store root (store is allowlisted, ws is int)
        $this->assertName($name);

        return $this->roots[$store] . '/' . $workspaceId . '/' . $name;
    }

    /** A fresh, unique work directory for one ffmpeg run (scratch; cleaned after). */
    public function newWorkDir(): string
    {
        $dir = $this->roots['work'] . '/' . bin2hex(random_bytes(8));
        $this->ensureDir($dir);

        return $dir;
    }

    /** Remove a work directory and its files (only under the work root — safe). */
    public function cleanupWorkDir(string $dir): void
    {
        $workRoot = rtrim($this->roots['work'], '/');
        if (!str_starts_with($dir, $workRoot . '/')) {
            return; // never delete outside the scratch root
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function assertStore(string $store): void
    {
        if (!in_array($store, self::STORES, true)) {
            throw new RuntimeException("Unknown media store: {$store}");
        }
    }

    private function assertName(string $name): void
    {
        if (preg_match(self::NAME_RE, $name) !== 1) {
            throw new RuntimeException('Invalid media name.');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Media directory cannot be created: {$dir}");
        }
    }
}
