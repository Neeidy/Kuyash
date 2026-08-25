<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Core\I18n;

/**
 * The one definition of how the AI disclosure is attached to a post's text
 * (Phase 25).
 *
 * WHY THIS EXISTS: Instagram has no native "made with AI" field, so the line is
 * appended to the caption — but only at PUBLISH time, never stored in the
 * caption itself. That is what makes the disclosure impossible to edit away: an
 * operator edits the BODY, and this is composed around it afterwards.
 *
 * Phase 25 lets a human edit that body, which means the editor has to show a
 * character count for what will ACTUALLY be sent — body plus this line plus the
 * hashtags. If the editor measured one composition and the publisher performed a
 * different one, the count would quietly lie. So both call this.
 */
final class Disclosure
{
    /**
     * The localized line, in the workspace owner's locale — the best proxy the
     * system has for the language the content is written in.
     */
    public static function line(Database $db, int $workspaceId): string
    {
        $row = $db->one(
            "SELECT u.locale FROM users u
             JOIN workspace_users wu ON wu.user_id = u.id
             WHERE wu.workspace_id = ?
             ORDER BY (wu.role = 'owner') DESC, wu.id ASC LIMIT 1",
            [$workspaceId],
        );
        $locale = (string) ($row['locale'] ?? 'en');

        $prev = I18n::locale();
        I18n::setLocale($locale);
        $line = I18n::t('compliance.ai_disclosure');
        I18n::setLocale($prev);

        return $line;
    }

    /**
     * Body + disclosure, on its own final line.
     *
     * DEDUPED (Phase 25): an operator who types the disclosure into the body
     * themselves would otherwise see it twice. If any line of the body already
     * IS the disclosure, the body is returned unchanged — the requirement is
     * that the line is present, not that this method wrote it.
     */
    public static function compose(string $caption, string $line): string
    {
        $caption = rtrim($caption);
        if ($caption === '') {
            return $line;
        }
        if (self::present($caption, $line)) {
            return $caption;
        }

        return $caption . "\n" . $line;
    }

    /** Does the body already carry the disclosure as a line of its own? */
    public static function present(string $caption, string $line): bool
    {
        $needle = mb_strtolower(trim($line));
        if ($needle === '') {
            return false;
        }
        foreach (preg_split('/\R/', $caption) ?: [] as $bodyLine) {
            if (mb_strtolower(trim((string) $bodyLine)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
