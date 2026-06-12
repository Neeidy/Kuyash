<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Workflow\JobExecutor;
use Kuyash\Workflow\JobResult;

/**
 * The real executor for the `trend_fetch` job type (replaces MockExecutor's
 * placeholder). It owns only the seam glue — resolve the trend, map it to a
 * JobResult — so swapping mock↔real is a provider swap, not an executor change
 * (adapter rule).
 *
 * Two paths:
 *  - "create from trend": the run carries a chosen cached trend (entity_type
 *    'trend' + entity_id) → emit THAT trend. If it was refreshed away, fail
 *    honestly (mirrors MockExecutor's "library asset no longer available").
 *  - normal full run: fetch/cache fresh trends for the workspace's niche and
 *    pick the top one.
 *
 * The result always carries `trend` (the topic) so downstream IDEA/SCRIPT keep
 * working unchanged; `format`, `score`, `source` and `freshness` ride along for
 * the run timeline and to carry the format recommendation toward IDEA.
 */
final class TrendExecutor implements JobExecutor
{
    public function __construct(
        private readonly TrendService $service,
        private readonly TrendRepository $repo,
        private readonly TrendConfigRepository $config,
    ) {
    }

    public function execute(array $job, array $prior): JobResult
    {
        $wsId = (int) $job['workspace_id'];

        if (($job['entity_type'] ?? null) === 'trend' && $job['entity_id'] !== null) {
            return $this->fromSelected($wsId, (int) $job['entity_id']);
        }

        return $this->fromNiche($wsId);
    }

    private function fromSelected(int $wsId, int $trendId): JobResult
    {
        $row = $this->repo->find($wsId, $trendId);
        if ($row === null) {
            return JobResult::failed('selected trend is no longer available', $this->service->providerName());
        }

        return JobResult::ready([
            'trend' => (string) $row['topic'],
            'niche' => (string) $row['niche'],
            'region' => (string) $row['region'],
            'score' => (int) $row['score'],
            'format' => (string) $row['format'],
            'source' => (string) $row['source'],
            'origin' => 'selected',
        ], (string) $row['source']);
    }

    private function fromNiche(int $wsId): JobResult
    {
        $cfg = $this->config->get($wsId);
        $feed = $this->service->feed($wsId, $cfg['niche'], $cfg['region']);

        if ($feed->isEmpty()) {
            $reason = $feed->error !== null ? ': ' . $feed->error : '';

            return JobResult::failed('no trends available' . $reason, $this->service->providerName());
        }

        $top = $feed->items[0];

        return JobResult::ready([
            'trend' => (string) $top['topic'],
            'niche' => (string) $top['niche'],
            'region' => (string) $top['region'],
            'score' => (int) $top['score'],
            'format' => (string) $top['format'],
            'source' => $feed->source,
            'freshness' => $feed->freshness,
            'origin' => 'niche',
        ], $feed->source);
    }
}
