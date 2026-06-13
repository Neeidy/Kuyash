# Phase 10 — Zernio Publishing (Plan)

> Approved in Plan Mode (2026-06-12). This is the authoritative Phase 10 plan.
> **No product code is written until the exact token `START PHASE 10`.** (Phase-discipline rule.)

## Context

Kuyash is built and accepted through **Phase 9** (HEAD `431e692`, pushed). The pipeline
`TREND → … → COMPLIANCE → PUBLISH` runs end-to-end, but **PUBLISH is still a pure mock**
(`MockExecutor` returns `JobResult::published(...,'mock')`) and there is **no accounts model**.
Phase 9 left deliberate seams for this phase: the `publish` job already carries an
`idempotency_key`, `PublishGateExecutor` already wraps the publish step with kill-switch/daily-cap
guardrails, and the daily-cap counters already expose a `?int $accountId = null` parameter waiting
to be wired. The compliance check already emits `ai_label_required`.

Phase 10 turns PUBLISH into a real (but **mock-first**, **doc-gated**) publishing subsystem: social
accounts, a faithful mock OAuth connect flow, scheduled + immediate publishing across platforms,
per-platform AI-label automation, a raw-first webhook inbox with idempotent processing, and a
reconciliation poll so no post is stuck "pending" forever.

**Doc-gate (firm):** `.claude/docs/zernio-notes.md` lists 12 required items; **none are supplied**
and there is no Zernio config/creds. → **Real Zernio stays BLOCKED.** The real client is built as a
flag-off stub that throws "doc-gated" if activated. All behaviour is exercised through the mock.

**User scope decisions (this planning round):**
1. **Trim cockpit/metrics** → the topbar live NEXT-UP `mm:ss` countdown widget and the daily
   `account_metrics` snapshot + growth deltas are **deferred to `phase-10-followups.md`**. (Scheduling
   *data* is still built; only the live topbar widget + metrics job are deferred.)
2. **Realistic two-leg mock OAuth** → initiate → mock provider callback → store an account
   **reference + health only**, never tokens/passwords (Zernio owns OAuth tokens).
3. **Schedule + immediate** → publish now OR pick a future time; future publishes defer via the
   existing queue `run_after`; a server-rendered "next scheduled" line reads the schedule.

---

## Current state — built / verified / mocked

- **Built & accepted through Phase 9** (541 tests PASS, 3 reviewers GO, pushed to `origin/main`).
- **Verified:** auth + tenant isolation, content library, workflow engine + worker + watchdog +
  immutable event log, Script/Caption engine, Trend Radar, Media Production (ffmpeg/TTS/Pexels),
  R2 storage abstraction, Compliance Agent (truthful Manual/Auto records + guardrails).
- **Mocked / flag-off:** OpenAI text+TTS, Pexels, Google Trends + YouTube Data (real-but-default-OFF
  behind adapters). **PUBLISH entirely mock. No `accounts` table.** Migrations 0001–0007 applied.

## Recommended next phase — **ONE: Phase 10 — Zernio Publishing** (token `START PHASE 10`)

---

## Scope (precise) — backbone, mock-first behind `PublishProvider`

### A. Data model — migration `0008_accounts.sql` (tenant-scoped, append-only)
- **`accounts`**: `id, workspace_id(FK), platform(instagram|tiktok|youtube), handle, external_ref
  (Zernio account id, nullable until connected), status(connected|reauth_needed|disconnected),
  health(ok|degraded|unknown), default_reference_asset_id(FK assets, nullable — wires the Phase 7
  per-account-default seam), connected_at, created_at, updated_at`. **No tokens/passwords columns.**
- **`posts`** (one publish target = one (run, account)): `id, workspace_id, run_id, job_id,
  account_id, platform, status(scheduled|publishing|published|failed|cancelled), external_post_id
  (nullable), external_url(nullable), ai_label_applied(0|1), scheduled_for(nullable=immediate),
  idempotency_key(UNIQUE "run:{run}:acct:{acct}:publish"), error_message, created_at, posted_at,
  updated_at`. Per-account granularity drives caps, next-up, reconciliation, idempotency.
- **`webhook_events`** (raw-first inbox, per content-pipeline.md): `id, source('zernio'),
  external_event_id(UNIQUE — duplicate delivery = no-op), payload_json, signature, received_at,
  processed_at(nullable), process_error(nullable)`. Workspace/account resolved during processing.

### B. Provider adapter (copy the Trend/Storage template)
- `src/Publish/PublishProvider.php` (interface: `publish()`, `status()`, `name()`),
  `MockPublishProvider.php` (deterministic; simulates all 8 modes), `ZernioPublishProvider.php`
  (real **flag-off stub** — throws "doc-gated" if `ZERNIO_MOCK=false` without docs/creds; uses the
  `HttpClient`/signing seam). Binding in `src/bindings/core.php`; `config/zernio.php`;
  `.env.example` adds `ZERNIO_MOCK=true` (default) + placeholder url/key/timeout.
- **Mock simulates all 8 required modes:** success · platform rejection · rate-limit(429→backoff) ·
  auth-fail(401/403→dead-letter) · partial multi-platform(2 ok/1 fail) · lost webhook(→reconcile) ·
  duplicate webhook(→idempotent) · transport timeout(→retry).

### C. Publish executor + guardrails (reuse Phase 9 wrapper)
- `src/Publish/ZernioPublishExecutor.php` implements the job-executor interface; registered as the
  **inner** `publish` executor, still wrapped by the existing `PublishGateExecutor` (kill-switch +
  cap unchanged). Iterates the run's connected accounts, writes/updates `posts` rows, calls the
  provider per account, records per-target results. Supersedes `MockExecutor`'s publish branch.
- **AI-label automation:** read `prior['compliance_check']['ai_label_required']`; set
  `posts.ai_label_applied`; include the per-platform AI-label/content-flag field in the (mock)
  payload. Truthful — never claimed when not applied.
- **Idempotency:** per-(run,account) key prevents double-post on retry/re-enqueue.

### D. Scheduling (schedule + immediate)
- Optional "schedule for" datetime at the render-review approval/publish step (default = now).
  Publish job `run_after` = scheduled time (reuse existing queue defer); `posts.scheduled_for`
  copies it. Server-rendered "Next scheduled" line on the Accounts page reads the earliest pending
  publish. (Live topbar `mm:ss` countdown widget = **deferred**.)

### E. Mock OAuth connect flow (realistic two-leg)
- `/accounts` page + `AccountsController`: list accounts (platform, handle, status/health chips);
  **connect** `GET /accounts/connect/{platform}` → mock authorize screen → `GET /accounts/callback`
  → create account `external_ref` + health (**no tokens stored**); **disconnect** (POST + `data-confirm`).
  Per-account default-reference-asset picker (minimal). Nav entry in `layout/app.php`.

### F. Webhook inbox + processing + reconciliation
- **Inbox:** `POST /webhooks/zernio` — **CSRF-exempt** (external callback; protected by **signature
  verification**, not session), persist RAW + `external_event_id` to `webhook_events`, return 200
  fast. Duplicate `external_event_id` = unique-constraint no-op.
- **Processing:** separate step (worker sweep) reads unprocessed rows, matches `posts` by
  `external_post_id`, updates status, writes an `events` audit row, sets `processed_at`. Replayable
  by resetting `processed_at` (process_error captured on failure).
- **Reconciliation:** `Reconciler` sweep (mirrors the Phase 4 watchdog, invoked from the worker
  loop) finds posts in-flight > 15 min with no webhook, polls provider `status()`, converges to
  published/failed. No post stays "pending" forever.

### G. Per-account daily cap (unify the two Phase-9 count points)
- Wire the existing `?int $accountId` seam: count published `posts` per account/day. Both the
  `AutoApprovalGate` (auto-approval site) and `PublishGateExecutor` (published site) call one
  per-account counter. Cap enforced per account; over-cap auto-runs defer (existing behaviour).

---

## Non-goals
- **Any real Zernio API call** — blocked until all 12 `zernio-notes.md` items are supplied.
- Storing platform passwords or OAuth tokens (Zernio owns them; Kuyash stores reference + health).
- **Topbar live NEXT-UP `mm:ss` countdown widget** and **daily `account_metrics` snapshot + growth
  deltas** → deferred to `.claude/docs/phase-10-followups.md`.
- Account-subset selection UI (publish targets = all connected accounts) → follow-up.
- Stripe/billing (Phase 11), Quick Create AI video (Phase 12), real analytics ingestion.
- Multi-tenant UI, team roles.

---

## Critical files
- **New migration:** `database/migrations/0008_accounts.sql` (accounts, posts, webhook_events).
- **New adapter:** `src/Publish/{PublishProvider,MockPublishProvider,ZernioPublishProvider,
  ZernioPublishExecutor}.php`; `config/zernio.php`; binding in `src/bindings/core.php` (template:
  `src/Trend/*` + `src/Storage/R2StorageProvider.php`); `.env.example` (+`ZERNIO_MOCK`).
- **Executor swap:** `src/bindings/core.php` (publish registration — keep `PublishGateExecutor`
  wrapper, swap inner from `MockExecutor` to `ZernioPublishExecutor`).
- **Cap unification:** `src/Compliance/PublishGateExecutor.php` (`publishedToday`) +
  `src/Compliance/AutoApprovalGate.php` (`autoApprovalsToday`) — wire `accountId` against `posts`.
- **AI-label source (read-only ref):** `src/Compliance/ComplianceCheckExecutor.php`
  (`ai_label_required`).
- **Webhook + reconciliation:** new `src/Publish/{WebhookController,WebhookInbox,Reconciler}.php`;
  CSRF-exempt route handling in `public/index.php` (route allowlist before the POST CSRF gate);
  worker-loop hook in `bin/worker.php` (mirror Phase 4 watchdog sweep).
- **UI:** new `src/Controllers/AccountsController.php`, `templates/accounts/index.php`; routes in
  `src/routes.php`; nav in `templates/layout/app.php`. Reuse `Csrf::field()`, `.card`, `.chip`,
  `.ui-state`, `data-confirm` patterns; truthful-badge pattern from `templates/runs/show.php`.
- **Audit:** `src/Workflow/EventLog.php::record()` for new keys (`publish.attempt/success/failed`,
  `publish.webhook_received`, `publish.reconciled`).
- **Tests:** extend `tests/run.php` (harness: `migratedDb`, `makeRig`, `check`, `FakeHttpClient`).

---

## Verification / acceptance criteria
- Migration 0008 applies cleanly; `accounts`/`posts`/`webhook_events` carry `workspace_id`; **every
  new query filters by workspace** (tenant-isolation tests prove cross-workspace denial).
- Mock publish runs end-to-end through the queue; **idempotency proven** (re-enqueued publish &
  duplicate webhook → single effect, no double-post).
- **All 8 mock failure modes** have passing tests, incl. partial multi-platform, lost
  webhook → reconciliation converges, duplicate webhook → idempotent no-op.
- **Webhook signature verification enforced**; unverified payloads rejected + logged; webhook route
  bypasses CSRF but nothing else does.
- **AI-label** set per platform exactly when compliance requires it; truthful approval record
  preserved through publish (no fake "human approved").
- **Per-account daily cap** enforced at the unified counter; over-cap auto-run defers.
- Scheduling: a future-scheduled publish defers via `run_after` and fires when due; "Next scheduled"
  line renders with empty/"no data" state.
- `ZERNIO_MOCK=false` without docs/creds → real stub **throws "doc-gated"**, never a live call.
- Full suite green (≥541 baseline + new); secret grep clean; live smoke (8082 + worker) regression-free.
- **Mandatory before close:** `security-auditor` **and** `compliance-reviewer` both GO, plus
  `ux-reviewer` (standard end-of-phase).

---

## Risks
- **Doc-gate temptation** — sliding toward a real call. *Mitigation:* real client is a flag-off stub
  that throws; no Zernio creds introduced; default `ZERNIO_MOCK=true`.
- **Webhook security** — CSRF-exempt endpoint is attack surface. *Mitigation:* HMAC signature verify
  before any processing, raw-first persistence, `external_event_id` idempotency, route allowlisted
  narrowly. **Security-auditor focus.**
- **Scope sprawl** — accounts + OAuth + scheduler + webhooks + reconciliation is wide even trimmed.
  *Mitigation:* mock-only OAuth/metrics, all-accounts publish (no subset UI), cockpit widgets deferred.
- **Compliance integrity across the publish boundary** — AI-label + truthful record must survive into
  the published record, not just the render. **Compliance-reviewer focus.**
- **Cap unification** — merging the two Phase-9 count points risks double-count/gap. *Mitigation:*
  single per-account counter against `posts`, explicit tests at both call sites.
- **Multi-account fan-out semantics** — partial failure must not fail the whole run or block other
  accounts. *Mitigation:* per-`posts` status; run completes with mixed per-target outcomes recorded.

---

## On approval (no coding)
1. ✅ Saved to `.claude/docs/phase-10-plan.md`.
2. ✅ Checkpoint updated (Sıradaki adım → START PHASE 10).
3. **Wait for the exact token `START PHASE 10`.** Do not start implementation before it.
