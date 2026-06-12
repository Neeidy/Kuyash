<?php

declare(strict_types=1);

namespace Kuyash\Auth;

use Kuyash\Core\Database;

/**
 * Brute-force protection over the login_attempts table.
 * Lock when ≥5 failures for an email OR ≥20 failures from an IP within
 * 15 minutes. Success clears that email's failure rows. Rows older than
 * 24h are deleted opportunistically on failed attempts (no cron).
 * Break-glass: rows are plain SQLite data the operator can delete by hand.
 */
final class LoginThrottle
{
    private const EMAIL_MAX_FAILURES = 5;
    private const IP_MAX_FAILURES = 20;
    private const WINDOW_SECONDS = 900;     // 15 min
    private const RETENTION_SECONDS = 86400; // 24 h

    public function __construct(private readonly Database $db)
    {
    }

    public function isLocked(string $email, string $ip): bool
    {
        $cutoff = $this->iso(time() - self::WINDOW_SECONDS);

        $emailFailures = $this->db->one(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE email = ? AND succeeded = 0 AND attempted_at >= ?',
            [$email, $cutoff],
        );
        if ((int) ($emailFailures['n'] ?? 0) >= self::EMAIL_MAX_FAILURES) {
            return true;
        }

        $ipFailures = $this->db->one(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE ip = ? AND succeeded = 0 AND attempted_at >= ?',
            [$ip, $cutoff],
        );

        return (int) ($ipFailures['n'] ?? 0) >= self::IP_MAX_FAILURES;
    }

    public function record(string $email, string $ip, bool $succeeded): void
    {
        if ($succeeded) {
            // failures are forgiven on success; keep the success row as a trace
            $this->db->run('DELETE FROM login_attempts WHERE email = ? AND succeeded = 0', [$email]);
        } else {
            // opportunistic retention cleanup — no cron in V1
            $this->db->run(
                'DELETE FROM login_attempts WHERE attempted_at < ?',
                [$this->iso(time() - self::RETENTION_SECONDS)],
            );
        }

        $this->db->run(
            'INSERT INTO login_attempts (email, ip, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
            [$email, $ip, $succeeded ? 1 : 0, $this->iso(time())],
        );
    }

    /** ISO-8601 UTC — lexicographic comparison equals chronological. */
    private function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
