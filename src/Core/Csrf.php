<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Session-bound CSRF token. One token per session; validated with
 * hash_equals on EVERY POST by the blanket gate in public/index.php —
 * routes can never forget the check. Exemption list: none in Phase 2
 * (webhooks add explicit exemptions in their own phase).
 */
final class Csrf
{
    public const FIELD = '_csrf';

    public function token(): string
    {
        $current = $_SESSION[self::FIELD] ?? null;
        if (!is_string($current) || $current === '') {
            $current = bin2hex(random_bytes(32));
            $_SESSION[self::FIELD] = $current;
        }

        return $current;
    }

    /** Hidden input for forms (trusted generated HTML). */
    public function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . View::e($this->token()) . '">';
    }

    public function validate(?string $token): bool
    {
        $known = $_SESSION[self::FIELD] ?? null;

        return is_string($known) && $known !== ''
            && is_string($token) && $token !== ''
            && hash_equals($known, $token);
    }
}
