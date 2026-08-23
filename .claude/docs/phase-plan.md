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

# Experience Layer (Phases 15-20) - premium UI/UX on the real app

Why: the real server-rendered app shipped functional but visually plain, while the (now-removed) phase-0-demo was premium/animated. This closes that gap by porting the demo's design system + motion + live layer onto the REAL app via **progressive enhancement** (server renders real data -> a client layer adds motion; SSE adds live updates). Binding spec: `.claude/docs/experience-layer-plan.md` (full) + `.claude/docs/ui-style-guide.md` + `.claude/docs/design/prototype-v3.html` (approved visual source of truth). Sliced by LAYER, not by screen. Each phase independently shippable - you can stop after any and still have a coherent product. Built on the existing mock-mode app (no real APIs required).

## Phase 15 - Design Foundation (look) - token: START PHASE 15
Goals: design tokens in one place (near-black neutral scale, ONE brand accent + 5 semantic status colors, 4px spacing grid, one card/control radius, 1px borders); self-hosted typography (Inter/JetBrains Mono - already in public/assets/fonts); consistent component layer (cards, badges, buttons, tables, form fields, empty/loading/error states) matching the demo's premium dark identity; restyle ALL ~21 server-rendered templates to this identity. NO motion, NO new features, NO backend changes - purely make it LOOK premium and consistent. Preserve every function + i18n (TR/EN) untouched.
Non-goals: animations, SSE/live, command palette, drawers, new screens, any PHP/DB/route change. Acceptance: every screen visually consistent with the style guide; before/after looks like a different product; zero functional regression (full suite green); TR/EN still 100%. ux-reviewer mandatory.

**Elevation Decision Gate (added after the Phase 15 plan):** Exploration found the premium dark design system was ALREADY ported into the real app (base.css/app.css carry the demo tokens, fonts, and component layer), so Phase 15 executed as a **consolidation pass** (drift-fix: undefined tokens, off-palette grays, the 3 number idioms, native date/time dark inputs) rather than a from-scratch restyle. Because the result is now visible on screen, the elevation question is decided **AT Phase 15 acceptance — not deferred**: the user looks at the rendered app and chooses NOW — **not needed → proceed to Phase 16 (Motion)**; **needed → a concrete, scoped Phase 15.5 (Elevation)** is written first (reworked hierarchy/density, elevated cards/surfaces, richer empty/loading/error states; still presentation-only — no PHP/DB/route/i18n change). No "we'll see later" — the gate forces a yes/no with a defined next phase. Carry-in for whichever path: `phase-15-followups.md` A11Y-1 (faint `--text-3` tier sits below WCAG AA on small text).

## Phase 15.9 — Loop & Visual-Test Infra · token: START PHASE 15.9
Goals: local run+seed script; headless screenshot tool (375/768/1280 × EN/TR, console-error capture; dev-only Node tooling — app stays build-free); `/go` autonomous loop command (plan→build→3-gate test→verdict→branch-commit→/clear; fail-cap 2 → stop-and-report; human-gate table); orchestrator + ux-reviewer/qa-reviewer/security-auditor gate task templates. NO product UI/DB/route change. Full spec: `.claude/docs/experience-layer-plan.md` §2–3. Continuous run — no per-phase stop (review at end).

## Phase 16 — Motion & Interaction Core · token: START PHASE 16
Goals: motion tokens (reduced-motion zeroes all); static ambient gradient bg (no blur/anim); sliding-pill nav, hover-lift, scroll-reveal; Cmd+K command palette; global slide-over drawer; KPI count-up; teal accent (#2ff0d2) adopted globally (approved). GPU-light rules BINDING (animate transform/opacity/dashoffset ONLY; no animated blur, no persistent backdrop-filter, no spinner). Client-only enhancement. Full spec: experience-layer-plan.md §4.
Non-goals: SSE/live, new backend, new screen, pipeline node-graph (P18), inline player (P17). Acceptance: motion premium + state-mapped; reduced-motion respected; 60fps, idle GPU ~0; Cmd+K + drawer work; §1.2 clean; full suite green; TR/EN intact. Continuous run — no per-phase stop (review at end). ux-reviewer.

## Phase 17 — Signature Dashboard · token: START PHASE 17
Goals: business KPIs (count-up + sparkline + delta, REAL data, "no data" fallback); account live-stream widgets (slow ken-burns video transform-only, like/comment/share, follower growth, health); awaiting-approval INLINE player (in-card playback + progress + "playing" pill, does NOT open drawer — fixes old bug); truthful compliance/AI badges. Real dev-DB data; no new DB surface. Full spec: §5.
Non-goals: pipeline node-graph (P18), SSE liveness (P19), new metrics backend. Acceptance: dashboard renders from real data; missing data → "no data"; inline player plays in-card; badges truthful; empty/loading/error present; 60fps; 375/768/1280 OK; no regression. Continuous run — no per-phase stop (review at end). ux-reviewer + compliance-reviewer.

## Phase 18 — Pipeline / Workflow Visualization · token: START PHASE 18
Goals: node-graph spaced boxes + connectors (done=solid green, active=left→right FILL-FLOW + leading dot, wait=dim dashed) bound to REAL job state; status ICONS not text (✓ / ⚡ heartbeat / dashed-ring); active-box heartbeat glow + rising fill; click box → side detail panel (PLAIN language, NO tech jargon: no ffmpeg/TTS/queue); mobile stacked fallback. VISUALIZES only — engine stays linear. Full spec: §6.
Non-goals: workflow engine change, SSE live progress (P19), new node type. Acceptance: real pipeline state reflected (done/active/wait); fill-flow only on active segment; box click opens correct plain panel; no jargon; mobile stacked works; §1.2 clean; engine untouched; no regression. Continuous run — no per-phase stop (review at end). ux-reviewer.

## Phase 19 — Live Ops / SSE · token: START PHASE 19
Goals: pure-PHP SSE endpoint + JS live client (one event interface, mock-first); live KPIs, activity feed, render-queue progress, pulsing job status, topbar "NEXT UP — mm:ss" countdown + heartbeat; tenant-scoped, SHORT SQLite reads only (no long transaction on stream), graceful reconnect, static/no-JS fallback. The one real backend surface — kept isolated. Full spec: §7.
Non-goals: real external API calls, websockets, new queue system. Acceptance: dashboard updates live without refresh; SSE tenant-isolated + secure; degrades to static when JS/SSE off; event-interface + tenant-scope tests; no long transaction; no regression. Continuous run — no per-phase stop (review at end). security-auditor + ux-reviewer mandatory.

## Phase 20 — Polish, Perf & A11y Close · token: START PHASE 20
Goals: GPU/perf verification (idle GPU ~0, 60fps, §1.2 violation sweep — zero persistent backdrop / animated blur / spinner); WCAG AA (incl. phase-15-followups A11Y debts); keyboard nav (Cmd+K, drawer, focus-trap, focus-visible); aria-current/SR labels; truthful-badge FINAL audit; Experience security pass; full reduced-motion coverage. Full spec: §8.
Non-goals: new features. Acceptance: perf targets met + measured; a11y AA; all badges truthful; security clean; reduced-motion 100%; no regression. Continuous run — no per-phase stop (review at end). ux-reviewer + security-auditor (+ compliance-reviewer).

## Phase 21 — Full Experience Conversion (ALL screens) · token: START PHASE 21
Why: Phases 16-20 delivered the v3 signature work on the DASHBOARD + global motion/palette/drawer ONLY (plan-scoping gap). Audit found the other ~10 screens unchanged, the requested account live-stream widgets missing, and heavy UI tech-jargon. Phase 21 finishes the job in ONE pass.
Goals: convert ALL 12 screens (dashboard, quick, trends, library, workflows, queue, accounts, logs, digest, usage, settings, login) to the v3 visual language; build the real account live-stream widget (video + like/comment/share + follower growth) on dashboard + accounts; FULL jargon scrub (worker/mock/policy/render_review/script_draft/Faz/düğüm → user language, zero technical detail anywhere); inline player with mock media; fix queue red-block defect; verify SSE liveness. Global motion/⌘K/drawer everywhere. Presentation + i18n + mock-data ONLY — engine/route/DB/real-API untouched. Full spec: `.claude/docs/phase-21-full-conversion.md`.
Non-goals: workflow engine change, new routes/DB schema, real external API. Acceptance: every screen v3-consistent; ZERO UI jargon; account widgets live (mock); defects fixed; ⌘K+drawer+motion everywhere; 375/768/1280; reduced-motion; truthful badges; 732+ tests green. Reviewers: ux-reviewer (12-screen jargon sweep = zero) + qa-reviewer + security-auditor + compliance-reviewer. Human gate: YES (single, at end). NOT run via continuous /go — token-gated, reviewed once at end.

## Phase 22 — Panel + Real Data · token: START PHASE 22
Why: a read-only audit + live provider probe found the dashboard's account cards had NO metric source (every figure a deterministic sample), one real data bug (a blind INSERT in connect() forked a duplicate account on every reconnect), and one UI defect (the sliding nav pill used the overshooting `--spring` curve, so it visibly sprang back while browsing tabs). The probe also established the boundary this phase respects: `GET /accounts` returns a REAL follower count, while the analytics post list comes back empty — so audience is real today, per-post engagement is not.
Goals: read-only metrics seam on the publish adapter (`accountMetrics()` — audience AND per-post engagement, so the path lights up automatically once the provider reports posts); daily snapshot chore in the worker (zero spend, one row per account per UTC day, migration 0014 `account_metrics` + `accounts.followers_count`); real follower wired through sync → card; account de-duplication (revive-existing connect + migration 0015: re-point posts → delete duplicates → UNIQUE index); nav pill easing fix; relative-time display so machine timestamps never reach the UI.
Non-goals: scheduled/recurring publishing (Phase 23), fabricating engagement, flipping any mock flag, engine/node-graph change. Deleting labelled demo data (it is kept, honestly marked, for the case study).
Acceptance: every fabricated number carries the "sample" chip and every unmarked number came from the provider; snapshot idempotent + tenant-scoped + zero usage rows; duplicates repaired with no orphaned posts; tests green. Reviewers: security-auditor + ux-reviewer + compliance-reviewer.

## Phase 23 — Planned Publishing (weekly slots) · token: START PHASE 23
Why: single-instant scheduling already works end to end (approval → `runs.publish_after` → the queue's `run_after` gate → the adapter's `scheduledFor`). What is missing is a repeating weekly plan on top of it.
Goals: `publish_slots` schema (weekday + time, optionally per account) + per-workspace timezone (removing the adapter's hard-coded `timezone: 'UTC'`); slot resolver (next matching UTC instant); slot picker on the approval queue; "next up" calendar + countdown on the cockpit (completes the Phase 10 deferral); per-account targeting.
Non-goals: a general-purpose scheduler/cron engine, per-post ad scheduling. Acceptance: a picked slot resolves to the correct UTC instant across DST, publishes when due, and shows truthfully in the cockpit.

## V2 / SaaS-ification Parking Lot (documented, NOT planned)
Stripe billing & plans, multi-tenant UI, customer onboarding, team roles, AI avatars (HeyGen-class), ElevenLabs premium voice/music, additional AI-video providers, lip-sync/dubbing, multi-language; **boost suggestion (detect high-engagement posts → "Promote in Ads Manager" deep link — recommendation + link ONLY, never an ads platform)**; **workspace→account branching graph visualization (read-only view; engine stays linear)**; **revenue/MRR panel (meaningful only after Stripe)**.
