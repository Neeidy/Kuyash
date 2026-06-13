# Kuyash — Phase Plan (Authoritative)

Each phase starts ONLY with its exact token. Every phase ends with a verdict report + acceptance self-check.

## Phase 0 — Static Clickable Dashboard Demo · token: START PHASE 0
Goals: 13-screen static demo (HTML/CSS/vanilla JS, mock data, offline, no CDN) validating the full product vision. Screens: Dashboard Home, Trend Radar, Content Studio (script editor + shooting briefs + Quick Create tab), Content Library, Workflow Builder, Render Queue, Creators/Accounts, Post Preview, Live Logs/Jobs, Analytics, Usage/Credits & Costs, Settings, Onboarding Wizard.
Non-goals: any backend, PHP, database, auth, real APIs, external network requests.

## Phase 1 — Pure PHP App Skeleton · token: START PHASE 1
Goals: Caddy config, public/index.php, simple router, config loader, service container, central error handler, base layout system, clean folder structure.
Non-goals: business logic, auth, database content, integrations.

## Phase 2 — Auth + SQLite Foundation · token: START PHASE 2
Goals: users, sessions, workspaces, roles, migrations, Argon2id hashing, CSRF, session hardening, tenant isolation basics, WAL/busy_timeout. SaaS-ready schema (workspace_id everywhere), single-user UI.
Non-goals: multi-tenant UI, billing, teams. Security-auditor mandatory before close.

## Phase 3 — Content Library Backend · token: START PHASE 3
Goals: video/photo upload, strict validation, local storage first, metadata model, asset types (own/face/stock/AI), library UI on backend.
Non-goals: R2, ffmpeg processing, AI generation.

## Phase 4 — Workflow Engine · token: START PHASE 4
Goals: workflow JSON model (canonical nodes), validation, deterministic execution, SQLite job queue + worker, logs, retry/failure handling; **self-healing watchdog (stuck-job timeout → requeue/fail + dead-letter view)**; **append-only pipeline event log (immutable transitions feeding timeline UI, audit, analytics)**.
Non-goals: autonomous agent loops, real external calls.

## Phase 5 — Script & Caption Engine · token: START PHASE 5
Goals: OpenAI adapter (mock-first): ideas, scripts, hooks, CTAs, captions, hashtags; versioned prompt templates; draft/approved states; per-platform caption variations; cost recording; **seeded variation engine (hook pools, pacing, asset shuffle — measurably lowers slop similarity)**; **prompt-assist behind TextProvider adapter (OpenAI + optional Anthropic Claude, user-approved second provider) powering the Create composer's assisted modes**.
Non-goals: TTS, video, trends.

## Phase 6 — Trend Radar Backend · token: START PHASE 6
Goals: TrendProvider adapters — Google Trends API + YouTube Data API (official, primary), TikTok third-party (best-effort, graceful degradation), caching, niche config, format recommendation (face/faceless), "create from trend"; **Creator Watch (optional sub-goal, best-effort): follow specific creators by handle via the same third-party TikTok provider — top clips (most viewed / latest / most engaged) cached daily, rendered as a wall section inside Trend Radar; person-based signal next to topic-based trends; degrades gracefully, never blocks the pipeline (see trend-sources.md)**.
Non-goals: scraping fragile sources as hard dependencies; Instagram trend promises; reposting/republishing watched creators' content (Creator Watch is research signal ONLY — compliance rule).

## Phase 7 — Media Production (Stock Mode) · token: START PHASE 7
Goals: TTS adapter (OpenAI base), subtitles (script-timed SRT; Whisper alignment = follow-up), Pexels StockProvider, ffmpeg assembly (voice + visuals + subtitles + music note), safe execution, temp cleanup, render artifacts; **draft-first rendering (low-res preview render for approval, full render only after approve)**; **content-addressed asset cache (hash TTS text+voice / stock clips → reuse, cut cost & time)**; **reference-asset slice (reference-asset model, 2026-06-12): workspace default avatar pointer (`workspaces.avatar_asset_id`; per-ACCOUNT defaults arrive with accounts in Phase 10) + per-run reference pick (`runs.reference_asset_id`) + `face`-format runs resolve VISUALS=LIBRARY to the selected reference asset (ready library clip/photo; photo → ffmpeg still-clip). NO AI generation here**; **dashboard cockpit first pass (ui-style-guide cockpit spec): KPI strip + active runs panel + awaiting-approval strip with render thumbnails — built from REAL data now available (runs, jobs, renders); countdown/deltas slots stay placeholder until Phase 10**.
Non-goals: AI video generation (Phase 12), ElevenLabs (V2 premium), avatar generation (V2), accounts table (Phase 10), shooting-brief/awaiting_recording (REMOVED — superseded by the reference-asset model, not deferred).

## Phase 8 — Cloudflare R2 · token: START PHASE 8
Goals: private storage, signed URLs, StorageProvider abstraction, upload/delete lifecycle, migration from local.
Non-goals: public buckets, CDN features.

## Phase 9 — Compliance Agent + Approval Modes · token: START PHASE 9
Goals: compliance checks (AI-label requirement, slop/variation score, format rules), Manual/Auto approval modes with truthful records, guardrails (per-account daily caps, budget caps, kill switch, daily digest, auto-fallback), audit logs.
Non-goals: any untruthful record. Compliance-reviewer mandatory before close.

## Phase 10 — Zernio Publishing · token: START PHASE 10
Goals: mock Zernio client → real ONLY after doc-gate (.claude/docs/zernio-notes.md); account OAuth connect flows; publish/schedule; per-platform AI-label automation; webhook handling; status reconciliation; idempotency; **next-post countdown feed (topbar "NEXT UP <account> — mm:ss" + "last posted" line, fed by the schedule queue — UI spec in ui-style-guide.md)**; **daily account_metrics snapshot job (followers/views/engagement via Zernio/platform APIs where available, best-effort) → dashboard/accounts show growth deltas (+/−) against previous snapshot; missing data degrades to "no data", never blocks**.
Non-goals: any real call before docs. Security-auditor + compliance-reviewer mandatory before close.

## Phase 11 — Usage, Costs & Credit Ledger · token: START PHASE 11
Goals: per-call cost recording (model, tokens/seconds, price), per-workspace credit ledger, budget caps wiring, usage UI; **real cost pre-flight estimation (estimate before pipeline start; block over-budget runs — replaces the Phase 0 mock)**.
Non-goals: Stripe, real payments. Security-auditor mandatory before close.

## Phase 12 — Quick Create AI Video · token: START PHASE 12
Goals: VideoGenProvider adapter (single provider via aggregator, image-to-video), credit-gating, mandatory AI label, Quick Create UI wiring; input = an uploaded photo OR a library reference asset + prompt (reference-asset model: "make my cat cook in the kitchen" lives HERE, not in Phase 7).
Non-goals: multiple providers, AI avatars, text-to-video epics. Compliance-reviewer mandatory before close.

## Phase 13 — Hardening · token: START PHASE 13
Goals: full security review, test checklist, SQLite + media backup/restore, Caddy/Tunnel review, failure recovery, production readiness checklist.
Non-goals: new features.

## Phase 14 — i18n (TR/EN) · token: START PHASE 14
Added after V1 (0–13) shipped — a missed original requirement (the real backend shipped English-only). NEW mini-phase, pure presentation layer: the DB stores message KEYS not localized text, so there is no stored-text migration and approval-record truthfulness is untouched.
Goals: `Core/I18n` static translator (active locale + `t($key,$params)`, fallback `locale → en → key`, `{name}` interpolation) + `View::t` (escaped); `lang/en.php` + `lang/tr.php` flat dictionaries with the former `Messages::MAP/EVENTS/STATUS` folded in (Messages becomes the locale-aware facade — the "swap one class" design realized; public API + call sites unchanged); migration 0012 `users.locale` (NOT NULL DEFAULT 'en' CHECK en/tr); per-user locale resolution (logged-in → session-cached column, anon → `APP_LOCALE`); `/locale` CSRF-gated switch (persists column + session, safe path-only redirect-back); `<html lang>`; EN/TR topbar toggle; ~250 UI literals across the 21 templates → `View::t`. Locked decisions: (a) EN = default + source language, TR selectable (missing TR key → EN); (b) per-USER locale (SaaS-ready), not per-workspace.
Non-goals: a third language, RTL, plural-rules engine ({n} only), translating DB content (captions/scripts stay authored), date/number locale reformatting, gettext/.po, auto-translation. Reviewers: ux-reviewer (TR-length layout) + compliance-reviewer (TR truthfulness — gate) + focused security-auditor (/locale CSRF, locale CHECK).

# Experience Layer (Phases 15-18) - premium UI/UX on the real app

Why: the real server-rendered app shipped functional but visually plain, while the (now-removed) phase-0-demo was premium/animated. This closes that gap by porting the demo's design system + motion + live layer onto the REAL app via **progressive enhancement** (server renders real data -> a client layer adds motion; SSE adds live updates). Binding spec: `.claude/docs/ui-style-guide.md` + the demo (in git history) as visual reference. Sliced by LAYER, not by screen. Each phase independently shippable - you can stop after any and still have a coherent product. Built on the existing mock-mode app (no real APIs required).

## Phase 15 - Design Foundation (look) - token: START PHASE 15
Goals: design tokens in one place (near-black neutral scale, ONE brand accent + 5 semantic status colors, 4px spacing grid, one card/control radius, 1px borders); self-hosted typography (Inter/JetBrains Mono - already in public/assets/fonts); consistent component layer (cards, badges, buttons, tables, form fields, empty/loading/error states) matching the demo's premium dark identity; restyle ALL ~21 server-rendered templates to this identity. NO motion, NO new features, NO backend changes - purely make it LOOK premium and consistent. Preserve every function + i18n (TR/EN) untouched.
Non-goals: animations, SSE/live, command palette, drawers, new screens, any PHP/DB/route change. Acceptance: every screen visually consistent with the style guide; before/after looks like a different product; zero functional regression (full suite green); TR/EN still 100%. ux-reviewer mandatory.

## Phase 16 - Motion & Interaction (feel) - token: START PHASE 16
Goals: motion-token system (durations/easings per ui-style-guide; reduced-motion zeroes all); View Transitions on navigation (defined CSS-fade fallback); @starting-style modal/drawer entrances; staggered list reveals; hover lifts; pulse-ring status dots (processing/publishing only); count-up numbers on KPIs; command palette (Cmd+K) for navigation/actions; global detail drawer (slide-over for queue/trend/account rows). Client-side enhancement only - no backend/data changes.
Non-goals: SSE/live data, new backend, new screens. Acceptance: motion perceptible but premium (every animation maps to a state change; reduced-motion respected; 60fps); Cmd+K + drawer work; zero functional regression. ux-reviewer mandatory.

## Phase 17 - Live Ops / SSE (alive) - token: START PHASE 17
Goals: pure-PHP SSE (Server-Sent Events) streaming endpoint + JS live client sharing one event interface (mock-first pattern - client works whether events come from a ticker or real SSE); live dashboard widgets fed by real dev-DB state: ticking KPIs, streaming activity feed, live render-queue progress, pulsing job status, next-post countdown ("NEXT UP <account> - mm:ss"). Tenant-scoped streams; short SQLite reads only (no long transactions on the stream); graceful reconnect; reduced-motion/no-JS fallback to static render.
Non-goals: real external API calls, websockets, new queue system. The ONE phase with real backend surface - keep isolated. Acceptance: dashboard updates live without refresh; SSE endpoint tenant-isolated + secure; degrades to static when JS/SSE unavailable; tests for event interface + tenant scoping. security-auditor + ux-reviewer mandatory.

## Phase 18 - Signature Visualizations (distinctive) - token: START PHASE 18
Goals: portfolio-grade wow pieces - workspace->account branching node graph (read-only visualization per account; engine stays linear, this only VISUALIZES); account stat deltas (up/down growth indicators, mock until Phase 10 metrics live); platform-skin post preview (content inside IG/TikTok/YT chrome mock); trend ticker marquee (pausable, hover-stops). Additive presentation only.
Non-goals: changing the workflow engine, real metrics backend (deltas mock until go-live), Creator Watch (separate deferred feature), boost/ads. Acceptance: each visualization renders from real workspace/account data; node graph read-only + mobile-stacked fallback; zero engine change; zero functional regression. ux-reviewer mandatory.

## V2 / SaaS-ification Parking Lot (documented, NOT planned)
Stripe billing & plans, multi-tenant UI, customer onboarding, team roles, AI avatars (HeyGen-class), ElevenLabs premium voice/music, additional AI-video providers, lip-sync/dubbing, multi-language; **boost suggestion (detect high-engagement posts → "Promote in Ads Manager" deep link — recommendation + link ONLY, never an ads platform)**; **workspace→account branching graph visualization (read-only view; engine stays linear)**; **revenue/MRR panel (meaningful only after Stripe)**.
