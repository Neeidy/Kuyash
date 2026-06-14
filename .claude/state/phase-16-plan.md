# Phase 16 — Motion & Interaction Core (plan)

> Token: `START PHASE 16` (run inside the `/go` continuous loop). Reviewer gates: ux (visual) + qa (code) + security.
> Visual source of truth: `.claude/docs/design/prototype-v3.html`. Binding: `experience-layer-plan.md` §4 + `ui-style-guide.md`.

## Goal
Embed v3's *feel* as a client-side enhancement layer: brighter teal accent, static ambient
gradient, a sliding-pill sidebar, hover-lift + one-time entrance, a ⌘K command palette, a
reusable global drawer, and KPI count-up. Pure progressive enhancement — the server already
renders real data; JS only adds motion/affordances. **No backend, DB, route, or screen-restyle.**

## Scope (in)
1. **Teal accent reconciliation** (approved global swap): `--accent` `#2dd4bf` → `#2ff0d2`,
   `--accent-press` → `#13c4a8`, add `--glow` token. Reversible CSS value change only.
2. **Static ambient gradient** on `body` (painted once, zero animation, GPU ~0 idle) — teal
   top-right + violet bottom-left at low alpha over `--bg`. `background-attachment: fixed`.
3. **Sliding-pill sidebar nav** — JS-injected pill that slides to the hovered item and rests on
   the active item; returns to active on mouse-leave. No-JS keeps the existing `is-active` marker.
4. **Hover-lift on KPI tiles** + a **one-time staggered entrance** on `.main > *` (CSS-only,
   reduced-motion-zeroed, flash-free — same proven pattern as the existing `.asset-grid` reveal).
5. **⌘K command palette** — global partial + `palette.js`: open on ⌘/Ctrl-K, type-to-filter,
   arrow/Enter keyboard nav, Escape/backdrop close, focus trap + restore. Items = navigation
   (reuse `nav.*` labels) + one "Keyboard shortcuts" action that opens the drawer.
6. **Reusable global drawer** — generic partial + `drawer.js` exposing `PL.drawer.open({title,html})`
   and `[data-drawer-open]` wiring; demonstrated by the shortcuts/help drawer. 17/18 reuse it.
7. **KPI count-up** — `motion.js`, requestAnimationFrame, once, integer `.kpi__num` only (skips
   money/`%`/decimals). No-JS shows the real number; reduced-motion → instant.
8. **`PL` namespace** (`window.PL.motion`) with `durOf(--token)` reading CSS vars (0 under
   reduced-motion) — the seam base.css already references in a comment.

## Scope (out — explicitly deferred)
- SSE / live data, topbar live-heartbeat dot (→ Phase 19; a perpetual decorative pulse would
  violate §1.2 rule 6 "every animation maps to a state" with no live state behind it yet).
- Dashboard restyle, account widgets, inline player (→ Phase 17).
- Pipeline node-graph + node-click drawer *content* (→ Phase 18; the drawer *mechanism* is here).
- Any PHP/DB/route/controller change. New backend surface = none.

## Motion-rule (§1.2) compliance commitments
- Animate only `transform` / `opacity` (no dashoffset needed this phase). No `filter: blur`
  animation, no `background-position`/`width`/`top`/`left` animation.
- `backdrop-filter` ONLY on the ⌘K palette scrim and drawer scrim (on-demand modals).
- No spinner / no continuous spin. Every animation maps to a state: pill→hover/active,
  lift→hover, entrance→load (once), count-up→load (once), palette/drawer→open.
- Ambient background is a static gradient (no animation).
- All durations come from `--dur-*` tokens → `prefers-reduced-motion` zeroes them globally.

## Files
**New**
- `public/assets/js/motion.js` — `PL` namespace, sliding pill, count-up.
- `public/assets/js/palette.js` — ⌘K command palette.
- `public/assets/js/drawer.js` — generic drawer + `data-drawer-open`.
- `templates/layout/partials/command-palette.php` — palette markup (authed shell only).
- `templates/layout/partials/drawer.php` — drawer markup + `<template>` for shortcuts content.

**Modified**
- `public/assets/css/base.css` — accent token values, `--glow`, ambient gradient on body.
- `public/assets/css/app.css` — pill, kpi hover-lift, `.main > *` entrance, palette + drawer CSS.
- `templates/layout/app.php` — `html.js` inline class, topbar ⌘K trigger, include partials,
  defer-load the new scripts. (Shell partial — in scope; no logic change.)
- `lang/en.php` + `lang/tr.php` — a small, parity-matched set of `cmd.*` / `help.*` keys for the
  palette + shortcuts drawer (a bilingual palette cannot exist without labels). Parity preserved.

## Acceptance (self-check at gate time)
- [ ] Motion is perceptible but premium; every animation maps to a state; idle GPU ~0.
- [ ] `prefers-reduced-motion` zeroes all entrance/transition/count-up.
- [ ] ⌘K opens/filters/keyboard-navigates/closes; focus trapped + restored; works EN & TR.
- [ ] Drawer opens from the palette shortcuts action, traps focus, Esc/scrim closes.
- [ ] Count-up animates integer KPIs only; money/`%` untouched; no-JS shows real numbers.
- [ ] §1.2 not violated (grep new CSS/JS: no animated blur, no persistent backdrop on non-modal,
      no spinner; backdrop-filter only on palette/drawer scrims).
- [ ] No console errors; 0px horizontal overflow at 375/768/1280 × EN/TR.
- [ ] Full suite stays green; no new dependency / build tool; JS-off fallback renders every screen.
- [ ] No tech-jargon introduced; truthful badges untouched.

## Tests
- **Visual:** `tools/visual/gate.sh --out storage/visual/phase-16` → exit 0; ux-reviewer judges
  pill/palette/drawer/entrance vs v3, reduced-motion, no overflow, TR at 375.
- **Code:** `php tests/run.php` green + a small test asserting accent token + i18n parity (en/tr
  key sets equal for the new `cmd.*`/`help.*` keys); scope = only the files above changed.
- **Security:** new JS reflects no user input (static nav hrefs / static template content); no new
  route; backdrop-filter scoped; no secrets.

## Notes / decisions
- Chose **CSS-only load entrance** over IntersectionObserver: flash-free, no "content hidden if JS
  errors" failure mode, reduced-motion-safe, and consistent with the existing `.asset-grid`
  pattern. The §16 acceptance is "one-time, premium, reduced-motion-zeroed" — not "must use IO".
- Pre-existing `nav.foot_title` = "Phase 12 · Quick Create" leaks an internal phase label in the
  sidebar foot. NOT fixed here (i18n-copy change, out of motion scope) — recorded as a Phase 20
  honest-copy follow-up.
