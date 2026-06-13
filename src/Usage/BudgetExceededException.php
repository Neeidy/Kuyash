<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Workflow\WorkflowException;

/**
 * Pre-flight refusal: a run's estimated cost would push month-to-date spend past
 * the workspace budget cap, so the run is never started (locked decision: a hard
 * block, not a warning). A WorkflowException subclass so the existing startRun
 * catch sites flash 'run.budget_exceeded' unchanged; the cents fields let callers
 * and tests inspect the decision.
 */
final class BudgetExceededException extends WorkflowException
{
    public function __construct(
        public readonly int $estimateCents,
        public readonly int $remainingCents,
        public readonly int $capCents,
    ) {
        parent::__construct(
            'run.budget_exceeded',
            sprintf(
                'preflight: estimate %d¢ exceeds remaining %d¢ of %d¢ monthly cap',
                $estimateCents,
                $remainingCents,
                $capCents,
            ),
        );
    }
}
