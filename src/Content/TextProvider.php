<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * The text-generation seam. MockTextProvider (default) and OpenAiTextProvider
 * (real, behind a flag) implement this; ContentExecutor depends only on this
 * interface, so swapping providers is one config line (adapter rule).
 *
 * $kind ∈ {idea, script, caption, hashtag}.
 * $context carries sanitized upstream facts (trend, idea, hook, asset title).
 * $seed is deterministic (run_id + step) so the SAME run reproduces output and
 * DIFFERENT runs vary (slop control).
 *
 * Implementations throw TextProviderException on an unrecoverable failure
 * (its message is already sanitized — never a key, header, or raw payload).
 */
interface TextProvider
{
    /** @param array<string, mixed> $context */
    public function generate(string $kind, array $context, int $seed): TextResult;

    /**
     * The provider tag recorded on jobs ('mock' | 'openai' | …). Lets the
     * vendor-blind ContentExecutor label a failure correctly without naming a
     * vendor itself — so a future second provider is never mislabeled.
     */
    public function name(): string;
}
