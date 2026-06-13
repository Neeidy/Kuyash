<?php

declare(strict_types=1);

namespace Kuyash\Usage;

use Kuyash\Core\Database;

/**
 * The money-denominated credit ledger (credit_transactions). "Credits" is a
 * friendly display layer over real cents — there is NO prepaid economy and no
 * auto-allowance: balance = SUM(amount_cents) of grants (+), spends (−) and
 * adjusts (±). Spends are written by UsageRecorder beside each usage_event;
 * grants/adjusts are MANUAL (seed / bin/grant-credits.php), since there is no
 * Stripe in V1. Every method is workspace-scoped.
 */
final class CreditLedger
{
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(private readonly Database $db)
    {
    }

    /** Balance in cents (granted + adjusted − spent). May be negative if spend ran ahead of grants. */
    public function balanceCents(int $workspaceId): int
    {
        $row = $this->db->one(
            'SELECT COALESCE(SUM(amount_cents), 0) AS bal FROM credit_transactions WHERE workspace_id = ?',
            [$workspaceId],
        );

        return (int) ($row['bal'] ?? 0);
    }

    /**
     * Lifetime totals for display: granted (+), spent (absolute), adjusted (net).
     *
     * @return array{granted: int, spent: int, adjusted: int}
     */
    public function totals(int $workspaceId): array
    {
        $row = $this->db->one(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'grant'  THEN amount_cents END), 0) AS granted,
                COALESCE(SUM(CASE WHEN type = 'spend'  THEN amount_cents END), 0) AS spent,
                COALESCE(SUM(CASE WHEN type = 'adjust' THEN amount_cents END), 0) AS adjusted
             FROM credit_transactions WHERE workspace_id = ?",
            [$workspaceId],
        ) ?? [];

        return [
            'granted' => (int) ($row['granted'] ?? 0),
            'spent' => abs((int) ($row['spent'] ?? 0)),
            'adjusted' => (int) ($row['adjusted'] ?? 0),
        ];
    }

    /**
     * Manual grant of credit (positive cents). Returns the new transaction id.
     * Not for spend — that path is UsageRecorder, mirroring a real usage_event.
     */
    public function grant(int $workspaceId, int $cents, string $reason, ?string $now = null): int
    {
        return $this->record($workspaceId, 'grant', abs($cents), $reason, $now);
    }

    /** Manual adjustment (signed cents): corrections, comps, refunds. */
    public function adjust(int $workspaceId, int $cents, string $reason, ?string $now = null): int
    {
        return $this->record($workspaceId, 'adjust', $cents, $reason, $now);
    }

    /**
     * Recent ledger lines (newest first) for the page.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $workspaceId, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT id, type, amount_cents, reason, ref_run_id, ref_job_id, created_at
             FROM credit_transactions WHERE workspace_id = ?
             ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$workspaceId],
        );
    }

    private function record(int $workspaceId, string $type, int $cents, string $reason, ?string $now): int
    {
        $this->db->run(
            'INSERT INTO credit_transactions (workspace_id, type, amount_cents, reason, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [$workspaceId, $type, $cents, $reason, $now ?? gmdate(self::ISO)],
        );

        return $this->db->lastInsertId();
    }
}
