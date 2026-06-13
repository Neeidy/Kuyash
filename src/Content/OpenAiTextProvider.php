<?php

declare(strict_types=1);

namespace Kuyash\Content;

use Kuyash\Core\PermanentFailureException;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpTransportException;
use Throwable;

/**
 * Real OpenAI Chat Completions adapter — OFF by default, selected only when
 * OPENAI_MOCK=false and a key is present (see bindings/core.php). Depends on
 * the HttpClient seam, so tests drive every branch with a fake transport and
 * ZERO network.
 *
 * Honesty + safety:
 * - Returns provider='openai', the config model, and a real cost from usage.
 * - Every failure (transport, non-2xx, empty/malformed JSON) becomes a
 *   TextProviderException whose message carries only a status/reason — never
 *   the API key, request headers, or raw response body.
 * - Asks for a strict JSON object; the model's text is itself JSON we decode.
 */
final class OpenAiTextProvider implements TextProvider
{
    private const DEFAULT_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly PromptLibrary $prompts,
        private readonly VariationEngine $variation,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function generate(string $kind, array $context, int $seed): TextResult
    {
        $topic = Sanitizer::clean((string) ($context['topic'] ?? 'an evergreen topic'), 120);
        $variant = $this->variation->variant($seed, $topic);
        $model = (string) ($this->config['model'] ?? 'gpt-4o-mini');

        $payload = [
            'model' => $model,
            'messages' => $this->prompts->messages($kind, $context, $variant),
            'temperature' => (float) ($this->config['temperature'] ?? 0.8),
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->call($payload);
        $content = $this->extractContent($response);
        $parsed = $this->decodeJson($content, 'model content');

        $data = $this->shape($kind, $parsed, $topic, $variant);

        // usage comes straight off the decoded response — no hidden state
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $cost = CostCalculator::compute(
            $model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            $this->priceTable(),
        );
        $data['cost_usd'] = $cost['usd'];

        return new TextResult($data, 'openai', $this->prompts->version($kind), $model, $cost['cents']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> decoded top-level response
     */
    private function call(array $payload): array
    {
        $key = (string) ($this->config['api_key'] ?? '');
        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/json',
        ];
        if (!empty($this->config['org_id'])) {
            $headers['OpenAI-Organization'] = (string) $this->config['org_id'];
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $timeout = (int) ($this->config['timeout'] ?? 30);

        try {
            $response = $this->http->post($endpoint, $headers, $body, $timeout);
        } catch (HttpTransportException $e) {
            // safe to surface: transport description only (no headers/body)
            throw new TextProviderException('OpenAI request failed: ' . $e->getMessage());
        }

        if ($response->status === 429) {
            throw new TextProviderException('OpenAI rate limited (HTTP 429)');
        }
        if ($response->status === 401 || $response->status === 403) {
            // credentials invalid/forbidden — retrying cannot fix it; dead-letter
            // fast (PermanentFailure) instead of burning the backoff budget
            throw new PermanentFailureException('OpenAI request rejected (HTTP ' . $response->status . ') — credentials invalid or forbidden');
        }
        if ($response->status < 200 || $response->status >= 300) {
            // status only — never echo the response body
            throw new TextProviderException('OpenAI request failed (HTTP ' . $response->status . ')');
        }

        $decoded = $this->decodeJson($response->body, 'response');
        if (!is_array($decoded)) {
            throw new TextProviderException('OpenAI response was not a JSON object');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $response */
    private function extractContent(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new TextProviderException('OpenAI response had no message content');
        }

        return $content;
    }

    /** @return mixed */
    private function decodeJson(string $raw, string $what): mixed
    {
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new TextProviderException("OpenAI {$what} was not valid JSON");
        }
    }

    /**
     * Coerce the model's JSON into the canonical per-kind shape (the same shape
     * MockTextProvider produces), throwing if required fields are missing.
     *
     * @param mixed                                                            $parsed
     * @param array{hook: string, pacing: string, opener: string, cta: string} $variant
     *
     * @return array<string, mixed>
     */
    private function shape(string $kind, mixed $parsed, string $topic, array $variant): array
    {
        if (!is_array($parsed)) {
            throw new TextProviderException('OpenAI returned an unexpected JSON shape');
        }

        return match ($kind) {
            'idea' => [
                'idea' => $this->str($parsed, 'idea', 'Angle on "' . $topic . '"'),
                'hook' => $this->str($parsed, 'hook', $variant['hook']),
                'format' => '15-45s vertical',
            ],
            'script' => $this->shapeScript($parsed),
            'caption' => ['captions' => $this->shapeCaptions($parsed)],
            'hashtag' => ['hashtags' => $this->shapeHashtags($parsed)],
            default => throw new TextProviderException("OpenAI: unsupported content kind '{$kind}'"),
        };
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return array{script: string, word_count: int, estimated_duration_s: float}
     */
    private function shapeScript(array $parsed): array
    {
        $script = $this->str($parsed, 'script', '');
        if ($script === '') {
            throw new TextProviderException('OpenAI script was empty');
        }
        // recompute word_count from the actual text (don't trust the model's number)
        $wordCount = count(preg_split('/\s+/', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $duration = isset($parsed['estimated_duration_s']) && is_numeric($parsed['estimated_duration_s'])
            ? round((float) $parsed['estimated_duration_s'], 1)
            : round($wordCount / 2.5, 1);

        return ['script' => $script, 'word_count' => $wordCount, 'estimated_duration_s' => $duration];
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return array<string, string>
     */
    private function shapeCaptions(array $parsed): array
    {
        $captions = [];
        foreach (PromptLibrary::platforms() as $platform) {
            $value = $parsed[$platform] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new TextProviderException("OpenAI caption missing for '{$platform}'");
            }
            $captions[$platform] = Sanitizer::clean($value, 280);
        }

        return $captions;
    }

    /**
     * @param array<string, mixed> $parsed
     *
     * @return list<string>
     */
    private function shapeHashtags(array $parsed): array
    {
        $raw = $parsed['hashtags'] ?? null;
        if (!is_array($raw) || $raw === []) {
            throw new TextProviderException('OpenAI returned no hashtags');
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag) || trim($tag) === '') {
                continue;
            }
            $clean = Sanitizer::clean($tag, 40);
            $tags[] = str_starts_with($clean, '#') ? $clean : '#' . ltrim($clean, '#');
        }
        if ($tags === []) {
            throw new TextProviderException('OpenAI hashtags were all empty');
        }

        return array_values(array_slice(array_unique($tags), 0, 12));
    }

    /** @param array<string, mixed> $a */
    private function str(array $a, string $key, string $default): string
    {
        $v = $a[$key] ?? null;

        return is_string($v) && trim($v) !== '' ? Sanitizer::clean($v, 2000) : $default;
    }

    /** @return array<string, array{in: float, out: float}> */
    private function priceTable(): array
    {
        $prices = $this->config['prices'] ?? [];

        return is_array($prices) ? $prices : [];
    }
}
