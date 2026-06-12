<?php

declare(strict_types=1);

namespace Kuyash\Library;

use Kuyash\Workspace\WorkspaceContext;
use RuntimeException;
use Throwable;

/**
 * The full upload pipeline behind the controller's SAPI gate:
 * validate → title default → probe → hash → store → create.
 * Lives outside the controller so the ordering is CLI-testable and so
 * Phase 6/7 stock/AI producers can reuse the same ingest path.
 */
final class AssetIngest
{
    public function __construct(
        private readonly AssetValidator $validator,
        private readonly MediaProbe $probe,
        private readonly AssetStorage $storage,
        private readonly AssetRepository $assets,
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
        $this->storage->store($ctx->id(), $file->tmpPath, $storedName);

        try {
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
            ]);
        } catch (Throwable $e) {
            // no orphan on DB failure: the row is the source of truth, so a
            // failed insert must take the just-stored file with it
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
