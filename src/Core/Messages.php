<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * The shared message-key dictionary (Phase 3 follow-up, triggered by the
 * second/third flash-consuming controller). Controllers and the event feed
 * resolve KEYS through this single map — the future TR i18n pass replaces
 * exactly one class, mechanically. Templates never see raw keys.
 */
final class Messages
{
    /** Flash / validation messages. */
    public const MAP = [
        // library (moved verbatim from LibraryController)
        'upload.success' => 'Upload complete — the asset is ready.',
        'upload.too_large' => 'The file exceeds the server upload limit.',
        'upload.video_too_large' => 'The video exceeds the size limit.',
        'upload.photo_too_large' => 'The photo exceeds the size limit.',
        'upload.no_file' => 'Choose a file to upload.',
        'upload.failed' => 'The upload failed — please try again.',
        'upload.empty' => 'The uploaded file is empty.',
        'upload.extension_not_allowed' => 'That file format is not supported.',
        'upload.content_mismatch' => 'The file content does not match its extension.',
        'upload.broken_image' => 'The image file is broken or unreadable.',
        'upload.bad_type' => 'Pick a valid asset type.',
        'asset.deleted' => 'Asset deleted.',
        'asset.delete_failed' => 'The asset could not be deleted.',

        // workflow / runs / queue
        'workflow.not_found' => 'That workflow does not exist.',
        'run.started' => 'Run started — the worker picks it up from the queue.',
        'run.invalid_workflow' => 'The workflow definition failed validation — the run was not started.',
        'run.asset_required' => 'Pick a ready library video to distribute.',
        'run.asset_not_ready' => 'That asset is not a ready library video.',
        'run.reference_not_ready' => 'That reference asset is not available.',
        'run.budget_exceeded' => 'This run would exceed your monthly budget cap — it was not started. Raise the cap in Settings or grant more budget.',
        'avatar.updated' => 'Default avatar updated for this workspace.',
        'avatar.cleared' => 'Default avatar cleared.',
        'avatar.invalid' => 'Pick a ready library asset to use as the avatar.',
        'approval.approved' => 'Approved — the run continues.',
        'approval.rejected' => 'Rejected — the run was cancelled.',
        'approval.already_decided' => 'Already decided — someone (or something) was faster.',
        'job.retried' => 'Job requeued — the worker will retry it.',
        'job.retry_not_failed' => 'Only failed jobs can be retried.',

        // trend radar (Phase 6)
        'trend.run_started' => 'Run started from the trend — the worker picks it up from the queue.',
        'trend.not_found' => 'That trend is no longer available — refresh and try again.',
        'trend.niche_updated' => 'Niche updated — the trend wall now reflects it.',
        'trend.refreshed' => 'Trends refreshed.',
        'trend.no_full_workflow' => 'No full pipeline workflow exists to start from.',
        'trend.invalid_niche' => 'Pick a niche from the list.',

        // compliance settings (Phase 9)
        'settings.saved' => 'Settings saved.',
        'settings.invalid' => 'Check the settings values — mode, daily cap (1-10) or budget is invalid.',
        'killswitch.on' => 'Kill switch is ON — auto-approvals stopped, queued auto publishes will hold.',
        'killswitch.off' => 'Kill switch is OFF — autonomy resumes under the usual guardrails.',

        // accounts / publishing (Phase 10)
        'account.connected' => 'Account connected — it can now receive published renders.',
        'account.disconnected' => 'Account disconnected — it will stop receiving renders.',
        'account.connect_failed' => 'Could not complete the connection — please try again.',
        'account.invalid_platform' => 'Pick a supported platform (Instagram, TikTok or YouTube).',
        'account.not_found' => 'That account does not exist.',
        'account.reference_updated' => 'Default reference updated for this account.',
        'account.reference_invalid' => 'Pick a ready library asset as the reference.',
    ];

    /**
     * Event-feed line templates, keyed by events.key. Placeholders {x} are
     * substituted from params_json. Unknown keys fall back to the key itself
     * (never a crash on old rows).
     */
    public const EVENTS = [
        'run.started' => 'run #{run} started — {workflow} ({template})',
        'run.completed' => 'run #{run} completed',
        'run.failed' => 'run #{run} failed at {node}',
        'run.cancelled' => 'run #{run} cancelled',
        'job.created' => '{type} queued (run #{run})',
        'job.claimed' => '{type} claimed by {worker} (run #{run})',
        'job.finished' => '{type} finished (run #{run})',
        'job.published' => '{type} complete — per-account results recorded (run #{run})',
        'job.awaiting_approval' => '{type} awaiting your approval (run #{run})',
        'job.failed' => '{type} failed: {error} (run #{run})',
        'job.requeued' => '{type} requeued, retry {retry}/{max} (run #{run})',
        'job.manual_retry' => '{type} manually retried by {user} (run #{run})',
        'approval.approved' => '{node} approved by {user} (run #{run})',
        'approval.rejected' => '{node} rejected by {user} (run #{run})',
        'watchdog.requeued' => 'watchdog requeued stale {type}, retry {retry}/{max} (run #{run})',
        'watchdog.failed' => 'watchdog dead-lettered stale {type} (run #{run})',
        'compliance.passed' => 'compliance {status} — policy {policy} (run #{run})',
        'compliance.warned' => 'compliance WARN: similarity {slop} — needs manual review (run #{run})',
        'compliance.blocked' => 'compliance BLOCK: {reason} (policy {policy}, run #{run})',
        'run.blocked_by_compliance' => 'run #{run} cancelled by compliance: {reason}',
        'approval.auto_approved' => '{node} auto-approved by compliance agent (policy {policy}) (run #{run})',
        'guardrail.kill_switch' => 'kill switch is ON — auto-approval denied, sent to manual review (run #{run})',
        'guardrail.kill_switch_on' => 'kill switch flipped ON by {user}',
        'guardrail.kill_switch_off' => 'kill switch flipped OFF by {user}',
        'guardrail.daily_cap_reached' => 'daily auto-approval cap reached ({used}/{cap}) — sent to manual review (run #{run})',
        'guardrail.budget_cap_reached' => 'monthly budget cap reached — sent to manual review (run #{run})',
        'guardrail.preflight_block' => 'run refused before start — estimate {estimate_cents}¢ over the remaining budget ({remaining_cents}¢ of {cap_cents}¢ cap)',
        'guardrail.fallback_to_manual' => 'quality score {score} dropped below {threshold} — workspace fell back to Manual mode (run #{run})',
        'guardrail.publish_deferred' => 'publish deferred: {reason} (run #{run})',
        'guardrail.approval_mode_changed' => 'approval mode set to {mode} by {user}',

        // publishing lifecycle (Phase 10) — per-account targets
        'publish.success' => 'published to {platform} (AI label {ai_label}) (run #{run})',
        'publish.attempt' => 'publish to {platform}: {result} (run #{run})',
        'publish.failed' => 'publish to {platform} failed: {reason} (run #{run})',
        'publish.account_reauth' => '{platform} account needs re-authorization — flagged (run #{run})',
        'publish.no_accounts' => 'no connected accounts — nothing to publish (run #{run})',
        'publish.webhook_received' => 'webhook: {platform} {result} (run #{run})',
        'publish.reconciled' => 'reconciled {platform}: {result} — lost webhook recovered by poll (run #{run})',
    ];

    /**
     * Job/run status → display label. One map so a raw enum never reaches a
     * chip ('awaiting_approval' renders as 'awaiting approval' everywhere).
     */
    public const STATUS = [
        'queued' => 'queued',
        'processing' => 'processing',
        'awaiting_approval' => 'awaiting approval',
        'ready' => 'ready',
        'failed' => 'failed',
        'published' => 'published',
        'cancelled' => 'cancelled',
        'running' => 'running',
        'completed' => 'completed',
    ];

    public static function text(string $key): string
    {
        return self::MAP[$key] ?? $key;
    }

    public static function status(string $status): string
    {
        return self::STATUS[$status] ?? $status;
    }

    /**
     * Resolve an event row's key + params into a display line.
     *
     * @param array<string, mixed> $params
     */
    public static function event(string $key, array $params): string
    {
        $template = self::EVENTS[$key] ?? $key;

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use ($params): string {
                $value = $params[$m[1]] ?? null;

                return is_scalar($value) ? (string) $value : $m[0];
            },
            $template,
        ) ?? $template;
    }

    /**
     * Resolve queued flashes into displayable {type, text} pairs.
     *
     * @return list<array{type: string, text: string}>
     */
    public static function resolveFlashes(Flash $flash): array
    {
        return array_map(
            static fn (array $f): array => [
                'type' => $f['type'],
                'text' => self::text($f['key']),
            ],
            $flash->pull(),
        );
    }
}
