<?php

declare(strict_types=1);

namespace Kuyash\Content;

/**
 * How long a post's text is, per platform, measured on what will ACTUALLY be
 * sent (Phase 25).
 *
 * The assembled string is body + AI-disclosure line (where one is required) +
 * hashtag block — mirroring, in order, what ZernioPublishExecutor and
 * ZernioPublishProvider do. Measuring only the body would understate every
 * count, and the disclosure is appended after all upstream clamping, so it can
 * only push the total up.
 *
 * ⚠ The limits themselves are UNVERIFIED (see config/platforms.php). This class
 * reports "over" as a fact about the configured number, and the caller decides
 * what to do with it — today: WARN, never block. Nothing here refuses anything.
 */
final class PlatformLimits
{
    /**
     * @param array{limits: array<string, array{caption_chars: int, hashtags: int}>, warn_at: float} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /** Platforms the limits are known for. */
    public function platforms(): array
    {
        return array_keys((array) ($this->config['limits'] ?? []));
    }

    /**
     * Exactly the string the platform receives — the same composition, in the
     * same order, as the publish path.
     *
     * @param list<string> $hashtags
     */
    public function assemble(string $body, array $hashtags, string $disclosureLine = ''): string
    {
        $text = $disclosureLine === ''
            ? rtrim($body)
            : \Kuyash\Publish\Disclosure::compose($body, $disclosureLine);

        if ($hashtags !== []) {
            $text = rtrim($text) . "\n\n" . implode(' ', $hashtags);
        }

        return $text;
    }

    /**
     * Measure one platform.
     *
     * @param list<string> $hashtags
     *
     * @return array{platform: string, chars: int, chars_limit: int, tags: int, tags_limit: int,
     *               over_chars: bool, over_tags: bool, near_chars: bool, near_tags: bool,
     *               near_chars_at: int, near_tags_at: int, known: bool}
     */
    public function measure(string $platform, string $body, array $hashtags, string $disclosureLine = ''): array
    {
        $limit = (array) ($this->config['limits'][$platform] ?? []);
        $known = $limit !== [];
        $charsLimit = (int) ($limit['caption_chars'] ?? 0);
        $tagsLimit = (int) ($limit['hashtags'] ?? 0);
        $warnAt = (float) ($this->config['warn_at'] ?? 0.9);

        $chars = mb_strlen($this->assemble($body, $hashtags, $disclosureLine));
        $tags = count($hashtags);

        return [
            'platform' => $platform,
            'chars' => $chars,
            'chars_limit' => $charsLimit,
            'tags' => $tags,
            'tags_limit' => $tagsLimit,
            // An unknown platform reports no opinion rather than a false "fine".
            'over_chars' => $known && $charsLimit > 0 && $chars > $charsLimit,
            'over_tags' => $known && $tagsLimit > 0 && $tags > $tagsLimit,
            'near_chars' => $known && $charsLimit > 0 && $chars <= $charsLimit && $chars >= (int) ($charsLimit * $warnAt),
            'near_tags' => $known && $tagsLimit > 0 && $tags <= $tagsLimit && $tags >= (int) ($tagsLimit * $warnAt),
            // the counts the UI needs to reach the same verdict live, so the
            // promised "warns as you approach the limit" is actually visible
            // while typing and not only after a save
            'near_chars_at' => $known ? (int) ($charsLimit * $warnAt) : 0,
            'near_tags_at' => $known ? (int) ($tagsLimit * $warnAt) : 0,
            'known' => $known,
        ];
    }

    /**
     * Measure several platforms at once.
     *
     * @param array<string, string> $bodies   platform → body
     * @param list<string>          $hashtags shared across platforms
     * @param array<string, string> $disclosureFor platform → line ('' where none applies)
     *
     * @return array<string, array<string, mixed>>
     */
    public function measureAll(array $bodies, array $hashtags, array $disclosureFor = []): array
    {
        $out = [];
        foreach ($bodies as $platform => $body) {
            $out[(string) $platform] = $this->measure(
                (string) $platform,
                (string) $body,
                $hashtags,
                (string) ($disclosureFor[$platform] ?? ''),
            );
        }

        return $out;
    }
}
