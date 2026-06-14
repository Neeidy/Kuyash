# Phase 20 — Polish, performance & accessibility close-out (plan)

> FINAL `/go` loop phase, stacked on `feat/phase-19-live-sse`. After this the loop STOPS (H3).
> Gates: ux + security (+ compliance for the truthful-badge final audit). Spec: `experience-layer-plan.md` §8.

## Goal
Close out the Experience Layer: clear the accumulated honest-copy + a11y debts, prove §1.2 / perf
compliance, and run the final truthful-badge + security pass — without adding new features.

## Changes (concrete, low-risk)
1. **A11y contrast (resolves phase-15-followups A11Y-1 + A11Y-2):** `--text-3` `#7c7c85` → `#8a8a93`
   so the faint tier clears WCAG AA (≥4.5:1) on EVERY surface — measured: bg 5.78 / surface 5.51 /
   surface-2 5.23 / surface-3 4.86 (was 4.33 / 4.02 on surface-2/3, below AA). Still clearly fainter
   than `--text-2`. One token, global.
2. **Honest copy (clears the jargon follow-up):** `nav.foot_title`/`nav.foot_text` drop the internal
   "Phase 12" label + the "credit-gated / mock-first" jargon → plain "Quick Create / Turn a photo into
   a short, AI-labeled video." (EN + TR). "AI-labeled" kept — it's a truthful compliance fact.
3. **Pipeline node polish:** add a `title` attr to each node button (full canonical name on hover —
   addresses the 768px ellipsis truncation; aria-label already carried it).

## Verification (no code change — gate evidence)
- **§1.2 / perf:** scanned all Experience CSS/JS — every @keyframes animates opacity/transform ONLY
  (rise-in, pl-rise, pl-fade, pl-pop, pl-hb); `backdrop-filter` only on `.cmdk` + `.drawer__scrim`
  (on-demand modals); zero spinner/rotate; the fill-flow SMIL is JS-guarded by `reduced()`. Idle GPU ~0
  when nothing is active (heartbeats only run on a real active/live state).
- **prefers-reduced-motion:** every perpetual/entrance animation has an explicit reduced-motion override
  (rise-in, pl-rise, pl-hb ×2) or uses `--dur-*` tokens that the media query zeroes (pl-fade/pl-pop).
- **Keyboard a11y:** ⌘K (open/filter/arrow/Enter/Esc, focus trap + restore), drawer (Esc/scrim close,
  focus → close button), node buttons + player + palette items are real `<button>`s → global
  `:focus-visible` ring; nav keeps `aria-current="page"`.
- **Truthful badges (compliance gate):** approval records ("Approved by you" vs "auto-approved by
  compliance agent"), AI-label chips, no fabricated metrics — final audit EN + TR.
- **Security final pass:** the Experience surfaces (palette/drawer/inline-player/node-graph/live SSE) —
  escaping, drawer innerHTML invariant, tenant-scoped read-only /live.

## Accepted (recorded, not changed)
- The dashboard worker-down banner ("…start it with php bin/worker.php") is legitimate operator
  guidance for a self-hosted personal tool, shown only when the worker is down — kept intentionally.
- JobRepository `SELECT *` read-model (LOW) — safe (template reads whitelisted fields); kept.
- SSE router-level unauth test (LOW) — controller backstop + `$protected` guard already cover it.
- Caddy/Cloudflare `text/event-stream` no-cache — enable-time ops note (in-app headers already correct).

## Tests
- Visual: `tools/visual/gate.sh --out storage/visual/phase-20` exit 0; ux verifies the contrast bump
  reads correctly + honest foot copy + no regression.
- Code: `php tests/run.php` green + a contrast/honest-copy guard test.
- Compliance: truthful badges EN + TR unchanged-and-correct.
