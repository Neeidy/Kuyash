<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Config;
use Kuyash\Core\Response;
use Kuyash\Core\View;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        return Response::html($this->view->render('home', [
            'title' => $this->config->get('app.name', 'Kuyash'),
            'appName' => $this->config->get('app.name', 'Kuyash'),
            'env' => $this->config->get('app.env', 'prod'),
            'version' => $this->config->get('app.version', 'dev'),
            'debug' => $this->config->get('app.debug') === true,
        ]));
    }
}
