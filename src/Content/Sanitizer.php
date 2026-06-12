<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Input hygiene for text that flows from upstream jobs into prompts and mock
 * output. Even though Phase 5's upstream (trend) is mock, the rule applies
 * from day one: strip control characters and clamp length so a future real
 * trend string can never carry prompt-injection payloads or unbounded text
 * into an OpenAI request.
 */
final class Sanitizer
{
    /** Strip control chars (keep normal whitespace), collapse runs, clamp length. */
    public static function clean(string $value, int $maxLength = 280): string
    {
        // drop C0/C1 control chars except tab/newline, which we fold to spaces
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}
