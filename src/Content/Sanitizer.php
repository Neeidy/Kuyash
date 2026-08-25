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
        // A /u pattern returns null on malformed UTF-8, and `?? ''` turned one
        // bad byte in a pasted line into a silently EMPTIED line — which then
        // hit the empty-caption block and told the operator "write something
        // for Instagram" about text they had very much written. Fall back to
        // the byte-wise pass so the words survive and the encoding damage is
        // limited to the offending characters.
        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = $stripped ?? (preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value);
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        $value = $collapsed ?? (preg_replace('/\s+/', ' ', $value) ?? $value);
        $value = trim($value);

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}
