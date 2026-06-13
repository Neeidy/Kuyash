<?php

declare(strict_types=1);

namespace Kuyash\Library;

use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Kuyash\Workspace\WorkspaceContext;
use RuntimeException;
use Throwable;

/**
 * The full upload pipeline behind the controller's SAPI gate:
 * validate → title default → probe → hash → store → put(durable) → create.
 * Lives outside the controller so the ordering is CLI-testable and so
 * Phase 6/7 stock/AI producers can reuse the same ingest path.
 *
 * The file always lands on local disk first (AssetStorage); put() then persists
 * it to the configured durable disk and the row records which disk it is on. On
 * the default 'local' driver put() is a no-op (the file is already in place).
 */
final class AssetIngest
{
    public function __construct(
        private readonly AssetValidator $validator,
        private readonly MediaProbe $probe,
        private readonly AssetStorage $storage,
        private readonly AssetRepository $assets,
        private readonly StorageManager $durable,
        private readonly int $maxTags,
        private readonly int $maxTagLength,
    ) {
    }

    /**
     * @return int new asset id
     *
     * @throws InvalidUploadException on validation failure (nothing written)
     */
    public function ingest(WorkspaceContext $ctx, UploadedFile $file, string $type, string $title, string $rawTags): int
    {
        $meta = $this->validator->validate($file);

        $title = trim($title);
        if ($title === '') {
            $title = pathinfo($file->originalName, PATHINFO_FILENAME) ?: 'Untitled';
        }
        $title = mb_substr($title, 0, 120);

        $probed = $this->probe->probe($file->tmpPath, $meta['kind']);

        // hash BEFORE the move: an aborted hash leaves nothing on disk, and an
        // empty sha256 would silently break Phase 7's content-addressed cache
        $sha256 = hash_file('sha256', $file->tmpPath);
        if ($sha256 === false) {
            throw new RuntimeException('Could not hash the uploaded file.');
        }

        $storedName = $this->storage->newStoredName($meta['ext']);
        $absPath = $this->storage->store($ctx->id(), $file->tmpPath, $storedName);
        $disk = $this->durable->defaultName();

        try {
            // persist to the durable disk (no-op on 'local'); a failed remote put
            // must NOT leave a half-stored asset, so it joins the cleanup path
            $this->durable->default()->put(
                StorageKey::make('asset', $ctx->id(), $storedName),
                $absPath,
                $meta['mime'],
            );

            return $this->assets->create($ctx, [
                'kind' => $meta['kind'],
                'type' => $type,
                'title' => $title,
                'original_filename' => mb_substr($file->originalName, 0, 255),
                'stored_name' => $storedName,
                'mime' => $meta['mime'],
                'size_bytes' => $file->sizeBytes,
                'sha256' => $sha256,
                'duration_s' => $probed['duration_s'],
                'width' => $probed['width'],
                'height' => $probed['height'],
                'aspect' => $probed['aspect'],
                'tags' => $this->parseTags($rawTags),
            ], $disk);
        } catch (Throwable $e) {
            // no orphan on failure: the row is the source of truth, so a failed
            // put/insert must take the just-stored local file with it. A failed
            // remote put leaves nothing to clean up there — S3/R2 PUT is atomic
            // (the object only becomes visible on a 2xx).
            $this->storage->delete($ctx->id(), $storedName);
            throw $e;
        }
    }

    /** @return list<string> normalized, deduplicated, capped tag list */
    public function parseTags(string $raw): array
    {
        $tags = [];
        foreach (explode(',', $raw) as $tag) {
            // truncate BEFORE the duplicate check so two long tags cannot
            // collapse into duplicates after the cut
            $tag = mb_substr(mb_strtolower(trim($tag)), 0, $this->maxTagLength);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= $this->maxTags) {
                break;
            }
        }

        return $tags;
    }
}
