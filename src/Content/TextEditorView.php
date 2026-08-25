<?php

declare(strict_types=1);

namespace Kuyash\Content;

use Kuyash\Compliance\CompliancePolicy;
use Kuyash\Core\Database;
use Kuyash\Publish\AccountRepository;
use Kuyash\Publish\Disclosure;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * Everything the text editor needs for one run, assembled once (Phase 25).
 *
 * Derive-only. Both screens that show the editor — the approval queue and the
 * run detail — go through here, so the character counts they show and the AI
 * notice they display are the same on both, and both are the composition the
 * publisher actually performs.
 */
final class TextEditorView
{
    public function __construct(
        private readonly ContentRevision $revisions,
        private readonly PlatformLimits $limits,
        private readonly AccountRepository $accounts,
        private readonly Database $db,
        private readonly WorkspaceSettings $settings,
        // Optional so existing constructions stay valid; null = no held draft.
        private readonly ?DraftStash $drafts = null,
    ) {
    }

    /**
     * @return array{text: array<string, mixed>, limits: array<string, array<string, mixed>>,
     *               disclosure: string}|null
     */
    public function forRun(WorkspaceContext $ctx, int $runId): ?array
    {
        $text = $this->revisions->forRun($ctx, $runId);
        if ($text === null) {
            return null;
        }
        $wsId = $ctx->id();

        // A save the gate refused redirected here rather than re-rendering, so
        // without this the operator's words would be replaced by the stored ones
        // and lost. Only what is DISPLAYED is swapped: `hash`, `edited` and the
        // edit block still describe what is actually stored, so the next submit
        // still races against the right version and no unsaved text is ever
        // presented as saved.
        $held = $this->drafts?->take($wsId, $runId);
        if ($held !== null && $text['editable']) {
            foreach (array_keys($text['captions']) as $platform) {
                if (array_key_exists((string) $platform, $held['captions'])) {
                    $text['captions'][$platform] = $held['captions'][(string) $platform];
                }
            }
            $text['hashtags'] = $held['hashtags'];
            $text['unsaved'] = true;
        }
        // A refused save leaves DIFFERENT words in the boxes than the ones the
        // stored verdict judged. The chip's referent would still be technically
        // right ("what will publish"), but it says "on the text you edited"
        // beside text that is not that — so it says nothing until the operator
        // saves again.
        $suppressBadge = ($text['unsaved'] ?? false) === true;

        // The EFFECTIVE decision, not just the requirement. Publish appends the
        // Instagram line only when the media needs a label AND this workspace's
        // Instagram disclosure toggle is on (ADR-021) — so a screen that showed
        // the line from `ai_label_required` alone would promise, on the one
        // surface built to say the notice cannot be removed, a notice that
        // Settings had already removed. Only Instagram spends characters on it;
        // elsewhere it is a native flag and costs nothing.
        //
        // …and only while the post can still go out. WorkspaceSettings answers
        // "what would happen if this published NOW", which is the right question
        // for a post still waiting and the WRONG one for a post already sent:
        // flipping the Settings toggle afterwards would rewrite what the record
        // claims about a post that has already been published one way. On a
        // finished, stopped or in-flight run the editor asserts nothing about
        // the notice — `posts.ai_label_applied` and the run's own AI-label row
        // already carry the historical fact, further down the same page.
        $stillOpen = $text['editable'];
        $disclosesHere = $stillOpen
            && $text['ai_label_required']
            && $this->settings->aiDiscloses($wsId, 'instagram');
        $line = $disclosesHere ? Disclosure::line($this->db, $wsId) : '';
        // …and when it IS required but switched off, the screen says that plainly
        // instead of pretending nothing was ever needed.
        $text['disclosure_suppressed'] = $stillOpen && $text['ai_label_required'] && !$disclosesHere;
        $disclosureFor = [];
        // Everywhere except Instagram the notice rides a native platform flag and
        // costs no characters — but it is toggle-gated per platform exactly like
        // Instagram's is (ADR-021), so this lists the platforms that will ACTUALLY
        // carry it. Saying "TikTok gets it too" while its switch is off would be
        // the same false assurance the Instagram line was fixed for.
        $native = [];
        foreach (array_keys($text['captions']) as $platform) {
            $platform = (string) $platform;
            $disclosureFor[$platform] = ($platform === 'instagram') ? $line : '';
            if ($platform !== 'instagram'
                && $stillOpen
                && $text['ai_label_required']
                && $this->settings->aiDiscloses($wsId, $platform)
            ) {
                $native[] = $platform;
            }
        }
        $text['native_disclosure'] = $native;

        // Was this text changed after somebody approved it? The approval stands —
        // it was a real decision — but the screen says so plainly.
        $text['edited_after_approval'] = $text['edited'] && $this->approvedBefore($wsId, $runId, (string) ($text['edit']['at'] ?? ''));

        // What the approval card's compliance chip should SAY. Derived here so
        // the queue, the dashboard and the run screen cannot disagree.
        $text['badge'] = $suppressBadge ? null : self::badge($text);

        return [
            'text' => $text,
            'limits' => $this->limits->measureAll($text['captions'], $text['hashtags'], $disclosureFor),
            'disclosure' => $line,
        ];
    }

    /**
     * The compliance chip for ONE run, or null when there is nothing to override.
     *
     * A run whose text a person edited was last judged by ContentGate at save
     * time — on the words that will actually publish. The chip beside the
     * approval button used to keep showing the compliance_check score, which
     * belongs to the GENERATED text and is no longer what goes out. That is not
     * a wrong number so much as a number about the wrong thing, sitting next to
     * the button that publishes.
     *
     * Null means "no edit" → callers keep rendering the compliance_check result,
     * which is then still exactly right.
     *
     * @return array{status: string, slop: float|null, similar: bool}|null
     */
    public function badgeFor(WorkspaceContext $ctx, int $runId): ?array
    {
        $text = $this->revisions->forRun($ctx, $runId);

        return $text === null ? null : self::badge($text);
    }

    /**
     * @param array<string, mixed> $text
     *
     * @return array{status: string, slop: float|null, similar: bool}|null
     */
    private static function badge(array $text): ?array
    {
        $verdict = $text['edit']['verdict'] ?? null;
        if (!is_array($verdict) || !is_string($verdict['status'] ?? null)) {
            return null;
        }
        $slop = $verdict['slop']['score'] ?? null;
        $score = is_numeric($slop) ? (float) $slop : null;

        return [
            'status' => $verdict['status'],
            'slop' => $score,
            // "warned" and "too similar" are DIFFERENT facts. A warning about a
            // tag count rendered as a similarity chip read "similarity 0.00",
            // which named the wrong check and printed a number that meant
            // nothing. The similarity chip appears only when similarity is
            // actually what crossed the line.
            'similar' => $score !== null && $score >= CompliancePolicy::SLOP_WARN,
        ];
    }

    /** Platforms this workspace actually publishes to. */
    public function connectedPlatforms(int $workspaceId): array
    {
        return array_values(array_unique(array_map(
            static fn (array $a): string => (string) $a['platform'],
            $this->accounts->connectedFor($workspaceId),
        )));
    }

    private function approvedBefore(int $workspaceId, int $runId, string $editedAt): bool
    {
        if ($editedAt === '') {
            return false;
        }
        $row = $this->db->one(
            "SELECT id FROM approvals
             WHERE workspace_id = ? AND run_id = ? AND node = 'PUBLISH'
               AND decision = 'approved' AND decided_at <= ?
             LIMIT 1",
            [$workspaceId, $runId, $editedAt],
        );

        return $row !== null;
    }
}
