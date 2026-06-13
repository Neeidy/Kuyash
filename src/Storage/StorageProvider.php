<?php

declare(strict_types=1);

namespace Kuyash\Storage;

/**
 * The durable-storage + serving seam the architecture rule has always required.
 * Keyed by a logical object key ("{store}/{ws}/{name}", see StorageKey) so core
 * code never references a vendor or a filesystem path. LocalStorageProvider is
 * the default; R2StorageProvider is the Cloudflare R2 adapter — swapping is one
 * binding line + one config value (STORAGE_DRIVER).
 *
 * ffmpeg is NOT a storage backend: it always reads/writes local scratch. This
 * seam is the persistence/serving boundary either side of it — put() after a
 * render is produced locally, getToLocal() to stage an object back before ffmpeg.
 *
 * Implementations MUST stream large media (put streams the file body,
 * getToLocal streams to disk with a hard byte cap) — never buffer a whole video.
 */
interface StorageProvider
{
    /** Persist a finished LOCAL file under $key. Streams the body (no buffering). */
    public function put(string $key, string $localPath, string $contentType): void;

    /**
     * Stream the object at $key down to $destPath, hard-capped at the provider's
     * configured max so a hostile/oversized object can't balloon worker memory.
     */
    public function getToLocal(string $key, string $destPath): void;

    /** Best-effort delete; false when the object was already absent. */
    public function delete(string $key): bool;

    public function exists(string $key): bool;

    /** Object size in bytes, or null when absent/unknown. */
    public function size(string $key): ?int;

    /**
     * A short-TTL presigned GET URL for $key, or NULL when the provider serves
     * locally (Local) — a null return tells the caller to stream instead of
     * redirect. $responseHeaders pins response-* overrides (content-type,
     * content-disposition) into the presign.
     *
     * @param array<string, string> $responseHeaders
     */
    public function temporaryUrl(string $key, int $ttl, array $responseHeaders = []): ?string;
}
