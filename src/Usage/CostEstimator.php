<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Workflow\Nodes;

/**
 * Deterministic pre-flight cost estimate for a run, computed from its node set
 * before any job runs. Sums the config estimate for each job type in the
 * expanded chain, grouped by ledger category. Conservative by design (the
 * Phase 9 approval-time budget gate stays as an actual-spend backstop) and
 * config-driven (config/usage.php) so prices live in one place.
 */
final class CostEstimator
{
    /**
     * @param array{estimate_cents: array<string, int>, categories: array<string, string>} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param list<array{node: string, ...}>|array<int, array<string, mixed>> $nodes the run's nodes_json entries
     *
     * @return array{total_cents: int, by_category: array<string, int>}
     */
    public function estimateRun(string $template, array $nodes): array
    {
        // accept either a decoded nodes_json ([{node,locked,settings}, …]) or a
        // bare list of node ids; fall back to the template's canonical sequence
        $nodeIds = array_values(array_filter(array_map(
            static fn (mixed $n): string => is_array($n) ? (string) ($n['node'] ?? '') : (string) $n,
            $nodes,
        ), static fn (string $id): bool => $id !== ''));

        if ($nodeIds === []) {
            $nodeIds = Nodes::template($template);
        }

        $estimates = $this->config['estimate_cents'] ?? [];
        $categories = $this->config['categories'] ?? [];

        $total = 0;
        $byCategory = [];
        foreach (Nodes::expand($nodeIds) as $entry) {
            $type = $entry['type'];
            $cents = (int) ($estimates[$type] ?? 0);
            if ($cents <= 0) {
                continue;
            }
            $category = (string) ($categories[$type] ?? 'other');
            $byCategory[$category] = ($byCategory[$category] ?? 0) + $cents;
            $total += $cents;
        }

        return ['total_cents' => $total, 'by_category' => $byCategory];
    }
}
