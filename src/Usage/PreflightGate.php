<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Workflow\EventLog;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * Pre-flight budget gate (locked decision: a HARD block). Consulted by
 * Engine::startRun before a run is created: if the run's estimated cost would
 * push month-to-date spend past the workspace budget cap, the run is REFUSED
 * (BudgetExceededException → flashed 'run.budget_exceeded') and a
 * guardrail.preflight_block event is recorded. No cap set → never blocks.
 *
 * Reads only local indexed SQLite (estimate from config, MTD from usage_events);
 * the refusal happens before any transaction, so no half-started run remains.
 */
final class PreflightGate
{
    public function __construct(
        private readonly CostEstimator $estimator,
        private readonly UsageRepository $usage,
        private readonly WorkspaceSettings $settings,
        private readonly EventLog $events,
    ) {
    }

    /**
     * @param list<array<string, mixed>>|array<int, mixed> $nodes the run's nodes_json entries
     *
     * @throws BudgetExceededException when the estimate exceeds the remaining budget
     */
    public function check(int $workspaceId, string $template, array $nodes, string $now): void
    {
        $cap = $this->settings->compliance($workspaceId)['budget_cap_cents'];
        if ($cap === null) {
            return; // no cap → never blocks (the friendly, opt-in control)
        }

        $estimate = $this->estimator->estimateRun($template, $nodes)['total_cents'];
        $spent = $this->usage->monthToDateSpendCents($workspaceId, $now);
        $remaining = $cap - $spent;

        if ($estimate > $remaining) {
            $this->events->record($workspaceId, 'warn', 'guardrail', 'guardrail.preflight_block', [
                'estimate_cents' => $estimate,
                'remaining_cents' => max(0, $remaining),
                'cap_cents' => $cap,
            ]);

            throw new BudgetExceededException($estimate, max(0, $remaining), $cap);
        }
    }
}
