# Phase 15 (Design Foundation — Consolidation) — Followups

Deferred items surfaced during the Phase 15 consolidation pass. None block the phase;
each is a conscious, recorded decision for a later phase (mostly the Experience Layer
elevation phases 16/18).

## A11Y-1 — Faint tier (`--text-3` #6b6b74) is below WCAG AA on small text (RECORDED, accepted for now)

**What:** The neutral faint/tertiary token `--text-3: #6b6b74` yields ~3.4–3.8:1 contrast on
the dark surfaces (`--bg` #0a0a0b, `--surface` #111113, `--surface-2` #17171a). For text ≤ ~12px
("normal" per WCAG) the AA bar is 4.5:1, so this tier is **sub-AA**. It clears the 3:1 UI/large
bar — nothing is illegible.

**Why it came up in Phase 15:** The drift-fix mapped 6 off-palette `var(--text-dim, #8b949e)`
labels (`#8b949e` ≈ 6:1) onto the app's existing `--text-3` tier for consistency
(`.cockpit-topline`, `.kpi__label`, `.approval-thumb__ph`, `.approval-thumb__cap`,
`.budget-bar__legend`, and originally `.field__hint`). This is a **pre-existing tier** —
`--text-3` already powered `.faint`, `.meta`, `.field__label`, `.kv dt`, `.term__head`, `.note`
at the same ratio long before Phase 15. The consolidation did not create a failing tier; it moved
6 more labels into one. The ux-reviewer flagged it as a should-fix requiring an explicit decision.

**Decided in Phase 15:**
- **Fixed now (in-scope):** `.field__hint` is *instructional* helper text (caps/budget guidance),
  so it was promoted to the **secondary** tier `--text-2` (#a1a1aa, clears AA comfortably) — a
  semantic-tier correction, not elevation.
- **Accepted/deferred:** the remaining 5 are genuinely faint meta/captions paired with a prominent
  number or thumbnail; they stay at `--text-3` for tier consistency. Patching them to a one-off
  lighter value would re-introduce drift (the very thing this phase removed).

**For the elevation phase (16/18) — choose ONE, holistically:**
- (a) Nudge the WHOLE `--text-3` tier lighter (~#7f7f88 / ~#808088) so every faint-tier use clears
  AA in one token change — a single-source consistency + a11y win, but a deliberate app-wide
  lightening of the calm/dim aesthetic the style guide intends, so it's an elevation-scope call.
- (b) Keep `--text-3` calm and introduce a distinct "secondary-small but readable" tier for the
  text-bearing labels that need ≥4.5:1, leaving purely decorative meta at the dim tier.

Do NOT silently patch per-label — decide the tier policy once, app-wide.

## Notes (no action required)

- `--i` stagger custom property (`var(--i, 0)`, app.css `.asset-grid > *`) is set inline in
  `templates/library/index.php` as a functional reveal index — intentional, not an off-token color.
- Intentional raw hex retained in app.css: `#000` (video/poster letterbox ×2) and `#fff`
  (`.btn--danger` label) — off-palette by design (true-black letterbox, max-contrast danger label).
- KPI numbers already received `tabular-nums` via the co-applied `.num` class in
  `templates/dashboard.php`; the base.css selector fix (`.kpi__value` → `.kpi__num` +
  `.quality-score` + `.trend-card__score`) future-proofs standalone `.kpi__num` and newly extends
  tabular figures to the quality-score and trend-score numbers.
