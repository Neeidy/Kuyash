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

    /**
     * Queue a message KEY (not text) plus any placeholder values it needs.
     * Params are resolved at render time by Messages::resolveFlashes, so a flash
     * still speaks the reader's language — which is why the VALUES are stored
     * rather than an already-formatted sentence.
     *
     * @param array<string, scalar|null> $params
     */
    public function add(string $type, string $messageKey, array $params = []): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'key' => $messageKey, 'params' => $params];
    }

    /**
     * Return queued messages and clear them.
     *
     * @return list<array{type: string, key: string, params?: array<string, scalar|null>}>
     */
    public function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return is_array($messages) ? array_values($messages) : [];
    }
}
