<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Core\Database;

/**
 * Per-workspace niche/region config. One row per workspace; an absent row means
 * "use the config defaults" (no implicit global state). Scoped by a raw
 * workspace_id (web passes $ctx->id(), worker passes the job's workspace_id).
 */
final class TrendConfigRepository
{
    /** The niche allowlist the UI offers and the controller validates against. */
    public const NICHES = ['general', 'fitness', 'cooking', 'tech', 'travel', 'finance', 'beauty'];

    /**
     * @param array{niche: string, region: string} $defaults
     */
    public function __construct(
        private readonly Database $db,
        private readonly array $defaults,
    ) {
    }

    /** @return array{niche: string, region: string} */
    public function get(int $workspaceId): array
    {
        $row = $this->db->one(
            'SELECT niche, region FROM trend_config WHERE workspace_id = ?',
            [$workspaceId],
        );
        if ($row === null) {
            return $this->defaults;
        }

        return ['niche' => (string) $row['niche'], 'region' => (string) $row['region']];
    }

    public function set(int $workspaceId, string $niche, string $region, string $now): void
    {
        $this->db->run(
            'INSERT INTO trend_config (workspace_id, niche, region, updated_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (workspace_id)
             DO UPDATE SET niche = excluded.niche, region = excluded.region, updated_at = excluded.updated_at',
            [$workspaceId, $niche, $region, $now],
        );
    }

    /** @return list<string> */
    public static function niches(): array
    {
        return self::NICHES;
    }
}
