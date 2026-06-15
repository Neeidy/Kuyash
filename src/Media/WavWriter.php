<?php

declare(strict_types=1);

namespace Kuyash\Media;

use RuntimeException;

/**
 * Pure-PHP writer for a valid mono 16-bit PCM WAV (8 kHz). Used by the MOCK TTS
 * provider to produce a REAL, playable audio file of a given duration — no
 * network, no ffmpeg, deterministic. The clip is near-silent (a faint marker
 * tone at the start so a player shows non-zero audio); the point is a real file
 * the assembly step can mux, not speech.
 */
final class WavWriter
{
    private const SAMPLE_RATE = 8000;
    private const BITS = 16;
    private const CHANNELS = 1;

    /** Write $durationSeconds of audio to $path. Returns the byte count written. */
    public static function writeSilence(string $path, float $durationSeconds): int
    {
        $seconds = max(0.1, min(120.0, $durationSeconds)); // clamp to a sane render range
        $sampleCount = (int) round($seconds * self::SAMPLE_RATE);
        $dataSize = $sampleCount * self::CHANNELS * (self::BITS / 8);

        $byteRate = self::SAMPLE_RATE * self::CHANNELS * (self::BITS / 8);
        $blockAlign = self::CHANNELS * (self::BITS / 8);

        $header = 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)                  // PCM fmt chunk size
            . pack('v', 1)                   // audioFormat = PCM
            . pack('v', self::CHANNELS)
            . pack('V', self::SAMPLE_RATE)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', self::BITS)
            . 'data'
            . pack('V', $dataSize);

        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("WAV file cannot be created: {$path}");
        }

        fwrite($handle, $header);

        // a short marker tone (first ~120ms), then silence — kept tiny + streamed
        $written = 0;
        $toneSamples = min($sampleCount, (int) (0.12 * self::SAMPLE_RATE));
        for ($i = 0; $i < $toneSamples; $i++) {
            $amp = (int) (1500 * sin(2 * M_PI * 440 * $i / self::SAMPLE_RATE));
            $written += (int) fwrite($handle, pack('v', $amp & 0xFFFF));
        }
        $silence = str_repeat("\0\0", 4096);
        for ($i = $toneSamples; $i < $sampleCount; $i += 4096) {
            $chunk = min(4096, $sampleCount - $i);
            $written += (int) fwrite($handle, $chunk === 4096 ? $silence : str_repeat("\0\0", $chunk));
        }

        fclose($handle);

        return strlen($header) + $dataSize;
    }

    /**
     * Streaming WAV writers (notably OpenAI TTS) emit 0xFFFFFFFF in the RIFF and
     * data chunk-size fields as a placeholder meaning "length unknown / streamed".
     * Trusting it literally makes durationOf return nonsense (0xFFFFFFFF ÷ byteRate
     * ≈ 89478s). We detect this sentinel and derive the real size from the file.
     */
    private const STREAMING_SIZE_SENTINEL = 0xFFFFFFFF;

    /**
     * Best-effort duration (seconds) of a PCM WAV by walking its chunks:
     * data-chunk byte count ÷ byte rate. Returns null on a non-PCM/odd file so
     * the caller can fall back to an estimate (never a hard failure).
     */
    public static function durationOf(string $path): ?float
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (fread($handle, 12) === false) {
                return null;
            }
            $byteRate = null;
            $dataSize = null;

            while (!feof($handle)) {
                $head = fread($handle, 8);
                if ($head === false || strlen($head) < 8) {
                    break;
                }
                $id = substr($head, 0, 4);
                $size = unpack('V', substr($head, 4, 4))[1] ?? 0;

                if ($id === 'fmt ') {
                    $fmt = fread($handle, $size);
                    if ($fmt !== false && strlen($fmt) >= 16) {
                        $byteRate = unpack('V', substr($fmt, 8, 4))[1] ?? null;
                    }
                } elseif ($id === 'data') {
                    $dataSize = $size;
                    if ($size === self::STREAMING_SIZE_SENTINEL) {
                        // Unknown/streamed length: the payload runs from here to EOF.
                        $payloadStart = ftell($handle);
                        $stat = fstat($handle);
                        $fileSize = $stat['size'] ?? null;
                        if ($payloadStart !== false && $fileSize !== null && $fileSize > $payloadStart) {
                            $dataSize = $fileSize - $payloadStart;
                        }
                    }
                    break; // duration is known once we have data size + byte rate
                } else {
                    fseek($handle, $size + ($size % 2), SEEK_CUR); // chunks are word-aligned
                }
            }

            if ($byteRate === null || $byteRate <= 0 || $dataSize === null) {
                return null;
            }

            return round($dataSize / $byteRate, 2);
        } finally {
            fclose($handle);
        }
    }
}
