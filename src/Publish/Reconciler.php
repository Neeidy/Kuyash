<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Workflow\EventLog;

/**
 * Self-healing publish sweep (mirrors the Phase 4 watchdog): an accepted post
 * left in-flight beyond a threshold with NO webhook is polled via the provider's
 * status() and converged to published/failed. No post stays "pending" forever —
 * a lost webhook is compensated by polling. Invoked from the worker loop on a
 * cadence; tests drive sweep() directly.
 */
final class Reconciler
{
    /** Posts in-flight longer than this without a webhook are polled. */
    public const STALE_AFTER_S = 900; // 15 min

    public function __construct(
        private readonly PostRepository $posts,
        private readonly PublishProvider $provider,
        private readonly EventLog $events,
    ) {
    }

    /** @return int posts converged this sweep */
    public function sweep(string $nowIso, int $thresholdSeconds = self::STALE_AFTER_S): int
    {
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($nowIso) - max(1, $thresholdSeconds));
        $stale = $this->posts->inflightOlderThan($cutoff);

        $converged = 0;
        foreach ($stale as $post) {
            $externalId = (string) ($post['external_post_id'] ?? '');
            if ($externalId === '') {
                continue;
            }
            try {
                $outcome = $this->provider->status($externalId);
            } catch (PublishProviderException) {
                continue; // transient — leave in-flight, a later sweep retries
            }

            $wsId = (int) $post['workspace_id'];
            $runId = (int) $post['run_id'];
            $jobId = $post['job_id'] === null ? null : (int) $post['job_id'];
            $platform = (string) $post['platform'];

            if ($outcome->status === PublishOutcome::PUBLISHED) {
                $this->posts->markPublished((int) $post['id'], $externalId, (string) $outcome->externalUrl, $nowIso);
                $this->events->record($wsId, 'info', 'transition', 'publish.reconciled', [
                    'platform' => $platform, 'result' => 'published', 'run' => $runId,
                ], $runId, $jobId);
                $converged++;
            } elseif (in_array($outcome->status, [PublishOutcome::REJECTED, PublishOutcome::AUTH_FAILED], true)) {
                $this->posts->markFailed((int) $post['id'], (string) $outcome->error, $nowIso);
                $this->events->record($wsId, 'warn', 'transition', 'publish.reconciled', [
                    'platform' => $platform, 'result' => 'failed', 'run' => $runId,
                ], $runId, $jobId);
                $converged++;
            }
            // ACCEPTED/RATE_LIMITED on poll → still pending, retry next sweep
        }

        return $converged;
    }
}
