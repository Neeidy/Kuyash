<?php

declare(strict_types=1);

namespace Kuyash\Publish;

/**
 * The ONE internal shape a provider hands back (adapter rule: vendors translate
 * their response into THIS; core never sees a vendor body). publish() and
 * status() both return it.
 *
 * Outcome taxonomy — the executor maps each to a post + job decision:
 *   PUBLISHED    → live now: post 'published' (+ external id/url, posted_at).
 *   ACCEPTED     → async: provider took it, confirmation arrives by webhook or
 *                  is polled by the reconciler. Post stays 'publishing'.
 *   REJECTED     → platform rejection (terminal, non-retryable): post 'failed'.
 *   AUTH_FAILED  → account credentials bad (terminal): post 'failed' + the
 *                  account is flagged reauth_needed/degraded.
 *   RATE_LIMITED → transient throttle: the post stays in-flight and the JOB
 *                  returns failed so the queue backs off and retries (other,
 *                  already-published targets are skipped on retry — idempotent).
 * A transport timeout is a PublishProviderException, not an outcome (also transient).
 */
final class PublishOutcome
{
    public const PUBLISHED = 'published';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const AUTH_FAILED = 'auth_failed';
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $status,
        public readonly ?string $externalPostId = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function published(string $externalPostId, string $externalUrl): self
    {
        return new self(self::PUBLISHED, $externalPostId, $externalUrl);
    }

    public static function accepted(string $externalPostId): self
    {
        return new self(self::ACCEPTED, $externalPostId);
    }

    public static function rejected(string $error): self
    {
        return new self(self::REJECTED, null, null, $error);
    }

    public static function authFailed(string $error): self
    {
        return new self(self::AUTH_FAILED, null, null, $error);
    }

    public static function rateLimited(string $error): self
    {
        return new self(self::RATE_LIMITED, null, null, $error);
    }

    /** A terminal outcome never gets re-attempted (published/rejected/auth-failed). */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::PUBLISHED, self::REJECTED, self::AUTH_FAILED], true);
    }
}
