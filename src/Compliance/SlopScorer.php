<?php

declare(strict_types=1);

namespace Kuyash\Compliance;

use Kuyash\Content\VariationEngine;
use Kuyash\Core\Database;

/**
 * Slop/variation scoring (Phase-5 followup #8: score the ACTUAL produced
 * text, not the template pools). The candidate is the run's rendered script +
 * captions (distribution runs: captions only — they have no script); history
 * is the same-shaped text of the workspace's last N runs, read from
 * jobs.result_json. The score is the MAX Jaccard similarity against any
 * single history run — one near-duplicate is the violation, an average would
 * dilute it. Empty history scores 0 (a first run cannot be slop).
 */
final class SlopScorer
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{score: float, history_runs: int}
     */
    public function score(int $workspaceId, int $runId, string $candidate): array
    {
        if (trim($candidate) === '') {
            return ['score' => 0.0, 'history_runs' => 0];
        }

        $history = $this->historyTexts($workspaceId, $runId);
        $max = 0.0;
        foreach ($history as $text) {
            $max = max($max, VariationEngine::similarity($candidate, $text));
        }

        return ['score' => round($max, 4), 'history_runs' => count($history)];
    }

    /**
     * The text a run is judged on, built from the worker's prior results:
     * script (full template) + every platform caption. History rows are built
     * with the same shape so candidate and history compare like-for-like.
     *
     * @param array<string, array<string, mixed>> $prior
     */
    public static function candidateText(array $prior): string
    {
        $parts = [];
        $script = (string) ($prior['script_draft']['script'] ?? '');
        if (trim($script) !== '') {
            $parts[] = $script;
        }
        $captions = $prior['caption_generation']['captions'] ?? [];
        if (is_array($captions)) {
            foreach ($captions as $caption) {
                if (is_string($caption) && trim($caption) !== '') {
                    $parts[] = $caption;
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * One concatenated text per history run: the last N runs of THIS workspace
     * (current run excluded) that produced script/caption text. Cancelled and
     * blocked runs are deliberately included — repeating just-blocked content
     * must keep scoring high.
     *
     * @return list<string>
     */
    private function historyTexts(int $workspaceId, int $excludeRunId): array
    {
        $rows = $this->db->all(
            "SELECT run_id, type, result_json FROM jobs
             WHERE workspace_id = ? AND run_id != ?
               AND type IN ('script_draft', 'caption_generation')
               AND result_json IS NOT NULL
               AND run_id IN (
                   SELECT DISTINCT run_id FROM jobs
                   WHERE workspace_id = ? AND run_id != ?
                     AND type IN ('script_draft', 'caption_generation')
                     AND result_json IS NOT NULL
                   ORDER BY run_id DESC
                   LIMIT " . CompliancePolicy::SLOP_HISTORY_RUNS . '
               )
             ORDER BY run_id DESC, step ASC',
            [$workspaceId, $excludeRunId, $workspaceId, $excludeRunId],
        );

        $byRun = [];
        foreach ($rows as $row) {
            $result = json_decode((string) $row['result_json'], true);
            if (!is_array($result)) {
                continue;
            }
            $byRun[(int) $row['run_id']] ??= [];
            $byRun[(int) $row['run_id']][(string) $row['type']] = $result;
        }

        return array_values(array_filter(array_map(
            static fn (array $results): string => self::candidateText([
                'script_draft' => $results['script_draft'] ?? [],
                'caption_generation' => $results['caption_generation'] ?? [],
            ]),
            $byRun,
        ), static fn (string $text): bool => trim($text) !== ''));
    }
}
