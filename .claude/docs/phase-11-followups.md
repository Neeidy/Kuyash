# Phase 11 — Deferred follow-ups

Phase 11 (Usage, Costs & Credit Ledger) closed at **630 PASS / 0 FAIL** with a 5-dimension
review all **GO** (security mandatory gate clean). The items below were consciously deferred —
none block the phase; most are Phase 13 (hardening) candidates.

## From the Phase 11 review (non-blocking)

1. **MTD basis change on deploy (compliance nit, defer → 13).** `AutoApprovalGate::monthToDateSpendCents`
   now sums `usage_events` instead of `jobs.cost_cents`, with **no historical backfill** (a documented
   plan non-goal). On the first deploy of migration 0009, observed MTD spend snaps to the new ledger
   basis (often $0 for spend incurred before 0009), briefly making the pre-flight gate + the Phase 9
   approval-time backstop more lenient than true cumulative spend. No untruthful record results (every
   recorded row stays real; the cap is a budget control, not a compliance claim). *Optional:* a one-time
   operator release note, or a muted first-month hint on `/usage`, so the reset isn't mistaken for lost
   spend.

2. **Surface `model` + `units` (token/char counts) through the executor seam (defer → 13).**
   `usage_events.model` and `usage_events.units` are **NULL in V1**: provider + category + cost are
   captured truthfully, but fine-grained counts are not threaded through `TextResult`/`TtsResult` →
   `JobResult` → the executors. `cost-model.md` envisions recording model + units; Phase 12 (AI-video)
   will populate `units = seconds`. Threading text/tts counts is a clean, low-risk later addition.

3. **Recorder-cannot-roll-back-finalize regression test (QA low, optional → 13).** `UsageRecorder::record`
   is **non-throwing by construction** (INSERT OR IGNORE, cost clamped, validated category/unit_type) and
   is now documented as such in the class docblock. A dedicated regression test pinning "a ledger problem
   never rolls back an otherwise-successful finalize" is defense-in-depth, not a current defect.

## From the plan's "carried follow-ups" (not folded in)

4. **OpenAI / Pexels quota surfacing on `/usage`** (plan optional). `api_quota_usage` (Phase 6/7) could
   render alongside spend; non-blocking, deferred.

5. **401/403 non-retryable fast-fail** (Phase 5 follow-up). The plan said "include if cheap, else Phase 13".
   It touches the broader executor-result/retry path beyond Phase 11's recorder hook, so it was **deferred
   to Phase 13** to keep this phase's diff focused on the ledger + budget gate.

## Applied during the review (NOT deferred — done in this phase)

- Skip non-positive cost in the recorder (a sub-cent call rounded to $0.00 records no row).
- Discriminating MTD parity test (old `jobs.cost_cents` total ≠ `usage_events` total).
- End-to-end `finalizeAwaiting` recorder test through `Engine::finalize`.
- Config-consistency test (every priced type maps to a schema-valid category).
- UX polish: over-cap banner figures, "biggest category" amount, `role="progressbar"` + `aria-valuenow`,
  zero-balance neutral chip, "showing latest N of M charges" scope note.
