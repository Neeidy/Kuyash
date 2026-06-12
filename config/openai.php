<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // mock-first: real OpenAI is used ONLY when OPENAI_MOCK is explicitly false
    // AND a key is present (the binding checks both). Anything else → mock.
    'mock' => Config::env('OPENAI_MOCK', true) !== false,

    'api_key' => (string) Config::env('OPENAI_API_KEY', ''),
    'org_id' => (string) Config::env('OPENAI_ORG_ID', ''),
    'model' => (string) Config::env('OPENAI_MODEL', 'gpt-4o-mini'),
    'timeout' => (int) Config::env('OPENAI_TIMEOUT', 30),
    'temperature' => 0.8,
    'endpoint' => 'https://api.openai.com/v1/chat/completions',

    // US cents per 1,000,000 tokens. Prices drift — keep them HERE, never in
    // code; correct them to your account's current rates. (Phase 11 owns the
    // ledger; Phase 5 only records cost_cents on the job row.)
    'prices' => [
        'gpt-4o-mini' => ['in' => 15.0, 'out' => 60.0],
        'gpt-4o' => ['in' => 250.0, 'out' => 1000.0],
    ],
];
