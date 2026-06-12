<?php

declare(strict_types=1);

namespace Kuyash\Media;

use Kuyash\Content\Sanitizer;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * Real executor for the `tts` job type (replaces MockExecutor's placeholder).
 * Narrates the approved script through the injected TtsProvider and caches the
 * audio content-addressed (same script+voice+provider → reuse, no respend).
 * The audio file is referenced by a tagged media ref in result_json so the
 * assembly step can mux it.
 */
final class TtsExecutor implements JobExecutor
{
    public function __construct(
        private readonly TtsProvider $provider,
        private readonly AssetCache $cache,
        private readonly string $voice,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $ws = (int) $job['workspace_id'];
        $text = $this->narration($prior);
        if ($text === '') {
            return JobResult::failed('tts: no script text to narrate', $this->provider->name());
        }

        $key = hash('sha256', 'tts|' . $this->provider->name() . '|' . $this->voice . '|' . $text);

        try {
            $entry = $this->cache->remember(
                $ws,
                'tts',
                $key,
                $this->provider->audioExtension(),
                fn (string $path): array => $this->produce($text, $path),
            );
        } catch (TtsProviderException $e) {
            return JobResult::failed($e->getMessage(), $this->provider->name());
        }

        // honest cost: a cache hit spends nothing; a miss carries the real cents
        $cost = $entry->cached ? null : ($entry->meta['cost_cents'] ?? null);

        return JobResult::ready([
            'provider' => $this->provider->name(),
            'voice' => $this->voice,
            'audio_ref' => $entry->ref,
            'duration_s' => $entry->meta['duration_s'] ?? null,
            'cached' => $entry->cached,
        ], $this->provider->name(), is_int($cost) ? $cost : null);
    }

    /** @return array<string, mixed> */
    private function produce(string $text, string $path): array
    {
        $result = $this->provider->synthesize($text, $this->voice, $path);

        return [
            'duration_s' => $result->durationSeconds,
            'voice' => $this->voice,
            'model' => $result->model,
            'cost_cents' => $result->costCents,
        ];
    }

    /**
     * The narration text: the approved script, else the idea, else the topic.
     * Sanitized (untrusted upstream text can include real trend strings).
     *
     * @param array<string, array<string, mixed>> $prior
     */
    private function narration(array $prior): string
    {
        $script = (string) ($prior['script_draft']['script'] ?? '');
        if (trim($script) === '') {
            $script = (string) ($prior['idea_generation']['idea'] ?? ($prior['trend_fetch']['trend'] ?? ''));
        }

        return Sanitizer::clean($script, 2000);
    }
}
