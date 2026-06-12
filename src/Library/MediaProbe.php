<?php

declare(strict_types=1);

namespace Kuyash\Library;

use RuntimeException;
use Throwable;

/**
 * Pure-PHP media metadata probe — NO ffmpeg/ffprobe before Phase 7.
 *
 * Video: seek-based ISO BMFF (mp4/mov) box walker. Reads mvhd (v0/v1) for
 * duration and the first visual trak's tkhd for display dimensions,
 * including the rotation matrix: phones store portrait video as a landscape
 * pixel buffer plus a 90°/270° matrix — without the swap, every portrait
 * clip would misclassify as 16:9 and false-trigger the 9:16 format warning.
 *
 * Photo: getimagesize().
 *
 * Fail-soft contract: ANY anomaly (truncated, garbage, fragmented, exotic)
 * returns all-null metadata — uploads are never blocked by the probe.
 */
final class MediaProbe
{
    private const MAX_DEPTH = 8;
    private const MAX_BOXES = 4096;

    /** aspect label => target ratio (width / height), matched within 2% */
    private const ASPECT_TARGETS = [
        '9:16' => 9 / 16,
        '16:9' => 16 / 9,
        '1:1' => 1.0,
        '4:5' => 4 / 5,
    ];

    private int $boxCount = 0;

    /** @return array{duration_s: ?float, width: ?int, height: ?int, aspect: ?string} */
    public function probe(string $path, string $kind): array
    {
        try {
            return $kind === 'photo' ? $this->probePhoto($path) : $this->probeVideo($path);
        } catch (Throwable $e) {
            error_log("Kuyash: media probe failed for {$path}: {$e->getMessage()}");

            return self::unknown();
        }
    }

    /** @return array{duration_s: ?float, width: ?int, height: ?int, aspect: ?string} */
    private function probePhoto(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return self::unknown();
        }

        return [
            'duration_s' => null,
            'width' => $info[0],
            'height' => $info[1],
            'aspect' => self::classifyAspect($info[0], $info[1]),
        ];
    }

    /** @return array{duration_s: ?float, width: ?int, height: ?int, aspect: ?string} */
    private function probeVideo(string $path): array
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($size === false || $handle === false) {
            return self::unknown();
        }

        $this->boxCount = 0;
        $duration = null;
        $width = null;
        $height = null;

        try {
            $this->walk($handle, 0, $size, 0, function (string $type, int $start, int $end) use ($handle, &$duration, &$width, &$height): void {
                if ($type === 'moov') {
                    $this->walk($handle, $start, $end, 1, function (string $t, int $s, int $e) use ($handle, &$duration, &$width, &$height): void {
                        if ($t === 'mvhd' && $duration === null) {
                            $duration = $this->parseMvhd($handle, $s, $e);
                        } elseif ($t === 'trak' && $width === null) {
                            $this->walk($handle, $s, $e, 2, function (string $tt, int $ts, int $te) use ($handle, &$width, &$height): void {
                                if ($tt === 'tkhd' && $width === null) {
                                    [$w, $h] = $this->parseTkhd($handle, $ts, $te);
                                    if ($w > 0 && $h > 0) { // audio traks are 0×0
                                        $width = $w;
                                        $height = $h;
                                    }
                                }
                            });
                        }
                    });
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'duration_s' => $duration,
            'width' => $width,
            'height' => $height,
            'aspect' => ($width !== null && $height !== null) ? self::classifyAspect($width, $height) : null,
        ];
    }

    /**
     * Bounds-checked box walk over [$start, $end). Handles 32-bit sizes,
     * size==1 (64-bit largesize, required to skip >4GB-capable mdat) and
     * size==0 (box extends to end). moov regularly sits AFTER mdat in phone
     * files — the walk never assumes ordering.
     *
     * @param resource $handle
     * @param callable(string, int, int): void $visit type, payloadStart, payloadEnd
     */
    private function walk($handle, int $start, int $end, int $depth, callable $visit): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $pos = $start;
        while ($pos + 8 <= $end) {
            if (++$this->boxCount > self::MAX_BOXES) {
                throw new RuntimeException('box count cap exceeded');
            }

            fseek($handle, $pos);
            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) < 8) {
                return;
            }

            $boxSize = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerLen = 8;

            if ($boxSize === 1) {
                $large = fread($handle, 8);
                if (!is_string($large) || strlen($large) < 8) {
                    return;
                }
                $boxSize = unpack('J', $large)[1];
                $headerLen = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $end - $pos;
            }

            if ($boxSize < $headerLen || $pos + $boxSize > $end) {
                return; // corrupt or truncated — stop walking this level
            }

            $visit($type, $pos + $headerLen, $pos + $boxSize);

            $pos += $boxSize;
        }
    }

    /** @param resource $handle */
    private function parseMvhd($handle, int $start, int $end): ?float
    {
        $payload = $this->readPayload($handle, $start, $end, 32);
        if ($payload === null) {
            return null;
        }

        $version = ord($payload[0]);
        if ($version === 1) {
            if (strlen($payload) < 32) {
                return null;
            }
            $timescale = unpack('N', substr($payload, 20, 4))[1];
            $duration = unpack('J', substr($payload, 24, 8))[1];
        } else {
            if (strlen($payload) < 20) {
                return null;
            }
            $timescale = unpack('N', substr($payload, 12, 4))[1];
            $duration = unpack('N', substr($payload, 16, 4))[1];
            if ($duration === 0xFFFFFFFF) { // v0 "unknown" sentinel
                return null;
            }
        }

        if ($timescale <= 0 || $duration <= 0) {
            return null; // fragmented mp4 reports 0 here — fall back to unknown
        }

        return round($duration / $timescale, 2);
    }

    /**
     * Display dimensions from tkhd (16.16 fixed point), rotation-corrected
     * via the transformation matrix (swap on canonical 90°/270°; any
     * non-canonical matrix → best-effort unrotated dimensions).
     *
     * @param resource $handle
     *
     * @return array{0: int, 1: int}
     */
    private function parseTkhd($handle, int $start, int $end): array
    {
        $payload = $this->readPayload($handle, $start, $end, 96);
        if ($payload === null) {
            return [0, 0];
        }

        $version = ord($payload[0]);
        $matrixOffset = $version === 1 ? 52 : 40;
        $dimsOffset = $version === 1 ? 88 : 76;

        if (strlen($payload) < $dimsOffset + 8) {
            return [0, 0];
        }

        $width = unpack('N', substr($payload, $dimsOffset, 4))[1] >> 16;
        $height = unpack('N', substr($payload, $dimsOffset + 4, 4))[1] >> 16;

        // matrix cells a,b,c,d as signed 16.16; compare against canonical rotations
        $a = $this->signed32(unpack('N', substr($payload, $matrixOffset, 4))[1]);
        $b = $this->signed32(unpack('N', substr($payload, $matrixOffset + 4, 4))[1]);
        $c = $this->signed32(unpack('N', substr($payload, $matrixOffset + 12, 4))[1]);
        $d = $this->signed32(unpack('N', substr($payload, $matrixOffset + 16, 4))[1]);

        $one = 0x00010000;
        $rot90 = ($a === 0 && $b === $one && $c === -$one && $d === 0);
        $rot270 = ($a === 0 && $b === -$one && $c === $one && $d === 0);

        if ($rot90 || $rot270) {
            return [$height, $width];
        }

        return [$width, $height];
    }

    /** @param resource $handle */
    private function readPayload($handle, int $start, int $end, int $max): ?string
    {
        $len = min($end - $start, $max);
        if ($len < 4) {
            return null;
        }
        fseek($handle, $start);
        $payload = fread($handle, $len);

        return is_string($payload) && strlen($payload) === $len ? $payload : null;
    }

    private function signed32(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public static function classifyAspect(int $width, int $height): string
    {
        $ratio = $width / $height;
        foreach (self::ASPECT_TARGETS as $label => $target) {
            if (abs($ratio - $target) / $target <= 0.02) {
                return $label;
            }
        }

        return 'other';
    }

    /** @return array{duration_s: null, width: null, height: null, aspect: null} */
    private static function unknown(): array
    {
        return ['duration_s' => null, 'width' => null, 'height' => null, 'aspect' => null];
    }
}
