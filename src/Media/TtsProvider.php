<?php

declare(strict_types=1);

namespace Kuyash\Media;

/**
 * The text-to-speech seam. MockTtsProvider (DEFAULT, writes a real offline WAV)
 * and OpenAiTtsProvider (real audio/speech, behind a flag) implement this;
 * TtsExecutor depends only on this interface, so swapping providers is one config
 * line (adapter rule).
 *
 * synthesize() MUST write a playable audio file to $targetPath (the caller owns
 * the path — server-generated, validated) and return a TtsResult describing it.
 * Failures throw TtsProviderException with a sanitized message (no key/headers).
 */
interface TtsProvider
{
    public function synthesize(string $text, string $voice, string $targetPath): TtsResult;

    /** The file extension this provider writes (e.g. 'wav') — drives the cache name. */
    public function audioExtension(): string;

    /** Provider tag recorded on the job/cache ('mock' | 'openai'). */
    public function name(): string;
}
