<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Minimal session-backed flash messages. Messages are stored as message KEYS
 * (i18n-ready — the TR pass later only adds a dictionary); templates resolve
 * keys through a small PHP map passed in by the controller.
 */
final class Flash
{
    private const KEY = '_flash';

    public function add(string $type, string $messageKey): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'key' => $messageKey];
    }

    /**
     * Return queued messages and clear them.
     *
     * @return list<array{type: string, key: string}>
     */
    public function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return is_array($messages) ? array_values($messages) : [];
    }
}
