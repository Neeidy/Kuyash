<?php

declare(strict_types=1);

namespace Kuyash\Storage;

/**
 * The provider-agnostic object key: "{store}/{ws}/{name}". It doubles as the R2
 * object path and the Local relative path, so the SAME traversal guard the rest
 * of the codebase uses (AssetStorage / MediaPaths NAME_RE) is enforced here — a
 * key can never escape its store root, and user input never reaches one (names
 * are always server-generated {32-hex}.{ext}).
 *
 * Stores mirror MediaPaths: asset (Library uploads), cache (intermediates),
 * render (draft/final artifacts).
 */
final class StorageKey
{
    /** Same shape AssetStorage / MediaPaths enforce: server-generated, traversal-proof. */
    private const NAME_RE = '/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/';
    public const STORES = ['asset', 'cache', 'render'];

    public function __construct(
        public readonly string $store,
        public readonly int $workspaceId,
        public readonly string $name,
    ) {
    }

    public static function make(string $store, int $workspaceId, string $name): string
    {
        self::assertStore($store);
        self::assertWorkspace($workspaceId);
        self::assertName($name);

        return $store . '/' . $workspaceId . '/' . $name;
    }

    /** Parse + fully validate a key string (defense-in-depth vs a tampered row). */
    public static function parse(string $key): self
    {
        $parts = explode('/', $key);
        if (count($parts) !== 3) {
            throw new StorageException('Malformed storage key.');
        }
        [$store, $ws, $name] = $parts;
        if (!ctype_digit($ws) || (int) $ws < 1) {
            throw new StorageException('Malformed storage key workspace.');
        }
        self::assertStore($store);
        self::assertName($name);

        return new self($store, (int) $ws, $name);
    }

    private static function assertStore(string $store): void
    {
        if (!in_array($store, self::STORES, true)) {
            throw new StorageException("Unknown storage store: {$store}");
        }
    }

    private static function assertWorkspace(int $workspaceId): void
    {
        if ($workspaceId < 1) {
            throw new StorageException('Invalid storage key workspace.');
        }
    }

    private static function assertName(string $name): void
    {
        if (preg_match(self::NAME_RE, $name) !== 1) {
            throw new StorageException('Invalid storage object name.');
        }
    }
}
