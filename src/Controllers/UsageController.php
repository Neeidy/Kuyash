<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Core\Csrf;
use Kuyash\Core\Flash;
use Kuyash\Core\Messages;
use Kuyash\Core\Response;
use Kuyash\Core\View;
use Kuyash\Usage\CreditLedger;
use Kuyash\Usage\UsageRepository;
use Kuyash\Workspace\WorkspaceContext;
use Kuyash\Workspace\WorkspaceSettings;

/**
 * Usage, Costs & Credits — the live single-workspace page (Phase 11), replacing
 * the Phase 0 mock. Read-only: month-to-date spend vs the budget cap (the
 * enforced control), a per-category breakdown, recent charges from usage_events,
 * and the credit balance from credit_transactions. All reads are workspace-scoped
 * by WorkspaceContext (tenant isolation). The cap itself is edited on /settings.
 */
final class UsageController
{
    /** The V1 cost categories shown in the breakdown, in display order, with labels. */
    private const CATEGORY_LABELS = [
        'ai_text' => 'AI text',
        'tts' => 'Voice (TTS)',
        'stock' => 'Stock visuals',
        'publish' => 'Publishing',
        'ai_video' => 'AI video',
    ];
    private const V1_CATEGORIES = ['ai_text', 'tts', 'stock', 'publish'];

    public function __construct(
        private readonly View $view,
        private readonly UsageRepository $usage,
        private readonly CreditLedger $credits,
        private readonly WorkspaceSettings $settings,
        private readonly WorkspaceContext $workspace,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function index(array $params = []): Response
    {
        $wsId = $this->workspace->id();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $cap = $this->settings->compliance($wsId)['budget_cap_cents'];
        $spent = $this->usage->monthToDateSpendCents($wsId, $now);
        $byCategory = $this->usage->monthToDateByCategory($wsId, $now);

        // remaining + cap usage % (only meaningful when a cap is set)
        $remaining = $cap !== null ? max(0, $cap - $spent) : null;
        $pct = $cap !== null && $cap > 0 ? (int) floor(($spent / $cap) * 100) : null;

        return Response::html($this->view->render('usage/index', [
            'title' => 'Usage & costs — Kuyash',
            'active' => 'usage',
            'workspaceName' => $this->workspace->currentName(),
            'csrfField' => $this->csrf->field(),
            'flashes' => Messages::resolveFlashes($this->flash),
            'monthLabel' => substr($now, 0, 7),
            'capCents' => $cap,
            'spentCents' => $spent,
            'remainingCents' => $remaining,
            'pct' => $pct,
            'breakdown' => $this->breakdown($byCategory),
            'biggest' => $this->biggest($byCategory),
            'eventCount' => $this->usage->monthToDateEventCount($wsId, $now),
            'charges' => $this->usage->recentCharges($wsId, 20),
            'categoryLabels' => self::CATEGORY_LABELS,
            'balanceCents' => $this->credits->balanceCents($wsId),
            'creditTotals' => $this->credits->totals($wsId),
            'ledger' => $this->credits->recent($wsId, 12),
        ], 'layout/app'));
    }

    /**
     * The fixed V1 category set (always shown, $0 default) plus ai_video only
     * when it has spend — so the breakdown stays the documented 4 in V1 yet is
     * forward-compatible with Phase 12.
     *
     * @param array<string, int> $byCategory
     *
     * @return list<array{key: string, label: string, cents: int}>
     */
    private function breakdown(array $byCategory): array
    {
        $keys = self::V1_CATEGORIES;
        if (($byCategory['ai_video'] ?? 0) > 0) {
            $keys[] = 'ai_video';
        }

        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                'key' => $key,
                'label' => self::CATEGORY_LABELS[$key] ?? $key,
                'cents' => (int) ($byCategory[$key] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Biggest spend category this month, or null when nothing was spent.
     *
     * @param array<string, int> $byCategory
     *
     * @return array{key: string, label: string, cents: int}|null
     */
    private function biggest(array $byCategory): ?array
    {
        $top = null;
        foreach ($byCategory as $key => $cents) {
            if ($cents > 0 && ($top === null || $cents > $top['cents'])) {
                $top = ['key' => $key, 'label' => self::CATEGORY_LABELS[$key] ?? $key, 'cents' => $cents];
            }
        }

        return $top;
    }
}
