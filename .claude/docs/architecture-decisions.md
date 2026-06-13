# Kuyash — Architecture Decision Records

## ADR-001: Pure PHP 8.3, no framework
Small, understandable, dependency-light backend for a single-server product. Frameworks add lock-in and surface area.

## ADR-002: SQLite with WAL as the only database
Single-server scale target; WAL gives concurrent reads + serialized writes. Job queue lives in SQLite — no Redis/queue infra in V1.

## ADR-003: Caddy + Cloudflare Tunnel
Automatic TLS, simple config, no exposed ports.

## ADR-004: Cloudflare R2, private by default
Media storage with signed URLs only; no public buckets.

## ADR-005: Vanilla JS + custom CSS
No frontend framework; the product surface is dashboard CRUD + one simple node graph.

## ADR-006: Mock-first integrations behind adapter interfaces
Every provider (OpenAI, TTS, Pexels, trends, AI-video, Zernio, Stripe, R2, ffmpeg) implements a PHP interface; mock adapters are first-class. Swap = one adapter + one config line. Aggregator (e.g. fal.ai) preferred for AI video to avoid vendor lock-in.

## ADR-007: Phase 0 static demo before any backend
Cheapest validation of the full 13-screen product vision.

## ADR-008: Multi-tenant schema, single-user UI
workspace_id on all tenant data from day one; SaaS-ification becomes feature-flipping, not migration.

## ADR-009: Compliance agent as core architecture
AI labels, slop control, truthful approval records, guardrails — platform penalties are an existential risk, so compliance is in the pipeline, not bolted on.

## ADR-010: Stock-mode production before AI video
<$0.10/video economics first; AI video (Phase 12) is credit-gated premium.

## ADR-011: Truthful approval records
Manual = real human record; Auto = "auto-approved by compliance agent". Never misrepresented — legal/trust requirement, enforced as a rule.

## ADR-012: Reference-asset model replaces the shooting-brief flow (2026-06-12)
The "face = human records a clip from a shooting brief, pipeline pauses at
awaiting_recording" assumption is REMOVED (user decision — it was wrong; no human
recording step exists in the product). Replacement: VISUALS can take a **reference
subject** — a library **reference asset** (any photo/clip: own face, a cat, a
product). The workspace/account avatar is just a pre-selected default reference
asset; a per-run pick overrides it ("make this one with my cat").
Phase boundaries (binding): **Phase 7** = avatar pointer (`workspaces.avatar_asset_id`
— per-ACCOUNT defaults arrive with the accounts table in Phase 10) + per-run pick
(`runs.reference_asset_id`) + `face`-format runs resolve VISUALS=LIBRARY to the
selected reference asset (photo → ffmpeg still-clip), NO AI generation; **Phase 12**
(Quick Create) = photo/reference + prompt → image-to-video AI, mandatory AI label;
**V2** = HeyGen-class avatar generation. The `awaiting_recording` status stays in the
runs/jobs CHECK enums as an unused stub (SQLite CHECK removal = table rebuild;
harmless). The Phase 6 `format` recommendation (face/faceless) keeps its stored
values; `face` now MEANS "reference".

## ADR-013: Media production = real ffmpeg fed by mock-first providers (Phase 7, 2026-06-12)
Producing real bytes runs through REAL ffmpeg locally; the TTS and stock providers
are mock-first behind seams but feed genuine inputs, so a full draft+final render
works offline. `src/Media/*`: `Ffmpeg` (proc_open arg-array — never a shell string;
timeout + temp cleanup), `MediaPaths` (tagged `store:ws:name` refs that cross the
job seam, traversal-proof, never absolute paths in the DB), `WavWriter` (pure-PHP
WAV), `AssetCache` (content-addressed sha256 — a hit re-uses the file, no respend),
`AssemblyEngine` (narrated = looped visual + TTS + script-timed subtitles; distribution
= normalize a library video; draft 540x960 then final 1080x1920 = draft-first
rendering), `TtsProvider`/`StockProvider` seams (Mock default / OpenAI-TTS + Pexels
real-but-flag-OFF), `AssetFetchExecutor` (reference → avatar → stock resolution per
ADR-012; photo → still-clip, video → ref). Nodes: PUBLISH expands to
`render_review → final_render → publish` (both templates), so the full-res render is
the approved content. **Subtitles:** the dev ffmpeg build lacks libass/libfreetype
(`subtitles`/`drawtext` filters), so the SRT ships as a sidecar + soft `mov_text`
track; burn-in is behind the `media.burn_subtitles` flag (a build-with-libass
followup). Serving = authed, tenant-scoped `/render` + `/media` with HTTP single-range
(Safari needs 206 for `<video>`). migration 0005 (`avatar_asset_id`, `reference_asset_id`,
`renders`, `asset_cache`). Commit `b90cb8e`.

## ADR-014: Storage abstraction — Local default + Cloudflare R2, flag-OFF (Phase 8, 2026-06-12)
`StorageProvider` is the durable-storage + serving seam (ADR-004/006 realized):
`put`/`getToLocal`/`delete`/`exists`/`size`/`temporaryUrl`, keyed by a logical
`{store}/{ws}/{name}` object key (`StorageKey`, traversal-proof, reuses the codebase
NAME_RE; user input never reaches a key). **`LocalStorageProvider`** is the default and
its put/getToLocal are in-place no-ops when the object already lives under the local
roots — so default deployments are byte-identical to Phase 7. **`R2StorageProvider`**
is real but flag-OFF (S3-compatible, path-style, region `auto`, service `s3`), signed by
a **hand-rolled `SigV4Signer`** (HMAC-SHA256 chain, no AWS SDK / no new dep; verified
against the AWS-published "GET ListUsers" known-answer — canonical-request hash
`f536975d…`, signature `33f5dad2…`). Streaming is a **new `Http/BlobClient` seam**
(`CurlBlobClient`: file-handle PUT, capped sink GET, DELETE/HEAD) — the buffering
`HttpClient` structurally can't express file-handle uploads or abort-on-cap downloads.
**Serving** resolves the provider PER OBJECT from a `storage_disk` column: R2 → 302 to a
short-TTL presigned GET (content-type + disposition pinned; tenant check BEFORE the URL
is minted; `no-store`), local → today's range-stream. **Seam placement:** ffmpeg always
reads/writes local scratch; `put()` runs after a render is produced, `getToLocal()`
stages a remote asset before ffmpeg. **Migration** = per-object `storage_disk` marker
(assets/renders/asset_cache, default `local`) + `bin/migrate-storage.php` backfill
(local→r2, verify-before-flip, idempotent/resumable, **never deletes the local copy** —
delete-after-verify is a Phase 13 eviction concern). The streaming+capped download
utility also retrofits the Pexels clip download (clears the Phase 7 buffering HARD GATE).
`asset_cache` stays a LOCAL reuse layer in Phase 8. **Enable-time HARD GATE:** a
live-bucket SigV4 smoke + PRIVATE/no-ACL bucket confirmation before `STORAGE_DRIVER=r2`.
**Deferred to Phase 13:** assembly-side staging for an evicted remote video; render/cache
eviction. Commit `ddc5cf9`.

## ADR-015: Compliance Agent + approval modes (Phase 9, 2026-06-12)
The COMPLIANCE node (locked into every workflow since Phase 4) becomes a real
`kuyash-v1` policy engine, and approvals gain a truthful Manual/Auto model with
autonomy guardrails (realizes ADR-011) — the prerequisites for any real publish
(Phase 10). **Truthful records as a DB invariant:** migration 0007 rebuilds
`approvals` with a CHECK — `manual ⇒ real decided_by + policy_version NULL` /
`auto ⇒ decided_by NULL + policy_version NOT NULL` (+ `score_json` snapshot); a
"human approved" stamp on an agent decision is a constraint violation, not just a
convention. The auto record is written ONLY worker-side in `Engine::finalizeAutoApproved`
(unreachable from HTTP). **`src/Compliance/*`:** `CompliancePolicy` (versioned
constants — threshold change = VERSION bump), `SlopScorer` (Phase-5 followup #8:
max Jaccard of the *rendered* script+captions vs the workspace's last 10 runs —
one near-duplicate is the violation, empty history = 0), `ComplianceCheckExecutor`
(ai_label [AI visuals OR any TTS incl. mock = synthetic voice → label required] /
format [15-45s + 9:16 from the draft render or asset metadata; missing metadata =
`unknown`, never blocks] / slop [warn ≥ 0.55, block ≥ 0.80] → status
pass/pass_with_ai_label/warn/block with a full audit `result_json`; provider
`kuyash-compliance`, cost NULL), `QualityScore` (derive-only:
`risk = 0.40·slop_avg + 0.35·block_rate` over last 20 checks `+ 0.25·reject_fail_rate`
over 7d; breach `score < 60 AND sample ≥ 5`), `AutoApprovalGate` + `GateDecision`
(ordered rules: mode≠auto → manual / kill switch / not pass|pass_with_ai_label /
daily cap / budget cap / quality breach → flip workspace to Manual), `PublishGateExecutor`
(wraps the still-mock publish; defers auto-approved publishes on kill switch / daily
published cap — Phase 10 swaps only the inner executor), `DigestReport` (derive-only
daily read-model). **Locked decision 1 (user-confirmed, compliance-reviewer GO):**
auto-approve scope = `pass` + `pass_with_ai_label` only (strict-pass would make Auto
dead code since full runs always carry TTS; the label is applied automatically at
publish so a labeled clean render is low-risk); warn/block NEVER auto-approve.
**Engine:** compliance branch (warn advances → guaranteed manual review via the gate;
block cancels the run with reasons; the check job stays `ready` — a verdict, not a
failure, so no retry waste); `JobResult::deferred` + `finalizeDeferred` (back to
`queued`, NO retry_count bump, event only when the reason changes — no spam; the
watchdog ignores `queued` so a deferred publish can't dead-letter). **Settings =
columns on `workspaces`** (not a new table — matches the avatar pattern):
`approval_mode`/`kill_switch`/`daily_post_cap`(1-10, default 2)/`budget_cap_cents`
(NULL = none); `WorkspaceSettings` moves to core.php (worker-side gate reads it,
session-free). **Guardrails constrain autonomy, never humans** — every guardrail
writes a `guardrail.*` audit event; manual approvals/publishes are never gated.
**Budget cap** = observed `SUM(jobs.cost_cents)` (truthful-minimal; Phase 11 ledger +
preflight replaces it). **Daily cap** counted at two points (gate auto-approvals +
PublishGate published) — unify into one per-account counter when Phase 10 adds
accounts (`?int $accountId = null` seam already present). **UI:** `/settings` +
`/digest`; truthful badges branch on the stored `mode` (auto → "Auto-approved by
compliance agent (policy kuyash-v1)", NEVER "by you"); `approvalsForRun` LEFT JOINs
users (auto rows have NULL decided_by by design). Verification: **541 PASS / 0 FAIL**
(+74); compliance-reviewer (MANDATORY) + security-auditor both **GO / 0 blocker**;
ux-reviewer conditional → 3 fixes applied. **Deferred:** `.claude/docs/phase-9-followups.md`.
Commit `431e692`.

## ADR-016: Zernio publishing — mock-first, doc-gated, per-account fan-out (Phase 10, 2026-06-12)

PUBLISH becomes a real-but-**mock-first**, **doc-gated** subsystem behind a
provider-agnostic `PublishProvider` seam (publish/status/name). `MockPublishProvider`
(default) is deterministic — outcome is a pure function of the request; failure/async
modes are provoked by a handle marker (reject/authfail/ratelimit/timeout/async), so one
provider exercises all 8 doc-gate modes with ZERO network. `ZernioPublishProvider` is a
real **flag-off stub**: built only when `ZERNIO_MOCK=false`, it still throws "doc-gated"
on every call BEFORE touching the HttpClient (12 `zernio-notes.md` items unsupplied;
integration rule). **migration 0008:** `accounts` (platform/handle/external_ref/status/
health/default_reference_asset_id — **NO token/password column**; Zernio owns OAuth),
`posts` (one row per (run,account); `UNIQUE idempotency_key` "run:{r}:acct:{a}:publish";
`ai_label_applied`; `scheduled_for`), `webhook_events` (RAW pre-resolution inbox, `UNIQUE
external_event_id` = dup-delivery no-op, no workspace_id — resolved from the matched post),
`runs.publish_after`. **`ZernioPublishExecutor`** (inner publish executor, supersedes
MockExecutor's branch) fans the run out to every connected account, writing/updating a
`posts` row per target: PUBLISHED→post published; ACCEPTED→in-flight (webhook/reconcile
confirms); REJECTED/AUTH_FAILED→post failed (auth also flags the account `reauth_needed`)
— **terminal per-target, the JOB still completes 'published'** so a partial failure never
fails the run; only RATE_LIMITED / transport-timeout return `JobResult::failed` so the
queue backs off (idempotent re-attempt skips terminal targets). **AI-label is truthful:**
`posts.ai_label_applied` = `prior.compliance_check.ai_label_required` exactly — never
claimed otherwise. **`PublishGateExecutor`** now enforces the **per-account daily cap** via
the unified `PublishCounter` (counts `posts`, the truthful source): an auto-approved publish
defers the whole job to next UTC midnight if ANY connected account is at cap (safe — publish
is idempotent per (run,account)); manual publishes pass through (guardrails constrain
autonomy, not humans). `AutoApprovalGate.autoApprovalsToday` stays approval-table /
workspace-wide by design (coarser upstream throttle; cannot cause an over-cap publish —
followup S1). **Scheduling:** an optional time at the render_review approval sets
`runs.publish_after`; `Engine::insertJob` gives the publish job that `run_after` (future →
defers on the existing queue gate, fires when due); past/empty = immediate. **Webhook
security:** `POST /webhooks/zernio` is **CSRF-EXEMPT** (narrow allowlist before the gate in
public/index.php — exact path, same normalization as the router so it can't diverge) and
NOT auth-protected; authenticated by **HMAC-SHA256** (`hash_equals`) verified BEFORE any
persistence; empty secret = **fail-closed** (503), invalid sig = 401 (no body logged),
oversized = 413 (64KB cap), payload url accepted only if `http(s)://`. **Mock OAuth** is a
realistic two-leg flow guarded by a one-time session `state` nonce on the GET callback;
stores reference + health only. **Worker loop:** `WebhookInbox::processPending` each tick +
`Reconciler::sweep` (15-min staleness poll) on the 5-min cadence — no post stays pending
forever. **UI:** `/accounts` (+ nav), runs/show "Published targets" card (per-target status,
AI-label chip, external link w/ `rel=noopener noreferrer nofollow`), digest publish counts.
All publish lifecycle events are `kind='transition'` (events.kind CHECK). Verification:
**587 PASS / 0 FAIL** (+46); DI wiring (web+worker) OK; live smoke (login, /accounts render,
mock OAuth connect + forged-state reject, webhook CSRF-exempt/fail-closed) OK; secret grep
clean. security-auditor + compliance-reviewer (both MANDATORY) **GO / 0 blocker** (1 MEDIUM
external_url scheme → fixed); ux-reviewer conditional → 2 fixes applied. **Deferred:**
`.claude/docs/phase-10-followups.md`. Commit `c664604`.

## ADR-017: Usage ledger, money-credit ledger & pre-flight budget gate (Phase 11, 2026-06-12)

Cost moves from the minimal `SUM(jobs.cost_cents)` to a real **append-only usage ledger**;
"credits" are a money-denominated **display layer over real cents** — **no prepaid economy,
no auto-allowance, no Stripe** (grants are manual). The enforced control is **month-to-date
spend vs `workspaces.budget_cap_cents`**, now a **pre-flight HARD block**. **migration 0009:**
`usage_events` (workspace_id/run_id/job_id/provider/category[ai_text|tts|stock|publish|ai_video]/
model/units/unit_type/cost_cents≥0, `UNIQUE(job_id)`, `idx (workspace_id, created_at)`) +
`credit_transactions` (type[grant|spend|adjust], **signed** amount_cents so balance =
`SUM(amount_cents)`; `idx (workspace_id, id DESC)`; **partial `UNIQUE(ref_job_id) WHERE
type='spend'`**). `model`/`units` are **NULL in V1** (provider+category+cost captured truthfully;
threading token/char counts through the executor seam is a Phase 13 follow-up). **`UsageRecorder`**
is the **single write path**, called from `Engine::finalizeSuccess` and `finalizeAwaiting`
**inside the finalize transaction** (plain `run()`, joins the open tx — never nests; the
short-tx rule holds). **TRUTHFUL by design:** writes a row ONLY for a **real, non-null,
positive** cost — mock providers and cache hits return null cost → **zero rows**; a sub-cent
call rounded to $0 → **zero rows**; unmapped/free job types (trend/assembly/render/compliance/
publish) → zero rows (`jobs.cost_cents` still holds the per-job rollup). **Idempotent:**
`INSERT OR IGNORE` on `usage_events(job_id)`; the mirrored `credit_transactions` spend
(amount = `-cost`) is written only when the usage row was **newly inserted**; recorder runs
only on terminal-success/awaiting (never on failure/retry) so retries can't inflate spend.
**Non-throwing by construction** (no UNIQUE violation, cost clamped to the ≥0 CHECK, category/
unit_type from validated config) → it cannot roll back an otherwise-successful finalize.
**`CostEstimator`** is deterministic + config-driven (`config/usage.php` estimate_cents +
categories + unit_types): expands the run's node set via `Nodes::expand`, sums per-type cents
grouped by category (full ≈ 10c, distribution ≈ 2c; `ai_video` = priced placeholder for Phase 12,
never charged). **`PreflightGate`** (in `Engine::startRun`, **before** any row is created, after
all validation): if `estimate > cap − MTD-spend` → emit `guardrail.preflight_block` + throw
**`BudgetExceededException`** (a now-non-final `WorkflowException` subclass, so the existing
startRun catch sites flash `run.budget_exceeded` unchanged) — **no half-started run**. No cap
set → never blocks. **Single source of truth:** `AutoApprovalGate::monthToDateSpendCents`
re-pointed from `jobs.cost_cents` to `usage_events` via injected `UsageRepository` (Phase 9
approval-time budget guardrail behaviour preserved — a **discriminating** parity test seeds a
different old-rollup total than the ledger and proves the gate reads the ledger). **MTD basis
change on deploy** (no backfill) is a documented plan non-goal → followups. **UI:** `/usage`
(nav between Logs/Digest and Settings; footer "Phase 11") — KPI strip (spent / cap / remaining /
biggest-category-with-amount), budget bar (`role=progressbar`, tone ok/warn/err), 4-category
breakdown, recent charges (truthful "real spend only" + "showing latest N of M" scope note),
credit balance (granted/spent/adjusted; 0=neutral), ≥75% warn / ≥90%+over err banners with
figures; empty/no-cap states; `Format::cents`. **`bin/grant-credits.php`** = manual grant
(positive) / adjust (signed), CLI-only, validates ws + amount. Verification: **630 PASS / 0
FAIL** (+43); DI (web+worker) OK; live smoke (login, /usage states, grant CLI, end-to-end
pre-flight hard-block leaving no run + event in /logs, under-budget proceeds) OK; secret grep
clean. **5-dimension review all GO** — security (MANDATORY) **0 blockers**, php-architect,
compliance/truthfulness, ux, qa; 2 MEDIUM qa findings applied (discriminating parity test +
finalizeAwaiting e2e test) + recorder non-positive-skip + ux polish. **Deferred:**
`.claude/docs/phase-11-followups.md`. Commit `bd6b5a6`.

## ADR-018: Quick Create — AI image-to-video, credit-gated, mock-first, AI-labeled (Phase 12, 2026-06-13)

Kuyash's **second pipeline entry**: a photo + prompt → **AI image-to-video** → the existing
compliance + publish tail. **Locked product shape:** a **short, brief-faithful chain** (the prompt
is the only creative input — **no TREND/IDEA/SCRIPT/VOICE**); **mock-first + doc-gated flag-off** real
provider (no async submit/poll in V1); a **dedicated `/quick` page**. Chain:
**VISUALS(ai) → CAPTION → HASHTAGS → MUSIC NOTE/STYLE → PREVIEW → COMPLIANCE → PUBLISH**
(PUBLISH expands render_review → final_render → publish). **No ASSEMBLE** (engineering refinement vs
the approved preview): the AI clip is already a finished video, so it is normalized at `final_render`
exactly like a distribution library video (`AssemblyEngine::assembleDistribution`, no narrated draft —
`AssemblyExecutor` hard-requires both tts + asset_fetch, which quick_create lacks). **migration 0010:**
id-preserving rebuild of the **`workflows` PARENT table** to widen `template` CHECK with `'quick_create'`
(`runs.entity_type` already allowed it since 0003; `reference_asset_id` since 0005 — no new column, the
prompt rides in the run's nodes_json VISUALS settings snapshot). **Migrator change (the FK trap):**
dropping a parent with child rows throws `FOREIGN KEY constraint failed`, and `PRAGMA foreign_keys` is a
**no-op inside a transaction** — so `Migrator` now toggles `foreign_keys=OFF` **around** each per-file tx
(restored ON on success AND throw paths; same always-ON-on-connect connection) and runs **`PRAGMA
foreign_key_check` after every file**, hard-failing on any orphan (net integrity gain — never verified
before). Verified on the **real dev DB: 4 workflows + 12 runs → 0 violations, 0 orphans**.
**VideoGenProvider seam** (adapter rule, mirrors StockProvider): `MockVideoGenProvider` (default —
ffmpeg **zoompan** Ken-Burns, real 9:16 clip, **costCents=null**, a `FAIL_SENTINEL` prompt triggers the
testable error path) + `FalVideoGenProvider` (real, built only when `VIDEO_MOCK=false`+key — a **doc-gated
stub that throws BEFORE any HTTP**; `.claude/docs/ai-video-notes.md` lists the 7 required doc items) +
`VideoResult{w,h,seconds,costCents,model,meta}`. **`AiVideoExecutor`** (`ai_video` job, defaults timeout
600 / **max_retries 1** — never blindly re-issue a paid call): resolves the run's ready **photo** reference
to a local path (stages remote disks like AssetFetchExecutor), reads+sanitizes the prompt, generates via
**`AssetCache::remember`** content-addressed (`ai_video|provider|assetId|prompt|dur`) → **cache HIT = null
cost (truthful)**, normalizes a **draft render** for review/format-check, and returns
`{visual_ref(cache), draft_render_id, duration, ai_label_required:true, title:prompt, cached}`.
**AI-label is ALWAYS required** (realistic AI media): `ComplianceCheckExecutor::aiLabelCheck` reads
`ai_video.ai_label_required` → `pass_with_ai_label` → `posts.ai_label_applied=1` — no unlabeled-publish
path, no misrepresentation (compliance MANDATORY review confirmed). **Source-aware `Nodes::expand`** is
**polymorphic**: bare node ids keep `VISUALS→asset_fetch` (back-compat); decoded `{node,settings}` entries
let `VISUALS(source=ai)→ai_video`. All three callers pass entries (`Engine::startRun`/`advance`,
`CostEstimator`). **`Engine::startRun` quick_create branch:** requires a ready photo, clamps the prompt to
300, injects it into the VISUALS snapshot, **re-validates the rewritten snapshot** (a drifted prompt can't
start a run), entity_type='quick_create'. The Phase 11 **`PreflightGate` hard-blocks** an over-budget run
(ai_video ≈ $7) **before any spend/row** (`BudgetExceededException` + `guardrail.preflight_block`).
`WorkflowRepository` **seeds** the quick_create workflow but **excludes it from the builder `listFor`**
(`findByTemplate` getter; `WorkflowController` redirects stray quick_create ids to `/quick`). **UI:**
`/quick` (`QuickCreateController` + `templates/quick/index.php`) — photo upload (reuses Phase 3 AssetIngest
validation) OR pick a ready photo (fieldset radiogroup), prompt, **live estimated credit cost**, **mandatory
AI-label notice**; "Create" nav item; `config/media.php` image_video block + `.env.example` VIDEO_* docs.
**Security:** the prompt **never reaches an ffmpeg argument or a path** (sanitized+clamped; the mock only
sentinel-compares it; ffmpeg via arg-array proc_open, no shell); every new query workspace-scoped; CSRF via
the central gate; doc-gated provider leaks no key. Verification: **673 PASS / 0 FAIL** (+43); DI (web+worker)
OK; smoke (real-DB 0010 rebuild + `VIDEO_MOCK=false` doc-gated + HTTP boot) OK; secret grep clean.
**5-dimension review:** compliance (MANDATORY) **GO/0**, security **GO/0**, php-architect **GO/0**, qa
**GO/0**, ux **CONDITIONAL→GO** (2 should-fix applied: ALL-CAPS hint→`field__hint`; non-photo-upload trap→
delete row + distinct message; + radiogroup/focus-visible/.env.example nits). **Deferred:**
`.claude/docs/phase-12-followups.md`. Commit `dd34bbb`.

## ADR-019: Hardening — fast-fail, rate-limit, backup/restore, R2 gate, prod readiness (Phase 13, 2026-06-13)
The FINAL phase (13/13): make the existing V1 safe to operate, no new product features. Seven hardening
threads, all additive + mock-first preserved.
**1. Non-retryable (401/403) fast-fail.** New `Core/PermanentFailure` (marker) + `PermanentFailureException`;
`JobResult` gains a `retryable` flag + `failedPermanent()`; `Engine::finalizeFailure($…, $retryable)`
dead-letters on the FIRST attempt (no backoff) when `!retryable` and labels the message `non-retryable: …`;
`Worker::tick` classifies an uncaught `PermanentFailure` as such. The money-spending adapters (OpenAI text,
OpenAI TTS, Pexels) throw `PermanentFailureException` on HTTP 401/403 — deliberately NOT their domain
exception, so it slips past the executor's `catch (DomainException)` (the TTS/stock producer runs OUTSIDE
`AssetCache::remember`'s try) and reaches the Worker classifier. 429/transport/5xx stay retryable. A
dead-lettered permanent failure is still manually retriable (operator fixes the key). Trends left as-is
(403=quota degrades gracefully); publish already terminal on AUTH_FAILED.
**2. PostRepository UNIQUE backstop.** `insertPublishing` catches a UNIQUE(idempotency_key) collision →
returns the existing post id (treat-as-existing), so a per-(run,account) race never fails the publish job or
double-posts. Unreachable under the single-claimed-job invariant; defense-in-depth.
**3. Webhook per-IP rate-limit.** Migration **0011** `rate_limits` (no workspace_id — pre-auth infra, like
login_attempts/webhook_events) + `Core/RateLimiter` (trailing-window count, clock-injectable, opportunistic
prune). `WebhookController` throttles per IP (120/60s, generous) BEFORE the HMAC/size/secret chain → 429;
limiter nullable so existing tests skip it.
**4. WAL-aware backup/restore.** `Core/SqliteBackup` = `wal_checkpoint(TRUNCATE)` + `VACUUM main INTO ?`
(bound param) + `integrity_check` — never a raw cp of a WAL DB. `bin/backup.php` (DB snapshot + local media
tree + manifest.json; `--db-only`/`--out=`) and `bin/restore.php` (DRY-RUN default; `--force` moves the live
DB+`-wal`/`-shm` aside to `.pre-restore-<ts>` reversibly, never deletes; re-checks integrity). R2 objects
have their own durability (not re-downloaded).
**5. R2 enable-time HARD GATE.** `bin/r2-smoke.php` (operator-gated): put→presigned GET (200+body)→**anonymous
GET must be 401/403 (PRIVATE confirmation; 200=PUBLIC=FAIL)**→delete. Exit 0=PASS/1=FAIL/2=not-configured.
Realizes the ADR-014 enable-time gate. R2 staging/eviction CODE stays deferred (locked scope decision —
no live bucket in V1).
**6. Caddy/Tunnel.** `(app)` snippet shares headers + path-blocklist (extended: `/database`,`/bin`,`/tests`);
production HTTPS block with HSTS (TLS-terminated only); `caddy validate` is an operator host step.
**7. Docs.** `production-readiness.md` (go-live checklist, `[ ]` now vs `[OP]` enable-time), `release-test-
checklist.md` (per-subsystem coverage map + smoke + failure-recovery drill).
**Verification: 693 PASS / 0 FAIL** (+20); secret grep clean; DI + HTTP boot OK; backup/restore CLI round-trip
OK; dev DB migrated to 0011 (WAL-safe backup). **3-dimension review:** security (MANDATORY) **GO/0**,
compliance **GO/0**, ux **GO** (1 polish applied: queue `non-retryable:`→"(no auto-retry)"). **Deferred:**
`.claude/docs/phase-13-followups.md` (CF-Connecting-IP per-IP behind tunnel, restore symlink containment,
rate-limit write-amp). **V1 phase-plan (0–13) COMPLETE.** Commit `9b68a67`.

## ADR-020: i18n (TR/EN) — presentation-layer locale, per-user, EN source + fallback (Phase 14, 2026-06-13)
A missed original requirement: the real backend shipped English-only. NEW mini-phase on top of V1 (0–13),
strictly presentation-layer — the DB stores message KEYS not localized text, so there is **no stored-text
migration and approval-record truthfulness is untouched**. Two locked decisions: (a) **EN = default + source
language**, TR selectable (missing TR key → EN); (b) **per-USER locale** (SaaS-ready), not per-workspace.
**1. Translator.** New `Core/I18n` (static — matches `View::e`/`Format`/`Messages`): `setLocale()` clamps to
`SUPPORTED=[en,tr]` (unknown→en), `t($key,$params)` resolves **locale → en → key** then interpolates `{name}`
(missing key renders visibly, never fatal), `lookup()` returns null-on-miss (the custom-fallback seam for
Messages), `resolve(?session,$default)` is a pure allowlist picker, `interpolate()` reuses Messages' grammar.
Lang maps load lazily via `require lang/{locale}.php` (path derived from `__DIR__`, never user input; cached);
`setLangDir()` is a test-only seam. `View::t($key,$params) = e(I18n::t(...))` — the escaped template short form.
**2. Lang files + the "swap one class".** `lang/en.php` + `lang/tr.php` flat `['key'=>'text']`, **478 keys each,
parity-checked (0 tr-only, 0 missing)**. The former `Messages::MAP` (flash, un-prefixed — the controller
contract), `EVENTS` (→`event.*`) and `STATUS` (→`status.*`) were folded in; `Messages` is now the thin
locale-aware facade (`text/status/event/resolveFlashes` delegate to I18n) — **public API + all ~16 call sites
unchanged**. This realizes the long-planned "the TR i18n pass replaces exactly one class" design.
**3. Migration 0012.** `users.locale TEXT NOT NULL DEFAULT 'en' CHECK (locale IN ('en','tr'))` — additive,
forward-only; the CHECK is DB-level defense behind the app allowlist.
**4. Locale resolution.** `Auth` selects `locale`, caches it at login in `$_SESSION[Auth::SESSION_LOCALE]`,
exposes `sessionLocale()`/`setSessionLocale()`; `public/index.php` calls `I18n::setLocale(I18n::resolve(
auth->sessionLocale(), config app.locale))` ONCE after `Session::start()` (anon → `APP_LOCALE`, new
`config/app.php` `app.locale`). No per-request DB hit just for locale.
**5. Switcher.** `LocaleController` + `POST /locale` (`$protected`; CSRF via the blanket gate — NOT exempt):
allowlist+CHECK validated, prepared-statement UPDATE + session cache, **path-only redirect-back** from Referer
(host dropped → no open redirect; rejects `//` AND `/\` protocol-relative). `templates/layout/app.php` topbar
`.lang-switch` EN/TR segmented toggle (one no-JS form POST per inactive locale, active = `aria-current` span);
`<html lang>` in `base.php`+`app.php`.
**6. Template extraction.** ~250 UI literals across the **21 templates** → `View::t('area.key')`; dynamic data
stays `View::e($var)`. Sentences with inline links use a segment-split (text runs as separate escaped keys) so
TR word order reads naturally. **Canonical node names (TREND/COMPLIANCE/PUBLISH/LIBRARY) are NOT translated.**
**Verification: 732 PASS / 0 FAIL** (+39: I18n fallback/interp/clamp/resolve, 0012 column/CHECK/default,
`/locale` CSRF+redirect+backslash, TR-render smoke ≥2 screens, **BOTH-language compliance-truthfulness gate**,
lang-parity + template-key coverage scan, Auth locale cache + LocaleController). Dev DB migrated to 0012
(WAL-safe backup `kuyash.pre-0012.bak.sqlite`); HTTP smoke: login→EN dash→`/locale`→TR dash (`lang="tr"`,
"Panel"/"Çıkış yap", 0 "Sign out"), DB persisted, smoke4 reset to 'en'. **3-dimension review:** compliance
(MANDATORY GATE) **GO/0** (TR keeps human-vs-agent approval + mandatory-AI-label distinction), security **GO**
(1 LOW backslash open-redirect → regex guard + test APPLIED), ux **GO** (slop-chip `chip--wrap` + `dash.kpi_cache`
TR shortened APPLIED; aria-current/colon nits deferred — cosmetic). **Deferred (cosmetic):** SR label on the
active language span; a few enum values (account status/health, asset type/kind, tx type) left as data, not
translated. Commit `2e4bd41`.
