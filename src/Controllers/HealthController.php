<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Config;
use Kuyash\Core\Response;

final class HealthController
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, string> $params */
    public function check(array $params = []): Response
    {
        // no PHP_VERSION here: an unauthenticated endpoint must not disclose
        // runtime versions (security review, Phase 1)
        return Response::json([
            'status' => 'ok',
            'app' => $this->config->get('app.name', 'Kuyash'),
            'env' => $this->config->get('app.env', 'prod'),
            'version' => $this->config->get('app.version', 'dev'),
        ]);
    }
}
