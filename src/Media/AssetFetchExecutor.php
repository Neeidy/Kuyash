<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Core\Database;
use Kuyash\Trend\QuotaCounter;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `asset_fetch` (VISUALS) job type. Resolves the visual a
 * run will be built around and emits a tagged media ref for the assembly step.
 *
 * Resolution order (reference-asset model, ADR-012):
 *  - distribution run → the run's library video (entity).
 *  - full run → per-run reference asset (runs.reference_asset_id) → workspace
 *    avatar (when the trend format is "face") → stock clip.
 *
 * Photo references/avatars become a still-clip (ffmpeg loop); video
 * references/library videos are referenced as-is (assembly loops them). Stock
 * clips are content-addressed cached; a real stock fetch is charged to quota.
 * NO AI generation here (that is Quick Create, Phase 12).
 */
final class AssetFetchExecutor implements JobExecutor
{
    /** @param array{width: int, height: int, preset: string} $geometry */
    public function __construct(
        private readonly Database $db,
        private readonly StockProvider $stock,
        private readonly Ffmpeg $ffmpeg,
        private readonly MediaPaths $paths,
        private readonly AssetCache $cache,
        private readonly QuotaCounter $quota,
        private readonly array $geometry,
        private readonly int $stockQuotaUnits = 1,
        private readonly int $fps = 24,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $ws = (int) $job['workspace_id'];
        $duration = $this->targetDuration($prior);

        // distribution run: the run's own library video is the visual
        if (($job['entity_type'] ?? null) === 'library' && $job['entity_id'] !== null) {
            $asset = $this->readyAsset($ws, (int) $job['entity_id']);
            if ($asset === null) {
                return JobResult::failed('library asset is no longer available', 'library');
            }

            return $this->fromAsset($ws, $asset, $duration, 'library');
        }

        // full run: per-run reference → workspace avatar (face) → stock
        $referenceId = $this->runReferenceId($ws, (int) $job['run_id']);
        if ($referenceId !== null) {
            $asset = $this->readyAsset($ws, $referenceId);
            if ($asset !== null) {
                return $this->fromAsset($ws, $asset, $duration, 'reference');
            }
            // a reference that was deleted/changed degrades to avatar/stock, never fails the run
        }

        if (($prior['trend_fetch']['format'] ?? 'faceless') === 'face') {
            $avatarId = $this->workspaceAvatarId($ws);
            if ($avatarId !== null) {
                $asset = $this->readyAsset($ws, $avatarId);
                if ($asset !== null) {
                    return $this->fromAsset($ws, $asset, $duration, 'avatar');
                }
            }
        }

        return $this->fromStock($ws, $prior, $duration);
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function fromAsset(int $ws, array $asset, float $duration, string $source): JobResult
    {
        $aiLabel = (string) $asset['type'] === 'ai';

        if ((string) $asset['kind'] === 'video') {
            // referenced as-is; assembly loops/truncates it to the narration length
            return JobResult::ready([
                'source' => $source,
                'visual_kind' => 'video',
                'visual_ref' => $this->paths->ref('asset', $ws, (string) $asset['stored_name']),
                'asset_id' => (int) $asset['id'],
                'title' => (string) $asset['title'],
                'ai_label_required' => $aiLabel,
                'duration_s' => $asset['duration_s'] === null ? null : (float) $asset['duration_s'],
            ], $source);
        }

        // photo → still-clip (cached by asset + duration bucket)
        $entry = $this->stillClip($ws, $asset, $duration);

        return JobResult::ready([
            'source' => $source,
            'visual_kind' => 'clip',
            'visual_ref' => $entry->ref,
            'asset_id' => (int) $asset['id'],
            'ai_label_required' => $aiLabel,
            'cached' => $entry->cached,
        ], 'ffmpeg');
    }

    private function stillClip(int $ws, array $asset, float $duration): CacheEntry
    {
        $source = $this->paths->resolve($this->paths->ref('asset', $ws, (string) $asset['stored_name']));
        $key = hash('sha256', 'still|' . (int) $asset['id'] . '|' . (int) round($duration));

        return $this->cache->remember($ws, 'stock', $key, 'mp4', function (string $out) use ($source, $duration): array {
            $this->ffmpeg->run([
                '-loop', '1', '-i', $source, '-t', (string) max(1.0, $duration),
                '-vf', "scale={$this->geometry['width']}:{$this->geometry['height']}:force_original_aspect_ratio=increase,crop={$this->geometry['width']}:{$this->geometry['height']}",
                '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-r', (string) $this->fps,
                $out,
            ]);

            return ['width' => $this->geometry['width'], 'height' => $this->geometry['height'], 'kind' => 'still'];
        });
    }

    private function fromStock(int $ws, array $prior, float $duration): JobResult
    {
        $query = (string) ($prior['trend_fetch']['niche'] ?? ($prior['trend_fetch']['trend'] ?? 'abstract'));
        $key = hash('sha256', 'stock|' . $this->stock->name() . '|' . $query . '|' . (int) round($duration));

        try {
            $entry = $this->cache->remember($ws, 'stock', $key, $this->stock->clipExtension(), function (string $out) use ($query, $duration): array {
                $result = $this->stock->fetchClip($query, $duration, $out);

                return [
                    'width' => $result->width,
                    'height' => $result->height,
                    'duration_s' => $result->durationSeconds,
                    'cost_cents' => $result->costCents,
                    'meta' => $result->meta,
                ];
            });
        } catch (StockProviderException $e) {
            return JobResult::failed($e->getMessage(), $this->stock->name());
        }

        // charge quota only for a REAL provider that actually called out (a miss)
        if (!$entry->cached && $this->stock->name() !== 'mock') {
            $this->quota->record($ws, $this->stock->name(), $this->stockQuotaUnits, gmdate('Y-m-d\TH:i:s\Z'));
        }

        $cost = $entry->cached ? null : ($entry->meta['cost_cents'] ?? null);

        return JobResult::ready([
            'source' => 'stock',
            'visual_kind' => 'clip',
            'visual_ref' => $entry->ref,
            'provider' => $this->stock->name(),
            'ai_label_required' => false,
            'cached' => $entry->cached,
        ], $this->stock->name(), is_int($cost) ? $cost : null);
    }

    private function targetDuration(array $prior): float
    {
        $d = $prior['tts']['duration_s'] ?? null;

        return is_numeric($d) && (float) $d > 0 ? (float) $d : 8.0;
    }

    private function runReferenceId(int $ws, int $runId): ?int
    {
        $row = $this->db->one('SELECT reference_asset_id FROM runs WHERE id = ? AND workspace_id = ?', [$runId, $ws]);
        $id = $row['reference_asset_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function workspaceAvatarId(int $ws): ?int
    {
        $row = $this->db->one('SELECT avatar_asset_id FROM workspaces WHERE id = ?', [$ws]);
        $id = $row['avatar_asset_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed>|null a ready asset of this workspace */
    private function readyAsset(int $ws, int $id): ?array
    {
        return $this->db->one(
            "SELECT id, kind, type, title, stored_name, duration_s FROM assets
             WHERE id = ? AND workspace_id = ? AND status = 'ready'",
            [$id, $ws],
        );
    }
}
