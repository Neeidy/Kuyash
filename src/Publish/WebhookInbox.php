<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\Database;
use Kuyash\Workflow\EventLog;
use Throwable;

/**
 * The raw-first webhook inbox (content-pipeline.md). The HTTP entrypoint
 * persists a RAW, signature-verified delivery here and returns 200 fast;
 * PROCESSING is a separate, replayable worker step that matches the delivery to
 * its post and converges its state. A duplicate delivery hits the UNIQUE
 * external_event_id and is a no-op (at-most-once side effects); a processing
 * failure is recorded (process_error) and replayable by resetting processed_at.
 */
final class WebhookInbox
{
    public function __construct(
        private readonly Database $db,
        private readonly PostRepository $posts,
        private readonly EventLog $events,
    ) {
    }

    /**
     * Persist a RAW delivery. Returns true if newly stored, false if it was a
     * duplicate (the UNIQUE external_event_id made the insert a no-op).
     */
    public function record(string $externalEventId, string $payloadJson, ?string $signature, string $now, string $source = 'zernio'): bool
    {
        $stmt = $this->db->run(
            'INSERT OR IGNORE INTO webhook_events (source, external_event_id, payload_json, signature, received_at)
             VALUES (?, ?, ?, ?, ?)',
            [$source, $externalEventId, $payloadJson, $signature, $now],
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Process unprocessed deliveries: match each to its post by external_post_id,
     * converge the post (published/failed), write an audit event in the post's
     * workspace, and stamp processed_at. A malformed payload or an unmatched id
     * is stamped with a process_error (terminal, replayable by resetting
     * processed_at) so the inbox never loops. Returns rows transitioned.
     */
    public function processPending(string $now, int $limit = 100): int
    {
        $rows = $this->db->all(
            'SELECT * FROM webhook_events WHERE processed_at IS NULL ORDER BY id ASC LIMIT ' . max(1, min(500, $limit)),
        );

        $processed = 0;
        foreach ($rows as $row) {
            $error = $this->processOne($row, $now);
            $this->db->run(
                'UPDATE webhook_events SET processed_at = ?, process_error = ? WHERE id = ?',
                [$now, $error, (int) $row['id']],
            );
            $processed++;
        }

        return $processed;
    }

    /** @return ?string null on success, else a short process_error reason */
    private function processOne(array $row, string $now): ?string
    {
        try {
            $payload = json_decode((string) $row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 'malformed_payload';
        }
        if (!is_array($payload)) {
            return 'malformed_payload';
        }

        $externalPostId = (string) ($payload['post_id'] ?? '');
        if ($externalPostId === '') {
            return 'missing_post_id';
        }
        $post = $this->posts->findByExternalId($externalPostId);
        if ($post === null) {
            return 'post_not_found';
        }

        $wsId = (int) $post['workspace_id'];
        $runId = (int) $post['run_id'];
        $jobId = $post['job_id'] === null ? null : (int) $post['job_id'];
        $status = (string) ($payload['status'] ?? '');
        $platform = (string) $post['platform'];

        if ($status === 'published') {
            // the payload url is attacker-controlled (verified sender, but still
            // external) — accept ONLY http(s) so a javascript:/data: scheme can
            // never reach an href; otherwise synthesize a safe one.
            $url = self::safeUrl($payload['url'] ?? null, 'https://' . $platform . '.example/p/' . $externalPostId);
            $this->posts->markPublished((int) $post['id'], $externalPostId, $url, $now);
            $this->events->record($wsId, 'info', 'transition', 'publish.webhook_received', [
                'platform' => $platform, 'result' => 'published', 'run' => $runId,
            ], $runId, $jobId);

            return null;
        }
        if ($status === 'failed') {
            $this->posts->markFailed((int) $post['id'], (string) ($payload['error'] ?? 'failed (webhook)'), $now);
            $this->events->record($wsId, 'warn', 'transition', 'publish.webhook_received', [
                'platform' => $platform, 'result' => 'failed', 'run' => $runId,
            ], $runId, $jobId);

            return null;
        }

        return 'unknown_status';
    }

    /** Accept only an http(s) URL; anything else (javascript:, data:, junk) → fallback. */
    private static function safeUrl(mixed $url, string $fallback): string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : $fallback;
    }
}
