<?php

declare(strict_types=1);

namespace Kuyash\Trend;

use Kuyash\Content\Sanitizer;
use Kuyash\Http\HttpClient;
use Kuyash\Http\HttpTransportException;
use Throwable;

/**
 * Real YouTube Data API v3 adapter (search.list) — OFF by default, selected
 * only when TREND_MOCK=false, TREND_PROVIDER=youtube and a key is present
 * (see bindings/core.php). Depends on the HttpClient seam, so tests drive every
 * branch with a fake transport and ZERO network.
 *
 * Maps the documented response shape — items[].snippet.title / .channelTitle,
 * items[].id.videoId — into TrendResult. search.list has no per-item interest
 * metric, so score is rank-derived (best-first). The call costs 100 quota units
 * (Google's documented search cost); TrendService records that against the day.
 *
 * Honesty + safety:
 * - Every failure (transport, non-2xx, malformed JSON) becomes a
 *   TrendProviderException carrying only a status/reason — never the API key
 *   (which rides in the query string), request headers, or raw body.
 * - All vendor text is run through Sanitizer before it is stored or rendered
 *   (untrusted external input — prompt-injection bounding, see compliance).
 */
final class YouTubeTrendsProvider implements TrendProvider
{
    private const DEFAULT_ENDPOINT = 'https://www.googleapis.com/youtube/v3/search';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly HttpClient $http,
        private readonly array $config,
    ) {
    }

    public function name(): string
    {
        return 'youtube';
    }

    public function fetch(string $niche, string $region, int $limit): array
    {
        $endpoint = (string) ($this->config['endpoint'] ?? self::DEFAULT_ENDPOINT);
        $timeout = (int) ($this->config['timeout'] ?? 15);

        $query = http_build_query([
            'key' => (string) ($this->config['api_key'] ?? ''),
            'part' => 'snippet',
            'type' => 'video',
            'videoDuration' => 'short',     // Shorts-relevant (<4 min)
            'order' => 'viewCount',
            'maxResults' => max(1, min(50, $limit)),
            'regionCode' => $this->regionCode($region),
            'relevanceLanguage' => 'en',
            'q' => $niche === 'general' || $niche === '' ? 'shorts' : $niche,
        ]);

        try {
            $response = $this->http->get($endpoint . '?' . $query, ['Accept' => 'application/json'], $timeout);
        } catch (HttpTransportException $e) {
            throw new TrendProviderException('YouTube request failed: ' . $e->getMessage());
        }

        if ($response->status === 403) {
            // 403 from this API is almost always quota exhaustion or a bad key
            throw new TrendProviderException('YouTube quota exceeded or forbidden (HTTP 403)');
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new TrendProviderException('YouTube request failed (HTTP ' . $response->status . ')');
        }

        return $this->shape($response->body, $niche, $region, $limit);
    }

    /** @return list<TrendResult> */
    private function shape(string $body, string $niche, string $region, int $limit): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new TrendProviderException('YouTube response was not valid JSON');
        }

        $items = is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])
            ? $decoded['items']
            : null;
        if ($items === null) {
            throw new TrendProviderException('YouTube response had no items');
        }

        $results = [];
        $rank = 0;
        foreach ($items as $item) {
            $title = $item['snippet']['title'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                continue;
            }
            $topic = Sanitizer::clean($title, 120);
            if ($topic === '') {
                continue;
            }

            $channel = $item['snippet']['channelTitle'] ?? null;
            $videoId = $item['id']['videoId'] ?? null;

            $results[] = new TrendResult(
                $topic,
                max(1, 100 - $rank * 7), // rank-derived: no per-item metric on search.list
                'youtube',
                $niche,
                $region,
                FormatRecommender::recommend($niche, $topic),
                [
                    'channel' => is_string($channel) ? Sanitizer::clean($channel, 80) : null,
                    'video_id' => is_string($videoId) ? Sanitizer::clean($videoId, 32) : null,
                ],
            );
            if (++$rank >= $limit) {
                break;
            }
        }

        if ($results === []) {
            throw new TrendProviderException('YouTube returned no usable trends');
        }

        return $results;
    }

    /** Two-letter ISO-3166 region for the API; defaults to US on junk input. */
    private function regionCode(string $region): string
    {
        $code = strtoupper(substr(trim($region), 0, 2));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : 'US';
    }
}
