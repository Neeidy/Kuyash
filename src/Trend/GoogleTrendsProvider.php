<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Content\Sanitizer;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpTransportException;
use Throwable;

/**
 * Real Google daily-trends adapter — OFF by default, selected only when
 * TREND_MOCK=false and TREND_PROVIDER=google_trends (see bindings/core.php).
 * Depends on the HttpClient seam, so tests drive every branch with a fake
 * transport and ZERO network.
 *
 * Source: the PUBLIC daily-trends endpoint (no API key), whose response is a
 * JSON object prefixed with the anti-JSON-hijacking guard ")]}'," — we strip
 * everything before the first '{' and decode the rest. The documented path is
 * default.trendingSearchesDays[].trendingSearches[].title.query (+ a
 * formattedTraffic string). This endpoint returns GENERAL daily trends, not a
 * niche query, so $niche is carried through as a tag only.
 *
 * NOTE (integrations rule — no hallucinated payloads): this maps the stable,
 * widely-used public endpoint. Migrating to the "official alpha" Google Trends
 * API later is a single-method re-map; until that payload is confirmed this
 * adapter stays behind the flag.
 *
 * Safety: failures become a TrendProviderException with a status/reason only;
 * all vendor text passes through Sanitizer before storage/render.
 */
final class GoogleTrendsProvider implements TrendProvider
{
    private const DEFAULT_ENDPOINT = 'https://trends.google.com/trends/api/dailytrends';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'google_trends';
    }

    public function fetch(string $niche, string $region, int $limit): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $timeout = (int) ($this->config['timeout'] ?? 15);

        $query = http_build_query([
            'hl' => 'en-US',
            'geo' => $this->geo($region),
            'ns' => 15,
        ]);

        try {
            $response = $this->http->get($endpoint . '?' . $query, ['Accept' => 'application/json'], $timeout);
        } catch (HttpTransportException $e) {
            throw new TrendProviderException('Google Trends request failed: ' . $e->getMessage());
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new TrendProviderException('Google Trends request failed (HTTP ' . $response->status . ')');
        }

        return $this->shape($response->body, $niche, $region, $limit);
    }

    /** @return list<TrendResult> */
    private function shape(string $body, string $niche, string $region, int $limit): array
    {
        // strip the ")]}'," (or similar) anti-hijack prefix Google prepends
        $brace = strpos($body, '{');
        if ($brace === false) {
            throw new TrendProviderException('Google Trends response was not parseable');
        }

        try {
            $decoded = json_decode(substr($body, $brace), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new TrendProviderException('Google Trends response was not valid JSON');
        }

        $days = $decoded['default']['trendingSearchesDays'] ?? null;
        if (!is_array($days)) {
            throw new TrendProviderException('Google Trends response had no trending searches');
        }

        $results = [];
        $rank = 0;
        foreach ($days as $day) {
            $searches = is_array($day) && isset($day['trendingSearches']) && is_array($day['trendingSearches'])
                ? $day['trendingSearches']
                : [];
            foreach ($searches as $search) {
                $titleQuery = $search['title']['query'] ?? null;
                if (!is_string($titleQuery) || trim($titleQuery) === '') {
                    continue;
                }
                $topic = Sanitizer::clean($titleQuery, 120);
                if ($topic === '') {
                    continue;
                }

                $traffic = $search['formattedTraffic'] ?? null;

                $results[] = new TrendResult(
                    $topic,
                    max(1, 100 - $rank * 5), // rank-derived; formattedTraffic kept in raw
                    'google_trends',
                    $niche,
                    $region,
                    FormatRecommender::recommend($niche, $topic),
                    ['traffic' => is_string($traffic) ? Sanitizer::clean($traffic, 24) : null],
                );
                if (++$rank >= $limit) {
                    return $results;
                }
            }
        }

        if ($results === []) {
            throw new TrendProviderException('Google Trends returned no usable trends');
        }

        return $results;
    }

    /** Region/country geo code for the endpoint; defaults to US on junk input. */
    private function geo(string $region): string
    {
        $code = strtoupper(substr(trim($region), 0, 2));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : 'US';
    }
}
