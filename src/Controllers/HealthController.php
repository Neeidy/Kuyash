<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Response;

final class HealthController
{
    /**
     * Public uptime probe. Deliberately minimal: an unauthenticated endpoint
     * discloses nothing (no app/env/version — Phase 2 decision).
     *
     * @param array<string, string> $params
     */
    public function check(array $params = []): Response
    {
        return Response::json(['status' => 'ok']);
    }
}
