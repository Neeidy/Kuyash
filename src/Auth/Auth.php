<?php

declare(strict_types=1);

namespace Kuyash\Auth;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Authentication service: Argon2id verification, throttled attempts,
 * session fixation protection (id regenerated on login), logout.
 * Session id calls are guarded by session_status() so the service stays
 * testable from CLI (where no real session is ever active).
 */
final class Auth
{
    private const SESSION_USER = 'auth_user_id';

    /**
     * Valid Argon2id hash of a random throwaway string. When the email is
     * unknown we verify against this instead of returning early, so response
     * timing does not reveal whether an account exists.
     */
    private const DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$bHZyb25HZ2ppT1JnZ1VUZA$FeMj+cc2QF4KMLWIqwiDEqg5ZGs3mwD9BVYDti68hnI';

    /** @var array<string, mixed>|null|false false = not looked up yet */
    private array|null|false $cachedUser = false;

    public function __construct(
        private readonly Database $db,
        private readonly LoginThrottle $throttle,
        private readonly WorkspaceContext $workspace,
    ) {
    }

    public function attempt(string $email, string $password, string $ip): LoginResult
    {
        // normalize at the choke point (security audit S1): users.email is
        // NOCASE but login_attempts is not — without this, varying the case
        // would give an attacker a fresh 5-attempt throttle bucket per variant
        $email = strtolower(trim($email));

        if ($this->throttle->isLocked($email, $ip)) {
            return LoginResult::Locked;
        }

        $user = $this->db->one(
            'SELECT id, email, password_hash FROM users WHERE email = ?',
            [$email],
        );

        $verified = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH)
            && $user !== null;

        // a user without any workspace membership cannot operate — fail closed
        $membership = $verified ? $this->workspace->resolveForUser((int) $user['id']) : null;
        $ok = $verified && $membership !== null;

        $this->throttle->record($email, $ip, $ok);

        if (!$ok) {
            return LoginResult::Invalid;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // fixation protection
        }

        $_SESSION[self::SESSION_USER] = (int) $user['id'];
        $this->workspace->set($membership['id']);
        $this->cachedUser = false;

        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->db->run(
                'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
                [password_hash($password, PASSWORD_ARGON2ID), gmdate('Y-m-d\TH:i:s\Z'), (int) $user['id']],
            );
        }

        return LoginResult::Ok;
    }

    /**
     * The logged-in user, or null. Short-circuits before any DB access when
     * the session carries no user id (so public routes never touch the DB).
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->cachedUser === false) {
            $id = $_SESSION[self::SESSION_USER] ?? null;
            $this->cachedUser = is_int($id)
                ? $this->db->one('SELECT id, email, name, created_at FROM users WHERE id = ?', [$id])
                : null;
        }

        return $this->cachedUser;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** Clear the session and rotate the id so the old cookie is dead. */
    public function logout(): void
    {
        $_SESSION = [];
        $this->cachedUser = false;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
