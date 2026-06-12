<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpTransportException;

/**
 * Real OpenAI Text-to-Speech adapter (POST /v1/audio/speech) — OFF by default,
 * selected only when TTS_MOCK=false and a key is present (see bindings/core.php).
 * Requests response_format=wav so the body is a ready-to-mux WAV; depends on the
 * HttpClient seam, so tests drive every branch with a fake transport and ZERO
 * network.
 *
 * Honesty + safety:
 * - Returns provider='openai', the config model, and a real cost from the input
 *   character count (audio APIs are billed per character, not per token).
 * - Every failure (transport, non-2xx, empty body) becomes a
 *   TtsProviderException carrying only a status/reason — never the API key,
 *   request headers, or raw body.
 */
final class OpenAiTtsProvider implements TtsProvider
{
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/audio/speech';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function audioExtension(): string
    {
        return 'wav';
    }

    public function synthesize(string $text, string $voice, string $targetPath): TtsResult
    {
        $model = (string) ($this->config['model'] ?? 'gpt-4o-mini-tts');
        $body = json_encode([
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => 'wav',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization' => 'Bearer ' . (string) ($this->config['api_key'] ?? ''),
            'Content-Type' => 'application/json',
        ];
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $timeout = (int) ($this->config['timeout'] ?? 60);

        try {
            $response = $this->http->post($endpoint, $headers, $body, $timeout);
        } catch (HttpTransportException $e) {
            throw new TtsProviderException('OpenAI TTS request failed: ' . $e->getMessage());
        }

        if ($response->status === 429) {
            throw new TtsProviderException('OpenAI TTS rate limited (HTTP 429)');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new TtsProviderException('OpenAI TTS request failed (HTTP ' . $response->status . ')');
        }
        if ($response->body === '') {
            throw new TtsProviderException('OpenAI TTS returned an empty audio body');
        }

        if (@file_put_contents($targetPath, $response->body) === false) {
            throw new TtsProviderException('OpenAI TTS audio could not be written to disk');
        }

        $duration = WavWriter::durationOf($targetPath)
            ?? round(max(1, mb_strlen($text)) / 15.0, 2); // fallback estimate

        return new TtsResult($duration, $this->cost($text), $model);
    }

    /**
     * APPROXIMATE cost from the input character count. NOTE: the legacy tts-1
     * models bill per character, but gpt-4o-mini-tts bills per TOKEN (text in +
     * audio out) — this estimate is advisory only. The Phase 11 credit ledger
     * reconciles against real account usage (see phase-7-followups).
     */
    private function cost(string $text): int
    {
        $perMillion = (float) ($this->config['price_cents_per_million_chars'] ?? 0.0);
        $chars = mb_strlen($text);

        return (int) round($chars / 1_000_000 * $perMillion);
    }
}
