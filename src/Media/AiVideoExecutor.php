<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Content\Sanitizer;
use Kuyash\Core\Database;
use Kuyash\Storage\StorageKey;
use Kuyash\Storage\StorageManager;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `ai_video` (Quick Create VISUALS) job type — image-to-
 * video. Resolves the run's reference photo, reads the prompt from the run's
 * VISUALS settings snapshot, and generates a 9:16 clip through the injected
 * VideoGenProvider.
 *
 * The generation is content-addressed via AssetCache (same photo + prompt +
 * provider + duration → reuse, no respend): a HIT returns null cost, a MISS
 * carries the provider's real cents (truthful, like TtsExecutor/AssetFetch).
 * The finished clip is then normalized to a DRAFT render so render_review can
 * preview it and compliance can format-check a real artifact; the cache ref is
 * handed forward as visual_ref so final_render re-normalizes it to full-res
 * (distribution parity — no narrated assembly, no ASSEMBLE step).
 *
 * AI video ALWAYS sets ai_label_required = true: realistic AI media must carry
 * the platform AI label, applied automatically downstream (compliance rule).
 */
final class AiVideoExecutor implements JobExecutor
{
    /** @param array{width: int, height: int, preset: string} $draftGeometry */
    public function __construct(
        private readonly Database $db,
        private readonly VideoGenProvider $provider,
        private readonly AssetCache $cache,
        private readonly AssemblyEngine $assembly,
        private readonly MediaPaths $paths,
        private readonly StorageManager $storage,
        private readonly array $draftGeometry,
        private readonly float $defaultSeconds = 16.0,
        private readonly float $maxSeconds = 30.0,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $ws = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];

        $run = $this->db->one('SELECT reference_asset_id, nodes_json FROM runs WHERE id = ? AND workspace_id = ?', [$runId, $ws]);
        if ($run === null) {
            return JobResult::failed('ai_video: run not found', $this->provider->name());
        }

        $referenceId = $run['reference_asset_id'] === null ? null : (int) $run['reference_asset_id'];
        if ($referenceId === null) {
            return JobResult::failed('ai_video: no reference photo for this run', $this->provider->name());
        }
        $asset = $this->db->one(
            "SELECT id, kind, stored_name, storage_disk FROM assets
             WHERE id = ? AND workspace_id = ? AND status = 'ready'",
            [$referenceId, $ws],
        );
        if ($asset === null || (string) $asset['kind'] !== 'photo') {
            return JobResult::failed('ai_video: reference is not a ready photo', $this->provider->name());
        }

        $prompt = $this->prompt($run['nodes_json']);
        if ($prompt === '') {
            return JobResult::failed('ai_video: empty prompt', $this->provider->name());
        }

        $duration = min($this->maxSeconds, max(1.0, $this->defaultSeconds));
        $source = $this->localSourcePath($ws, $asset);

        $key = hash('sha256', 'ai_video|' . $this->provider->name() . '|' . (int) $asset['id'] . '|' . $prompt . '|' . (int) round($duration));

        try {
            $entry = $this->cache->remember(
                $ws,
                'ai_video',
                $key,
                $this->provider->clipExtension(),
                function (string $out) use ($source, $prompt, $duration): array {
                    $result = $this->provider->generateFromImage($source, $prompt, $duration, $out);

                    return [
                        'width' => $result->width,
                        'height' => $result->height,
                        'duration_s' => $result->durationSeconds,
                        'cost_cents' => $result->costCents,
                        'model' => $result->model,
                        'meta' => $result->meta,
                    ];
                },
            );
        } catch (VideoGenProviderException $e) {
            return JobResult::failed($e->getMessage(), $this->provider->name());
        }

        // honest cost: a cache hit spends nothing; a miss carries the real cents
        $cost = $entry->cached ? null : ($entry->meta['cost_cents'] ?? null);

        // normalize to a DRAFT render so render_review previews the AI clip and
        // compliance can format-check it (9:16 + duration). final_render later
        // re-normalizes the same cache clip (visual_ref) to full-res.
        try {
            $draft = $this->assembly->assembleDistribution($ws, $runId, (int) $job['id'], 'draft', $this->draftGeometry, $entry->ref);
        } catch (FfmpegException $e) {
            return JobResult::failed('ai_video draft render failed: ' . $e->getMessage(), $this->provider->name());
        }

        return JobResult::ready([
            'source' => 'ai',
            'provider' => $this->provider->name(),
            'visual_kind' => 'video',
            'visual_ref' => $entry->ref,
            'draft_render_id' => $draft['render_id'],
            'poster_ref' => $draft['poster_ref'],
            'duration_s' => $entry->meta['duration_s'] ?? $draft['duration_s'] ?? null,
            'ai_label_required' => true, // realistic AI media — platform label is mandatory
            'title' => $prompt,
            'model' => $entry->meta['model'] ?? null,
            'cached' => $entry->cached,
        ], $this->provider->name(), is_int($cost) ? $cost : null);
    }

    /** Read + sanitize the prompt from the run's VISUALS settings snapshot. */
    private function prompt(mixed $nodesJson): string
    {
        $nodes = json_decode((string) $nodesJson, true);
        if (!is_array($nodes)) {
            return '';
        }
        foreach ($nodes as $entry) {
            if (is_array($entry) && ($entry['node'] ?? null) === 'VISUALS') {
                $prompt = (string) ($entry['settings']['prompt'] ?? '');

                return Sanitizer::clean($prompt, 300);
            }
        }

        return '';
    }

    /**
     * A LOCAL path ffmpeg can read for the reference photo. Local-disk assets
     * resolve in place; a remote-disk asset is staged into local cache scratch
     * via getToLocal() first (mirrors AssetFetchExecutor::localSourcePath).
     *
     * @param array<string, mixed> $asset
     */
    private function localSourcePath(int $ws, array $asset): string
    {
        $name = (string) $asset['stored_name'];
        $disk = (string) ($asset['storage_disk'] ?? 'local');
        if ($disk === 'local') {
            return $this->paths->resolve($this->paths->ref('asset', $ws, $name));
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION) ?: 'png';
        $dest = $this->paths->pathFor('cache', $ws, $this->paths->newName($ext));
        $this->storage->disk($disk)->getToLocal(StorageKey::make('asset', $ws, $name), $dest);

        return $dest;
    }
}
