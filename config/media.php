<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // ffmpeg/ffprobe are LOCAL binaries (not a network API). Real by default —
    // the mock providers below feed REAL ffmpeg real inputs (user decision).
    'ffmpeg' => (string) Config::env('FFMPEG_BIN', '/opt/homebrew/bin/ffmpeg'),
    'ffprobe' => (string) Config::env('FFPROBE_BIN', '/opt/homebrew/bin/ffprobe'),
    'ffmpeg_timeout' => (int) Config::env('FFMPEG_TIMEOUT', 900), // matches the assembly job watchdog

    // storage roots for produced media (private; served only via authed routes)
    'render_root' => Config::env('RENDER_STORAGE_ROOT', dirname(__DIR__) . '/storage/renders'),
    'cache_root' => Config::env('CACHE_STORAGE_ROOT', dirname(__DIR__) . '/storage/cache'),
    'asset_root' => Config::env('LIBRARY_STORAGE_ROOT', dirname(__DIR__) . '/storage/assets'),
    'work_root' => Config::env('MEDIA_WORK_ROOT', dirname(__DIR__) . '/storage/work'),

    // render geometry: draft = fast low-res preview for approval; final = full-res
    // after approval (draft-first rendering).
    'draft' => ['width' => 540, 'height' => 960, 'preset' => 'ultrafast'],
    'final' => ['width' => 1080, 'height' => 1920, 'preset' => 'veryfast'],
    'fps' => 24,

    // Burned-in captions need an ffmpeg built with libass/libfreetype (the
    // `subtitles`/`drawtext` filters). When absent (as on the dev box) the SRT is
    // emitted as a sidecar and muxed as a soft mov_text track. Burn-in stays a
    // follow-up; flip this only on a build that has the filter.
    'burn_subtitles' => Config::env('BURN_SUBTITLES', false) === true,

    // TTS — mock-first. Real OpenAI audio/speech ONLY when TTS_MOCK=false + key.
    'tts' => [
        'mock' => Config::env('TTS_MOCK', true) !== false,
        'api_key' => (string) Config::env('OPENAI_API_KEY', ''),
        'model' => (string) Config::env('TTS_MODEL', 'gpt-4o-mini-tts'),
        'voice' => (string) Config::env('TTS_VOICE', 'alloy'),
        'endpoint' => 'https://api.openai.com/v1/audio/speech',
        'timeout' => (int) Config::env('TTS_TIMEOUT', 60),
        'words_per_second' => 2.5, // ~150 wpm narration pace (mock duration model)
        // US cents per 1M characters (drift-prone — correct to your account)
        'price_cents_per_million_chars' => (float) Config::env('TTS_PRICE_PER_M_CHARS', 1500.0),
    ],

    // AI image-to-video (Phase 12) — mock-first. Mock = ffmpeg zoompan clip from
    // the reference photo (real files, $0 spend). The real fal.ai-class provider
    // builds ONLY when VIDEO_MOCK=false + a key is set, and even then is a
    // DOC-GATED stub that throws until .claude/docs/ai-video-notes.md is filled.
    // default/max clip seconds are kept inside the 15–45s compliance band.
    'image_video' => [
        'mock' => Config::env('VIDEO_MOCK', true) !== false,
        'api_key' => (string) Config::env('FAL_API_KEY', ''),
        'model' => (string) Config::env('VIDEO_MODEL', 'image-to-video'),
        'endpoint' => (string) Config::env('VIDEO_ENDPOINT', ''),
        'timeout' => (int) Config::env('VIDEO_TIMEOUT', 120),
        'default_seconds' => (float) Config::env('VIDEO_DEFAULT_SECONDS', 16.0),
        'max_seconds' => (float) Config::env('VIDEO_MAX_SECONDS', 30.0),
    ],

    // Stock visuals — mock-first. Mock = ffmpeg lavfi color clips (real files).
    // Real Pexels ONLY when STOCK_MOCK=false + key.
    'stock' => [
        'mock' => Config::env('STOCK_MOCK', true) !== false,
        'api_key' => (string) Config::env('PEXELS_API_KEY', ''),
        'endpoint' => 'https://api.pexels.com/videos/search',
        'timeout' => (int) Config::env('STOCK_TIMEOUT', 30),
        'quota_units' => 1, // Pexels: 1 request unit (visibility; ledger is Phase 11)
        // hard cap for the streamed clip download (Phase 8: clears the buffering
        // HARD GATE) — an oversized clip aborts mid-transfer, never balloons
        'max_download_bytes' => (int) Config::env('STOCK_MAX_DOWNLOAD_BYTES', 134_217_728), // 128 MiB
    ],
];
