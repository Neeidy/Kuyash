# Kuyash — Phase 9 Plan: Compliance Agent + Approval Modes

> Token to start: **`START PHASE 9`**. This document is the plan only — no product
> code is written until that exact token is issued. Plan approval confirms direction;
> it does **not** unlock coding on its own. After approval this plan is saved to
> `.claude/docs/phase-9-plan.md` and the checkpoint is updated.

## Context — why now

Phases 0–8 are accepted (`ddc5cf9` = HEAD, origin synced, **467 PASS / 0 FAIL**).
The pipeline produces real renders (Phase 7) on swappable storage (Phase 8), but the
COMPLIANCE node — locked into every workflow since Phase 4 — is still the
`MockExecutor` "always pass, policy mock-v0" stub, and every approval is hardcoded
`mode='manual'`. Phase 10 (Zernio publishing) must not arrive before real compliance
exists: AI-label enforcement, slop scoring, format rules, truthful Manual/Auto
approval records, and autonomy guardrails are the prerequisites for letting anything
reach a real platform. Phase 9 is the phase-plan's designated next step and the
compliance-policy doc explicitly gates it: *"Phase 9 cannot close without the quality
score and its thresholds implemented and tested."*

## Current state (verified)

- `compliance_check` job type exists, runs via `MockExecutor` ([src/Workflow/MockExecutor.php](src/Workflow/MockExecutor.php) — mock pass, checks stub for format/slop/ai_label). No real executor registered in [src/bindings/core.php](src/bindings/core.php).
- `approvals` table (0003): `mode IN ('manual','auto')` but `'auto'` is a schema-only stub; **`decided_by INTEGER NOT NULL`** — conflicts with truthful auto records. `Engine::recordApproval()` hardcodes `'manual'`.
- Engine seams verified: `finalizeSuccess()` special-cases `compliance_check` (events `compliance.passed`, [Engine.php:325](src/Workflow/Engine.php#L325)); `finalizeAwaiting()` ([Engine.php:335](src/Workflow/Engine.php#L335)) is where `render_review` pauses — the single seam for Auto mode.
- Events audit log is append-only with kinds `transition|compliance|guardrail` — `'guardrail'` exists, unused. No schema change needed there.
- `VariationEngine::similarity()` (Jaccard, 0–1) exists, commented "used by Phase 9 scoring". Phase-5 followup #8: score the **actual rendered script/caption text**.
- `ai_label_required` propagates from `assets.type='ai'` through asset_fetch → assembly → final_render priors. TTS prior presence = synthetic voice in final audio.
- `WorkspaceSettings` ([src/Workspace/WorkspaceSettings.php](src/Workspace/WorkspaceSettings.php)) exists but is avatar-only and works on **columns of the `workspaces` row**; bound in web.php only — worker/Engine can't reach it yet.
- No `/settings` or `/digest` routes/templates. "Approved by you" is hardcoded in [templates/runs/show.php:169](templates/runs/show.php#L169) and [templates/queue/index.php:88](templates/queue/index.php#L88) — a truthfulness bug the moment Auto exists. `RunRepository::approvalsForRun()` INNER JOINs users — breaks on NULL `decided_by`.

## Locked decisions (confirmed 2026-06-12)

1. **Auto-approve scope = `pass` + `pass_with_ai_label`** (user-confirmed). The AI
   label is applied automatically at publish, so a labeled clean render is low-risk;
   strict-`pass`-only would make Auto dead code on the main pipeline (full runs always
   carry TTS). `warn`/`block` NEVER auto-approve. Flagged explicitly to the
   compliance-reviewer gate.
2. **Truthful records enforced by schema.** Migration rebuilds `approvals`:
   `decided_by` nullable + new `policy_version TEXT`, `score_json TEXT`, and CHECK
   `(mode='manual' AND decided_by IS NOT NULL AND policy_version IS NULL) OR
   (mode='auto' AND decided_by IS NULL AND policy_version IS NOT NULL)` — "records
   never misrepresented" becomes a database invariant. Sentinel "agent user" rejected
   as an untruthful-record pattern.
3. **Settings = columns on `workspaces`** (not a new table): `approval_mode`,
   `kill_switch`, `daily_post_cap` (default 2 — policy's conservative 1–3 band),
   `budget_cap_cents` (NULL = no cap). Matches the existing `WorkspaceSettings`
   pattern (avatar already lives there); simpler migration (ADD COLUMN, no
   backfill/upsert). Binding moves web.php → core.php (Engine/worker needs it).
4. **Block = run cancelled with reasons** (no revise-loop in V1 — that stays a
   Phase-5-followups deferral). `compliance_check` returns `ready` even on block (a
   completed check, not a job failure — avoids retry waste); the Engine branch
   cancels the run + records `compliance.blocked` / `run.blocked_by_compliance`
   events with reasons. User starts a corrected run.
5. **Policy constants versioned in code** (`CompliancePolicy::VERSION = 'kuyash-v1'`,
   thresholds, weights). Threshold change = version bump. Not per-workspace user
   settings (no-overbuild).
6. **Guardrails enforce at two points**: (A) the auto-approval gate, (B) a
   `PublishGateExecutor` wrapping the mock publish executor (kill switch / daily cap
   defer **auto-approved** publishes; manual approvals always pass — guardrails
   constrain autonomy, not humans). Phase 10 swaps the inner executor; the gate
   survives.
7. **Daily digest = derive-only, in-app** (no email, no new table): read-model over
   approvals + jobs + guardrail events for a UTC date.

## Scope (precise)

### 1. Migration `database/migrations/0007_compliance.sql`
- `workspaces` ADD COLUMNs: `approval_mode TEXT NOT NULL DEFAULT 'manual' CHECK(IN ('manual','auto'))`, `kill_switch INTEGER NOT NULL DEFAULT 0`, `daily_post_cap INTEGER NOT NULL DEFAULT 2 CHECK(1–10)`, `budget_cap_cents INTEGER NULL CHECK(NULL OR >0)`.
- `approvals` rebuild (CREATE new → INSERT…SELECT → DROP → RENAME → recreate index) per locked decision 2. Safe: nothing references approvals.

### 2. Compliance core (`src/Compliance/`)
- **`CompliancePolicy.php`** — constants: VERSION `'kuyash-v1'`; duration 15–45 s (±0.5); aspect 9:16 (|w/h−0.5625| ≤ 0.01); slop **warn ≥ 0.55, block ≥ 0.80**; history N=10 runs; quality-score weights/threshold/windows (below).
- **`SlopScorer.php`** — candidate text = rendered script + captions (distribution: captions only); history = last N=10 runs' same-shaped text from `jobs.result_json` (workspace-scoped, current run excluded); score = **max** Jaccard similarity vs history (one near-duplicate is the violation); empty history → 0.
- **`ComplianceCheckExecutor.php`** — real executor registered for `compliance_check` (MockExecutor arm deleted). Checks: **ai_label** (AI visuals prior OR tts prior present → required; reasons `ai_visuals`/`synthetic_voice` — applies to mock TTS too: the artifact is synthetic regardless of provider), **format** (duration/aspect from render row or asset_fetch metadata; missing metadata → `unknown`, recorded, never blocks — only definite violations block), **slop**. Status: block reason → `block`; slop warn → `warn`; label → `pass_with_ai_label`; else `pass`. `result_json` = full audit record (checks, scores, reasons, policy) per compliance-policy audit rule. Provider `'kuyash-compliance'`, cost NULL.
- **`QualityScore.php`** — derive-only read model:
  `risk = 0.40·slop_avg(last 20 checks) + 0.35·block_rate(last 20 checks) + 0.25·reject_fail_rate(7d: rejected render_reviews + failed publishes / totals)`;
  `score = round(100·(1−risk))`; **breach: score < 60 AND sample ≥ 5**. Computed at every auto-approval attempt + shown in Settings/Digest. Not persisted (inputs already in jobs/approvals); the breach **flip** is the persisted, audited state change.
- **`AutoApprovalGate.php`** (+ GateDecision DTO) — ordered rules at `finalizeAwaiting()` for `render_review`: mode≠auto → manual (silent); kill switch → deny (`guardrail.kill_switch`); compliance result must be pass/pass_with_ai_label (locked decision 1) else manual (silent); daily cap (COUNT today's auto-approvals, UTC) → deny (`guardrail.daily_cap_reached`); budget cap (month-to-date `SUM(jobs.cost_cents)` — truthful minimal; Phase 11 replaces with ledger+preflight) → deny (`guardrail.budget_cap_reached`); quality breach → **flip workspace to manual** + `guardrail.fallback_to_manual` + deny. Auto path: guarded UPDATE → approvals row (`mode='auto'`, `decided_by NULL`, `policy_version`, `score_json`) → `approval.auto_approved` event → advance. All local SQLite reads (short-transaction rule holds). Count methods take `(int $workspaceId, ?int $accountId = null)` — Phase 10 per-account seam.
- **`PublishGateExecutor.php`** — wraps mock publish: run auto-approved + kill switch → defer; + daily published cap → defer to next UTC midnight. Manual-approved → pass through.
- **`DigestReport.php`** — UTC date → auto-approved items (with render thumbnails), auto-published jobs, that day's guardrail events, current quality score + mode, fallback highlight.

### 3. Engine + queue plumbing (`src/Workflow/`)
- `Engine::finalizeSuccess()` — compliance branch: pass/pass_with_ai_label → `compliance.passed` (params + result + slop) → advance; warn → `compliance.warned` (level warn) → advance (gate guarantees manual review); block → `compliance.blocked` (level error) + run cancelled + `run.blocked_by_compliance`, no advance.
- `Engine::finalizeAwaiting()` — consult AutoApprovalGate for `render_review` before writing awaiting state. `recordApproval()` extended (mode, policy_version, score_json).
- **`JobResult::deferred(reason, delaySeconds)`** + `Engine::finalizeDeferred()` — guarded UPDATE back to `'queued'` + `run_after`, **no retry_count increment** (a halt is not a failure); defer reason in `error_message`; `guardrail.publish_deferred` event only when the reason changes (no event spam).
- `RunRepository::approvalsForRun()` → LEFT JOIN users.
- `WorkspaceSettings` — new getters/setters for the 4 columns; binding moved to core.php.
- `Messages.php` — new keys: `compliance.warned/.blocked`, `run.blocked_by_compliance`, `approval.auto_approved`, 5× `guardrail.*`, settings flashes.

### 4. UI (minimal, vanilla JS + custom CSS)
- **`GET/POST /settings`** (+ `POST /settings/kill-switch`) — SettingsController + template: approval-mode radio, daily cap, budget cap, prominent kill-switch, read-only quality score + policy version + "auto slots used today x/cap". CSRF per existing pattern. Kill-switch flips write `guardrail.kill_switch_on/_off` audit events.
- **`GET /digest`** — DigestController + template (date param, default today).
- **Truthful badges**: [templates/runs/show.php](templates/runs/show.php) + [templates/queue/index.php](templates/queue/index.php) branch on mode — manual → "Approved by you · email · time"; auto → "Auto-approved by compliance agent (policy kuyash-v1) · time" + score. Queue render_review card gains compliance chip (pass / AI label / warn slop 0.xx).
- Nav links in layout.

## Non-goals (explicit)

- NO real publishing, accounts table, Zernio, webhooks, per-account caps (Phase 10 — seam only).
- NO credit ledger, preflight cost estimation, full budget wiring (Phase 11 — budget check here = truthful minimal SUM of observed `cost_cents`).
- NO AI video / Quick Create (Phase 12). NO revise-loop for blocked runs (deferred).
- NO email/notification infrastructure (digest is in-app).
- NO per-workspace threshold tuning UI (policy constants are versioned code).
- NO untruthful record of any kind — anywhere, ever.

## Acceptance criteria (measurable)

1. Full mock run e2e: compliance_check produces real `kuyash-v1` result with format/slop/ai_label checks; full runs (TTS) → `pass_with_ai_label`; AI-label decision recorded in audit events.
2. Slop: near-duplicate run → warn ≥ 0.55 lands in manual review even in Auto mode; extreme duplicate ≥ 0.80 → run cancelled with reasons in timeline.
3. Format: out-of-range duration or non-9:16 blocks; missing metadata never blocks.
4. Manual mode (default): behavior unchanged vs Phase 8; record = real user + timestamp; schema CHECK rejects untruthful rows (proven by `throws()` tests).
5. Auto mode: clean render auto-approves with `mode='auto'`, `decided_by NULL`, policy version + score snapshot; UI shows "Auto-approved by compliance agent (policy kuyash-v1)" — never "by you".
6. Guardrails: daily cap deny + event; budget cap deny; kill switch instantly stops auto-approvals AND defers queued auto-approved publishes (manual publishes unaffected); all writes guardrail events.
7. Quality score: formula + thresholds implemented exactly as specified; breach flips workspace to Manual + event + digest highlight; sample < 5 never flips; re-enabling Auto is a human Settings act.
8. Digest page lists the day's auto-approved/published items + guardrail events.
9. Tenant isolation on every new query; migration preserves existing approvals; full suite green (~535 expected, 0 FAIL); no secrets; no network in tests.

## Manual test steps (phase close)

1. `php tests/run.php` → 0 FAIL.
2. Live smoke ([Terminal-1] `php -S 127.0.0.1:8082`, [Terminal-2] `php bin/worker.php`, smoke4@kuyash.local): default Manual full run → unchanged flow, compliance chip visible.
3. Settings → enable Auto → clean run auto-approves; badge truthful; digest shows it.
4. Run same topic repeatedly → slop warn lands in manual queue; force near-identical → block cancels run with reasons.
5. Set daily cap = 1 → second auto-approval denied with guardrail event; flip kill switch → auto stops instantly, queued auto publish defers; manual approve still publishes (mock).
6. Reviewers: **compliance-reviewer (MANDATORY — hand it: truthfulness CHECK, locked decision 1, badge wording)** + security-auditor (new POSTs, CSRF, tenant scoping) + ux-reviewer (settings/digest).

## Risks

1. **`pass_with_ai_label` auto-approve interpretation** — user-confirmed but explicitly re-verified at the compliance-reviewer gate; worst case = one-line gate change to strict pass.
2. **Slop threshold calibration** — mock texts share scaffolding; deterministic seeds let tests pin scores; tuning = policy version bump, never silent.
3. **Existing e2e breakage** — compliance now judges: full-run assertions change (`mock-v0`→`kuyash-v1`, pass→pass_with_ai_label); distribution fixtures need 15–45 s durations. Deliberate fixture updates, not threshold softening.
4. **Truthfulness drift across UI surfaces** — every approvals-rendering surface must branch on mode; one missed "Approved by you" = critical bug by policy. Dedicated tests per surface.
5. **New defer semantics** — small new Engine path; mitigated: no retry interplay, watchdog ignores `'queued'`, dedicated tests.
6. **Gate reads inside finalize transaction** — all local indexed SQLite, bounded query count; no external calls (rule holds).

## Test plan (~70 new checks; 467 → ~535)

Migration integrity + truthfulness CHECK throws (8) · SlopScorer bands/history/isolation (6) · ComplianceCheckExecutor per-status + ai-label via visuals/TTS + format + unknown-metadata (10) · Engine outcomes pass/warn/block (5) · truthful records manual vs auto (8) · guardrails cap/budget/kill/fallback + tenant isolation (10) · QualityScore math + boundaries + sample floor (6) · PublishGate/defer (7) · digest (4) · controller/UI smoke incl. badge truthfulness (8) · existing e2e updates (~5 modified + 1 new block test).

## Token

Implementation starts ONLY with the exact token: **`START PHASE 9`**.
Plan approval ≠ start. I will wait for the token.
