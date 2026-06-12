<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * The outcome of an AssetCache lookup: where the file lives (a tagged media
 * ref), its stored metadata, and whether this was a cache HIT (reuse) or a
 * freshly produced MISS. `cached` drives honest cost: a hit spends nothing.
 */
final class CacheEntry
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $ref,
        public readonly string $name,
        public readonly array $meta,
        public readonly bool $cached,
    ) {
    }
}
