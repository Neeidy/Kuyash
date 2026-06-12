# Phase 6 Follow-ups (deferred by design/review, NOT forgotten)

Status: final at phase close. Three reviewers — security-auditor **PASS (GO)**,
integration-reviewer **PASS (0 blockers)**, php-architect **PASS (GO)** — **0
blockers**. Cheap should-fix/nice-to-have items were APPLIED during the phase;
the rest are tracked here. Suite: **384 PASS / 0 FAIL**, zero network in tests.

## Applied during the phase (from reviews)
- **Index covers the rank-ordered batch read** (php SF2): `idx_trends_lookup`
  is now `(workspace_id, niche, region, fetched_at DESC, rank)` so `cached()`'s
  `ORDER BY rank` is index-covered, not a filesort.
- **Credential-in-URL guard comment** (security S1): `CurlHttpClient::send()`
  documents that `$url` may carry a key in its query string and must never be
  logged/surfaced; only the transport error string is, which never echoes `$url`.
- **Variable shadow removed** (security N1): `GoogleTrendsProvider` parse loop
  uses `$titleQuery` instead of reusing `$query` (the URL query string).

## Accepted tradeoffs (explicit, recorded — do not "discover" later)
- **Web read path performs the live provider fetch** (php SF1, the substantive
  finding). `TrendController::index()` / `/trends/refresh` call
  `TrendService::feed()`, which on cold/expired cache makes a synchronous
  outbound GET (up to the configured timeout). This is DORMANT in Phase 6:
  the default provider is the offline mock (instant), so no page load blocks and
  no outbound call happens. **HARD GATE:** before enabling any REAL TrendProvider
  in production, move the live fetch to the worker (enqueue a refresh/`trend_fetch`
  job; serve the web read path cache-only with a "refresh queued" empty state).
  Until then real providers stay flag-OFF. Owner: Phase 7+ (when a real provider
  is first turned on) or Phase 13 hardening.
- **GoogleTrendsProvider targets the UNOFFICIAL public daily-trends endpoint**
  (integration SF1). The mapped shape (`)]}',` prefix +
  `default.trendingSearchesDays[].trendingSearches[].title.query` +
  `formattedTraffic`) is real and long-stable (pytrends-class), NOT hallucinated,
  but it is not the "official alpha" API named in `trend-sources.md`.
  **Verdict: stays mock-only for production** until the official alpha payload is
  documented; flipping it is a single-method re-map. YouTubeTrendsProvider, by
  contrast, maps the documented Data API v3 `search.list` and is real-ready
  (flag-OFF until a token + key).

## Deferred to later phases (from reviews)
### Compliance / Security
- **Semantic prompt-injection from REAL trend text** (security S2; carried from
  phase-5-followups). `Sanitizer::clean` strips control chars + clamps length
  but does NOT neutralize a trend literally named "ignore previous instructions…".
  Impact is bounded today (real providers OFF by default; trend text is embedded
  inside a quoted JSON-shaped instruction; `OpenAiTextProvider::shape()`
  re-validates output shape; no secret/tool in the prompt to exfiltrate). Phase 9
  owns instruction/data separation (fence untrusted trend text in a clearly
  delimited user-data block). Do NOT enable real trend providers by default
  before that pass.
- **`trends.raw` is unconsumed surface** (security S3): the shaped row carries
  `raw` (sanitized vendor metadata) for audit, but no executor/template reads it.
  Harmless today (template escapes everything via `View::e`, never prints `raw`).
  If a future template renders `raw`, escape it. Optional: column allowlist on
  the repository shape instead of `SELECT *`.

### Integration / Architecture
- **Region is not ISO-validated** (integration SF2): niche-config region is
  clamped to `^[A-Z]{2}$` only; a bogus `ZZ` would 403/empty on a real provider
  and degrade safely (never blocks). A small country allowlist would avoid silent
  empty feeds. Low priority.
- **Three independent niche lists** (php N3): `TrendConfigRepository::NICHES`,
  `MockTrendProvider::POOLS` keys, and `FormatRecommender::FACE_NICHES` can drift.
  Safe today (mock falls back to `general`; controller validates the allowlist).
  Consider a single `Niches` source of truth.
- **Shared `rankScore($rank)` helper** (integration N1): YouTube (decay 7) and
  Google (decay 5) both derive score from rank with a `max(1, …)` clamp; one
  helper would keep the "score is rank-derived, not a real metric" contract in
  one place.
- **Shared 2-letter region clamp** (security N3): `regionCode`/`geo`/`cleanRegion`
  duplicate the same clamp across two providers + the controller.

## Phase 6 trigger items carried forward (from the phase plan / phase-5-followups)
- **Creator Watch** (person-based signals): DEFERRED by user decision. Follow
  creators by handle via the same third-party TikTok tier → daily cached
  top-clips wall section in Trend Radar. Research signal ONLY — never
  repost/republish (compliance). A later pass; not in Phase 6.
- ~~**awaiting_recording + shooting-brief PAUSE flow** (face format)~~ —
  **SUPERSEDED (2026-06-12, user decision): the shooting-brief / awaiting_recording
  flow is REMOVED entirely (wrong product assumption — no human recording step).**
  Replaced by the **reference-asset model**: `face` format = build the video around a
  reference asset (workspace/account avatar default, any library photo/clip, or
  per-run pick), resolved at VISUALS. Phase 7 = resolution only (no AI generation);
  Phase 12 = photo/reference + prompt → AI image-to-video; HeyGen-class avatar
  generation = V2. The Phase 6 `format` recommendation (face/faceless) stays and now
  means reference/faceless. `awaiting_recording` remains an unused schema stub.
  See `.claude/docs/architecture-decisions.md`.
- **Real TikTok / Instagram trend providers**: stay mock/best-effort; no fragile
  hard dependency (trend-sources.md). TikTok third-party tier degrades gracefully.
- **`api_quota_usage` budget enforcement**: Phase 6 only RECORDS units per
  provider per UTC day. Caps/over-budget blocking are Phase 11 (credit ledger).
- **401/403 non-retryable classification** (carried from phase-5-followups): a
  bad key still retries then dead-letters; fold the non-retryable JobResult/Engine
  signal into Phase 11/13 hardening.
