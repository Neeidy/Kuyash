<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Text that was typed but could not be stored — held for exactly one page load
 * (Phase 25).
 *
 * A save can be refused for good reasons: compliance blocked it, another tab
 * saved first, the throttle fired. Every one of those ends in a redirect (POST →
 * redirect → GET, so a reload cannot re-post), and a redirect re-renders the
 * editor from what is STORED. Without this, being told "that text cannot be
 * saved" also silently destroyed it — all three bodies and the tags, not just
 * the one the gate objected to, with no undo. On the screen whose entire purpose
 * is letting a person write the post, that is the worst possible response to
 * "you are close, change one thing".
 *
 * One-shot, like a flash message, and scoped to the run it came from: a draft
 * must never surface on a different post.
 */
final class DraftStash
{
    private const KEY = '_content_draft';

    /**
     * @param array<string, string> $captions
     * @param list<string>          $hashtags
     */
    public function keep(int $workspaceId, int $runId, array $captions, array $hashtags): void
    {
        $_SESSION[self::KEY] = [
            'ws' => $workspaceId,
            'run' => $runId,
            'captions' => $captions,
            'hashtags' => array_values($hashtags),
        ];
    }

    /**
     * The held draft for this run, and forget it. Null when there is none, or
     * when the one held belongs to another run or another workspace.
     *
     * @return array{captions: array<string, string>, hashtags: list<string>}|null
     */
    public function take(int $workspaceId, int $runId): ?array
    {
        $held = $_SESSION[self::KEY] ?? null;
        if (!is_array($held)
            || (int) ($held['ws'] ?? 0) !== $workspaceId
            || (int) ($held['run'] ?? 0) !== $runId
        ) {
            return null;
        }
        unset($_SESSION[self::KEY]);

        $captions = [];
        foreach ((array) ($held['captions'] ?? []) as $platform => $body) {
            if (is_string($platform) && is_string($body)) {
                $captions[$platform] = $body;
            }
        }

        return [
            'captions' => $captions,
            'hashtags' => array_values(array_filter((array) ($held['hashtags'] ?? []), is_string(...))),
        ];
    }
}
