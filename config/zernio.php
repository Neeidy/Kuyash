<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // DOC-GATED (integration rule + .claude/docs/zernio-notes.md): the real
    // Zernio client is BLOCKED until all 12 documentation items are supplied.
    // mock-first: leave ZERNIO_MOCK=true (default) and the deterministic mock
    // provider runs. Setting ZERNIO_MOCK=false builds the real client, which
    // THROWS "doc-gated" on use — it never makes a live call (no docs, no creds).
    'mock' => Config::env('ZERNIO_MOCK', true) !== false,

    'endpoint' => (string) Config::env('ZERNIO_ENDPOINT', ''),
    'api_key' => (string) Config::env('ZERNIO_API_KEY', ''),
    'timeout' => (int) Config::env('ZERNIO_TIMEOUT', 30),

    // HMAC-SHA256 secret for verifying inbound webhook signatures. Empty =
    // fail-closed: the webhook endpoint rejects every delivery (a missing secret
    // must never silently accept unsigned callbacks). Set a strong value in the
    // local/prod .env to exercise the inbox; the mock signs deliveries with it.
    'webhook_secret' => (string) Config::env('ZERNIO_WEBHOOK_SECRET', ''),
];
