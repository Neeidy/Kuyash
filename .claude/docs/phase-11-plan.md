# Phase 11 — Usage, Costs & Credit Ledger (Approved Plan)

> Approved via `/next-phase` (Plan Mode) on 2026-06-12. **No implementation** until the user
> issues the exact token `START PHASE 11`. Plan approval ≠ start coding.

## Context

Phases 1–10 are accepted, committed, and pushed (Phase 10 = `c664604`, suite **587 PASS / 0 FAIL**).
Cost is tracked today only as `jobs.cost_cents` (one integer per job), and the **only** aggregation
is `AutoApprovalGate::monthToDateSpendCents()` — a `SUM(jobs.cost_cents)` used by the Phase 9
budget-cap guardrail. That code itself notes it is "truthful but minimal — the Phase 11 credit
ledger + preflight estimation replaces this." The Phase 0 demo promised a full **Usage/Credits &
Costs** screen (balance, budget bar, 4-category breakdown, recent charges, warning banner) that has
**no real backend** yet. `cost-model.md` already names the canonical tables: `usage_events` and
`credit_transactions`. Phase 12 (AI-video) is specified as "credit-gated" and depends on this ledger
existing first.

Phase 11 turns the minimal SUM into a real, append-only usage ledger + a money-denominated credit
ledger, wires the budget cap as a **pre-flight hard gate**, and replaces the Phase 0 mock Usage
screen with live single-workspace data.

## Locked decisions (confirmed with user, 2026-06-12)

1. **Ledger denomination = money (cents), cap-gated.** `usage_events` + `credit_transactions` are
   stored in **cents**. The enforced gate is **month-to-date real spend vs `workspaces.budget_cap_cents`**.
   "Credits" is a friendly display layer over real cents — **no invented prepaid economy**, no
   auto-allowance refill. Grants/top-ups are **manual** (seed / small `bin/` admin script), because
   there is no Stripe in V1.
2. **Pre-flight = hard block.** A run's estimated cost is computed *before it starts*; if it would
   exceed the remaining budget, the run is **refused** with a clear message (matches the phase-plan's
   literal "block over-budget runs"). No cap set → never blocks.
3. **`usage_events` becomes the single source of truth for MTD spend.** `AutoApprovalGate` is
   re-pointed from `SUM(jobs.cost_cents)` to `SUM(usage_events.cost_cents)`. `jobs.cost_cents` stays
   as a denormalized per-job rollup for run/job detail views (no behavior change there).
4. **Single-workspace UI.** Schema is multi-tenant (`workspace_id` everywhere) per house rule, but the
   Usage page is single-workspace in V1. The Phase 0 demo's multi-workspace usage table = V2.

## Scope (in)

1. **Migration `0009_usage_ledger.sql`** (latest is 0008):
   - `usage_events` — append-only: `id, workspace_id, run_id, job_id, provider, category
     (ai_text|tts|stock|publish|ai_video), model, units, unit_type (tokens|chars|seconds|calls),
     cost_cents, created_at`. Index `(workspace_id, created_at)`. Unique guard on `job_id` (or
     equivalent) to make recording idempotent across retries.
   - `credit_transactions` — append-only: `id, workspace_id, type (grant|spend|adjust),
     amount_cents (signed), reason, ref_run_id, ref_job_id, created_at`. Balance = `SUM(amount_cents)`.
2. **`src/Usage/UsageRecorder.php`** — single write path. When a job finalizes with a **real** cost,
   append exactly one `usage_events` row + one matching `credit_transactions` `spend` row. **Cache
   hits and mock providers report `null` cost → no rows** (truthful: no fake spend). Idempotent per
   job. Category derived from job type (script/idea/caption/hashtag → `ai_text`; tts → `tts`;
   asset_fetch → `stock`; publish → `publish`; future ai_video → `ai_video`). Wired into
   `Engine::finalize()` / `finalizeAwaiting()` beside the existing `cost_cents` write.
3. **`src/Usage/CostEstimator.php`** — config-driven per-node cost table (mirrors the Phase 0
   `cost-model` constants but in real cents, reusing existing per-model price config). Estimates a
   run's total + per-category breakdown from its node set and inputs before start.
4. **Pre-flight gate in `Engine::startRun()`** — `remaining = budget_cap_cents − MTD spend
   (usage_events)`. If `estimate > remaining` → refuse the run (typed `BudgetExceededException`),
   emit a `guardrail.preflight_block` event, surface a clear UI message. No cap → no block.
5. **Re-point `AutoApprovalGate::monthToDateSpendCents()`** to `usage_events` (source of truth);
   keep guardrail behavior identical (parity test).
6. **`src/Usage/CreditLedger.php` / `UsageRepository.php`** — balance, MTD-by-category, recent
   charges, all workspace-scoped.
7. **`UsageController` + `GET /usage` + `templates/usage/index.php` + nav**: live single-workspace
   page replacing the Phase 0 mock — KPI cards (Spent this month, Budget cap + progress bar,
   Remaining, Biggest category), 4-category breakdown, recent charges list, ≥75%/≥90% warning
   banner; empty/loading/error states. Nav item between Logs and Settings; footer → "Phase 11".
8. **`bin/grant-credits.php`** — manual grant/adjust (no Stripe). Budget cap itself stays editable on
   `/settings` (Phase 9, unchanged).
9. **Tests** — recording (real → 1 row; cache/mock → 0 rows; idempotent on retry), balance =
   SUM, estimator math (deterministic), pre-flight hard-block (over-budget refused + event;
   under-budget proceeds; no-cap never blocks), `AutoApprovalGate` repoint parity, tenant isolation
   on every query, truthfulness (mock cost never recorded as real). Happy path + failure states.

## Non-goals (out)

- Stripe, real payments, auto top-up, monthly auto-allowance refill (V2 / SaaS-ification).
- A prepaid credit *economy* with a depleting pool (explicitly rejected in favor of money/cap model).
- Multi-workspace usage table UI (schema multi-tenant; V1 UI single-workspace).
- A real AI-video provider or its real cost (Phase 12 — estimator carries an `ai_video` category as a
  priced placeholder only).
- Changing approval-mode / guardrail semantics (Phase 9 unchanged; only the MTD SUM source moves).
- Historical backfill of `jobs.cost_cents` into `usage_events` (start fresh; document the basis change).

## Carried follow-ups to consider (fold in only if cheap, else defer to 13)

- **401/403 non-retryable fast-fail** (Phase 5 integration #3) — a `JobResult` non-retryable signal so
  a bad key fails fast instead of retrying to dead-letter. Touches the executor-result path this phase
  edits; include if low-cost, otherwise Phase 13.
- **OpenAI/Pexels quota surfacing** — `api_quota_usage` (Phase 6/7) could render on the Usage page;
  optional, non-blocking.

## Acceptance criteria / verification

- `0009` applies cleanly; tenant columns present; indexes created.
- Every real provider spend writes exactly **one** `usage_events` row + one `credit_transactions`
  spend; mock/cache → **zero**; idempotent across retries (verified).
- Credit balance == `SUM(credit_transactions.amount_cents)`; cached/derived values consistent.
- `CostEstimator` is deterministic from config (unit-tested).
- Pre-flight: over-budget run **refused** (event + clear message); under-budget proceeds; no cap →
  never blocks.
- `AutoApprovalGate` MTD spend now from `usage_events` with **behavior parity** to the old SUM.
- `/usage` renders real MTD spend, cap progress, 4-category breakdown, recent charges, warning banner;
  single-workspace; empty/loading/error states present; responsive (375 / 768 / 1280).
- All Usage/ledger queries workspace-scoped (tenant-isolation test).
- No secrets; **no untruthful records** (mock cost never shown as real spend).
- Full suite green (587 + new) ; live smoke (`8082` + worker) regression-free.
- **security-auditor MANDATORY before close** (phase-plan). Plus `php-architect` + `ux-reviewer`.

### Manual test
- `php tests/run.php` → all green.
- Apply `0009`; run a stock workflow (mock) → Usage page renders, $0 real spend (mock truthful).
- With `.env` `OPENAI_MOCK=false` + key, run a real script job → exactly one `ai_text` usage_event +
  spend appears on `/usage`.
- Set a tiny budget cap on `/settings`, start a run whose estimate exceeds remaining → blocked with
  message + `guardrail.preflight_block` in `/logs`(+/digest).
- `bin/grant-credits.php` to raise budget → re-run proceeds.
- Smoke: **[Terminal-1]** `... php -S 127.0.0.1:8082 -t public public/index.php`;
  **[Terminal-2]** `... php bin/worker.php`. Smoke user smoke4@kuyash.local.

## Risks

- **Double-counting** (both `usage_events` and `jobs.cost_cents` summed) → enforce single MTD source =
  `usage_events`; `jobs.cost_cents` is display-only rollup; parity test pins it.
- **Retry idempotency** — a requeued job must not append a second usage_event; guard via `job_id`
  uniqueness / record-only-on-terminal-success.
- **Estimate vs actual drift** — hard-blocking on an *estimate* can false-positive (block a fine run)
  or false-negative (let a run slightly exceed). Mitigate with conservative estimates; the Phase 9
  approval-time budget gate remains as an actual-spend backstop.
- **Truthfulness** — cache hits / mock providers must never create spend rows (core compliance value).
- **MTD basis change on deploy** — switching the SUM source resets the observed number (no backfill);
  acceptable in dev, documented.
- **Scope creep** into Stripe / credit-economy — explicitly fenced above.

## Representative files

- `database/migrations/0009_usage_ledger.sql` (new)
- `src/Usage/UsageRecorder.php`, `CostEstimator.php`, `UsageRepository.php`, `CreditLedger.php` (new)
- `src/Workflow/Engine.php` (recorder wiring in finalize/finalizeAwaiting + pre-flight in startRun)
- `src/Compliance/AutoApprovalGate.php` (repoint `monthToDateSpendCents` → `usage_events`)
- `src/Controllers/UsageController.php` (new), `src/routes.php` (+`GET /usage`),
  `templates/usage/index.php` (new), `templates/layout/app.php` (nav + footer)
- `bin/grant-credits.php` (new), `tests/...` (new)
- config: per-node estimator cost table + existing per-model price config

## Gate

Implementation starts **only** on the exact token: **`START PHASE 11`**. I will wait for it.
