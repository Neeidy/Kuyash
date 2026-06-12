<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // mock-first: a real trend provider is used ONLY when TREND_MOCK is
    // explicitly false AND the chosen provider is configured (the binding
    // checks both). Anything else → the deterministic offline mock.
    'mock' => Config::env('TREND_MOCK', true) !== false,

    // which real provider to use when mock is off: 'youtube' | 'google_trends'.
    // youtube needs a key; google_trends uses the public daily-trends endpoint.
    'provider' => (string) Config::env('TREND_PROVIDER', 'youtube'),

    // cache TTL — trends do not need real-time precision (trend-sources.md: 6–24h).
    'cache_ttl_seconds' => (int) Config::env('TREND_CACHE_TTL', 21600), // 6h

    // how many trends to fetch/show per niche
    'limit' => (int) Config::env('TREND_LIMIT', 8),

    // default niche/region for a workspace with no saved trend_config row
    'default_niche' => 'general',
    'default_region' => 'US',

    // daily quota units charged per successful real fetch (Phase 6 records;
    // Phase 11 enforces budgets). YouTube search.list costs 100 units; the
    // Google daily-trends endpoint is uncosted (count 1 call for visibility).
    'quota_units' => [
        'youtube' => 100,
        'google_trends' => 1,
    ],

    'youtube' => [
        'api_key' => (string) Config::env('YOUTUBE_API_KEY', ''),
        'endpoint' => 'https://www.googleapis.com/youtube/v3/search',
        'timeout' => (int) Config::env('TREND_TIMEOUT', 15),
    ],

    'google_trends' => [
        'endpoint' => 'https://trends.google.com/trends/api/dailytrends',
        'timeout' => (int) Config::env('TREND_TIMEOUT', 15),
    ],
];
