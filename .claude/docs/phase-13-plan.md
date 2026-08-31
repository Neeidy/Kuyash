# Phase 13 — Hardening (Production Readiness) — APPROVED PLAN

> Approved via `/next-phase` (Plan Mode) on 2026-06-13. **Build begins ONLY on the exact token
> `START PHASE 13`.** This doc is the saved scope; approval here accepted scope, it did not unlock code.
> Final phase (13 of 13) — the production-readiness capstone.

## Context

Phases 0–12 are built, accepted, committed, and pushed (`origin/main` = HEAD, Phase 12 `dd34bbb`).
Suite is **673 PASS / 0 FAIL**. The full create→approve→publish→operate loop works mock-first behind
adapters; compliance, guardrails, usage/credit ledger, and Quick Create AI video are all in place.
Real Zernio / AI-video / R2 are doc-/flag-gated; defaults are mock.

Phase 13 makes what exists **safe to operate** — not adding features. It folds in the hardening-class
follow-ups deferred from Phases 9–12 and the carried Phase 8 R2 enable-time gate, then runs a full
cumulative security review and produces a go-live checklist.

**Two scope decisions locked at proposal time (2026-06-13):**
1. **R2 staging/eviction = operator-gated.** Build enable-time smoke tooling + document the lifecycle;
   **do NOT write speculative staging/eviction code** (R2 has no live bucket in V1).
2. **LOW php-architect refactors = documented tech-debt, not done** (don't refactor a green build at the line).

## Scope (precisely)

1. **Full cumulative security review** — `security-auditor` across the whole codebase (mandatory gate),
   walking `security-checklist.md` end to end. Address carried security follow-ups that are genuine hardening:
   - Per-IP **rate limiting** on `POST /webhooks/zernio` (reuse the `LoginThrottle` pattern) — Phase 10 LOW.
   - Graceful backstop on `PostRepository::insertPublishing()` UNIQUE insert (catch → treat as existing) —
     Phase 10 LOW.
   - **401/403 non-retryable fast-fail** in the executor/retry path (don't burn retries on auth failures) —
     Phase 11 #5 / Phase 5 carry. Minimal, well-tested change to the queue retry classification.

2. **Test checklist + critical-path gap closure** — full suite green; add the two deferred regression tests:
   - Executor **real-cost passthrough** with a stub non-null-cost provider (Phase 12 #4).
   - **Recorder-cannot-roll-back-finalize** regression (Phase 11 #3).
   - A consolidated "release test checklist" doc enumerating happy + failure paths per subsystem.

3. **SQLite + media backup/restore (WAL-aware)** — `bin/backup.php` (online `VACUUM INTO` /
   `.backup`-equivalent, safe under WAL + busy_timeout; never a raw file copy mid-WAL) + `bin/restore.php`;
   local media dir included; document R2's own durability (R2 objects are not re-uploaded by the DB backup).
   Round-trip tested: backup a populated DB → restore to a fresh path → `PRAGMA integrity_check` + row parity.

4. **Caddy / Cloudflare Tunnel review** — confirm/define security headers (CSP, X-Frame-Options: DENY,
   X-Content-Type-Options: nosniff, Referrer-Policy, HSTS where TLS-terminated), HTTPS posture, Tunnel config.
   Live tunnel verification is operator-gated (no live tunnel locally) — config reviewed + documented.

5. **Failure recovery verification** — exercise and document: worker crash mid-job → watchdog requeue/
   dead-letter (Phase 4), retry exhaustion → explicit dead state, kill switch → autonomous actions halt,
   auto-fallback to Manual on quality breach. Smoke-tested, not just asserted.

6. **R2 enable-time gate tooling (operator-gated)** — `bin/r2-smoke.php`: live-bucket SigV4 round-trip
   (put → presigned GET → delete) + **PRIVATE/no-ACL confirmation** that must pass before `STORAGE_DRIVER=r2`.
   Document the lifecycle (assembly-side staging + delete-after-verify eviction) as the enable-time procedure;
   **defer the staging/eviction code** (locked decision).

7. **Production readiness checklist** — `.claude/docs/production-readiness.md`: env posture (`debug=false`,
   secrets present, `.env.example` parity), all doc-gates still firm (Zernio 12 items, AI-video 7 items,
   R2 smoke), kill switch verified, audit logs present for sensitive actions, GDPR delete/export thinking
   noted, backup/restore proven, headers confirmed. Every item checked or explicitly marked operator-gated.

## Non-goals

- **No new product features.** Excluded (V2 / feature follow-ups, NOT hardening): account-subset selection UI,
  `account_metrics` snapshot + growth deltas, live NEXT-UP countdown widget, Claude 2nd text provider,
  Creator Watch, Studio UI, Whisper subtitle alignment.
- **No flipping any real integration on** — Zernio, AI-video, R2 stay doc/flag-gated. Build the *gate tooling*,
  not live calls.
- **No Stripe, no multi-tenant UI, no onboarding.**
- **No speculative R2 staging/eviction code**; **no LOW php refactors** (per the two scope decisions).
- No schema-breaking migrations; no redesign of working subsystems.

## Verification / acceptance criteria

- Full suite **green (≥ 675 PASS / 0 FAIL)** incl. the two new regression tests;
  `php tests/run.php`.
- `security-auditor` = **GO / 0 blockers** (mandatory). `ux-reviewer` run; final `compliance-reviewer`
  sanity pass (truthful records, AI labels, caps still intact).
- Backup→restore round-trip works: `integrity_check = ok` + row-count parity on the restored DB.
- Caddy security headers present (documented `curl -I` expectation).
- Failure-recovery smoke: worker-kill → watchdog requeue; retry exhaustion → dead-letter; kill switch halts.
- `bin/r2-smoke.php` exists and is wired as the enable-time gate (run operator-side with real creds).
- `production-readiness.md` exists; every line checked or marked operator-gated.
- Secret grep clean; no scope creep (no new product features).

## Risks

- **Scope creep (highest).** Hold the no-new-features line; the two scope decisions already trim it.
- **Retry-path change** (401/403 fast-fail) can regress queue behavior — minimal change + happy/auth-failure tests.
- **Destabilizing a green build at the finish line** — prefer additive hardening + tests over refactors.
- **Caddy/Tunnel + R2 smoke can't be fully exercised locally** — config review + tooling now, live
  verification operator-gated and documented as such.
- **Backup consistency** between SQLite job state and R2 objects is a split concern — document, don't
  re-implement R2 durability.

## Mandatory reviewers before close

`security-auditor` (mandatory for Phase 13 — this IS the full security review) + `ux-reviewer` (every phase)
+ a final `compliance-reviewer` sanity pass (go-live prudence).

## Approval token

Build begins ONLY on the exact token: **`START PHASE 13`**.
