<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * The shape crossing the TtsProvider seam: facts about the audio file the
 * provider just wrote. costCents is real integer spend (null for mock — mock
 * work is never presented as real spend); model is the vendor model id or null.
 */
final class TtsResult
{
    public function __construct(
        public readonly float $durationSeconds,
        public readonly ?int $costCents = null,
        public readonly ?string $model = null,
    ) {
    }
}
