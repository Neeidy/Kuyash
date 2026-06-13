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
