<?php

declare(strict_types=1);

namespace Kuyash\Content;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Reading and writing a run's post text — the ONE place an edit is stored
 * (Phase 25).
 *
 * WHERE IT GOES, AND WHY THERE: straight back into the generating job's
 * `result_json`, over the same `captions` / `hashtags` keys. That is the single
 * thing the publish path reads (`Worker::priorResults()` rebuilds it from the
 * jobs table on every tick), so an edit reaches the platform with NO change to
 * the publish path at all — publishing the un-edited text becomes structurally
 * impossible rather than something four separate readers have to remember.
 *
 * The AI's own words are not destroyed: the first edit copies them to
 * `captions_ai` / `hashtags_ai`, which is what "restore what Kuyash wrote"
 * reads and what keeps the record of who wrote what honest. The
 * `compliance_check` job's result is never touched — it stays a truthful record
 * of the text that WAS scored at that point in the chain.
 *
 * TENANCY: every statement carries `workspace_id`. There is no unscoped read or
 * write in this class.
 */
final class ContentRevision
{
    public const CAPTION_JOB = 'caption_generation';
    public const HASHTAG_JOB = 'hashtag_generation';

    /** Longest body we will store, per platform. Matches the generator's own clamp. */
    public const MAX_CAPTION = 2200;
    public const MAX_TAGS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * The run's current text, plus everything the editor needs to decide whether
     * it may be changed.
     *
     * @return array{captions: array<string, string>, hashtags: list<string>,
     *               captions_ai: array<string, string>, hashtags_ai: list<string>,
     *               edited: bool, edit: array<string, mixed>|null, hash: string,
     *               editable: bool, locked_reason: string|null,
     *               ai_label_required: bool, script: string}|null
     */
    public function forRun(WorkspaceContext $ctx, int $runId): ?array
    {
        $wsId = $ctx->id();
        $rows = $this->db->all(
            'SELECT type, result_json FROM jobs
             WHERE run_id = ? AND workspace_id = ? AND type IN (?, ?, ?, ?)',
            [$runId, $wsId, self::CAPTION_JOB, self::HASHTAG_JOB, 'compliance_check', 'script_draft'],
        );
        if ($rows === []) {
            return null;
        }

        $by = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['result_json'], true);
            $by[(string) $row['type']] = is_array($decoded) ? $decoded : [];
        }
        $caption = $by[self::CAPTION_JOB] ?? null;
        if ($caption === null) {
            return null;
        }
        $hashtag = $by[self::HASHTAG_JOB] ?? [];

        $captions = self::stringMap($caption['captions'] ?? []);
        $hashtags = self::tagList($hashtag['hashtags'] ?? []);
        $edit = is_array($caption['edit'] ?? null) ? $caption['edit'] : null;
        $lock = $this->lockReason($wsId, $runId);

        return [
            'captions' => $captions,
            'hashtags' => $hashtags,
            'captions_ai' => self::stringMap($caption['captions_ai'] ?? []),
            'hashtags_ai' => self::tagList($hashtag['hashtags_ai'] ?? []),
            'edited' => $edit !== null,
            'edit' => $edit,
            'hash' => self::hash($captions, $hashtags),
            'editable' => $lock === null,
            'locked_reason' => $lock,
            'ai_label_required' => (bool) (($by['compliance_check']['ai_label_required'] ?? false)),
            'script' => (string) ($by['script_draft']['script'] ?? ''),
        ];
    }

    /**
     * Why this run's text cannot be changed right now, or null when it can.
     *
     * The window is deliberately the same one Engine::cancelRun uses: anything
     * up to the moment the publish job is actually claimed. Before that nothing
     * has been sent, so changing the text really does change what goes out.
     * After it, the platform may already have the post and an edit here would be
     * a promise the system cannot keep.
     */
    public function lockReason(int $workspaceId, int $runId): ?string
    {
        $run = $this->db->one(
            'SELECT status FROM runs WHERE id = ? AND workspace_id = ?',
            [$runId, $workspaceId],
        );
        if ($run === null) {
            return 'not_found';
        }
        // Two different endings, said differently. Telling the operator a
        // CANCELLED run was "already published" would be a false publication
        // claim on the one screen whose job is to be exact about what went out.
        if ((string) $run['status'] === 'completed') {
            return 'run_over';
        }
        if (in_array((string) $run['status'], ['failed', 'cancelled'], true)) {
            return 'run_stopped';
        }

        // Already gone, or going: nothing typed here could change what the
        // platform has.
        $inFlight = $this->db->one(
            "SELECT COUNT(*) AS n FROM jobs
             WHERE run_id = ? AND workspace_id = ? AND type = 'publish'
               AND status IN ('processing', 'published')",
            [$runId, $workspaceId],
        );
        if ((int) ($inFlight['n'] ?? 0) > 0) {
            return 'publishing';
        }
        $posted = $this->db->one(
            "SELECT COUNT(*) AS n FROM posts
             WHERE run_id = ? AND workspace_id = ? AND status IN ('publishing', 'published')",
            [$runId, $workspaceId],
        );
        if ((int) ($posted['n'] ?? 0) > 0) {
            return 'publishing';
        }

        // NOT YET, either. Editing is only open in the window where the text is
        // FINISHED and the publish has not fired: the run waiting at its publish
        // approval, or an approved publish still sitting behind its gate.
        //
        // Anything earlier is a trap rather than a feature. Mid-pipeline, later
        // steps have not written their results yet — an edit would hash over
        // half-built content, the generator would then overwrite what was typed,
        // and publish would refuse the mismatch as tampering. The operator would
        // lose their words AND be accused of changing them.
        //
        // `final_render` counts as open too. It is the step between the approval
        // and the birth of the publish job, it renders the VIDEO and never reads
        // the text, and nothing has reached the platform while it runs — so an
        // edit here is still genuinely safe, and the publish job that follows
        // rebuilds `$prior` from the jobs table and picks the new text up. Left
        // out, the operator would be told "you approved it, now you may not
        // change it for a few minutes", which nothing on the screen explains.
        $open = $this->db->one(
            "SELECT COUNT(*) AS n FROM jobs
             WHERE run_id = ? AND workspace_id = ?
               AND ((type = 'render_review' AND status = 'awaiting_approval')
                    OR (type = 'final_render' AND status IN ('queued', 'processing'))
                    OR (type = 'publish' AND status = 'queued'))",
            [$runId, $workspaceId],
        );
        if ((int) ($open['n'] ?? 0) === 0) {
            return 'not_ready';
        }

        // Defence in depth: never let a save land on a step that has not run.
        // `result_json IS NOT NULL` is the worker's own definition of "this step
        // produced output" (Worker::priorResults), and writing to a job that has
        // not finished would both break that invariant and be silently discarded
        // when the job does finish.
        $unfinished = $this->db->one(
            'SELECT COUNT(*) AS n FROM jobs
             WHERE run_id = ? AND workspace_id = ? AND type IN (?, ?) AND result_json IS NULL',
            [$runId, $workspaceId, self::CAPTION_JOB, self::HASHTAG_JOB],
        );
        if ((int) ($unfinished['n'] ?? 0) > 0) {
            return 'not_ready';
        }

        return null;
    }

    /**
     * Store an edit. Callers MUST have run it past ContentGate first — this
     * method records the verdict it is handed, it does not judge.
     *
     * @param array<string, string>     $captions
     * @param list<string>              $hashtags
     * @param array<string, mixed>      $verdict  ContentGate::judge() output
     *
     * @return bool false when the text moved under the caller (another tab, or
     *              the run left its editable window between check and write)
     */
    public function save(
        WorkspaceContext $ctx,
        int $runId,
        array $captions,
        array $hashtags,
        string $expectedHash,
        int $userId,
        string $userEmail,
        array $verdict,
        string $now,
    ): bool {
        $wsId = $ctx->id();

        // IMMEDIATE, not the default deferred BEGIN: this closure reads the two
        // job rows before it writes them, and the worker commits constantly —
        // with a read snapshot the upgrade fails outright (BUSY_SNAPSHOT), which
        // no busy_timeout covers, and the operator gets a 500 with their text
        // gone instead of the honest "reload and try again" below.
        return $this->db->immediateTransaction(function () use (
            $wsId, $runId, $captions, $hashtags, $expectedHash, $userId, $userEmail, $verdict, $now
        ): bool {
            $capRow = $this->db->one(
                'SELECT id, result_json FROM jobs
                 WHERE run_id = ? AND workspace_id = ? AND type = ? AND result_json IS NOT NULL',
                [$runId, $wsId, self::CAPTION_JOB],
            );
            if ($capRow === null) {
                return false;
            }
            $cap = json_decode((string) $capRow['result_json'], true);
            $cap = is_array($cap) ? $cap : [];

            // `result_json IS NOT NULL` — a row that has not produced output yet is
            // not a place to store an edit (see lockReason).
            $tagRow = $this->db->one(
                'SELECT id, result_json FROM jobs
                 WHERE run_id = ? AND workspace_id = ? AND type = ? AND result_json IS NOT NULL',
                [$runId, $wsId, self::HASHTAG_JOB],
            );
            $tag = $tagRow === null ? [] : json_decode((string) $tagRow['result_json'], true);
            $tag = is_array($tag) ? $tag : [];

            // Optimistic concurrency without a schema change: the form carried a
            // hash of what it loaded. If the stored text no longer matches, some
            // other tab (or another person) saved in between, and overwriting
            // them silently would lose their work.
            $currentHash = self::hash(
                self::stringMap($cap['captions'] ?? []),
                self::tagList($tag['hashtags'] ?? []),
            );
            if ($currentHash !== $expectedHash) {
                return false;
            }
            if ($this->lockReason($wsId, $runId) !== null) {
                return false;
            }

            // Preserve what the AI wrote — once, on the first edit only, so a
            // second edit does not overwrite the original with the first edit.
            if (!array_key_exists('captions_ai', $cap)) {
                $cap['captions_ai'] = self::stringMap($cap['captions'] ?? []);
            }
            if ($tagRow !== null && !array_key_exists('hashtags_ai', $tag)) {
                $tag['hashtags_ai'] = self::tagList($tag['hashtags'] ?? []);
            }

            $cap['captions'] = $captions;

            // Restoring the AI's own words is not an edit any more. Keeping the
            // "you edited it" chip over text byte-identical to what Kuyash wrote
            // would be a small lie on a screen whose whole job is to be exact
            // about who wrote what.
            $isRestore = ($cap['captions_ai'] ?? null) === $captions
                && ($tag['hashtags_ai'] ?? null) === array_values($hashtags);

            $editBlock = [
                'by' => $userId,
                'by_email' => $userEmail,
                'at' => $now,
                // what publish re-checks: the text it is about to send must be
                // exactly the text this edit was judged on. Hash ONLY what this
                // transaction actually stores — a hash covering tags that were
                // never written would fail the publish guard and record the
                // operator as having tampered with text they never touched.
                'hash' => self::hash($captions, $tagRow === null ? [] : $hashtags),
                'verdict' => $verdict,
            ];
            if ($isRestore) {
                unset($cap['edit']);
            } else {
                $cap['edit'] = $editBlock;
            }

            // COMPARE-AND-SWAP, not read-then-write. In WAL a transaction that
            // reads first and writes later cannot upgrade once another
            // connection has committed — and busy_timeout does not cover that
            // case, so it surfaces as "database is locked" and the operator's
            // text is gone. Matching the OLD json in the WHERE makes the write
            // itself the check: a lost race returns false and the caller says
            // "reload and try again" instead of throwing a 500.
            $swapped = $this->db->run(
                'UPDATE jobs SET result_json = ?
                 WHERE id = ? AND workspace_id = ? AND type = ? AND result_json = ?',
                [self::json($cap), (int) $capRow['id'], $wsId, self::CAPTION_JOB, (string) $capRow['result_json']],
            )->rowCount();
            if ($swapped === 0) {
                return false;
            }

            if ($tagRow === null) {
                // Defensive: every workflow template includes HASHTAGS, so this
                // is unreachable today. If it ever happens, tags the operator
                // typed would silently vanish — refuse rather than store half.
                return $hashtags === [];
            }

            $tag['hashtags'] = array_values($hashtags);
            if ($isRestore) {
                unset($tag['edit']);
            } else {
                $tag['edit'] = $editBlock;
            }
            // Checked exactly like the caption swap above. Unchecked, a lost race
            // left the captions edited and the tags stale while reporting success
            // — and publish would then blame the operator for the mismatch.
            $swappedTags = $this->db->run(
                'UPDATE jobs SET result_json = ?
                 WHERE id = ? AND workspace_id = ? AND type = ? AND result_json = ?',
                [self::json($tag), (int) $tagRow['id'], $wsId, self::HASHTAG_JOB, (string) $tagRow['result_json']],
            )->rowCount();

            return $swappedTags > 0;
        });
    }

    /**
     * The content hash publish re-checks against. Order-stable and
     * whitespace-exact, because the point is to detect ANY change that did not
     * come through the gate.
     *
     * @param array<string, string> $captions
     * @param list<string>          $hashtags
     */
    public static function hash(array $captions, array $hashtags): string
    {
        ksort($captions);

        return hash('sha256', self::json(['c' => $captions, 'h' => array_values($hashtags)]));
    }

    /**
     * Clean and clamp operator input. Nothing downstream re-sanitizes a caption,
     * so this is the only place it happens for edited text.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, string>
     */
    public static function cleanCaptions(array $raw): array
    {
        $out = [];
        foreach ($raw as $platform => $body) {
            if (!is_string($platform) || !is_string($body)) {
                continue;
            }
            // Bounded BEFORE the per-line work. The clamp below is applied to the
            // result, so without this an 8 MB pasted body would be split into
            // millions of lines and sanitized line by line first — real work for
            // text that was always going to be cut to MAX_CAPTION.
            $body = mb_substr($body, 0, self::MAX_CAPTION * 4);
            // Newlines are meaningful in a caption, so they survive; Sanitizer
            // would collapse them, and it is used here per LINE for that reason.
            $lines = array_map(
                static fn (string $line): string => Sanitizer::clean($line, self::MAX_CAPTION),
                preg_split('/\R/', $body) ?: [],
            );
            $out[$platform] = mb_substr(rtrim(implode("\n", $lines)), 0, self::MAX_CAPTION);
        }

        return $out;
    }

    /**
     * Split a free-text tag field into clean tags: one leading '#', no spaces,
     * deduped, capped.
     *
     * @return list<string>
     */
    public static function cleanHashtags(string $raw): array
    {
        $out = [];
        // Same bound, same reason: the field is capped at MAX_TAGS anyway, and
        // an unbounded split here was the one place a single POST could buy
        // millions of regex calls.
        $raw = mb_substr($raw, 0, 4000);
        foreach (preg_split('/[\s,]+/u', $raw) ?: [] as $piece) {
            $tag = Sanitizer::clean(ltrim((string) $piece, '#'), 40);
            $tag = preg_replace('/[^\p{L}\p{N}_]/u', '', $tag) ?? '';
            if ($tag === '') {
                continue;
            }
            $out['#' . $tag] = true;
        }

        return array_slice(array_keys($out), 0, self::MAX_TAGS);
    }

    /** @param mixed $raw @return array<string, string> */
    private static function stringMap(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** @param mixed $raw @return list<string> */
    private static function tagList(mixed $raw): array
    {
        return array_values(array_filter((array) $raw, is_string(...)));
    }

    /** @param array<mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
