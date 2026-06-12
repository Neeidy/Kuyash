<?php

declare(strict_types=1);

namespace Kuyash\Core;

use RuntimeException;

/**
 * Hardened native PHP file sessions (no DB-backed handler — overbuild for a
 * single-server tool). Cookies are managed by PHP's session API, never via
 * Response headers (its header map cannot carry repeated Set-Cookie lines).
 *
 * cookieParams() is pure so the hardening flags are unit-testable without
 * calling session_start() in CLI.
 */
final class Session
{
    public function __construct(
        private readonly string $savePath,
        private readonly string $name = 'kuyash_session',
        private readonly int $lifetime = 7200,
        private readonly bool $secure = true,
    ) {
    }

    /**
     * The exact settings start() applies — pure, no side effects.
     *
     * @return array<string, mixed>
     */
    public function cookieParams(): array
    {
        return [
            'name' => $this->name,
            // 0 = session cookie (dies with the browser); the server-side
            // idle limit is gc_maxlifetime
            'cookie_lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->secure,
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'gc_maxlifetime' => $this->lifetime,
        ];
    }

    /** Configure and start the session. Call once, before any output. */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (!is_dir($this->savePath) && !mkdir($this->savePath, 0700, true) && !is_dir($this->savePath)) {
            throw new RuntimeException("Session save path cannot be created: {$this->savePath}");
        }

        $p = $this->cookieParams();

        session_save_path($this->savePath);
        session_name($p['name']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $p['gc_maxlifetime']);
        session_set_cookie_params([
            'lifetime' => $p['cookie_lifetime'],
            'path' => $p['path'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);

        session_start();
    }
}
