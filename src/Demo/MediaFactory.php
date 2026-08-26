<?php

declare(strict_types=1);

namespace Kuyash\Demo;

/**
 * How the showcase seed obtains the media it puts in the library (DEV/demo
 * tooling). Behind an interface for one reason: the seed must never be the place
 * where a duration is DECIDED. It asks for "about 23 seconds" and stores back
 * whatever the factory reports having produced.
 *
 * That distinction is not academic. `assets.duration_s` is not a caption: the
 * asset_fetch job copies it into its result and the compliance check measures
 * the 15-45s format band against it. A seed that writes a number it merely
 * intended would produce an audit record asserting a duration the file does not
 * have — on the one screen whose whole job is to be believed.
 *
 * @phpstan-type Made array{
 *     path: string, duration_s: float|null, width: int|null, height: int|null,
 *     aspect: string|null, size_bytes: int, sha256: string, mime: string
 * }
 */
interface MediaFactory
{
    /**
     * Produce a vertical video of roughly $seconds at $target and MEASURE it.
     * $variant shifts the look so a library of demo clips does not read as ten
     * copies of one gradient.
     * Returns null when it could not be made (no ffmpeg, no source fixture) —
     * the caller then seeds nothing rather than a row pointing at no bytes.
     *
     * @return array<string, mixed>|null
     */
    public function clip(string $target, int $seconds, int $variant = 0): ?array;

    /**
     * Produce a single still frame at $target and measure it. $index picks a
     * DIFFERENT frame per call — two stills cut from the same first frame are
     * byte-identical, which is a sha256 collision under two different titles.
     *
     * @return array<string, mixed>|null
     */
    public function still(string $target, int $index = 0): ?array;
}
