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

## ADR-021: Zernio real adapter + per-platform AI disclosure (Phase 10 enable, 2026-06-14)

**Context.** Phase 10 shipped a doc-gated Zernio STUB. With the live spec now in hand
(`https://zernio.com/openapi.yaml` + docs.zernio.com), we built the REAL
`ZernioPublishProvider` behind the existing `PublishProvider` seam. Every field/path is
taken VERBATIM from the spec (no fabrication): base `https://zernio.com/api` + `/v1/...`,
`Authorization: Bearer sk_…`; `POST /v1/media/presign {filename,contentType}` →
`{uploadUrl,publicUrl}` → PUT the render (unauthenticated, presigned) →
`POST /v1/posts {content, mediaItems:[{url,type}], platforms:[{platform,accountId,
platformSpecificData}], publishNow|scheduledFor+timezone}`; status via `GET /v1/posts/{id}`;
read-only `GET /v1/accounts`; webhook HMAC-SHA256 raw-body / `X-Zernio-Signature`, dedup on
`payload.id` / `X-Zernio-Event-Id`. Outcomes map to the taxonomy
(published/accepted/rejected/auth-failed/rate-limited); 429 → bounded backoff; the
`{error,code,reason}` envelope → terminal failure (402 PAYMENT_REQUIRED / 4xx) or
transient (`PublishProviderException` on 5xx/transport).

**AI-label — corrected finding.** An earlier note said Zernio exposes NO AI field. WRONG
(a truncated-fetch artifact). The raw spec defines native AI-disclosure flags: **YouTube
`containsSyntheticMedia`**, **TikTok `videoMadeWithAi`** (also Twitter `madeWithAi`, out of
scope). **Instagram has NO native field.**

**Decision.** HYBRID disclosure with FULL operator control:
- `aiLabelApplied` (set when the compliance check sees realistic AI media) drives disclosure.
- **YouTube/TikTok** → set the native flag. **Instagram** → append a localized
  "Made with AI" / "AI ile üretildi" caption line on its own final line (owner locale).
- A per-platform toggle lives in **Settings → AI disclosure** (migration 0013:
  `ai_disclose_{instagram,youtube,tiktok}`, **default 1/ON** — compliance-first). Turning one
  off is honored but writes a truthful `compliance.ai_disclosure_suppressed` audit event at
  publish time (never silent); the UI shows a risk warning. The post's `ai_label_applied`
  records the EFFECTIVE per-platform decision (truthful).

**Why platform-native over caption-everywhere.** A platform's own AI label is stronger and
truer than caption text (the platform renders the official disclosure). Caption text is the
honest fallback only where no field exists (Instagram).

**Constraint honored.** `ZERNIO_MOCK` stays `true` — NO live publish. The only real call made
during this phase was a read-only `GET /v1/accounts` (lists the connected Instagram account).
The real publish path is exercised by unit tests against fakes (all 8 documented modes).

**Verification.** 821 PASS / 0 FAIL (+adapter mapping ×13, AI disclosure ×5, webhook event-id
×2, settings/migration). Schemas cross-checked against the committed raw `openapi.yaml`.

## ADR-022: The weekly plan becomes a calendar, with two modes (Phase 24, 2026-08-23)

Phase 23 shipped weekly **time templates** ("Mon 09:00" in `workspaces.timezone`,
resolved by a pure DST-correct `SlotResolver`). What it could not do is **hold**
anything: content was bound to a time only at the moment of approval, one item at a
time, so an operator could say "send this to the next Tuesday" but never "put THIS
video on Tuesday the 24th". Phase 24 adds the missing noun — a dated **occurrence**
— and a **mode** saying who fills each time.

**Risk-first.** Before any product code, a spike proved the central seam end to end
with existing code only: a DST-crossing `America/New_York` Wed 09:00 resolves to
`2026-03-11T13:00:00Z`, survives `runs.publish_after` → the queue's `run_after` gate
→ and arrives at the adapter as exactly that `scheduledFor`. Everything after is a
delta on a proven path (`p24/task0`, 2 checks).

**migration 0017 (additive only):** `publish_slots.mode` ('manual'|'auto', default
manual — the honest truth about existing rows); `workspaces.auto_lead_minutes`
(30–1440, default 180) + `plan_paused`; and **`slot_occurrences`** — the calendar
cell. Identity is `UNIQUE(slot_id, local_date)`: the LOCAL day, not the instant, so a
daylight-saving shift moves `publish_at` without ever splitting one Monday into two
cells. `UNIQUE(run_id) WHERE run_id IS NOT NULL` makes "start content for this cell"
safe to retry. **`status` is deliberately only `open|assigned|skipped`** — "preparing
/ waiting for you / scheduled / published" are READ from the run and its jobs by the
derive-only `PlanBoard`, never stored a second time (Phase 22/23's standing rule:
read the real job gate, not the plan). One state machine, not two.

**The load-bearing decision: `runs.publish_after` is written at run BIRTH, not at
approval.** `Engine::approve` only ever *writes* a time (never clears one), so a
pre-set instant survives an approval that names nothing — and, critically, it also
reaches runs that never pass through `approve()` at all, which is the compliance
agent's `finalizeAutoApproved` path. Written at approval instead, an auto-approved
planned post would have ignored its slot and published immediately. Two new Engine
methods carry this: **`setPublishAfter`** (nullable — a missed slot must not leave a
past instant behind, because the queue reads a past instant as "publish now") and
**`cancelRun`** (guarded; refuses once the publish job is `processing`/`published`,
and writes NO `approvals` row — cancelling is not a human rejection). **`startRun`
now delegates to `startRunFor(int $workspaceId, …)`** so the sessionless worker can
create a run without a `WorkspaceContext` ever being bound there (the house rule the
repositories already follow); every existing caller and tenancy check is unchanged.

**`PlanRunner`** is the worker half, on the ordinary chore cadence **and on worker
startup BEFORE the first claim** — order is load-bearing: a worker down over a
planned time must CLOSE those stale publishes, not wake up and fire a day of them.
Three steps per workspace: materialize (idempotent), **sweep** (`GRACE_MINUTES = 60`;
inside the window it still goes out, beyond it the queued publish is cancelled and
the cell is closed with a real reason), then **produce**. Production runs every
guardrail *before a single row exists*: `plan_paused` → `kill_switch` → per-account
daily cap (`PublishCounter`) → connected accounts → owner (`runs.created_by` is NOT
NULL, so an automatic run is attributed to the real owner) → `full` workflow →
`PreflightGate` inside `startRunFor` (throws `BudgetExceededException` before any
row: **no half-started run, no spend**). A block is **NOTED, not closed**
(`noteBlocked`, audited only when the reason CHANGES — the `finalizeDeferred`
discipline): a cap resets and a switch goes back on, so declaring the cell missed
early would be a lie.

**Approval is not weakened.** `script_draft` remains a human gate for automatic runs
(`Nodes::APPROVAL_TYPES` untouched), so the default automatic flow is exactly "Kuyash
writes it, then it waits for you". **ADR-015's locked auto-approval scope is NOT
extended**; fully-unattended publishing stays a separate, compliance-gated task.
`approval_mode` remains `'manual'` by default and no second autonomy toggle was
added — `plan_paused` is deliberately narrower (it stops PRODUCTION; posts a human
already approved keep their time).

**Two real defects found while building, both fixed:** deleting a publishing time
threw a FK violation once its cells existed (`SlotRepository::remove` now drops the
time and its days in one transaction — a day has no meaning without its time, and
the audit trail lives in the append-only event log); and `.sr-only` was used in
markup but **never defined in CSS**, so "hidden" labels had been rendering in full
since Phase 23. Also: `input[type="number"]` matched neither styled selector list
(the same Phase-15 drift), and a calendar cell is far narrower than the shared 200px
select floor (the trap the approval card hit in Phase 23).

**UI:** `/plan` is calendar-first — a **day list at 375px**, a **7-column week grid
at 768px+** (CSS grid over existing tokens, no new dependency). Cell states carry
zero jargon ("Empty", "Kuyash will make one", "Waiting for you", "Ready to go out",
"Missed" + the real reason). The queue's planned card **states** its day instead of
asking again, with "publish now instead" as a deliberate, separate choice that
explicitly CLEARS the stored instant. **Phase 23 debts closed:** plan mutations are
now audited as `guardrail.*`, `slots.invalid` no longer conflates a duplicate with
bad input, and the plan routes plus `/accounts/sync` are per-IP throttled.
**Truthfulness:** the visual seed materializes cells through the real materializer
and leaves them `open` only — a demo never renders a day as "Published" for a post
that never happened.

**All three closing gates returned NO-GO, and every blocker was fixed in the same
round** — each pinned by a test in the `p24/gatefix` group. The convergent one (all
three found it) was the worst: removing a publishing time filtered its committed
days on `publish_at > now`, so a day inside the grace window was deleted without
confirmation and without cancelling its run — leaving a run holding a PAST
`publish_after`, which the queue reads as "publish now". Approving that leftover
card later would have published immediately, from a time the operator had deleted,
with no plan record that it was ever planned. That is the same failure class Phase
23 rated CRITICAL. Fixed on both sides: the confirmation now covers every day
carrying work whatever its time, and `SlotRepository::remove()` refuses outright
while any day still points at a run. Also fixed: a successfully-published day was
swept and audited as `missed` (a fabricated failure per post, every day); the board
windowed from `now`, so the one day needing an explanation was the one day that
vanished and the dashboard's missed counter could never leave zero; every `skipped`
day rendered as a red "Missed", including days the operator cleared and days a
guardrail deliberately held; an uncaught `PlanRunner::tick()` on the worker's
pre-claim path could kill the worker and silently halt ALL publishing; deleting an
ordinary old library video hit a foreign key and 500'd; the approval confirmation
reported the PLAN's time rather than the one the run actually carries, and a
replayed `publish_now` could mutate a run whose approval the engine then refused;
the calendar ignored the real queue gate it had already selected; and
`plan.reason_compliance_block` named slop as the cause of blocks that may have been
format.

Verification: **994 PASS / 0 FAIL** (+53); full visual gate **75 PNG / 0 console
errors / 0 horizontal overflow**; 12 nav routes live 200; live end-to-end (assign →
run #4 → `publish_after` = the cell's instant → approve naming no time → instant
SURVIVED, record `manual`/real user/no policy). Dev DB migrated to 0017 after a
WAL-safe backup: 0 FK violations, existing times defaulted to `manual`. Deferred:
`.claude/docs/phase-24-followups.md`.


## ADR-024: A side card may fail; the dashboard may not (2026-08-26)

Two reads on the dashboard — the week's plan line and the accounts card — could
take the entire page down. The plan one actually did: /dashboard answered 500 for
every workspace that had a publishing time and stayed fine for every workspace
that had none, so the fault read as unrelated to the plan and survived a route
sweep that only looked at status codes. The trigger was a database behind on its
migrations; the defect was that a trigger like that could reach the page at all.

**The line we drew, and where we stopped.** A side card is one the dashboard is
fully useful without: the plan band, the accounts card. Those are guarded. The
reads that remain — kpis, activeRuns, awaiting, nextPublish, business — ARE the
dashboard; if `runs` or `jobs` cannot be read there is nothing honest left to
render, and those must keep failing loudly. Blanket-catching `snapshot()` would
turn the page into one that quietly shows less and less.

**Why a failure gets its own state rather than an existing one.** This is the
part worth remembering. Both cards already had a zero-ish state that MEANS
something to a reader, and handing a failed read that value would have said
something false:

- Zeros for the plan would state a measurement nobody took — above all
  `0 missed`, which an operator reads as "nothing was missed".
- Null for the plan is what a workspace with NO plan looks like, and the page
  renders that as "approved videos publish straight away". A workspace whose plan
  is alive would have been told its posts publish immediately.
- An empty list for the accounts card is what "No accounts connected yet" is
  rendered from; a failed read returning one would tell an operator with live
  channels that they have none.

So each failure carries its own third value and its own sentence: the count is
missing, not zero; these accounts could not be read, which is not the same as
having none. The compliance rule against dressing up a zero is the same rule —
this is what it looks like when the number is absent rather than small.

**A status code is not proof a page worked.** `bin/health.php` logs in, checks
status AND body, and names the workspace it landed in — there is no
workspace-switch route, so one run only ever proves one tenant, which is exactly
the distinction this bug turned on. It exists because an ad-hoc `curl -o /dev/null
-w '%{http_code}'` sweep reported 200 for a dashboard that was a stack trace and
the reading was never afterwards accounted for.

Verification: 1059 PASS / 0 FAIL; both guards proven by removing them and watching
the regression tests reproduce the real PDOException.

## ADR-023: A person can edit the post's text — without a way around compliance or the AI label (Phase 25, 2026-08-24)

Until now the AI wrote the caption and hashtags and the operator could only approve
or reject them. For the distribution-only user — upload your own video, let Kuyash
schedule and publish — that was the single biggest gap. Phase 25 makes the text
editable at the approval step, and spends its whole design budget on the two things
that must not give way when it becomes editable.

**Risk-first.** Before any product code, a spike proved the central claim with the
code that already existed: write an edit straight into `jobs.result_json`, drive
`Worker::tick()`, and the spy provider's `PublishRequest` carries the EDITED text,
still ends with the AI disclosure line, and still has `aiLabelApplied === true`
(`p25/task0`, plus an unedited baseline).

**Storage: no migration, and deliberately no second store.** The edit is written
back over the same `captions` / `hashtags` keys of the generating job — which is
the one thing the publish path reads (`Worker::priorResults()` rebuilds `$prior`
from the jobs table every tick). Writing there means publish reads the edit with
**no change to the publish path at all**, so "publishing the un-edited text" stops
being a mistake four separate readers must each remember not to make, and becomes
structurally impossible. A separate `content_revisions` table was considered and
rejected for exactly that reason: it would have needed an override lookup in
publish, `SlopScorer::historyTexts`, the run screen and the dashboard drawer, and
one forgotten reader publishes the wrong words. What the AI wrote is not lost — the
first edit copies it to `captions_ai` / `hashtags_ai` — and the `compliance_check`
job's own result is never touched, so the record of *what was scored* stays honest.

**The disclosure was already safe; this phase kept it that way and made it
visible.** Instagram has no native AI field, so the line is composed at PUBLISH
time, per account, around whatever the body says — it was never stored in the
caption, which is precisely why an edit cannot carry it off. The composition moved
into one place (`Publish\Disclosure`) so the editor's character counter measures
the same string the publisher builds; two implementations would drift and the count
would quietly start lying. `compose()` also now dedupes, so an operator who types
"Made with AI" themselves does not get it twice. TikTok and YouTube take a native
flag derived from `aiLabelApplied` — from the MEDIA, never from the text — so no
wording can move it. And the requirement itself comes from the media (AI visuals or
TTS): a human writing the words neither creates nor removes it.

**Compliance re-gate, in two places doing two different jobs.** `ContentGate` re-runs
the SAME `SlopScorer` with the SAME `CompliancePolicy` thresholds when an edit is
SAVED — not a second, softer policy. It has to exist because the canonical chain
scores the text at COMPLIANCE, which sits *before* the approval gate the operator
edits at; without it, editing would be the way around the check. At PUBLISH,
`ZernioPublishExecutor` compares a content hash against what the gate judged and
returns `failedPermanent` on a mismatch — text that reached the row without passing
the gate never goes out, and is not retried onto a live account. Slop is
deliberately **not** re-scored at publish: the corpus moves, so a post approved on
Monday could be blocked on Friday by *other* runs, silently stranding approved
content. The hash closes that hole without making publishing unpredictable.

**Limits warn, they do not block (locked decision).** `config/platforms.php` carries
per-platform caption and hashtag limits, and says in the file that they are
UNVERIFIED — platform product limits, not a documented API contract, and the
integrations rule forbids asserting what has not been checked. So the editor says
"this may be too long"; it never refuses. They live in config rather than
`CompliancePolicy` so adding them does not bump the policy version that every past
auto-approval record is stamped with. The one thing that does block is an EMPTY
caption on a *connected* platform — that is missing content, not a length opinion,
and the YouTube title is derived from the caption's first line.

**When editing is open, and why the window is exactly that wide.** Editing is
allowed from the moment the text is FINISHED until the moment publishing is
actually under way: the run waiting at its publish approval, the `final_render`
that follows an approval, or an approved publish still sitting behind its gate.
Earlier than that is a trap rather than a feature — mid-pipeline, later steps have
not written their results, so an edit would hash over half-built content, be
overwritten by the generator, and then be refused at publish as tampering; the
operator would lose their words *and* be accused of changing them. `final_render`
is inside the window on purpose: it renders the VIDEO and never reads the text,
the publish job does not exist yet, and nothing has reached a platform — leaving
it out meant telling someone "you approved it, so for the next few minutes you may
not fix a typo", which nothing on the screen could explain. Past that point the
platform may already hold the post, and an edit here would be a promise the system
cannot keep. A run that was cancelled or failed says so in its own words rather
than borrowing the finished run's "already published" — a false publication claim
on the one screen built to be exact about what went out.

**A refused save must not also destroy the writing.** Every refusal — compliance
block, another tab, the throttle — ends in a redirect, and a redirect re-renders
from what is stored. Without care, being told "that text cannot be saved" also
deleted it: all three bodies and the tags, because ONE of them was empty. So a
refusal holds the submitted text for exactly one page load (`Content\DraftStash`,
keyed by workspace AND run, because run ids restart per workspace) and the editor
shows it back with a line saying it is not stored. Only what is DISPLAYED is
swapped — `hash`, `edited` and the edit block still describe the database, so the
next submit races the right version and unsaved text is never presented as saved.
The write itself takes its lock up front (`Database::immediateTransaction`): a
deferred `BEGIN` that reads before it writes cannot upgrade once another
connection has committed, which in WAL is an instant "database is locked" that
`busy_timeout` does not cover — and the worker commits constantly, including
during the `final_render` window this phase deliberately opened. Left as it was,
the most likely collision in the whole feature produced a 500 with the operator's
words gone.

**The chip beside the publish button describes the text that will publish.** It
used to render the `compliance_check` score, which belongs to the AI-generated
draft — after an edit, a number about the wrong text, in the most consequential
place on the screen. When a run carries an edit, the chip is derived instead from
that edit's stored ContentGate verdict: the same scorer, the same thresholds,
judged on the words that will actually go out. With no edit it falls back and
nothing changes. One derived value feeds the queue card, the dashboard card and
the run screen, so the three cannot disagree about the same post. Two things are
kept apart that a first attempt ran together: being WARNED and being TOO SIMILAR.
A warning about a tag count rendered as "similarity to your recent posts 0.00" —
it named the wrong check and printed a meaningless number — so the similarity
chip now appears only when similarity is what crossed the threshold. The
`compliance_check` job's own result is still never rewritten: it remains the
record of what was scored at that point in the chain. Fixing the chip was not
enough on its own — the card carried a second, unqualified "Compliance: passed"
a few lines lower, sourced from the draft, which is the same false reassurance
one element down; it is suppressed once the text has been edited, while the
AI-label line survives because the label follows the MEDIA. And the chip is
shown for EVERY run, not only edited ones: an edit changes which verdict
applies, never whether the post was checked, so a chip that appeared only after
someone edited would make being checked look like a consequence of editing.

**Records stay truthful.** `approvals` is untouched: no schema change, no new
writer, no new `events.kind` (the edit maps onto `transition`/`compliance`). An
edit made after an approval does **not** rewrite that approval — it was a real
decision at a real time — but it is recorded as `content.edited_after_approval` at
`warn` level and shown as its own badge. An auto-approved render whose words a
person later changed shows two separate facts and never merges them into
"approved by you".

Verification: 1048 PASS / 0 FAIL; visual gate 93 PNG / 0 console errors / 0
horizontal overflow (`/runs/2`, `/runs/3` and `/runs/4` added to the
inventory, because a screen nobody photographs is a screen nobody checks — one
still editable, one finished and therefore read-only, one edited by a person, and
the seed holds TWO waiting posts so the two-editors-on-one-screen case is
photographed rather than assumed); live
end-to-end over real HTTP — edit
saved, `captions_ai` preserved, `compliance_check` untouched, empty caption
blocked, stale hash refused, restore returned the AI original. Deferred:
`.claude/docs/phase-25-followups.md`.

## ADR-025 — Video posters are content-addressed files, not a schema column

**Date:** 2026-08-26 · **Status:** accepted

**Context.** Every video preview in the product — library tiles, the approval
cards on /queue and the dashboard — rendered a grey box with a play glyph. The
product is about vertical video and showed none of it.

**Decision.** A poster is one extracted frame stored under the `cache` store,
named `substr(sha256('poster|v1|' . assets.sha256), 0, 32) . '.jpg'`.

*Why content-addressed and not a column.* The file IS the index: existence is a
stat, two rows holding the same bytes share one poster, and re-running the
backfill is free. `assets` gains no column, so there is no migration and no state
to keep in sync — a poster can be deleted or regenerated at any time without the
database knowing.

*Why never in a request path that serves a page.* Extraction runs at ingest, in
`bin/backfill-posters.php`, and in the demo seed. The dev server is
single-threaded, so a library of ten would otherwise mean ten sequential decodes
blocking the page that asked for them. `GET /media/{id}/poster` serves what
exists and 404s otherwise; callers resolve `has_poster` server-side so a miss is
a fallback tile, not a failed request.

*Why its own ffmpeg instance.* Ingest IS a web request, so a thumbnail grab
shared the upload with the user who triggered it. Inheriting the 900s assembly
watchdog made one crafted 200 MB upload a fifteen-minute worker hold. The poster
gets `media.poster_timeout` (15s); the assembly path keeps 900s.

*Why the poster lives on `<video poster="">`.* The sibling `<img>` and the
`<video>` were both `position:absolute; inset:0`, and a `<video
preload="metadata">` paints black over whatever is beneath it. That was invisible
only while the fixture's render files 404'd and the video element stayed empty.

**Consequences.** A migrated (R2) asset is staged to a work dir and cleaned up,
never into the canonical asset path — otherwise the backfill would re-materialise
a whole migrated library onto local disk. The route caches for one hour, not a
day: the FILE is content-addressed but the URL is keyed on `assets.id`, and
SQLite reuses freed rowids, so a longer cache could show a deleted video's frame
as a new asset's preview. Deleting an asset deletes its poster unless another row
still holds the same bytes.

## ADR-026 — "Approved by you" is said only to the person who decided

**Date:** 2026-08-26 · **Status:** accepted

**Context.** `templates/runs/show.php` hard-coded the label `runs.approved_by_you`
for every `mode='manual'` approval, with no reference to `decided_by`. "you" is
deictic — it resolves to whoever is reading — so the chip told any viewer that
THEY approved the run, while the email rendered beside it named someone else. Two
real operators in one workspace hit this identically; the demo seed's marked
account was simply the first data to exercise the path.

**Decision.** The label branches on whether the deciding account is the session
account: "Approved by you" when it is, "Approved by" otherwise. The record also
renders the deciding account's NAME before its email, because that is where a
marked account (`[SAMPLE] Demo operator`) says what it is — rendering the email
alone stripped the marker at the one screen that shows the record.

**Consequences.** `runs/show` needs `viewerId`; `RunRepository::approvalsForRun`
selects `u.name`. The human-vs-agent distinction the compliance rule protects is
untouched — an `auto` record still renders as the agent with its policy version
and never names a person. What changed is only whether a human record claims the
*reader* is that human.

**Related.** The demo seed writes approvals attributed to a marked, unloginable
demo account rather than to the operator; the record is truthful about who
decided, and this ADR is what makes the screen truthful about it too.
