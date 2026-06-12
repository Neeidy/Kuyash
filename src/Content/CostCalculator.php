<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * Token usage → cost. Prices come from config (model → [in, out] US cents per
 * 1,000,000 tokens), never hardcoded guesses (prices drift; config is the
 * single place to correct them).
 *
 * Returns BOTH an integer cent value (for the jobs.cost_cents column / Phase 11
 * ledger) and the precise micro-cost in USD (for honest sub-cent display) —
 * a single short-form call is typically a fraction of a cent.
 */
final class CostCalculator
{
    /**
     * @param array<string, array{in: float, out: float}> $prices cents per 1M tokens
     *
     * @return array{cents: int, usd: float}
     */
    public static function compute(string $model, int $inputTokens, int $outputTokens, array $prices): array
    {
        $rate = $prices[$model] ?? null;
        if ($rate === null) {
            return ['cents' => 0, 'usd' => 0.0];
        }

        $cents = ($inputTokens / 1_000_000) * $rate['in']
            + ($outputTokens / 1_000_000) * $rate['out'];

        return [
            'cents' => (int) round($cents),     // coarse column value (often 0 for tiny calls)
            'usd' => round($cents / 100, 6),    // precise, honest sub-cent figure
        ];
    }
}
