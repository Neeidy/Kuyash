<?php

declare(strict_types=1);

/**
 * Phase 25 — per-platform text limits, used ONLY to warn an operator while they
 * edit a post's text.
 *
 * ⚠ UNVERIFIED — DO NOT TURN THESE INTO A BLOCK.
 * These are platform PRODUCT limits (what the app or the website accepts), not
 * anything Kuyash has confirmed against a documented API contract. The
 * integrations rule is explicit: never hallucinate external API behaviour. So
 * until each number is checked against the platform's own current documentation
 * — and re-checked, because they move — the editor may only SAY "this looks too
 * long"; it must never refuse to save, and it must never stop a publish.
 *
 * Turning any of this into a hard block is a deliberate, separate decision that
 * needs the number verified first. `ContentGate` is written so that flipping it
 * is one branch, not a rewrite.
 *
 * (An EMPTY caption is a different thing and IS blocked — that is missing
 * content, not a length limit. See ContentGate.)
 *
 * caption_chars : characters of the ASSEMBLED text — the body, plus the AI
 *   disclosure line where one is required, plus the hashtag block. That is what
 *   actually reaches the platform, so it is what gets measured.
 * hashtags      : how many tags the platform tolerates before it reads as spam.
 */
return [
    'limits' => [
        // Instagram: caption limit widely documented as 2200 characters, 30 hashtags.
        'instagram' => ['caption_chars' => 2200, 'hashtags' => 30],
        // TikTok: caption length has changed more than once; 2200 is the conservative
        // figure. Verify before relying on it for anything but a warning.
        'tiktok' => ['caption_chars' => 2200, 'hashtags' => 30],
        // YouTube: the description field, not the title (the title is derived from
        // the caption's first line and capped at 100 by the adapter).
        'youtube' => ['caption_chars' => 5000, 'hashtags' => 15],
    ],

    /**
     * Fraction of a limit at which the editor starts warning, before the limit
     * is actually exceeded — so an operator has room to trim rather than being
     * told only once it is too late.
     */
    'warn_at' => 0.9,
];
