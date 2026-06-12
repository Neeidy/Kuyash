<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use InvalidArgumentException;

/**
 * Canonical node registry — the single source of truth for node names,
 * templates, the node→job mapping and per-job-type defaults.
 *
 * This is CODE, not config: the canonical names are a product invariant
 * (CLAUDE.md "never rename these nodes"), not a user-tunable setting.
 * The two templates are the only valid node sequences — there is no
 * general-purpose graph, no branching, no subset logic (no-overbuild).
 */
final class Nodes
{
    public const TEMPLATE_FULL = 'full';
    public const TEMPLATE_DISTRIBUTION = 'distribution';
    public const TEMPLATES = [self::TEMPLATE_FULL, self::TEMPLATE_DISTRIBUTION];

    /** Nodes that can never be removed or unlocked (compliance-first rule). */
    public const LOCKED = ['COMPLIANCE'];

    public const FULL = [
        'TREND', 'IDEA', 'SCRIPT', 'VOICE', 'VISUALS', 'ASSEMBLE',
        'CAPTION', 'HASHTAGS', 'MUSIC NOTE / STYLE', 'PREVIEW',
        'COMPLIANCE', 'PUBLISH',
    ];

    public const DISTRIBUTION = [
        'LIBRARY', 'CAPTION', 'HASHTAGS', 'MUSIC NOTE / STYLE', 'PREVIEW',
        'COMPLIANCE', 'PUBLISH',
    ];

    /** Allowed VISUALS sources (validator rule). */
    public const VISUALS_SOURCES = ['library', 'stock', 'ai'];

    /**
     * Node → job types. 1:1 except PUBLISH, which expands to the
     * content-pipeline tail: compliance_check → render_review → publish
     * (render_review is the approval gate, PREVIEW is not).
     */
    public const NODE_JOBS = [
        'TREND' => ['trend_fetch'],
        'IDEA' => ['idea_generation'],
        'SCRIPT' => ['script_draft'],
        'VOICE' => ['tts'],
        'VISUALS' => ['asset_fetch'],
        'LIBRARY' => ['asset_fetch'],
        'ASSEMBLE' => ['assembly'],
        'CAPTION' => ['caption_generation'],
        'HASHTAGS' => ['hashtag_generation'],
        'MUSIC NOTE / STYLE' => ['music_note'],
        'PREVIEW' => ['preview'],
        'COMPLIANCE' => ['compliance_check'],
        'PUBLISH' => ['render_review', 'publish'],
    ];

    /**
     * Per-job-type worker defaults: processing timeout (watchdog) and
     * max_retries. Timeouts are sized for the REAL providers arriving in
     * Phases 5/7/10 — mocks finish instantly, but a stuck row must always
     * have a deadline.
     *
     * @var array<string, array{timeout: int, max_retries: int}>
     */
    public const JOB_DEFAULTS = [
        'trend_fetch' => ['timeout' => 120, 'max_retries' => 3],
        'idea_generation' => ['timeout' => 120, 'max_retries' => 3],
        'script_draft' => ['timeout' => 120, 'max_retries' => 3],
        'tts' => ['timeout' => 300, 'max_retries' => 3],
        'asset_fetch' => ['timeout' => 300, 'max_retries' => 3],
        'assembly' => ['timeout' => 900, 'max_retries' => 3],
        'caption_generation' => ['timeout' => 120, 'max_retries' => 3],
        'hashtag_generation' => ['timeout' => 120, 'max_retries' => 3],
        'music_note' => ['timeout' => 120, 'max_retries' => 3],
        'preview' => ['timeout' => 300, 'max_retries' => 3],
        'compliance_check' => ['timeout' => 120, 'max_retries' => 3],
        'render_review' => ['timeout' => 120, 'max_retries' => 3],
        'publish' => ['timeout' => 300, 'max_retries' => 3],
    ];

    /** Job types whose executor pauses the run for a human decision. */
    public const APPROVAL_TYPES = ['script_draft', 'render_review'];

    /** Job statuses that never transition again. */
    public const JOB_TERMINAL = ['ready', 'failed', 'published', 'cancelled'];

    /** Run statuses that never transition again. */
    public const RUN_TERMINAL = ['completed', 'failed', 'cancelled'];

    /** @return list<string> canonical node ids for a template */
    public static function template(string $template): array
    {
        return match ($template) {
            self::TEMPLATE_FULL => self::FULL,
            self::TEMPLATE_DISTRIBUTION => self::DISTRIBUTION,
            default => throw new InvalidArgumentException("Unknown workflow template: {$template}"),
        };
    }

    /**
     * Default nodes_json structure for a template: every node unlocked except
     * the locked set, VISUALS defaulting to the stock source. Settings stay
     * minimal — editing them is a Phase 5+ feature and mocks ignore them.
     *
     * @return list<array{node: string, locked: bool, settings: array<string, mixed>}>
     */
    public static function defaultNodes(string $template): array
    {
        return array_map(static fn (string $node): array => [
            'node' => $node,
            'locked' => in_array($node, self::LOCKED, true),
            'settings' => $node === 'VISUALS' ? ['source' => 'stock'] : [],
        ], self::template($template));
    }

    /**
     * Expand a node sequence into the ordered job chain. Steps are 1-based;
     * the chain is what a run executes, one job at a time.
     *
     * @param list<string> $nodeIds
     *
     * @return list<array{step: int, node: string, type: string}>
     */
    public static function expand(array $nodeIds): array
    {
        $chain = [];
        $step = 1;
        foreach ($nodeIds as $node) {
            foreach (self::NODE_JOBS[$node] ?? [] as $type) {
                $chain[] = ['step' => $step, 'node' => $node, 'type' => $type];
                $step++;
            }
        }

        return $chain;
    }

    public static function timeoutFor(string $type): int
    {
        return self::JOB_DEFAULTS[$type]['timeout'] ?? 300;
    }

    public static function maxRetriesFor(string $type): int
    {
        return self::JOB_DEFAULTS[$type]['max_retries'] ?? 3;
    }

    /** @return list<string> all known job types */
    public static function jobTypes(): array
    {
        return array_keys(self::JOB_DEFAULTS);
    }
}
