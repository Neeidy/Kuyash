# Phase 10 — Follow-ups (deferred, non-blocking)

Captured at Phase 10 build close (2026-06-12). Three reviewers ran:
**security-auditor = GO** (0 blockers), **compliance-reviewer = GO** (0 blockers),
**ux-reviewer = CONDITIONAL GO** (0 blockers; should-fixes #1/#2 were APPLIED before close).
The items below are explicitly deferred — none block Phase 10.

## Deferred at planning (user scope-trim, locked in `phase-10-plan.md`)
- **Topbar live NEXT-UP `mm:ss` countdown widget.** Scheduling *data* is built (the
  `/accounts` "Next scheduled publish" line reads the earliest queued future publish job);
  only the live ticking topbar widget is deferred.
- **Daily `account_metrics` snapshot + growth deltas** (per-account follower/engagement
  trend). No table, no job in V1 — needs real Zernio analytics ingestion (doc-gated).
- **Account-subset selection UI** — a run publishes to ALL connected accounts. Per-run
  target selection (and per-account scheduling of a single run) is a follow-up.

## Security (security-auditor, all non-blocking)
- **MEDIUM — APPLIED.** `external_url` scheme validation: `WebhookInbox::safeUrl()` now
  accepts only `http(s)://` (else synthesizes a safe URL), plus a defensive `^https?://`
  guard in `templates/runs/show.php` before rendering the `<a href>`. Tested.
- **LOW — APPLIED (partial).** Webhook body-size cap (64 KiB → 413) added to
  `WebhookController`. Per-IP **rate limiting** on `POST /webhooks/zernio` is still deferred
  (reuse the `LoginThrottle` pattern when a real Zernio webhook arrives).
- **LOW — deferred.** `PostRepository::insertPublishing()` has no `try/catch` around the
  UNIQUE `idempotency_key` insert. Unreachable today (single-claimed publish job +
  `findByKey` pre-check; a throw is handled as a retried job), but make the backstop
  graceful (catch → treat as existing) if publish ever runs concurrently per (run,account).

## Compliance (compliance-reviewer, non-blocking)
- **S1 — cap-unification asymmetry.** Only `PublishGateExecutor` was moved onto `posts`
  (via `PublishCounter`, the binding per-account publish cap). `AutoApprovalGate::autoApprovalsToday()`
  still counts the `approvals` table workspace-wide — a coarser upstream auto-approval-volume
  throttle. Not a violation (the publish gate re-checks per-account against real published
  posts, so no over-cap publish can occur), but the "daily cap" label is overloaded across
  the two stages in a multi-account workspace. Revisit with the Phase 11 budget/credit ledger.
- **Observation (no action).** `PublishCounter` counts only `published` posts; an account
  mid-async-publish (accepted, awaiting webhook) could momentarily exceed its intended cap
  if many accepts land before webhooks confirm. Bounded by all-accounts-publish + idempotency;
  acceptable for V1.

## UX (ux-reviewer, nice-to-have — APPLIED items removed)
- Applied before close: status/health chip **dot tone** modifier; **accounts row alignment**
  (right-aligned `.job-row__side` actions group).
- **Deferred (polish):** a "publishing" (in-flight) post has no animated/pulse affordance —
  on a static page load it can read like a terminal state. Add an "in progress" cue.
- **Deferred (polish):** the inline `field--inline` reference-picker reuses `.field__label`
  whose base style is uppercase — "DEFAULT REFERENCE" reads slightly shouty mid-row.

## Carried HARD GATES (unchanged, from earlier phases)
- Phase 8: `STORAGE_DRIVER=r2` enable-time live-bucket SigV4 smoke + PRIVATE/no-ACL
  confirmation; assembly-side staging + render/cache eviction (→ Phase 13). See ADR-014.
- Phase 9 follow-ups remain in `phase-9-followups.md` (UX N3-N5, security L1-L3, Phase 11
  budget ledger).

## Doc-gate (still firm)
Real Zernio stays BLOCKED until all 12 items in `zernio-notes.md` are supplied. The real
`ZernioPublishProvider` is a flag-off stub that throws "doc-gated"; default `ZERNIO_MOCK=true`.
