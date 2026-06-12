<?php

declare(strict_types=1);

namespace Kuyash\Content;

use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * The real executor for the four content job types. It owns only the seam
 * glue — build a sanitized context from upstream results, call the injected
 * TextProvider, map the result to a JobResult — so swapping mock↔OpenAI is a
 * provider swap, not an executor change (adapter rule).
 *
 * script_draft pauses for human approval (awaiting_approval); the others go
 * straight to ready. Cost + provider + prompt_version ride along, including on
 * the paused script (a real generation already spent money before approval —
 * recording it is the honest thing to do).
 */
final class ContentExecutor implements JobExecutor
{
    private const TYPE_KIND = [
        'idea_generation' => 'idea',
        'script_draft' => 'script',
        'caption_generation' => 'caption',
        'hashtag_generation' => 'hashtag',
    ];

    public function __construct(private readonly TextProvider $provider)
    {
    }

    /**
     * The job types this executor serves — the single source of truth the
     * binding uses to register it (no duplicated list).
     *
     * @return list<string>
     */
    public static function contentTypes(): array
    {
        return array_keys(self::TYPE_KIND);
    }

    public function execute(array $job, array $prior): JobResult
    {
        $type = (string) $job['type'];
        $kind = self::TYPE_KIND[$type] ?? null;
        if ($kind === null) {
            return JobResult::failed("ContentExecutor: unsupported job type '{$type}'");
        }

        // same seed formula as MockExecutor: deterministic per (run, step)
        $seed = (int) crc32('run' . $job['run_id'] . '-step' . $job['step']);

        try {
            $result = $this->provider->generate($kind, $this->context($prior), $seed);
        } catch (TextProviderException $e) {
            // the provider already sanitized this message (no key/headers/payload);
            // the provider names itself so a future second provider isn't mislabeled
            return JobResult::failed($e->getMessage(), $this->provider->name());
        }

        $data = $result->data + ['prompt_version' => $result->promptVersion];

        if ($type === 'script_draft') {
            return JobResult::awaitingApproval($data, $result->provider, $result->costCents);
        }

        return JobResult::ready($data, $result->provider, $result->costCents);
    }

    /**
     * Sanitized facts upstream content can lean on. Full runs carry trend +
     * idea; distribution runs (no trend) fall back to the library asset title
     * as the topic.
     *
     * @param array<string, array<string, mixed>> $prior
     *
     * @return array<string, mixed>
     */
    private function context(array $prior): array
    {
        $topic = $prior['trend_fetch']['trend']
            ?? ($prior['asset_fetch']['title'] ?? 'an evergreen topic');

        return [
            'topic' => Sanitizer::clean((string) $topic, 120),
            'idea' => Sanitizer::clean((string) ($prior['idea_generation']['idea'] ?? ''), 200),
            'hook' => Sanitizer::clean((string) ($prior['idea_generation']['hook'] ?? ''), 200),
        ];
    }
}
