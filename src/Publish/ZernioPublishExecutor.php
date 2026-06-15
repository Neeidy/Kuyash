<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Closure;
use Kuyash\Core\Database;
use Kuyash\Core\I18n;
use Kuyash\Workflow\EventLog;
use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;
use Kuyash\Workspace\WorkspaceSettings;

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
        private readonly WorkspaceSettings $settings,
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

            // Per-platform AI disclosure (toggle-gated, default ON). The content
            // is realistic AI media (aiLabel) → disclose UNLESS the operator turned
            // this platform off. Suppression is audited, never silent. The HOW
            // differs: YouTube/TikTok carry a native flag (set in the adapter from
            // request.aiLabelApplied); Instagram has no native field, so we append
            // a caption disclosure line here. (ADR-021)
            $discloseOn = $this->settings->aiDiscloses($wsId, $platform);
            $effectiveAi = $aiLabel && $discloseOn;
            if ($aiLabel && !$discloseOn) {
                $this->events->record($wsId, 'warn', 'compliance', 'compliance.ai_disclosure_suppressed', [
                    'platform' => $platform, 'run' => $runId,
                ], $runId, $jobId);
            }
            $caption = (string) ($captions[$platform] ?? '');
            if ($platform === 'instagram' && $effectiveAi) {
                $caption = $this->withDisclosure($caption, $wsId);
            }

            $existing = $this->posts->findByKey($wsId, $key);
            if ($existing !== null && in_array((string) $existing['status'], ['published', 'failed', 'cancelled'], true)) {
                // terminal target — idempotent skip on a re-attempt
                $existing['status'] === 'published' ? $published++ : $failed++;
                continue;
            }

            $postId = $existing !== null
                ? (int) $existing['id']
                : $this->posts->insertPublishing($wsId, $runId, $jobId, $accountId, $platform, $effectiveAi, $scheduledFor, $key, $now);
            if ($existing !== null) {
                $this->posts->markPublishing($postId, $jobId, $now);
            }

            $request = new PublishRequest(
                $platform,
                (string) $account['handle'],
                $account['external_ref'] === null ? null : (string) $account['external_ref'],
                $key,
                $effectiveAi,
                $scheduledFor,
                $renderId,
                $caption,
                $hashtags,
                $wsId,
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
                PublishOutcome::PUBLISHED => $this->onPublished($wsId, $runId, $jobId, $postId, $platform, $effectiveAi, $outcome, $now, $published),
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

    /**
     * Append the AI-disclosure line on its own line at the end of the caption
     * (Instagram only — no native API field). Idempotent-ish: a blank caption
     * becomes just the line. Wording is the workspace owner's locale.
     */
    private function withDisclosure(string $caption, int $wsId): string
    {
        $line = $this->disclosureText($wsId);
        $caption = rtrim($caption);

        return $caption === '' ? $line : $caption . "\n" . $line;
    }

    /** Localized AI-disclosure text ("Made with AI" / "AI ile üretildi"), owner's locale. */
    private function disclosureText(int $wsId): string
    {
        $locale = $this->ownerLocale($wsId);
        $prev = I18n::locale();
        I18n::setLocale($locale);
        $line = I18n::t('compliance.ai_disclosure');
        I18n::setLocale($prev);

        return $line;
    }

    /** The workspace owner's UI locale (best proxy for the content language); defaults to en. */
    private function ownerLocale(int $wsId): string
    {
        $row = $this->db->one(
            "SELECT u.locale FROM users u
             JOIN workspace_users wu ON wu.user_id = u.id
             WHERE wu.workspace_id = ?
             ORDER BY (wu.role = 'owner') DESC, wu.id ASC LIMIT 1",
            [$wsId],
        );

        return (string) ($row['locale'] ?? 'en');
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
