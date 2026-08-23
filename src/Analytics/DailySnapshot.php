<?php

declare(strict_types=1);

namespace Kuyash\Analytics;

use Kuyash\Core\Database;
use Kuyash\Publish\PublishProvider;
use Kuyash\Publish\PublishProviderException;

/**
 * Daily audience + engagement snapshot (Phase 22). Runs from the worker loop on
 * the ordinary chore cadence, but writes AT MOST ONE row per account per UTC day
 * (UNIQUE(workspace_id, account_id, snapshot_date) + INSERT OR IGNORE), so the
 * cadence can stay coarse without either duplicating rows or missing a day.
 *
 * COST: zero. Every provider call here is a read-only GET — no generation, no
 * publish — so nothing is recorded in usage_events/credit_transactions (same
 * stance as Reconciler and WebhookInbox, which are also external-facing but
 * non-billable). A metrics poll must never look like spend.
 *
 * TENANCY: the worker is sessionless (no WorkspaceContext). Accounts are read
 * with their workspace_id attached and that id is re-applied on every write —
 * the isolation pattern EventLog/Maintenance use.
 *
 * TRUTHFULNESS: a metric the provider does not report stays NULL end-to-end; it
 * is never coerced to 0 so a screen looks populated. When the provider exposes
 * no per-post analytics (today's live state: an empty post list), the row still
 * records the REAL follower count and an honest post_count = 0.
 */
final class DailySnapshot
{
    /** Analytics window handed to the provider (days back from "now"). */
    public const WINDOW_DAYS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly PublishProvider $provider,
    ) {
    }

    /**
     * Capture today's snapshot for every connected account that lacks one.
     *
     * @return int rows written this run (0 = nothing due, or the provider was unreachable)
     */
    public function capture(string $nowIso): int
    {
        $day = substr($nowIso, 0, 10);

        $due = $this->db->all(
            "SELECT id, workspace_id, external_ref
             FROM accounts a
             WHERE a.status = 'connected'
               AND a.external_ref IS NOT NULL AND a.external_ref != ''
               AND NOT EXISTS (
                   SELECT 1 FROM account_metrics m
                   WHERE m.account_id = a.id
                     AND m.workspace_id = a.workspace_id
                     AND m.snapshot_date = ?
               )
             ORDER BY a.id ASC",
            [$day],
        );
        if ($due === []) {
            return 0; // already captured today — do not touch the network at all
        }

        $to = $day;
        $from = gmdate('Y-m-d', (int) strtotime($nowIso) - (self::WINDOW_DAYS * 86400));
        try {
            $metrics = $this->provider->accountMetrics(null, $from, $to);
        } catch (PublishProviderException) {
            return 0; // transient — a later tick retries; never fail the worker
        }

        /** @var array<string, array<string, mixed>> $byRef */
        $byRef = [];
        foreach ($metrics as $m) {
            $ref = (string) ($m['external_ref'] ?? '');
            if ($ref !== '') {
                $byRef[$ref] = $m;
            }
        }

        $provider = $this->provider->name();
        $written = 0;
        foreach ($due as $account) {
            // An account the provider does not report STILL gets a row — with
            // NULL metrics, which reads as "we looked today and learned nothing".
            // Skipping it instead would leave the account permanently "due", so
            // a stale placeholder ref would re-hit the provider on every chore
            // tick (~288 polls/day) against an undocumented rate limit.
            $row = $byRef[(string) $account['external_ref']] ?? null;

            $posts = array_values(array_filter((array) ($row['posts'] ?? []), 'is_array'));
            $totals = self::totals($posts);
            $followers = $row === null ? null : self::intOrNull($row['followers'] ?? null);

            $inserted = $this->db->run(
                'INSERT OR IGNORE INTO account_metrics
                    (workspace_id, account_id, snapshot_date, followers, has_analytics, post_count,
                     views, likes, comments, shares, posts_json, raw_json, provider, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $account['workspace_id'],
                    (int) $account['id'],
                    $day,
                    $followers,
                    // a null $row leaves every metric NULL and post_count 0 —
                    // an honest "we looked today and learned nothing" record
                    ($row !== null && ($row['has_analytics'] ?? false)) ? 1 : 0,
                    count($posts),
                    $totals['views'],
                    $totals['likes'],
                    $totals['comments'],
                    $totals['shares'],
                    self::json($posts),
                    self::json((array) ($row['raw'] ?? [])),
                    $provider,
                    $nowIso,
                ],
            )->rowCount();

            if ($inserted > 0) {
                $written++;
            }

            // hot value the account cards read; tenant-scoped like every write here
            if ($followers !== null) {
                $this->db->run(
                    'UPDATE accounts SET followers_count = ?, followers_synced_at = ?, updated_at = ?
                     WHERE id = ? AND workspace_id = ?',
                    [$followers, $nowIso, $nowIso, (int) $account['id'], (int) $account['workspace_id']],
                );
            }
        }

        return $written;
    }

    /**
     * Sum a metric across posts, keeping NULL when NO post reported it — an
     * unreported metric must not collapse into a confident 0.
     *
     * @param list<array<string, mixed>> $posts
     *
     * @return array{views: int|null, likes: int|null, comments: int|null, shares: int|null}
     */
    private static function totals(array $posts): array
    {
        $out = ['views' => null, 'likes' => null, 'comments' => null, 'shares' => null];
        foreach ($posts as $post) {
            foreach (array_keys($out) as $key) {
                $value = self::intOrNull($post[$key] ?? null);
                if ($value !== null) {
                    $out[$key] = ($out[$key] ?? 0) + $value;
                }
            }
        }

        return $out;
    }

    /** @param array<mixed> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_float($value) ? (int) $value : null);
    }
}
