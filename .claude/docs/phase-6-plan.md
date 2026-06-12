# Phase 6 Plan — Trend Radar Backend (APPROVED, awaiting `START PHASE 6`)

> Plan approved in Plan Mode (2026-06-12). Build is NOT unlocked — implementation
> starts ONLY on the exact token `START PHASE 6` (phase-discipline rule).
> Decisions: real adapters built flag-OFF (Phase 5 pattern); Creator Watch DEFERRED.

## Context

Phases 0–5 are accepted and committed. The pipeline runs end-to-end on durable
SQLite jobs; the first real-but-flag-off provider seam (TextProvider: Mock +
OpenAI) landed in Phase 5. The workflow's first node is `TREND → trend_fetch`,
currently served by `MockExecutor` with a placeholder result. Phase 6 turns that
placeholder into a real **TrendProvider** seam — the same adapter shape proven in
Phase 5 — so trends become a genuine, cached, niche-aware signal that feeds idea
generation and a face/faceless format recommendation, plus a "create from trend"
entry point and a Trend Radar UI page.

## Scope (Phase 6 only)

1. **`src/Trend/` adapter module** mirroring `src/Content/`:
   - `TrendProvider` interface + `TrendResult` value object (one internal shape:
     topic, score, region, source, freshness, suggested format, raw metadata).
   - `MockTrendProvider` — **default**, deterministic, niche-aware, offline.
   - **Real adapters behind a flag (default OFF), Phase-5 pattern:**
     `GoogleTrendsProvider` + `YouTubeTrendsProvider`, both using the existing
     `Kuyash\Http\HttpClient` seam so they are testable with `FakeHttpClient`
     (zero network in tests). Bindings select real only when off-by-default config
     says so AND a key is present; otherwise Mock.
   - TikTok third-party tier represented as a best-effort source that **degrades
     gracefully** (cached/optional, never blocks) — mock now, no fragile hard dep.
2. **`TrendExecutor`** (provider-agnostic glue) serving `trend_fetch`, registered
   in `bindings/core.php` exactly like `ContentExecutor` (one `register()` per
   type); `MockExecutor` drops `trend_fetch`.
3. **Trend cache** — new migration `0004_trends.sql`: cached trend rows with
   `workspace_id`, niche, source, payload, fetched_at, TTL (6–24h). Provider reads
   cache first; refresh on expiry; serves stale w/ freshness flag on provider fail.
4. **Niche config** — per-workspace niche/region used to scope fetches.
5. **Format recommendation** — derive face (shooting brief) vs faceless (stock)
   suggestion from trend signal, surfaced on the trend and carried toward IDEA.
6. **"Create from trend"** — action that seeds a new run from a selected trend
   (reuses the existing run-trigger path; no new engine).
7. **Trend Radar UI page** + route (`/trends`) — topic wall with empty/loading/
   error/stale states; freshness indicators; create-from-trend button.
8. **`api_quota_usage` counter** (carried from Phase 5 followups) — first
   rate-limited primary (YouTube/Google) needs basic quota accounting.
9. **Prompt-injection note** — real trend text is untrusted; keep instruction/data
   separation at the IDEA prompt boundary (Phase 5 Sanitizer clamps length/control
   chars; output-shape re-validation already bounds impact).

## Non-goals (explicit)

- **Creator Watch** — deferred to a later pass (documented follow-up), per decision.
- Instagram trend promises; any scraping as a hard pipeline dependency.
- Real TikTok trend integration (stays mock/best-effort).
- TTS, video, assembly, Pexels (Phase 7); R2 (Phase 8); compliance scoring (Phase 9).
- Reposting/republishing any creator content (compliance rule).
- New Studio/Create composer UI surface (engine + Trend Radar page only).

## Acceptance criteria / verification

- Full test suite green (prior 337 + new Trend tests, 0 FAIL); lint clean.
- **Zero network in tests** — real adapters exercised only via `FakeHttpClient`.
- Default provider is `mock`; real path requires flag-off-default + key (binding
  test, same as Phase 5's OpenAI selection test).
- Cache: second fetch within TTL hits cache (no provider call); past TTL refreshes;
  provider failure serves stale with a freshness flag (test all three).
- Format recommendation present on trend results; create-from-trend produces a
  valid run whose first job carries the trend context.
- Tenant isolation: every trend/cache query filters by `workspace_id`.
- No secrets committed; `.env.example` placeholders only; `config/trends.php`
  defaults to mock.
- Live smoke (two terminals, `[Terminal-1]` server / `[Terminal-2]` worker):
  `/trends` renders the mock wall → create-from-trend → run → worker → existing
  queue/approval flow still works.
- **Reviewers before close:** `security-auditor` (untrusted external input,
  injection, quota), `integration-reviewer` (adapter shape, mock-first, graceful
  degradation, no hallucinated API behavior), `php-architect`. Apply should-fix;
  defer rest to `phase-6-followups.md`. After acceptance + commit: security gate,
  then `git push origin main` automatically (no force).

## Risks

- **Google Trends "official alpha"** instability — mitigated by flag-off default +
  mock-first; do NOT hallucinate its payload; keep mocked if docs thin at build.
- **YouTube Data API quota** — guard with `api_quota_usage`; tests use fakes only.
- **Untrusted trend text → prompt injection** at IDEA — bounded by Sanitizer +
  output re-validation; document residual + Phase 9 hand-off.
- **Scope creep toward a trends dashboard** — keep the wall simple (no-overbuild).
- **Cache staleness UX** — show freshness honestly; never serve old data as fresh.

## Approval token

Build starts ONLY when the user writes: **`START PHASE 6`**
