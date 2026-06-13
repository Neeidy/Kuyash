<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * The inner `publish` executor (Phase 10) — supersedes MockExecutor's publish
 * branch. Still wrapped by the Phase-9 PublishGateExecutor (kill-switch +
 * per-account cap survive the swap). Fans the run out to every connected
 * account, writing one `posts` row per target and recording per-target results,
 * so a partial failure never fails the whole run or blocks the other accounts.
 *
 * Idempotency: a per-(run,account) key means a re-enqueued publish job
 * re-attempts ONLY non-terminal targets — a published account is never double
 * posted. Terminal per-target outcomes (platform rejection, auth failure) are
 * recorded on the post and the job still completes; only TRANSIENT failures
 * (rate-limit / transport timeout) return JobResult::failed so the queue backs
 * off and retries.
 *
 * AI-label automation: the post's ai_label_applied (and the per-platform flag in
 * the publish payload) is set EXACTLY when the compliance check required it —
 * truthful, never claimed otherwise.
 */
final class ZernioPublishExecutor implements JobExecutor
{
    private readonly Closure $clock;

    public function __construct(
        private readonly Database $db,
        private readonly PublishProvider $provider,
        private readonly AccountRepository $accounts,
        private readonly PostRepository $posts,
        private readonly EventLog $events,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    public function execute(array $job, array $prior): JobResult
    {
        $wsId = (int) $job['workspace_id'];
        $runId = (int) $job['run_id'];
        $jobId = (int) $job['id'];
        $now = ($this->clock)();

        $aiLabel = (bool) ($prior['compliance_check']['ai_label_required'] ?? false);
        $scheduledFor = $this->scheduledFor($wsId, $runId);
        $renderId = isset($prior['final_render']['render_id']) ? (int) $prior['final_render']['render_id'] : null;
        $captions = (array) ($prior['caption_generation']['captions'] ?? []);
        $hashtags = array_values(array_filter(
            (array) ($prior['hashtag_generation']['hashtags'] ?? []),
            is_string(...),
        ));

        $targets = $this->accounts->connectedFor($wsId);
        if ($targets === []) {
            $this->events->record($wsId, 'warn', 'transition', 'publish.no_accounts', [
                'run' => $runId,
            ], $runId, $jobId);

            return JobResult::published([
                'posts' => 0,
                'note' => 'no connected accounts — nothing to publish',
            ], $this->provider->name());
        }

        $published = $failed = $accepted = $retryable = 0;

        foreach ($targets as $account) {
            $accountId = (int) $account['id'];
            $platform = (string) $account['platform'];
            $key = "run:{$runId}:acct:{$accountId}:publish";

            $existing = $this->posts->findByKey($wsId, $key);
            if ($existing !== null && in_array((string) $existing['status'], ['published', 'failed', 'cancelled'], true)) {
                // terminal target — idempotent skip on a re-attempt
                $existing['status'] === 'published' ? $published++ : $failed++;
                continue;
            }

            $postId = $existing !== null
                ? (int) $existing['id']
                : $this->posts->insertPublishing($wsId, $runId, $jobId, $accountId, $platform, $aiLabel, $scheduledFor, $key, $now);
            if ($existing !== null) {
                $this->posts->markPublishing($postId, $jobId, $now);
            }

            $request = new PublishRequest(
                $platform,
                (string) $account['handle'],
                $account['external_ref'] === null ? null : (string) $account['external_ref'],
                $key,
                $aiLabel,
                $scheduledFor,
                $renderId,
                (string) ($captions[$platform] ?? ''),
                $hashtags,
            );

            try {
                $outcome = $this->provider->publish($request);
            } catch (PublishProviderException $e) {
                $retryable++;
                $this->events->record($wsId, 'warn', 'transition', 'publish.attempt', [
                    'platform' => $platform, 'result' => 'transport_error', 'run' => $runId,
                ], $runId, $jobId);
                continue; // leave the post in-flight; the job retry re-attempts it
            }

            match ($outcome->status) {
                PublishOutcome::PUBLISHED => $this->onPublished($wsId, $runId, $jobId, $postId, $platform, $aiLabel, $outcome, $now, $published),
                PublishOutcome::ACCEPTED => $this->onAccepted($wsId, $runId, $jobId, $postId, $platform, $outcome, $now, $accepted),
                PublishOutcome::REJECTED => $this->onFailed($wsId, $runId, $jobId, $postId, $platform, $outcome, 'warn', false, $now, $failed),
                PublishOutcome::AUTH_FAILED => $this->onFailed($wsId, $runId, $jobId, $postId, $platform, $outcome, 'error', true, $now, $failed, $accountId),
                PublishOutcome::RATE_LIMITED => $this->onRateLimited($wsId, $runId, $jobId, $platform, $now, $retryable),
                default => null,
            };
        }

        if ($retryable > 0) {
            // transient targets remain — fail the job so the queue backs off and
            // retries; already-terminal targets are skipped next time (idempotent)
            return JobResult::failed(
                "publish: {$retryable} target(s) need retry (rate-limit/timeout)",
                $this->provider->name(),
            );
        }

        return JobResult::published([
            'posts' => count($targets),
            'published' => $published,
            'accepted' => $accepted,
            'failed' => $failed,
            'ai_label_applied' => $aiLabel,
        ], $this->provider->name());
    }

    private function onPublished(int $wsId, int $runId, int $jobId, int $postId, string $platform, bool $aiLabel, PublishOutcome $o, string $now, int &$published): void
    {
        $this->posts->markPublished($postId, (string) $o->externalPostId, (string) $o->externalUrl, $now);
        $this->events->record($wsId, 'info', 'transition', 'publish.success', [
            'platform' => $platform, 'ai_label' => $aiLabel ? 1 : 0, 'run' => $runId,
        ], $runId, $jobId);
        $published++;
    }

    private function onAccepted(int $wsId, int $runId, int $jobId, int $postId, string $platform, PublishOutcome $o, string $now, int &$accepted): void
    {
        $this->posts->markAccepted($postId, (string) $o->externalPostId, $now);
        $this->events->record($wsId, 'info', 'transition', 'publish.attempt', [
            'platform' => $platform, 'result' => 'accepted', 'run' => $runId,
        ], $runId, $jobId);
        $accepted++;
    }

    private function onFailed(int $wsId, int $runId, int $jobId, int $postId, string $platform, PublishOutcome $o, string $level, bool $reauth, string $now, int &$failed, ?int $accountId = null): void
    {
        $this->posts->markFailed($postId, (string) $o->error, $now);
        $this->events->record($wsId, $level, 'transition', 'publish.failed', [
            'platform' => $platform, 'reason' => (string) $o->error, 'run' => $runId,
        ], $runId, $jobId);
        if ($reauth && $accountId !== null) {
            $this->accounts->markReauthNeeded($wsId, $accountId, $now);
            $this->events->record($wsId, 'warn', 'transition', 'publish.account_reauth', [
                'platform' => $platform, 'run' => $runId,
            ], $runId, $jobId);
        }
        $failed++;
    }

    private function onRateLimited(int $wsId, int $runId, int $jobId, string $platform, string $now, int &$retryable): void
    {
        $retryable++;
        $this->events->record($wsId, 'warn', 'transition', 'publish.attempt', [
            'platform' => $platform, 'result' => 'rate_limited', 'run' => $runId,
        ], $runId, $jobId);
    }

    /** The run's scheduled publish time, set at approval; null = immediate. */
    private function scheduledFor(int $wsId, int $runId): ?string
    {
        $row = $this->db->one(
            'SELECT publish_after FROM runs WHERE id = ? AND workspace_id = ?',
            [$runId, $wsId],
        );
        $value = $row['publish_after'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
