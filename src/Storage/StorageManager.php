<?php

declare(strict_types=1);

namespace Kuyash\Storage;

/**
 * Resolves the StorageProvider for a named disk. Serving and the backfill pick a
 * provider PER OBJECT from the row's storage_disk marker, so a single box can
 * serve Local and R2 objects simultaneously during a migration.
 */
final class StorageManager
{
    /** @param array<string, StorageProvider> $providers name => provider */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultDisk,
    ) {
    }

    public function disk(string $name): StorageProvider
    {
        return $this->providers[$name]
            ?? throw new StorageException("Unknown storage disk: {$name}");
    }

    public function default(): StorageProvider
    {
        return $this->disk($this->defaultDisk);
    }

    public function defaultName(): string
    {
        return $this->defaultDisk;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }
}
