<?php

declare(strict_types=1);

use Kuyash\Core\Config;

return [
    // upload caps stay BELOW post_max_size so users normally see the app-level
    // message, not the blank-$_POST CSRF 403 (see phase plan, risk 2)
    'max_video_bytes' => (int) Config::env('LIBRARY_MAX_VIDEO_BYTES', 200 * 1024 * 1024),
    'max_photo_bytes' => (int) Config::env('LIBRARY_MAX_PHOTO_BYTES', 20 * 1024 * 1024),

    // extension => [mime, kind]; the single source of truth for the allowlist.
    // Video is mp4+mov only in V1 (both ISO BMFF — what phones produce);
    // webm/mkv are a different container family and explicitly deferred.
    'allowed' => [
        'mp4' => ['video/mp4', 'video'],
        'mov' => ['video/quicktime', 'video'],
        'jpg' => ['image/jpeg', 'photo'],
        'jpeg' => ['image/jpeg', 'photo'],
        'png' => ['image/png', 'photo'],
        'webp' => ['image/webp', 'photo'],
    ],

    'storage_root' => Config::env('LIBRARY_STORAGE_ROOT', dirname(__DIR__) . '/storage/assets'),

    'max_tags' => 10,
    'max_tag_length' => 32,
];
