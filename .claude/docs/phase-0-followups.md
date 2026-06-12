# Phase 0 — Follow-up Fixes (close BEFORE START PHASE 1)

Source: independent Phase 0 audit (Cowork second-eye review, 2026-06-11). Status: 12/13 acceptance criteria verified (13/13 screens render headless with zero page errors, zero external requests, truthful badges, credit gate + AI-label notice, workspace switch live-tested PASS).

These items are **Phase 0 remediation** — they touch only `phase-0-demo/` files and require NO new phase token. Do not start any Phase 1 work while closing them.

## Must close before Phase 1

0. **FULL VISUAL REDESIGN (top priority — user rejected the current look as "generic AI design").** Redesign all 13 screens following `.claude/docs/ui-style-guide.md` (BINDING): Linear/Vercel-class calm minimalism + Datadog-style live-ops layer (simulated ticker in Phase 0), hard anti-AI-design bans (no purple-gradient cliché, no emoji headings, color = status only), self-hosted distinctive typography, single accent + 5 semantic colors, 4px spacing grid, **and the i18n layer (TR/EN dictionaries + topbar toggle, 100% string coverage)**. This is a large Phase 0 iteration: recommend Plan Mode first, present the visual plan (tokens, type choice, one redesigned reference screen description) before rebuilding all screens. Re-run the 13-criteria self-check plus the 6 redesign acceptance additions from the style guide. Items 1–4 below should be folded INTO this redesign rather than patched separately.

1. **ARIA / accessibility polish.** Sidebar nav, modals/confirm dialogs, toggle controls and toasts need proper roles/labels (`role="dialog"`, `aria-modal`, `aria-label` on icon-only buttons, focus trap or at least focus return on modal close, `aria-live="polite"` on toasts). Keyboard: Escape closes modals; Tab order sane on Settings and Studio.
2. **Onboarding screen consistency + depth.** It is the thinnest screen (~2.5K chars rendered). Make the wizard's niche/example data consistent with the active mock workspace (ws_fit / ws_calm) instead of generic placeholders, and ensure all five steps (workspace → connect account → pick niche → first trend → first content → test post) render meaningful mock content.
3. **Trend velocity color coding.** Velocity indicators on Trend Radar should encode direction/intensity visually (e.g. rising=green, hot=orange, falling=muted) — currently noted as flat.
4. **Settings kill-switch card text wrap.** At ~900–1000px viewport the kill-switch description column wraps one-word-per-line (button steals width). Give the text column a sensible min-width or stack the button below the text at that breakpoint. (Found in live Chrome review.)
5. **Manual responsive pass confirmation.** Code has 5 breakpoints; the user will verify 375/768/1280px visually. If the user reports any overflow/misalignment, fix within Phase 0 scope.

## Stays parked (do NOT do now — noted for later phases)

- Compliance result enum orthogonalization (pass / label / slop axes) → **Phase 9**, already noted in phase-plan.
- Real a11y audit with tooling → Phase 13 hardening.

## Definition of done for this list

- Items 1–3 implemented inside `phase-0-demo/` only; no new files outside it, no backend, still zero external requests, still passing the 13-criteria self-check from `.claude/docs/phase-0-demo-spec.md`.
- End with a VERDICT block; then wait for `START PHASE 1`.
