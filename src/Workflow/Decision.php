<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * Outcome of a guarded state transition (approve/reject/retry).
 * Race losers get AlreadyDecided — a calm "someone was faster" path,
 * never an exception (web vs worker vs watchdog writes race by design).
 */
enum Decision
{
    case Ok;
    case AlreadyDecided;
    case NotFound;
}
