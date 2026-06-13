<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Marker for a failure that retrying CANNOT fix — the queue must dead-letter it
 * immediately instead of burning the backoff/retry budget.
 *
 * The canonical case is an HTTP 401/403 from an external provider (invalid or
 * forbidden credentials): re-attempting the same request every few seconds will
 * never succeed, so the run fails fast and the operator fixes the key, then
 * manually retries the dead-lettered job (Engine::retryJob resets the counters).
 *
 * Transient failures (429 rate-limit, transport timeout, 5xx) deliberately do
 * NOT carry this marker — those are retried with exponential backoff as before.
 *
 * Worker::tick inspects this marker on an uncaught throwable; the retry decision
 * itself lives in Engine::finalizeFailure via JobResult::failedPermanent().
 */
interface PermanentFailure
{
}
