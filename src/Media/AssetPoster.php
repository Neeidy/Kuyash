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

    /**
     * Absolute local path the poster would live at, or NULL when it cannot be
     * worked out.
     *
     * NULLABLE ON PURPOSE. MediaPaths::pathFor() creates the store directory and
     * THROWS when it cannot — an unwritable storage/cache, a full disk, a
     * read-only mount after a deploy. Every caller of this is decorative (a grid
     * thumbnail, an <img> route), so a poster-layer fault must degrade to "no
     * poster", not take a page — or an upload — down with it.
     */
    public function pathFor(array $asset): ?string
    {
        try {
            return $this->paths->pathFor('cache', (int) $asset['workspace_id'], self::nameFor($asset));
        } catch (Throwable $e) {
            error_log('Kuyash: poster path unavailable — ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Asset ids in this workspace that are marked demo content AND have a poster
     * on disk — the pool a sample account card draws its preview frame from.
     *
     * DEMO ONLY, BY QUERY. The title filter is what keeps this away from the
     * operator's own footage: a sample card may show a sample frame, and a real
     * channel may never show a frame it did not publish.
     *
     * @return list<int>
     */
    public function samplePool(\Kuyash\Core\Database $db, int $workspaceId, string $marker): array
    {
        $ids = [];
        foreach ($db->all(
            "SELECT id, workspace_id, sha256, kind FROM assets
             WHERE workspace_id = ? AND kind = 'video' AND status = 'ready' AND title LIKE ?
             ORDER BY id ASC",
            [$workspaceId, $marker . '%'],
        ) as $row) {
            if ($this->exists($row)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Does the clip named by an awaiting-approval job have a poster?
     *
     * Takes the sha256 the job row already carries (JobRepository correlates it),
     * so a queue of eight costs eight stats and no extra queries — and the
     * template never emits an <img> that is guaranteed to 404.
     *
     * @param array<string, mixed> $job
     */
    public function existsForJob(array $job): bool
    {
        $sha = (string) ($job['library_sha256'] ?? '');

        return $sha !== '' && $this->exists([
            'workspace_id' => (int) ($job['workspace_id'] ?? 0),
            'sha256' => $sha,
        ]);
    }

    /** Is a usable poster already on disk? Never throws (see pathFor). */
    public function exists(array $asset): bool
    {
        $path = $this->pathFor($asset);

        return $path !== null && is_file($path) && filesize($path) > 0;
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
        if (!$this->ffmpeg->available()) {
            return null;
        }

        $tmp = null;
        $work = null;
        try {
            // INSIDE the guard, all of it. These reach MediaPaths, which creates
            // the store directory and throws when it cannot — so the "never
            // throws" contract above was only true for the middle of this method.
            $target = $this->pathFor($asset);
            if ($target === null) {
                return null;
            }
            if (is_file($target) && filesize($target) > 0) {
                return $target;
            }

            [$source, $work] = $this->localSource($asset);
            if ($source === null) {
                return null;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                return null;
            }

            // Written under a temp name, then moved: a timeout or a crash cannot
            // leave a truncated file that exists() would call a poster. The name
            // shape also cannot match MediaPaths::NAME_RE, so a stray temp is
            // unservable even if an abnormal exit skips the cleanup below.
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
            // match AssetStorage's 0640 rather than inheriting ffmpeg's umask
            @chmod($target, 0640);

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
            // a staged R2 copy is scratch, not a restored asset — see localSource
            if ($work !== null) {
                $this->paths->cleanupWorkDir($work);
            }
        }
    }

    /**
     * A local path ffmpeg can read, plus the scratch dir to clean up afterwards.
     *
     * An asset already on local disk is used in place and needs no scratch. One
     * living on R2 is staged into a WORK DIRECTORY — deliberately not into the
     * canonical asset path. Writing it there would silently re-materialise the
     * object the storage migration just moved off local disk, and the backfill
     * script sweeps every video in every workspace: on an R2-primary deployment
     * that is the whole library downloaded, uncapped, as a side effect of making
     * thumbnails. Disk exhaustion there takes SQLite's WAL with it.
     *
     * @param array<string, mixed> $asset
     *
     * @return array{0: string|null, 1: string|null} [readable path, scratch dir to remove]
     */
    private function localSource(array $asset): array
    {
        $ws = (int) $asset['workspace_id'];
        $name = (string) $asset['stored_name'];
        $local = $this->paths->pathFor('asset', $ws, $name);
        if (is_file($local)) {
            return [$local, null];
        }
        if ($this->storage === null) {
            return [null, null];
        }

        // The asset row names its own disk; StorageManager is only the registry,
        // so the provider is resolved before exists()/getToLocal() are called.
        $key = StorageKey::make('asset', $ws, $name);
        $work = null;
        try {
            $disk = $this->storage->disk((string) ($asset['storage_disk'] ?? 'local'));
            if (!$disk->exists($key)) {
                return [null, null];
            }
            $work = $this->paths->newWorkDir();
            $staged = $work . '/' . $name;
            $disk->getToLocal($key, $staged);

            return is_file($staged) ? [$staged, $work] : [null, $work];
        } catch (Throwable $e) {
            error_log('Kuyash: poster source staging failed — ' . $e->getMessage());

            return [null, $work];
        }
    }
}