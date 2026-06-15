<?php

declare(strict_types=1);

namespace Kuyash\Publish;

use Kuyash\Core\RateLimiter;
use Kuyash\Core\Response;
use Throwable;

/**
 * Inbound Zernio webhook endpoint (POST /webhooks/zernio). This route is
 * CSRF-EXEMPT (an external callback has no session/token) and is instead
 * protected by HMAC-SHA256 signature verification: the body is authenticated
 * BEFORE anything is persisted or processed. Verified deliveries are stored RAW
 * in the inbox and acknowledged fast; processing happens later in the worker.
 *
 * Fail-closed: an unconfigured secret rejects every delivery (503). An invalid
 * signature is rejected (401) and logged WITHOUT the body. Nothing here echoes
 * the payload or the secret.
 */
final class WebhookController
{
    public const SIGNATURE_HEADER = 'HTTP_X_ZERNIO_SIGNATURE';

    /** Stable per-delivery id for idempotency (Zernio sends it as a header too). */
    public const EVENT_ID_HEADER = 'HTTP_X_ZERNIO_EVENT_ID';

    /** Logical rate-limit bucket for this endpoint. */
    private const RATE_BUCKET = 'webhook:zernio';

    /** Webhook payloads are small JSON status events — cap the body to bound abuse. */
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly WebhookInbox $inbox,
        private readonly string $secret,
        private readonly ?RateLimiter $rateLimiter = null,
    ) {
    }

    /** Route entrypoint: read the raw body + signature header + client IP, then verify. */
    public function receive(array $params = []): Response
    {
        $raw = file_get_contents('php://input');
        $signature = (string) ($_SERVER[self::SIGNATURE_HEADER] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $eventId = (string) ($_SERVER[self::EVENT_ID_HEADER] ?? '');

        return $this->handle(is_string($raw) ? $raw : '', $signature, $ip, $eventId);
    }

    /**
     * Per-IP rate-limit → verify → persist raw → ack. Directly testable (no
     * php://input): the route passes the captured body/signature/ip/event-id here.
     * The dedup id comes from the X-Zernio-Event-Id header when present, else the
     * payload's `id` (Zernio's stable event UUID), else legacy `event_id`.
     */
    public function handle(string $rawBody, string $signature, string $ip = 'unknown', string $eventIdHeader = ''): Response
    {
        // throttle FIRST (cheapest path): a flood of bogus deliveries from one IP
        // is rejected before HMAC work. Generous cap — a real webhook never bursts
        // near it (see RateLimiter). No limiter wired (tests) → no throttling.
        if ($this->rateLimiter !== null && $this->rateLimiter->tooMany(self::RATE_BUCKET, $ip)) {
            error_log('Kuyash: webhook rejected — per-IP rate limit exceeded');

            return self::text('rate limit exceeded', 429);
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            error_log('Kuyash: webhook rejected — body exceeds size cap');

            return self::text('payload too large', 413);
        }

        if ($this->secret === '') {
            error_log('Kuyash: webhook rejected — ZERNIO_WEBHOOK_SECRET not configured (fail-closed)');

            return self::text('webhook not configured', 503);
        }

        if (!$this->verify($rawBody, $signature)) {
            error_log('Kuyash: webhook rejected — invalid signature');

            return self::text('invalid signature', 401);
        }

        $eventId = $eventIdHeader !== '' ? $eventIdHeader : $this->eventId($rawBody);
        if ($eventId === null || $eventId === '') {
            error_log('Kuyash: webhook rejected — payload missing event id');

            return self::text('bad payload', 400);
        }

        // duplicate (UNIQUE external_event_id) is a no-op but still a 200 ack —
        // the sender must not retry an already-received delivery
        $this->inbox->record($eventId, $rawBody, $signature, gmdate('Y-m-d\TH:i:s\Z'));

        return self::text('ok', 200);
    }

    private function verify(string $rawBody, string $signature): bool
    {
        $signature = trim($signature);
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->secret);

        return hash_equals($expected, $signature);
    }

    private function eventId(string $rawBody): ?string
    {
        try {
            $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($payload)) {
            return null;
        }
        // Zernio's stable per-delivery id is `id`; accept legacy `event_id` too.
        $id = $payload['id'] ?? $payload['event_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private static function text(string $body, int $status): Response
    {
        return new Response($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
