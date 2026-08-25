<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Compliance\CompliancePolicy;
use Kuyash\Compliance\ContentGate;
use Kuyash\Content\ContentRevision;
use Kuyash\Content\DraftStash;
use Kuyash\Core\Flash;
use Kuyash\Core\RateLimiter;
use Kuyash\Core\Response;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\Disclosure;
use Kuyash\Core\Database;
use Kuyash\Workflow\EventLog;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Editing a post's text before it goes out (Phase 25).
 *
 * The AI writes a first draft; this is where a person makes it theirs. Two
 * things are true at once and neither is allowed to give way:
 *
 *   • the edit is what publishes — it is written over the same key the publish
 *     path already reads, so there is no way for the generated text to go out
 *     instead;
 *   • the edit still has to pass compliance — the canonical chain scores the
 *     text BEFORE the approval gate an operator edits at, so an unchecked edit
 *     would otherwise be a way around it. ContentGate re-runs the same scorer
 *     with the same thresholds here, and publish refuses text whose hash does
 *     not match what was judged.
 *
 * The mandatory AI disclosure is never in scope for an edit: it is composed at
 * publish time, around the body, so there is nothing here that can remove it.
 */
final class ContentController
{
    private const ISO = 'Y-m-d\TH:i:s\Z';
    private const RATE_BUCKET = 'content_edit';

    public function __construct(
        private readonly ContentRevision $revisions,
        private readonly ContentGate $gate,
        private readonly AccountRepository $accounts,
        private readonly Database $db,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Flash $flash,
        private readonly EventLog $events,
        private readonly \Kuyash\Workspace\WorkspaceSettings $settings,
        private readonly DraftStash $drafts,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function save(array $params = [], bool $restoring = false): Response
    {
        $runId = $params['id'] ?? '';
        if (!ctype_digit($runId)) {
            return $this->back(null, 'error', 'content.run_not_found');
        }
        if ($this->throttled()) {
            // Held before anything else runs: being told "too fast" must not also
            // be the reason a paragraph disappears. Both cleaners are pure, so
            // this costs no query on a request we are already refusing.
            $this->drafts->keep(
                $this->workspace->id(),
                (int) $runId,
                ContentRevision::cleanCaptions(is_array($_POST['caption'] ?? null) ? $_POST['caption'] : []),
                ContentRevision::cleanHashtags(is_string($_POST['hashtags'] ?? null) ? $_POST['hashtags'] : ''),
            );

            return $this->back((int) $runId, 'error', 'rate.limited');
        }

        $wsId = $this->workspace->id();
        $current = $this->revisions->forRun($this->workspace, (int) $runId);
        if ($current === null) {
            return $this->back(null, 'error', 'content.run_not_found');
        }
        if (!$current['editable']) {
            return $this->back((int) $runId, 'error', 'content.locked_' . ($current['locked_reason'] ?? 'publishing'));
        }

        // Only the platforms the generator produced are editable — a hand-posted
        // extra key would otherwise invent a caption for a platform the run has no
        // business publishing to.
        $posted = is_array($_POST['caption'] ?? null) ? $_POST['caption'] : [];
        $submitted = [];
        foreach (array_keys($current['captions']) as $platform) {
            // is_string BEFORE the cast: a nested array (caption[ig][]=x) would
            // otherwise hit "Array to string conversion", which this app turns
            // into a fatal — a 500 that also loses whatever was typed.
            $value = $posted[$platform] ?? null;
            $submitted[$platform] = is_string($value) ? $value : $current['captions'][$platform];
        }
        $captions = ContentRevision::cleanCaptions($submitted);
        $rawTags = $_POST['hashtags'] ?? '';
        $hashtags = ContentRevision::cleanHashtags(is_string($rawTags) ? $rawTags : '');

        $connected = array_values(array_unique(array_map(
            static fn (array $a): string => (string) $a['platform'],
            $this->accounts->connectedFor($wsId),
        )));

        $verdict = $this->gate->judge(
            $wsId,
            (int) $runId,
            $captions,
            $hashtags,
            $connected,
            $current['script'],
            // the EFFECTIVE line, matching what publish will actually append
            ($current['ai_label_required'] && $this->settings->aiDiscloses($wsId, 'instagram'))
                ? Disclosure::line($this->db, $wsId)
                : '',
        );

        if ($verdict['status'] === CompliancePolicy::BLOCK) {
            // Nothing is stored: the previous text stands, and the operator is
            // told which rule stopped it rather than being left guessing.
            $first = $verdict['reasons'][0] ?? ['key' => 'content.edit_blocked', 'params' => []];
            $this->events->record($wsId, 'warn', 'compliance', 'content.edit_blocked', [
                'run' => (int) $runId,
                // the sentence, not the internal key — this is read by a person
                'reason' => self::reasonText((string) $first['key'], (array) $first['params']),
                'slop' => $verdict['slop']['score'] ?? null,
                'policy' => (string) $verdict['policy'],
            ], (int) $runId);

            // …and the words themselves are handed back, so "change one thing"
            // does not cost the operator everything they wrote.
            $this->drafts->keep($wsId, (int) $runId, $captions, $hashtags);

            return $this->back((int) $runId, 'error', (string) $first['key'], self::humanize((array) $first['params']));
        }

        $now = gmdate(self::ISO);
        $saved = $this->store(
            (int) $runId,
            $captions,
            $hashtags,
            is_string($_POST['content_hash'] ?? null) ? $_POST['content_hash'] : '',
            $verdict,
            $now,
        );
        if (!$saved) {
            // Either another tab saved first, or the run left its editable
            // window between the check above and the write. The typed text is
            // held either way — the operator has to decide what to keep, and
            // cannot do that if it is gone.
            $this->drafts->keep($wsId, (int) $runId, $captions, $hashtags);

            return $this->back((int) $runId, 'error', 'content.stale');
        }

        // An edit made AFTER the approval keeps that approval — it was a real
        // decision at a real time — but it is recorded loudly, because the text
        // the person approved is not the text that will go out.
        // Restoring is not editing. ContentRevision::save() already drops the
        // edit block when the text is byte-identical to the AI's own, so logging
        // it as "a person changed the text" would contradict the record it just
        // wrote — and on an approved run it would raise a warn for a change that
        // put things BACK.
        $afterApproval = !$restoring && $this->approvedAlready($wsId, (int) $runId);
        $this->events->record(
            $wsId,
            $afterApproval ? 'warn' : 'info',
            'transition',
            $restoring ? 'content.restored' : ($afterApproval ? 'content.edited_after_approval' : 'content.edited'),
            ['run' => (int) $runId, 'user' => $this->userEmail()],
            (int) $runId,
        );

        // Every compliance decision gets an audit entry, including the ones that
        // pass — the rule does not say "log the failures". Without this a clean
        // edit left no record of the score or the policy it was judged under,
        // and the verdict kept in the job row is overwritten by the next edit.
        $this->events->record($wsId, 'info', 'compliance', 'content.edit_checked', [
            'run' => (int) $runId,
            'result' => (string) $verdict['status'],
            'slop' => $verdict['slop']['score'] ?? null,
            'policy' => (string) $verdict['policy'],
        ], (int) $runId);

        if ($verdict['status'] === CompliancePolicy::WARN) {
            // Its OWN key. Reusing `compliance.warned` recorded every warning —
            // including a length one — as "similarity too high", and left that
            // message's {slop} placeholder unfilled because no score was passed.
            // A compliance log has to state the finding that actually happened.
            $first = $verdict['warnings'][0] ?? ['key' => 'content.edit_blocked', 'params' => []];
            $this->events->record($wsId, 'warn', 'compliance', 'content.edit_warned', [
                'run' => (int) $runId,
                'reason' => self::reasonText((string) $first['key'], (array) $first['params']),
                'slop' => $verdict['slop']['score'] ?? null,
            ], (int) $runId);

            return $this->back((int) $runId, 'success', 'content.saved_with_warning');
        }

        return $this->back((int) $runId, 'success', 'content.saved');
    }

    /**
     * Put back exactly what Kuyash wrote. Goes through the same gate — restoring
     * is still a change to what will publish.
     *
     * @param array<string, string> $params
     */
    public function restore(array $params = []): Response
    {
        $runId = $params['id'] ?? '';
        if (!ctype_digit($runId)) {
            return $this->back(null, 'error', 'content.run_not_found');
        }
        // Checked BEFORE the reads below — delegating to save() would throttle
        // too, but only after this method had already spent four queries on a
        // request that was going to be refused. save() sees a bucket that is
        // already over its limit and refuses identically, so a restore still
        // counts once.
        if ($this->throttled()) {
            return $this->back((int) $runId, 'error', 'rate.limited');
        }
        $current = $this->revisions->forRun($this->workspace, (int) $runId);
        if ($current === null) {
            return $this->back(null, 'error', 'content.run_not_found');
        }
        if (!$current['edited'] || $current['captions_ai'] === []) {
            return $this->back((int) $runId, 'error', 'content.nothing_to_restore');
        }

        $_POST['caption'] = $current['captions_ai'];
        $_POST['hashtags'] = implode(' ', $current['hashtags_ai']);
        $_POST['content_hash'] = $current['hash'];

        return $this->save($params, true);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The write, with a bounded retry and no way to reach the error page.
     *
     * The worker commits constantly; a save that collides with it must end in
     * "reload and try again" — which keeps the typed text — never in a 500,
     * which loses it. Two quick retries cover a passing collision; after that we
     * report the honest failure rather than spinning.
     *
     * @param array<string, string> $captions
     * @param list<string>          $hashtags
     * @param array<string, mixed>  $verdict
     */
    private function store(int $runId, array $captions, array $hashtags, string $hash, array $verdict, string $now): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->revisions->save(
                    $this->workspace,
                    $runId,
                    $captions,
                    $hashtags,
                    $hash,
                    $this->userId(),
                    $this->userEmail(),
                    $verdict,
                    $now,
                );
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), 'database is locked')) {
                    throw $e;
                }
                usleep(120_000);
            }
        }

        return false;
    }

    private function approvedAlready(int $wsId, int $runId): bool
    {
        $row = $this->db->one(
            "SELECT id FROM approvals
             WHERE workspace_id = ? AND run_id = ? AND node = 'PUBLISH' AND decision = 'approved' LIMIT 1",
            [$wsId, $runId],
        );

        return $row !== null;
    }

    /**
     * A platform reaches a person as "Instagram", not as the slug the code uses.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function humanize(array $params): array
    {
        if (isset($params['platform']) && is_string($params['platform'])) {
            $params['platform'] = \Kuyash\Core\Messages::platform($params['platform']);
        }

        return $params;
    }

    /**
     * The sentence a person reads, not the key the code uses. Audit entries are
     * read on /logs by an operator, so a raw `content.too_similar` there is the
     * same jargon leak as one on a button.
     *
     * @param array<string, mixed> $params
     */
    private static function reasonText(string $key, array $params): string
    {
        return \Kuyash\Core\I18n::t($key, array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            self::humanize($params),
        ));
    }

    private function throttled(): bool
    {
        // Keyed per USER as well as per address. Kuyash sits behind Caddy and a
        // Cloudflare Tunnel, where every request arrives from the same local
        // address — an IP-only bucket would be one shared allowance for the
        // whole install, so one busy tab could lock the operator out of editing.
        // REMOTE_ADDR stays in the key because it is the one part a client
        // cannot forge (no forwarded header is trusted here).
        return $this->rateLimiter !== null
            && $this->rateLimiter->tooMany(
                self::RATE_BUCKET,
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . $this->userId(),
            );
    }

    private function userId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    private function userEmail(): string
    {
        return (string) ($this->auth->user()['email'] ?? 'unknown');
    }

    /**
     * Back where the operator came from. The target is chosen from a fixed set,
     * never from a submitted URL.
     *
     * @param array<string, mixed> $params
     */
    private function back(?int $runId, string $type, string $key, array $params = []): Response
    {
        $this->flash->add($type, $key, $params);
        $to = is_string($_POST['back'] ?? null) ? $_POST['back'] : '';
        if ($to === 'queue') {
            // …at the card that was being edited. The queue is thousands of
            // pixels tall on a phone; a bare /queue drops the operator at the
            // top with no idea which post their message is about.
            return Response::redirect($runId === null ? '/queue' : '/queue#run-' . $runId, 303);
        }

        return Response::redirect($runId === null ? '/queue' : '/runs/' . $runId, 303);
    }
}
