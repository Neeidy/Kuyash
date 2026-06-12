<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Default stock provider: generates a REAL 9:16 ffmpeg clip offline (lavfi color
 * source), its tint deterministically derived from the query so the same niche
 * reproduces. No network. provider='mock', costCents=null. The clip is full-res
 * (the draft assembly scales it down); the point is a real visual the assembly
 * step composites, not photographic footage.
 */
final class MockStockProvider implements StockProvider
{
    /** A small, distinct palette; pick is deterministic per query. */
    private const PALETTE = ['1f6feb', '238636', '8957e5', 'bb8009', 'cf222e', '0969da', '1a7f37'];

    public function __construct(
        private readonly Ffmpeg $ffmpeg,
        private readonly int $width = 1080,
        private readonly int $height = 1920,
        private readonly int $fps = 24,
    ) {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function clipExtension(): string
    {
        return 'mp4';
    }

    public function fetchClip(string $query, float $durationSeconds, string $targetPath): StockResult
    {
        $duration = max(1.0, min(60.0, $durationSeconds));
        $color = self::PALETTE[abs(crc32($query)) % count(self::PALETTE)];

        try {
            $this->ffmpeg->run([
                '-f', 'lavfi',
                '-i', "color=c=0x{$color}:s={$this->width}x{$this->height}:d={$duration}:r={$this->fps}",
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
                $targetPath,
            ]);
        } catch (FfmpegException $e) {
            throw new StockProviderException('mock stock clip generation failed: ' . $e->getMessage());
        }

        return new StockResult($this->width, $this->height, $duration, null, ['color' => $color]);
    }
}
