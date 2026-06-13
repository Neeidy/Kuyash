<?php

declare(strict_types=1);

/**
 * Phase 11 — usage ledger + pre-flight cost estimation.
 *
 * estimate_cents : conservative per-job-type spend estimate (cents), mirroring
 *   the cost-model.md figures (stock-mode total < $0.10/video). Used ONLY by the
 *   pre-flight budget gate to refuse over-budget runs BEFORE they start. Real
 *   spend is recorded separately by UsageRecorder from each job's actual cost —
 *   the estimate never becomes a charge.
 * categories  : job type → ledger category (matches the usage_events CHECK enum).
 *   The recorder writes a usage_events row ONLY for a mapped type that returned a
 *   real (non-null) cost. Unmapped types (trend_fetch, assembly, …) are free /
 *   local and never record spend.
 * unit_types  : category → unit denomination (usage_events.unit_type enum). The
 *   COUNT (units) and model are NULL in V1 — surfacing token/char counts through
 *   the executor seam is a Phase 13 follow-up; provider + category + cost are
 *   captured truthfully now.
 *
 * Prices drift: keep the estimates HERE, never hardcoded in code.
 */
return [
    'estimate_cents' => [
        'idea_generation' => 1,
        'script_draft' => 2,
        'caption_generation' => 1,
        'hashtag_generation' => 1,
        'tts' => 5,
        'asset_fetch' => 0,   // Pexels stock: free
        'publish' => 0,       // Zernio: free
        'ai_video' => 700,    // Phase 12 image-to-video placeholder (~$7 high end)
    ],

    'categories' => [
        'idea_generation' => 'ai_text',
        'script_draft' => 'ai_text',
        'caption_generation' => 'ai_text',
        'hashtag_generation' => 'ai_text',
        'tts' => 'tts',
        'asset_fetch' => 'stock',
        'publish' => 'publish',
        'ai_video' => 'ai_video',
    ],

    'unit_types' => [
        'ai_text' => 'tokens',
        'tts' => 'chars',
        'stock' => 'calls',
        'publish' => 'calls',
        'ai_video' => 'seconds',
    ],
];
