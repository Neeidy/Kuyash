# Phase 9 — Follow-ups (deferred, non-blocking)

Captured from the three closing reviewers (compliance / security / ux). All GO;
these are nice-to-haves and low-severity observations deliberately deferred so
Phase 9 stays scoped. None block the phase.

## UX (ux-reviewer) — fixed at close
- **B1 (FIXED):** kill-switch `data-confirm` moved from the `<button>` to the
  `<form>` so the global `form[data-confirm]` handler fires the confirm dialog.
- **N1 (FIXED):** `.field .field__hint` selector (0,2,0) now beats base.css
  `.field > span` (0,1,1) — hints no longer render uppercase/stretched.
- **N2 (FIXED):** quality score shows `—` + "not enough checks yet" until
  sample ≥ 5 (Settings + Digest), so a fresh workspace doesn't read a confident 100.

## UX — deferred polish
- **N3:** Digest idle-day summary line ("Quiet day — no autonomous actions")
  above the four empty cards (per-card empty states already exist).
- **N4:** Confirm Settings/Digest degrade to a friendly callout (not a 500) if
  `QualityScore`/`DigestReport` ever throw. Server-rendered V1; low risk.
- **N5:** `min="0"` / pattern on the budget input (server validation already
  rejects junk; this is a client foot-gun guard only).

## Security (security-auditor) — low / informational
- **L1:** `monthToDateSpendCents` has no upper time bound (`created_at >= monthStart`);
  correct for "month-to-date" under UTC now. Revisit with the Phase 11 ledger.
- **L2:** budget cap truncated to whole USD (×100), 6-digit max — intentional per plan.
- **L3:** `DigestReport`/`currentName` read the workspaces row by PK without a
  membership re-check — safe under the single-membership session model; re-verify
  if V2 multi-workspace switching arrives.

## Compliance (compliance-reviewer) — notes
- Daily cap is counted at TWO points (gate = auto-approvals; PublishGate =
  published). Correct today; unify into one per-account counter when Phase 10
  introduces accounts (the `?int $accountId = null` seam is already in place).
- Budget cap = observed `SUM(cost_cents)` (truthful-minimal) until the Phase 11
  credit ledger + preflight estimation replaces it (already in the plan).
