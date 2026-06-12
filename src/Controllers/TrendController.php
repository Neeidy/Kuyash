<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Trend\QuotaCounter;
use Kuyash\Trend\TrendConfigRepository;
use Kuyash\Trend\TrendService;
use Kuyash\Workflow\Engine;
use Kuyash\Workflow\WorkflowException;
use Kuyash\Workflow\WorkflowRepository;
use Kuyash\Workspace\WorkspaceContext;

/**
 * Trend Radar: the niche trend wall (read-through cache with honest freshness),
 * niche config, force-refresh, and "create from trend" (seeds a full pipeline
 * run pinned to the chosen trend — reuses Engine::startRun, no new engine).
 */
final class TrendController
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly View $view,
        private readonly TrendService $trends,
        private readonly TrendConfigRepository $config,
        private readonly QuotaCounter $quota,
        private readonly WorkflowRepository $workflows,
        private readonly Engine $engine,
        private readonly WorkspaceContext $workspace,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $cfg = $this->config->get($wsId);
        $feed = $this->trends->feed($wsId, $cfg['niche'], $cfg['region']);

        return Response::html($this->view->render('trends/index', [
            'title' => 'Trend Radar — Kuyash',
            'active' => 'trends',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'feed' => $feed,
            'niche' => $cfg['niche'],
            'region' => $cfg['region'],
            'niches' => TrendConfigRepository::niches(),
            'quota' => $this->quota->totalsForDay($wsId, substr(gmdate(self::ISO), 0, 10)),
        ], 'layout/app'));
    }

    /** @param array<string, string> $params */
    public function setNiche(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $niche = strtolower(trim((string) ($_POST['niche'] ?? '')));
        if (!in_array($niche, TrendConfigRepository::niches(), true)) {
            $this->flash->add('error', 'trend.invalid_niche');

            return Response::redirect('/trends', 303);
        }

        $region = $this->cleanRegion((string) ($_POST['region'] ?? ''));
        $this->config->set($wsId, $niche, $region, gmdate(self::ISO));
        $this->flash->add('success', 'trend.niche_updated');

        return Response::redirect('/trends', 303);
    }

    /** @param array<string, string> $params */
    public function refresh(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $cfg = $this->config->get($wsId);
        $this->trends->feed($wsId, $cfg['niche'], $cfg['region'], true);
        $this->flash->add('success', 'trend.refreshed');

        return Response::redirect('/trends', 303);
    }

    /** @param array<string, string> $params */
    public function create(array $params = []): Response
    {
        $raw = (string) ($_POST['trend_id'] ?? '');
        $trendId = ctype_digit($raw) && $raw !== '' ? (int) $raw : null;
        if ($trendId === null) {
            $this->flash->add('error', 'trend.not_found');

            return Response::redirect('/trends', 303);
        }

        $this->workflows->ensureDefaults($this->workspace);
        $full = null;
        foreach ($this->workflows->listFor($this->workspace) as $wf) {
            if (($wf['template'] ?? null) === 'full') {
                $full = $wf;
                break;
            }
        }
        if ($full === null) {
            $this->flash->add('error', 'trend.no_full_workflow');

            return Response::redirect('/trends', 303);
        }

        $userId = (int) ($this->auth->user()['id'] ?? 0);
        try {
            $this->engine->startRun($this->workspace, (int) $full['id'], null, $userId, $trendId);
        } catch (WorkflowException $e) {
            $this->flash->add('error', $e->messageKey);

            return Response::redirect('/trends', 303);
        }

        $this->flash->add('success', 'trend.run_started');

        return Response::redirect('/queue', 303);
    }

    /** Two-letter region code; falls back to US on junk (matches the providers). */
    private function cleanRegion(string $region): string
    {
        $code = strtoupper(substr(trim($region), 0, 2));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : 'US';
    }
}
