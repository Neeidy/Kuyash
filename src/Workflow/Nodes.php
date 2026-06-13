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
    public const TEMPLATE_QUICK_CREATE = 'quick_create';
    public const TEMPLATES = [self::TEMPLATE_FULL, self::TEMPLATE_DISTRIBUTION, self::TEMPLATE_QUICK_CREATE];

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

    /**
     * Quick Create (Phase 12): a photo + prompt → AI image-to-video → distribute.
     * VISUALS uses the 'ai' source (→ ai_video job); there is NO ASSEMBLE — the
     * finished AI clip is normalized at final_render exactly like a distribution
     * library video (no narrated draft assembly). Brief-faithful: no
     * TREND/IDEA/SCRIPT/VOICE — the prompt is the only creative input.
     */
    public const QUICK_CREATE = [
        'VISUALS', 'CAPTION', 'HASHTAGS', 'MUSIC NOTE / STYLE', 'PREVIEW',
        'COMPLIANCE', 'PUBLISH',
    ];

    /** Allowed VISUALS sources (validator rule). */
    public const VISUALS_SOURCES = ['library', 'stock', 'ai'];

    /**
     * Node → job types. 1:1 except PUBLISH, which expands to the
     * content-pipeline tail: render_review → final_render → publish.
     * render_review is the approval gate (PREVIEW is not); final_render is the
     * full-res render produced ONLY after approval (draft-first rendering — the
     * low-res draft is made earlier at ASSEMBLE).
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
        'PUBLISH' => ['render_review', 'final_render', 'publish'],
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
        // AI image-to-video (Phase 12): slow + paid. max_retries 1 — never blindly
        // re-issue an expensive generation; a real failure dead-letters fast.
        'ai_video' => ['timeout' => 600, 'max_retries' => 1],
        'assembly' => ['timeout' => 900, 'max_retries' => 3],
        'caption_generation' => ['timeout' => 120, 'max_retries' => 3],
        'hashtag_generation' => ['timeout' => 120, 'max_retries' => 3],
        'music_note' => ['timeout' => 120, 'max_retries' => 3],
        'preview' => ['timeout' => 300, 'max_retries' => 3],
        'compliance_check' => ['timeout' => 120, 'max_retries' => 3],
        'render_review' => ['timeout' => 120, 'max_retries' => 3],
        'final_render' => ['timeout' => 900, 'max_retries' => 3],
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
            self::TEMPLATE_QUICK_CREATE => self::QUICK_CREATE,
            default => throw new InvalidArgumentException("Unknown workflow template: {$template}"),
        };
    }

    /**
     * Default nodes_json structure for a template: every node unlocked except
     * the locked set, VISUALS defaulting to the stock source (quick_create uses
     * the 'ai' source + an empty prompt the run fills in). Settings stay minimal
     * — editing them is a Phase 5+ feature and mocks ignore them.
     *
     * @return list<array{node: string, locked: bool, settings: array<string, mixed>}>
     */
    public static function defaultNodes(string $template): array
    {
        return array_map(static function (string $node) use ($template): array {
            $settings = [];
            if ($node === 'VISUALS') {
                $settings = $template === self::TEMPLATE_QUICK_CREATE
                    ? ['source' => 'ai', 'prompt' => '']
                    : ['source' => 'stock'];
            }

            return [
                'node' => $node,
                'locked' => in_array($node, self::LOCKED, true),
                'settings' => $settings,
            ];
        }, self::template($template));
    }

    /**
     * Expand a node sequence into the ordered job chain. Steps are 1-based;
     * the chain is what a run executes, one job at a time.
     *
     * Polymorphic input: a bare list of node ids (legacy callers) OR decoded
     * nodes_json entries ({node, settings, …}). Only the entry form carries the
     * VISUALS source, so source-aware expansion (an 'ai' VISUALS → ai_video)
     * requires entries — Engine and CostEstimator pass them; a bare id keeps the
     * default VISUALS → asset_fetch mapping.
     *
     * @param list<string|array<string, mixed>> $nodes
     *
     * @return list<array{step: int, node: string, type: string}>
     */
    public static function expand(array $nodes): array
    {
        $chain = [];
        $step = 1;
        foreach ($nodes as $entry) {
            $node = is_array($entry) ? (string) ($entry['node'] ?? '') : (string) $entry;
            $settings = is_array($entry) && is_array($entry['settings'] ?? null) ? $entry['settings'] : [];
            foreach (self::jobsFor($node, $settings) as $type) {
                $chain[] = ['step' => $step, 'node' => $node, 'type' => $type];
                $step++;
            }
        }

        return $chain;
    }

    /**
     * The job type(s) a node expands to. SOURCE-AWARE for VISUALS: an 'ai' source
     * produces an ai_video job (Quick Create, image-to-video); any other source
     * resolves the visual through asset_fetch (library/stock/reference). Every
     * other node maps 1:1 via NODE_JOBS.
     *
     * @param array<string, mixed> $settings
     *
     * @return list<string>
     */
    private static function jobsFor(string $node, array $settings): array
    {
        if ($node === 'VISUALS' && ($settings['source'] ?? null) === 'ai') {
            return ['ai_video'];
        }

        return self::NODE_JOBS[$node] ?? [];
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
