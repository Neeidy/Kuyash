<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * Default TTS provider: writes a REAL, playable WAV offline (no network), its
 * duration estimated from the narration length at a natural speaking pace. The
 * assembly step muxes this exactly like real speech — the only difference is the
 * audio is near-silent. provider='mock', costCents=null (never real spend).
 */
final class MockTtsProvider implements TtsProvider
{
    public function __construct(private readonly float $wordsPerSecond = 2.5)
    {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function audioExtension(): string
    {
        return 'wav';
    }

    public function synthesize(string $text, string $voice, string $targetPath): TtsResult
    {
        $words = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $duration = $words > 0 ? $words / max(0.5, $this->wordsPerSecond) : 1.0;

        WavWriter::writeSilence($targetPath, $duration);

        // report the duration of the file actually written (clamped by the writer)
        $actual = WavWriter::durationOf($targetPath) ?? round($duration, 2);

        return new TtsResult($actual, null, null);
    }
}
