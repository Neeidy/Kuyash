# Phase 17 — Signature Dashboard (plan)

> `/go` loop, stacked on `feat/phase-16-motion-core`. Gates: ux (visual) + qa (code) + security
> + **compliance (4th, mandatory — truthful badges / no fabricated metrics)**.
> Visual source: `prototype-v3.html` (KPI strip, approval cards, accounts card). Spec: `experience-layer-plan.md` §5.

## Goal
Turn the dashboard into the v3 showcase, bound to REAL dev-DB data. Missing data shows an honest
"no data" — never fabricated. Three pieces: a business KPI strip, v3 inline-player approval cards,
and an honest connected-accounts widget.

## Honesty constraints (compliance gate is hard here)
- The `accounts` table stores NO follower/engagement metrics. So the widget shows ONLY real fields
  (platform, handle, health, reference) — NO fabricated likes/followers/growth. This is deliberate.
- Approval cards show the truthful pre-approval state ("Awaiting your review" + AI-label / compliance
  chips) and a REAL approve/reject form posting to the existing `/queue/job/{id}/approve|reject`
  (CSRF) — a dashboard approval records a real human approval, never a fake stamp.
- Inline player loads the real draft via `<video preload="none">` (so the media-free visual seed
  never 404s on page-load); when no render exists it shows an honest "preview pending" placeholder
  (the app's existing pattern), not a fake video frame.
- KPIs are real (CreditLedger + UsageRepository + render count); cost-per-content is `null` → "—"
  when there are no renders yet.

## Scope (in)
1. **Cockpit** (`src/Workflow/Cockpit.php`) — inject CreditLedger, UsageRepository, AccountRepository,
   JobRepository; `snapshot($ctx, $now)` adds `business` (balanceCents, spentMtdCents, chargesMtd,
   grantedThisWeekCents, costPerContentCents|null, awaiting), `accounts` (listFor, capped), and swaps
   `awaiting` to the rich shape (`awaitingApproval`, capped). Read-only; tenant-scoped; no new table/route.
2. **Binding** (`src/bindings/web.php`) — pass the 4 new services into Cockpit; controller passes `$now`.
3. **dashboard.php** — business KPI strip (count-up + honest deltas), 2-col grid (left: inline-player
   approval cards; right: connected-accounts widget), then the existing active-runs card.
4. **inline-player.js** — play-in-card: overlay click → `video.play()`, real `timeupdate` → progress
   scaleX, "Playing" badge, `ended` → reset. NEVER opens the drawer (the old bug). Reduced-motion safe.
5. **CSS** (app.css Phase 17 block) — KPI delta tones, inline player (poster/video/overlay/progress/
   playing badge/title/duration), account widget rows. transform/opacity only; no new backdrop.
6. **i18n** — ~10 parity-matched keys (`dash.kpi_*`, `dash.accounts_*`, `dash.watch_here`,
   `player.playing`, `player.preview_pending`); reuse `queue.*`/`status.*`/`common.*` for card badges+buttons.

## Scope (out)
- Pipeline node-graph (Phase 18), SSE live updates (Phase 19), any new metric backend / DB / migration.
- Fabricated account engagement (forbidden). New approval logic (reuse existing routes).

## Acceptance
- [ ] Dashboard renders real KPIs ($ balance from ledger, MTD spend, awaiting); cost-per-content "—" when 0 renders.
- [ ] Inline player plays in-card (drawer NOT opened); `preload="none"` → no video request on load.
- [ ] Approval cards: truthful state + AI/compliance chips; real CSRF approve/reject to existing routes.
- [ ] Accounts widget shows real health/platform/handle only — zero fabricated metrics.
- [ ] Empty/no-data states honest ("preview pending", "no accounts", "—").
- [ ] count-up handles money (decimals/prefix); reduced-motion + no-JS show the real number.
- [ ] 375/768/1280 × EN/TR clean; 0 console errors / 0 overflow; full suite green + new tests.

## Tests
- Visual: `tools/visual/gate.sh --out storage/visual/phase-17` exit 0; ux judges KPI/cards/accounts/no-data.
- Code: `php tests/run.php` green + new Cockpit business-KPI/accounts/awaiting tests (real data, costPerContent null path).
- Security: CSRF on the reused approve/reject forms; output escaping on captions/handles; tenant scope on the new reads.
- Compliance: truthful badges EN+TR; no fabricated metrics; AI label shown where required.

## Notes
- Inline-player video state is only screenshot-able with a real render; the media-free seed shows the
  honest placeholder state. Player playback verified by code review (precedent: P16 modals) + a JS-logic note.
- count-up extended in `motion.js` (Phase 16 module) to support `data-count` money values — a motion-layer
  addition, not a Phase-16 regression (existing integer auto-count-up preserved).
