<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // Durable storage driver. 'local' (default) keeps Phase 7 behavior exactly:
    // objects on local disk, served by the authed range-stream route. 'r2' routes
    // durable storage + serving to Cloudflare R2 — OFF until a bucket exists and
    // the enable-time HARD GATE (live-bucket smoke) is cleared.
    'driver' => (string) Config::env('STORAGE_DRIVER', 'local'),

    // Cloudflare R2 (S3-compatible). Real adapter, flag-OFF: a provider is only
    // built when account_id + keys + bucket are all present (else 'r2' is absent
    // and the manager fails safe to 'local'). PRIVATE bucket only.
    'r2' => [
        'account_id' => (string) Config::env('R2_ACCOUNT_ID', ''),
        'access_key_id' => (string) Config::env('R2_ACCESS_KEY_ID', ''),
        'secret_access_key' => (string) Config::env('R2_SECRET_ACCESS_KEY', ''),
        'bucket' => (string) Config::env('R2_BUCKET', ''),
        // optional full-host override; otherwise derived from the account id
        'endpoint' => (string) Config::env('R2_ENDPOINT', ''),
        'region' => 'auto',
        // short presign TTL: a leaked redirect URL expires fast
        'presign_ttl' => (int) Config::env('R2_PRESIGN_TTL', 300),
        // hard cap for streamed downloads (getToLocal) — oversized objects abort
        'max_download_bytes' => (int) Config::env('R2_MAX_DOWNLOAD_BYTES', 536_870_912), // 512 MiB
        'timeout' => (int) Config::env('R2_TIMEOUT', 60),
    ],
];
