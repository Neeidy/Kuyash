<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Response;
use Kuyash\Workflow\Cockpit;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Live layer (Phase 19) — the one real backend surface of the Experience Layer,
 * kept deliberately tiny and SAFE.
 *
 * It is an SSE endpoint, but IMMEDIATE-CLOSE: it emits a single workspace-scoped
 * snapshot + a `retry:` directive and returns, so the browser's EventSource
 * reconnects every few seconds. This is reconnect-polling shaped as SSE. The
 * deliberate trade-off:
 *   - never holds a connection on the single-threaded dev server (would block
 *     every other request, including the visual harness),
 *   - never holds the PHP session lock across a stream (session_write_close),
 *   - never opens a long transaction or a sleep loop → no resource exhaustion.
 * Tenant isolation comes from the session workspace; auth from the route guard.
 */
final class LiveController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly WorkspaceContext $workspace,
        private readonly Cockpit $cockpit,
    ) {
    }

    /** @param array<string, string> $params */
    public function stream(array $params = []): Response
    {
        // unreachable behind the route guard; fail-closed backstop
        if ($this->auth->user() === null) {
            return Response::redirect('/login');
        }

        $snapshot = $this->cockpit->liveSnapshot($this->workspace->id());
        $snapshot['ts'] = gmdate('Y-m-d\TH:i:s\Z');

        // drop the session lock immediately — this endpoint never holds it
        if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $body = "retry: 5000\n"
            . "event: snapshot\n"
            . 'data: ' . json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n\n";

        return new Response($body, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Content-Type-Options' => 'nosniff',
            'Connection' => 'close',
        ]);
    }
}
