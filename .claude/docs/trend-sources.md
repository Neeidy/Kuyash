# Kuyash — Trend Data Sources

## Reliability tiers
1. **Google Trends API (official, alpha — primary).** Search-interest signals by topic/region. Stable, legitimate, free.
2. **YouTube Data API (official — primary).** Trending/search for Shorts-relevant signals. Quota-managed.
3. **TikTok (third-party — best effort).** No official trend API (Research API is academic-only). Use a third-party provider/scraper via TrendProvider adapter. Fragile: must cache, degrade gracefully, never block the pipeline.
4. **Instagram (weakest — best effort).** No official trend data. Optional third-party signals only; never promised in UX.

## Rules
- All sources behind TrendProvider adapters; mock-first.
- Cache trend results (e.g. 6–24h TTL) — trends don't need real-time precision.
- Trend output feeds: idea generation, format recommendation (face/faceless), MUSIC NOTE suggestions.
- Platform-native trend sounds CANNOT be published via API — surface them as suggestions the user applies in-platform.
- If a provider fails: show cached data with freshness indicator; pipeline continues without trend input if user chooses.

## Creator Watch (Phase 6 optional sub-goal — person-based signals)
- Follow specific creators by handle (TikTok first) via the SAME third-party provider/adapter as TikTok trends — no new dependency class.
- Per creator, cache daily: top clips (most viewed / latest / most engaged) + follower/engagement stats. Render as a wall section inside Trend Radar.
- Purpose: research signal feeding idea generation ("what works in my niche right now, by whom") — NEVER repost/republish watched creators' content (compliance rule; copyright + inauthentic-content risk).
- Best-effort tier: cached, optional, degrades to "stale/no data", never blocks anything.
