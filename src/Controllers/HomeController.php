<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Response;

final class HomeController
{
    public function __construct(private readonly Auth $auth)
    {
    }

    /**
     * The root is a pure switchboard since Phase 2 — it also stops the old
     * skeleton page from disclosing env/debug state (Phase 1 follow-up).
     *
     * @param array<string, string> $params
     */
    public function index(array $params = []): Response
    {
        return Response::redirect($this->auth->check() ? '/dashboard' : '/login');
    }
}
