<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * The single shape that crosses the TextProvider seam (adapter rule: vendors
 * translate their responses into THIS, core never sees a vendor payload).
 *
 * - data: the generated content (idea/script/caption/hashtag fields)
 * - provider: 'mock' | 'openai' (recorded on the job row)
 * - model: vendor model id, or null for mock
 * - costCents: real spend in integer cents, or null for mock (Phase 4 rule:
 *   mock cost is never presented as real spend)
 * - promptVersion: the PromptLibrary template version used (audit trail)
 */
final class TextResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $provider,
        public readonly string $promptVersion,
        public readonly ?string $model = null,
        public readonly ?int $costCents = null,
    ) {
    }
}
