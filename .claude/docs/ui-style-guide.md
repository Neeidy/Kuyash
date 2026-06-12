# Kuyash — UI Style Guide (BINDING for all UI work, Phase 0 redesign onward)

The user rejected the first Phase 0 visual pass as "generic AI design". This guide defines the new direction. Every screen must follow it. When in doubt: calmer, fewer colors, more data honesty.

## Design North Stars (study these patterns, do not copy pixel-for-pixel)

- **Linear** — calm minimalism: neutral surfaces, restrained single accent, perfect spacing rhythm, typography does the hierarchy work.
- **Vercel/Geist** — near-monochrome base, crisp 1px borders instead of glows/shadows, data-dense but never cluttered.
- **Stripe Dashboard** — progressive disclosure: one key number first, drill-down on demand; impeccable tables.
- **Datadog / mission-control aesthetic** — the LIVE OPS layer: streaming logs, ticking counters, status pulses; the screen feels like it is operating, not posing.

2026 consensus from award-winning dashboards: progressive disclosure over density; whitespace and calm outperform clutter for daily-use tools; modular card grid (bento) with consistent card anatomy; real-time visualization as an engagement layer; unified visual language (same card style, spacing scale, chart style everywhere).

## Visual identity break (mandatory)

The redesign must be perceptibly a DIFFERENT product from the first pass, not a polish of it. Dark theme stays primary, but:

- Do NOT reuse the previous purple (#6d6af8-family) accent or any near-purple as the brand accent. Choose a new, restrained accent that reads premium (consider desaturated/dark-tinted candidates and justify the choice in the plan).
- Do NOT reuse the previous card/border/sidebar styling as-is; rebuild from the new tokens.
- A before/after screenshot of the Dashboard must look like two different products at first glance. If it doesn't, the redesign failed.

## Anti-"AI design" rules (hard bans)

- NO purple-gradient-on-dark cliché. NO glow effects. NO glassmorphism blur as decoration.
- NO emoji in headings or UI chrome ("Good morning 👋" is banned).
- NO three-color KPI card salad: color is reserved for STATUS ONLY.
- NO generic SaaS hero phrases. Microcopy is operational and specific ("3 renders awaiting your approval", not "Here's what's happening today").
- NO default system-font-stack look: pick ONE distinctive UI typeface pairing (self-hosted via @font-face from local files — still zero external requests at runtime; e.g. Inter Display/Inter, or Geist if license allows local bundling; mono font for numbers/logs: e.g. JetBrains Mono or IBM Plex Mono, local). **Font binary files (.woff2) must be committed under `phase-0-demo/assets/fonts/`, obtained from the projects' official open-source releases (one-time build-time download, announce it to the user first); verify zero external fetches at runtime.**

## Design tokens (define once in base.css as CSS custom properties)

- **Base:** near-black neutral scale (e.g. #0A0A0B → #18181B surfaces → #27272A borders), text zinc scale. Light theme optional later; dark is primary.
- **Single accent:** ONE brand accent used sparingly (primary actions, active nav only).
- **Semantic status (the only other colors on screen):** success/published, warning/awaiting, danger/failed, info/processing, ai-label (distinct hue reserved exclusively for AI-label indicators).
- **Spacing scale:** 4px base grid (4/8/12/16/24/32/48/64). No arbitrary values.
- **Radius:** one radius for cards, one for controls. **Borders:** 1px solid, no shadows except a single subtle elevation for modals.
- **Numbers:** tabular numerals, mono font for metrics, log lines, job ids, costs.

## Motion & Interaction System (BINDING — this is where "supernatural" lives)

Reference bar: the owner's site (yigitcan.uk — terminal-aesthetic blocks, marquee ticker, count-up stats, severed-boundary animation) is the FLOOR, not the ceiling. Kuyash must exceed it — but as award-grade PRODUCT motion (Linear/Arc/Vercel-class), never portfolio scroll-jacking inside app screens.

### Motion tokens (define once in base.css; every animation consumes these — zero hardcoded timings)

- Durations: `--dur-micro:150ms` (press/toggle/icon) · `--dur-quick:250ms` (hover/tooltip/badge) · `--dur-view:350ms` (panel/view) · `--dur-enter:420ms` (modal/drawer/stagger) · `--dur-long:550ms` (screen change/skeleton resolve)
- Easings: `--ease-out: cubic-bezier(0.22,1,0.36,1)` (entering) · `--ease-in` (leaving) · `--ease-inout` (reorder) · `--spring: linear(...)` spring curve via CSS `linear()` (generate with a spring generator; `@supports` fallback to `cubic-bezier(0.34,1.56,0.64,1)`). Spring is reserved for direct-manipulation feel (card expand, palette, drag) — never applied everywhere.
- `prefers-reduced-motion: reduce` zeroes ALL duration tokens in one `:root` override.

### Required patterns (all native — no GSAP/Lottie/any library; offline file:// safe)

1. **Screen morphs ("kayan ekranlar"):** View Transitions API (`document.startViewTransition`) on every sidebar navigation; shared-element morph via `view-transition-name` (active nav item, page header; Library card → Post Preview morph). Feature-detect; **defined fallback: CSS class-swap fade (`opacity` transition on the content container, `--dur-view`).**
2. **Enter transitions without JS:** `@starting-style` + `transition-behavior: allow-discrete` for modals, drawers, approval panel, Quick Create — slide+fade from `display:none`. **Fallback: `@supports not (transition-behavior: allow-discrete)` → JS classList toggle + opacity/transform transition (Safari coverage is not guaranteed).**
3. **Staggered list reveal:** items cascade with `animation-delay: calc(var(--i) * 40ms)` (cap ~300ms) — Queue, Trends, Library, Logs, Analytics.
4. **Skeleton → content morph:** shimmer skeleton dissolves into content (`@starting-style` on inserted content) — no jump-cut.
5. **Hover lift:** `transform: translateY(-2px)` + layered shadow on `--ease-out` (NOT spring — hover is not direct manipulation) — cards/rows only; 1-2 properties max per hover.
6. **Pulse-ring status dots:** expanding `box-shadow` ring on processing/publishing states only — never on idle.
7. **FLIP streaming logs:** new log lines slide in, existing rows FLIP-shift (Web Animations API + getBoundingClientRect) — Logs, Queue transitions, audit trail. Progressive enhancement only: if `element.animate()` is unavailable, items appear without shift animation.
8. **Count-up numbers:** rAF ease-out interpolation when values change — Dashboard KPIs, Usage, Analytics. Tabular/mono numerals so digits don't jitter.
9. **Terminal-aesthetic surfaces:** Logs pane and compliance audit trail rendered as a premium terminal block (mono font, dim timestamps, syntax-tinted levels) — evolves the owner-site DNA.
10. **Trend ticker marquee (tasteful):** slow, pausable, hover-stops marquee of rising trend chips on Dashboard topline — `@keyframes translate`, duplicated track. **Named exception to the state-change-only rule: the marquee is a persistent live-data affordance (like a stock ticker), not decoration — the onboarding wizard and this marquee are the ONLY two exceptions.**

### Premium vs cheap (hard rules)

- Named per-property transitions only — `transition: all` is BANNED.
- Animate only compositor properties (`transform`, `opacity`) wherever possible; layout changes use FLIP, never `width/height` tweens.
- Strict hierarchy: micro 150 / quick 250 / view 350+. One element animates max 2 properties simultaneously.
- Every animation maps to a state change or affordance. Decoration-only motion is banned — EXCEPT the onboarding wizard, which may be more cinematic (it is a one-time flow: staggered scene builds, progress choreography allowed).
- 60fps audit: no animation may trigger layout thrash; test with DevTools paint flashing.

## Live Ops layer (the "command center" feel — Phase 0: simulated, later phases: real)

Phase 0 simulates a living system with a lightweight ticker (setInterval driving mock state):

- Dashboard: counters tick, a compact live activity feed streams new mock events every few seconds, job status chips pulse subtly while "processing".
- Render Queue: progress bars advance; a job occasionally completes/fails and re-sorts; retry visibly resets it.
- Live Logs: terminal-style streaming log pane (mono font, timestamped lines appearing) with pause/resume.
- Status pulses: small animated dot on "processing"/"publishing" states only — motion is information, never decoration. Respect `prefers-reduced-motion`.
- A global "LIVE" indicator in the topbar with last-update timestamp.

Later phases replace the ticker with real data via **SSE (Server-Sent Events)** from pure PHP — native, no dependencies, fits the stack. Design the JS so the ticker and the SSE client share one event interface (mock-first pattern applies to the live layer too).

## i18n — Türkçe / English (build from Phase 0 onward, BINDING)

- All UI strings live in dictionaries: `i18n/en.js` and `i18n/tr.js` (key → string). NO hardcoded user-facing strings in screens/components.
- `t(key)` helper + language toggle in topbar (and Settings). Choice persisted via localStorage; **if localStorage is unavailable in the file:// context, fall back silently to in-memory state — never a hard error.** Default: EN; TR fully complete — not partial.
- Dates/numbers formatted via `Intl` with the active locale.
- Rule for all future phases: any new user-facing string lands in BOTH dictionaries in the same change. Backend (later) returns message KEYS, not prose, wherever user-visible.

## Card anatomy (every card, same skeleton)

Header (title + optional status chip + optional action) → body → footer (meta/timestamp). Same paddings, same border, same radius everywhere. Empty/loading/error states keep the same skeleton.

## Acceptance additions for the redesign

1. Zero external network requests still holds (fonts self-hosted locally).
2. Side-by-side check: no screen should look like a default AI-generated dashboard — distinctive type, disciplined color, live layer present.
3. Color audit: accent + 5 semantic status colors only; nothing else colored.
4. Motion audit: every animation maps to a state change; `prefers-reduced-motion` honored.
5. i18n audit: language toggle flips 100% of visible strings on every screen (TR and EN complete).
6. All 13 screens redesigned consistently — no mixed old/new styling.
