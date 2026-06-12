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
