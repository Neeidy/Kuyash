<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Throwable;

/**
 * A still frame for a library video, so every preview in the product shows what
 * the video actually is instead of a grey box with a play glyph.
 *
 * CONTENT-ADDRESSED, NO SCHEMA CHANGE. The poster's name is derived from the
 * asset's own sha256, so the file IS the index: `exists()` is a stat, the same
 * bytes never produce two posters, and a re-uploaded duplicate reuses the one
 * already made. `assets` gains no column and no migration is needed.
 *
 * NEVER IN A REQUEST PATH. Extraction is called at ingest, from the backfill
 * script, and from the demo seed — never while serving a page. The dev server is
 * single-threaded, so a library of ten videos would otherwise mean ten
 * sequential ffmpeg runs blocking the page that asked for them. The route serves
 * what exists and 404s otherwise; the template falls back to a gradient.
 *
 * SAFE BY CONSTRUCTION: {@see Ffmpeg} takes an ARG ARRAY through proc_open (no
 * shell string), carries a wall-clock timeout, and every path here is
 * server-generated — never user input. Output is written to a temp name and
 * moved into place, so a killed run cannot leave a half-written poster that
 * `exists()` would then report as good.
 */
final class AssetPoster
{
    /** Poster geometry — small enough to be a thumbnail, sharp enough at 2x. */
    private const WIDTH = 540;
    private const HEIGHT = 960;

    /** Where in the clip to sample. A hair in, so a fade-from-black is skipped. */
    private const SEEK_SECONDS = 1.0;

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaPaths $paths,
        private readonly ?StorageManager $storage = null,
    ) {
    }

    /**
     * The poster's stored name for an asset — pure, no I/O.
     *
     * Keyed on the asset's CONTENT (sha256), not its id: two rows holding the
     * same bytes share one poster, and re-running the backfill is free.
     *
     * @param array<string, mixed> $asset
     */
    public static function nameFor(array $asset): string
    {
        return substr(hash('sha256', 'poster|v1|' . (string) ($asset['sha256'] ?? '')), 0, 32) . '.jpg';
    }

    /** Absolute local path the poster would live at. No I/O beyond path building. */
    public function pathFor(array $asset): string
    {
        return $this->paths->pathFor('cache', (int) $asset['workspace_id'], self::nameFor($asset));
    }

    /** Is a usable poster already on disk? */
    public function exists(array $asset): bool
    {
        $path = $this->pathFor($asset);

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Produce the poster if it is missing. Returns its path, or null when one
     * could not be made.
     *
     * NEVER THROWS. A poster is decoration: an upload, a backfill sweep or a
     * seed must not fail because a frame could not be extracted. The caller gets
     * null and the UI falls back to its gradient.
     *
     * @param array<string, mixed> $asset
     */
    public function ensure(array $asset): ?string
    {
        if ((string) ($asset['kind'] ?? '') !== 'video' || (string) ($asset['sha256'] ?? '') === '') {
            return null;
        }
        if ($this->exists($asset)) {
            return $this->pathFor($asset);
        }
        if (!$this->ffmpeg->available()) {
            return null;
        }

        $target = $this->pathFor($asset);
        $tmp = null;
        try {
            $source = $this->localSource($asset);
            if ($source === null) {
                return null;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }

            // Written under a temp name, then moved: a timeout or a crash cannot
            // leave a truncated file that exists() would call a poster.
            $tmp = $dir . '/.poster-' . bin2hex(random_bytes(8)) . '.jpg';
            $this->ffmpeg->run([
                // -ss BEFORE -i seeks without decoding the whole clip
                '-ss', (string) self::SEEK_SECONDS,
                '-i', $source,
                '-frames:v', '1',
                // cover the frame, then crop — never letterboxed, never stretched
                '-vf', sprintf(
                    'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
                    self::WIDTH,
                    self::HEIGHT,
                    self::WIDTH,
                    self::HEIGHT,
                ),
                '-q:v', '4',
                $tmp,
            ]);

            if (!is_file($tmp) || filesize($tmp) === 0) {
                return null;
            }
            if (!@rename($tmp, $target)) {
                return null;
            }
            $tmp = null;

            return $target;
        } catch (Throwable $e) {
            // A clip shorter than the seek point, a codec ffmpeg will not open, a
            // missing object — all of them mean "no poster", not "request failed".
            error_log('Kuyash: poster extraction failed for asset #'
                . (string) ($asset['id'] ?? '?') . ' — ' . $e->getMessage());

            return null;
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * A local path ffmpeg can read. An asset living on R2 is staged down first —
     * the same thing AssemblyEngine does for its inputs, and the reason a
     * migrated library still gets posters.
     *
     * @param array<string, mixed> $asset
     */
    private function localSource(array $asset): ?string
    {
        $ws = (int) $asset['workspace_id'];
        $name = (string) $asset['stored_name'];
        $local = $this->paths->pathFor('asset', $ws, $name);
        if (is_file($local)) {
            return $local;
        }
        if ($this->storage === null) {
            return null;
        }

        // The asset row names its own disk; StorageManager is only the registry,
        // so the provider is resolved before exists()/getToLocal() are called.
        $key = StorageKey::make('asset', $ws, $name);
        try {
            $disk = $this->storage->disk((string) ($asset['storage_disk'] ?? 'local'));
            if (!$disk->exists($key)) {
                return null;
            }
            $dir = dirname($local);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            $disk->getToLocal($key, $local);
        } catch (Throwable $e) {
            error_log('Kuyash: poster source staging failed — ' . $e->getMessage());

            return null;
        }

        return is_file($local) ? $local : null;
    }
}
