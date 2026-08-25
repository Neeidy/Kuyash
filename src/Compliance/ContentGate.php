<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Kuyash\Content\PlatformLimits;
use Kuyash\Publish\Disclosure;

/**
 * The compliance gate a HUMAN EDIT has to pass before it can be saved (Phase 25).
 *
 * WHY IT EXISTS: the canonical chain scores the text at COMPLIANCE, which sits
 * BEFORE the approval gate the operator edits at. So an edit made on the
 * approval card was never seen by the compliance check that already passed. If
 * nothing re-ran, editing would be a way around it.
 *
 * This is not a second policy — it reuses the existing SlopScorer and the same
 * CompliancePolicy thresholds, so an edit is judged by exactly the rules the
 * generated text was judged by.
 *
 * WHERE THE SLOP RE-SCORE HAPPENS, AND WHY ONLY HERE: at SAVE time, against the
 * corpus the operator can actually see. Re-scoring again at publish would mean a
 * post approved on Monday could be blocked on Friday because OTHER runs moved
 * the corpus — silently stranding approved content. What publish verifies
 * instead is that the text it is about to send is byte-for-byte the text this
 * gate approved (a content hash), which is what makes "no edit bypasses
 * compliance" true without making it unpredictable.
 *
 * WHAT BLOCKS vs WHAT ONLY WARNS:
 *   BLOCK — near-duplicate of recent content (the existing slop rule), and an
 *           EMPTY caption for a platform that is actually connected.
 *   WARN  — every platform length/hashtag limit. Those numbers are UNVERIFIED
 *           (config/platforms.php); refusing a save on a figure nobody has
 *           checked would be the system asserting something it does not know.
 */
final class ContentGate
{
    public function __construct(
        private readonly SlopScorer $slop,
        private readonly PlatformLimits $limits,
    ) {
    }

    /**
     * Judge an edit.
     *
     * @param array<string, string> $captions        platform → edited body
     * @param list<string>          $hashtags        edited tags
     * @param list<string>          $connected       platforms with a connected account
     * @param string                $priorScriptText the run's script, if any (slop scores script+captions together)
     * @param string                $disclosureLine  '' when the media needs no AI disclosure
     *
     * @return array{status: string, reasons: list<array{key: string, params: array<string, mixed>}>,
     *               warnings: list<array{key: string, params: array<string, mixed>}>,
     *               slop: array{score: float, history_runs: int},
     *               limits: array<string, array<string, mixed>>, policy: string}
     */
    public function judge(
        int $workspaceId,
        int $runId,
        array $captions,
        array $hashtags,
        array $connected,
        string $priorScriptText = '',
        string $disclosureLine = '',
    ): array {
        $reasons = [];
        $warnings = [];

        // 1. Missing content on a channel that will actually receive it. This is
        //    not a length limit — it is nothing to publish — so it blocks. It
        //    also matters concretely: the YouTube title is derived from the
        //    caption's first line, and an empty one falls back to a placeholder
        //    the operator never chose.
        foreach ($connected as $platform) {
            if (trim((string) ($captions[$platform] ?? '')) === '') {
                $reasons[] = ['key' => 'content.empty_caption', 'params' => ['platform' => $platform]];
            }
        }

        // 2. Slop — the SAME scorer and thresholds the generated text faced.
        //    Candidate text is assembled in SlopScorer's own shape so the edit is
        //    compared like-for-like against history.
        $candidate = self::candidate($priorScriptText, $captions);
        $slop = $this->slop->score($workspaceId, $runId, $candidate);
        if ($slop['score'] >= CompliancePolicy::SLOP_BLOCK) {
            $reasons[] = ['key' => 'content.too_similar', 'params' => [
                'score' => number_format($slop['score'], 2),
                'block_at' => number_format(CompliancePolicy::SLOP_BLOCK, 2),
            ]];
        } elseif ($slop['score'] >= CompliancePolicy::SLOP_WARN) {
            $warnings[] = ['key' => 'content.similar', 'params' => [
                'score' => number_format($slop['score'], 2),
            ]];
        }

        // 3. Platform limits — WARN ONLY. See the class docblock.
        $disclosureFor = [];
        foreach (array_keys($captions) as $platform) {
            // Instagram is the only platform where the disclosure is text; on the
            // others it is a native flag and costs no characters.
            $disclosureFor[(string) $platform] = ($platform === 'instagram') ? $disclosureLine : '';
        }
        $measured = $this->limits->measureAll($captions, $hashtags, $disclosureFor);
        foreach ($measured as $platform => $m) {
            if ($m['over_chars']) {
                $warnings[] = ['key' => 'content.too_long', 'params' => [
                    'platform' => $platform, 'n' => $m['chars'], 'limit' => $m['chars_limit'],
                ]];
            }
            if ($m['over_tags']) {
                $warnings[] = ['key' => 'content.too_many_tags', 'params' => [
                    'platform' => $platform, 'n' => $m['tags'], 'limit' => $m['tags_limit'],
                ]];
            }
        }

        // 4. The operator typed the disclosure themselves. Harmless — publish
        //    dedupes it — but worth saying so it does not look like it vanished.
        if ($disclosureLine !== '') {
            foreach ($captions as $platform => $body) {
                if ($platform === 'instagram' && Disclosure::present((string) $body, $disclosureLine)) {
                    $warnings[] = ['key' => 'content.disclosure_typed', 'params' => []];
                }
            }
        }

        $status = match (true) {
            $reasons !== [] => CompliancePolicy::BLOCK,
            $warnings !== [] => CompliancePolicy::WARN,
            default => CompliancePolicy::PASS,
        };

        return [
            'status' => $status,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'slop' => $slop,
            'limits' => $measured,
            'policy' => CompliancePolicy::VERSION,
        ];
    }

    /**
     * The text slop is scored on — script plus every platform caption, joined
     * exactly as SlopScorer::candidateText() does it, so candidate and history
     * are the same shape.
     *
     * @param array<string, string> $captions
     */
    private static function candidate(string $script, array $captions): string
    {
        $parts = [];
        if (trim($script) !== '') {
            $parts[] = $script;
        }
        foreach ($captions as $caption) {
            if (is_string($caption) && trim($caption) !== '') {
                $parts[] = $caption;
            }
        }

        return implode("\n", $parts);
    }
}
