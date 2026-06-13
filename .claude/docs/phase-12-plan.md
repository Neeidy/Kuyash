# Phase 12 — Quick Create AI Video (credit-gated, image-to-video) — Approved Plan

> Approved via `/next-phase` (Plan Mode) on 2026-06-13. **No implementation** until the user
> issues the exact token `START PHASE 12`. Plan approval ≠ start coding.

## Context

Kuyash's second pipeline entry, promised in the product brief and the Phase 0 demo: a user
uploads a photo (or picks a library reference asset) + writes a prompt → **AI image-to-video** →
the clip is distributed through the existing compliance + publish tail. Phases 7/9/10/11 already
laid every dependency: the reference-asset model (`runs.reference_asset_id`, `startRun`
`$referenceAssetId`), the credit ledger + pre-flight budget gate (`config/usage.php` already
carries an `ai_video` = 700¢ placeholder), mandatory AI-label compliance, and the
distribution-style "normalize a finished video at `final_render`" render path. Phase 12 wires the
last missing piece — the **VideoGenProvider** seam — behind a mock-first, doc-gated adapter, plus
a dedicated **Quick Create** page.

**Locked product decisions (confirmed with user, 2026-06-13):**
1. **Short, brief-faithful chain** — no TREND/IDEA/SCRIPT/VOICE; the prompt is the creative input.
2. **Mock-first + doc-gated flag-off real stub** (exactly like Zernio in Phase 10). No async
   submit/poll/reconcile machinery in V1 — the mock is synchronous (instant ffmpeg clip); the real
   provider throws "doc-gated" until docs+creds exist.
3. **Dedicated `/quick` Quick Create page** (photo + prompt + live estimated credit cost + the
   mandatory AI-label notice).

**Refinement surfaced at approval:** the chain preview showed an `ASSEMBLE` step; the plan **omits
ASSEMBLE** and reuses the distribution path's `final_render` normalize instead. The AI clip is
already a finished video, so it is treated exactly like a ready library video in distribution
(normalized to full-res 9:16 at `final_render`) — this maximizes reuse and avoids a bespoke "silent
draft assembly" path (`AssemblyExecutor` today hard-requires both an `asset_fetch` visual **and** a
`tts` audio track — `src/Media/AssemblyExecutor.php:30-37`).

Chosen chain: **VISUALS(ai) → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE →
PUBLISH** (PUBLISH expands render_review → final_render → publish, per `Nodes::NODE_JOBS`).

## Scope (in)

1. **Migration `0010_ai_video.sql`** — widen the `workflows.template` CHECK to add `'quick_create'`
   (SQLite table rebuild, same pattern as the 0007 approvals rebuild: CREATE `workflows_new` →
   `INSERT…SELECT` preserving ids → DROP → RENAME → recreate `idx_workflows_workspace`). `runs`
   FK → `workflows(id)` resolves after the rename because ids are preserved (verify with
   `PRAGMA foreign_key_check` in the migration test). `runs.entity_type` already allows
   `'quick_create'` (0003 stub); `reference_asset_id` already exists (0005). No new columns — the
   prompt rides in the run's `nodes_json` VISUALS `settings.prompt` snapshot.

2. **VideoGenProvider seam** — `src/Media/VideoGenProvider.php` (interface), `VideoResult.php`,
   `VideoGenProviderException.php`. One method, mirroring `StockProvider`/`TtsProvider`:
   `generateFromImage(string $imagePath, string $prompt, float $durationSeconds, string $targetPath): VideoResult`
   → `{width, height, durationSeconds, costCents, model, meta}`.
   - `MockVideoGenProvider` (default): animates the still photo into a real 9:16 mp4 via ffmpeg
     `zoompan` (Ken-Burns), deterministic; **cost null** (mock = no spend, truthful, like
     `MockStockProvider`/`MockTtsProvider`); a sentinel prompt triggers an error mode for tests.
   - `FalVideoGenProvider` (real, flag-off stub): built only when `VIDEO_MOCK=false` + key; throws
     **"doc-gated"** before any HTTP (mirror `ZernioPublishProvider`; list the required aggregator
     docs in a short `.claude/docs/` note or inline, like `zernio-notes.md`).

3. **`AiVideoExecutor`** (`src/Media/AiVideoExecutor.php`, job type `ai_video`) — resolve the run's
   reference photo to a local path (reuse `AssetFetchExecutor`'s reference lookup +
   `StorageManager::getToLocal`), read the prompt from the run's VISUALS settings, call the provider
   through **`AssetCache::remember`** (content-addressed `hash('ai_video|'.provider.'|'.photo.'|'.prompt.'|'.dur)` →
   cache hit returns **null cost**, truthful), and return
   `JobResult::ready({visual_ref, duration_s, ai_label_required: true, title: <prompt>, cached}, provider, costCents)`.
   Register the clip as a draft render (`RenderRepository`) so `render_review` previews it.

4. **`Nodes.php`** — add `TEMPLATE_QUICK_CREATE = 'quick_create'` (+ to `TEMPLATES`); the node list
   above; `defaultNodes('quick_create')` sets VISUALS `settings = ['source' => 'ai', 'prompt' => '']`;
   `JOB_DEFAULTS['ai_video'] = ['timeout' => 600, 'max_retries' => 1]` (slow + expensive → don't
   retry a paid call blindly). **Source-aware `expand`:** VISUALS maps to `ai_video` when its
   `settings.source === 'ai'`, else `asset_fetch`. Change `expand()` to accept node entries (with
   settings) and pass entries from the three callers — `Engine::startRun`, `Engine::advance`,
   `CostEstimator::estimateRun`; keep bare-id input working for existing tests (polymorphic).

5. **`Engine::startRun` quick_create branch** — when `template === 'quick_create'`: require a ready
   `photo` reference asset (reuse the existing reference validation), set `entity_type = 'quick_create'`,
   snapshot the prompt into the run's nodes_json VISUALS settings. The Phase 11 `PreflightGate`
   already runs here and estimates the `ai_video` job (~$7) → over-budget quick_create runs are
   hard-blocked before any spend (no extra work).

6. **`FinalRenderExecutor`** (`src/Media/FinalRenderExecutor.php`) — generalize the visual + AI-label
   source: `$visualRef = $prior['ai_video']['visual_ref'] ?? $prior['asset_fetch']['visual_ref']`;
   `ai_label_required` likewise. With no `tts` audio it already takes the `assembleDistribution`
   normalize path (line 53-62) — the AI clip is normalized to full-res 9:16.

7. **`ComplianceCheckExecutor::aiLabelCheck`** — also read `$prior['ai_video']['ai_label_required']`
   so AI video always forces the platform label → `pass_with_ai_label` → `posts.ai_label_applied = 1`
   (truthful, via the existing publish path). One-line addition (`src/Compliance/ComplianceCheckExecutor.php:85-98`).

8. **Quick Create UI** — `GET /quick` + `POST /quick` (`src/Controllers/QuickCreateController.php`,
   `templates/quick/index.php`, nav item "Create"). Form: upload a photo (reuse Phase 3 `AssetIngest`
   → stores `kind=photo`) **or** pick a ready photo asset; prompt field; **live estimated credit cost**
   (`CostEstimator::estimateRun('quick_create', …)` → `Format::cents`); the mandatory AI-label notice.
   POST validates (CSRF, prompt non-empty, photo present/owned), ingests the upload if any, then
   `Engine::startRun(quick_create workflow, referenceAssetId = photo, prompt)`. `WorkflowRepository::ensureDefaults`
   seeds the per-workspace Quick Create workflow.

9. **Config + bindings** — `config/media.php`: `image_video` block (`mock`, `api_key`, `model`,
   `endpoint`, default/max clip seconds). `src/bindings/core.php`: `VideoGenProvider` factory
   (mock vs real flag, like `StockProvider`), `AiVideoExecutor`, `register('ai_video', …)`.
   `src/bindings/web.php`: `QuickCreateController`. `config/usage.php` already carries `ai_video`.

10. **Tests** (`tests/run.php`, mock-first, ZERO network — mirror the TTS/stock executor sections):
    `MockVideoGenProvider` produces a real clip + error mode; `ai_video` executor (ai_label_required
    true, cache reuse → null cost, real cost recorded → one `ai_video` usage_event); **quick_create
    e2e** via `makeRig` (start → tick → render_review pause → approve → final_render normalize →
    compliance `pass_with_ai_label` → publish `ai_label_applied=1`); **credit pre-flight blocks an
    over-budget quick_create run**; tenant isolation; `QuickCreateController` page (states + est. cost
    + AI-label notice); migration 0010 applies + `foreign_key_check` clean + template count updated.

## Non-goals (out)

- Multiple AI-video providers, AI **avatars** (HeyGen-class), text-to-video epics, lip-sync/dubbing,
  multi-language (V2 parking lot, phase-plan.md:55,61-62).
- **Stripe / real payments** (V2; grants stay manual via `bin/grant-credits.php`).
- Real **async submit/poll/reconcile** — deferred until a real provider's docs exist (the real
  provider is doc-gated flag-off; the mock is synchronous). Documented as a real-integration follow-up.
- Full-run `VISUALS=ai` (only `quick_create` uses AI visuals in V1).
- A separate per-job credit re-check — the run-level `PreflightGate` already refuses an over-budget
  run before any job/call runs (satisfies "blocked before the call").
- Editing the canonical node names; no general-purpose graph.

## Verification / acceptance criteria

- `0010` applies cleanly; `workflows.template` accepts `quick_create`; `PRAGMA foreign_key_check`
  empty; full suite green (630 + new).
- A quick_create run (mock) produces a real 9:16 mp4 from the photo, **$0 real spend** (mock =
  truthful), `render_review` previews the clip, approval → `final_render` full-res →
  compliance `pass_with_ai_label` → publish with `ai_label_applied=1` to connected accounts.
- Estimated credit cost shown on `/quick` matches `CostEstimator` (~$7); a workspace at/over budget
  is **hard-blocked at startRun** (`BudgetExceededException` → `run.budget_exceeded`,
  `guardrail.preflight_block` event, no run row).
- AI-label is **always** required for AI video and recorded truthfully end-to-end.
- All new queries workspace-scoped (tenant-isolation test). No secrets; cache hit / mock → no spend.
- **Manual smoke** ([Terminal-1] server :8082, [Terminal-2] worker, smoke4): upload a photo on
  `/quick` + prompt → run → approve at queue → published post carries the AI label; `/usage` shows
  $0 (mock); set `VIDEO_MOCK=false` + key → run fails fast "doc-gated" (no spend).
- **compliance-reviewer MANDATORY before close** (AI-label truthfulness + credit-gate); plus
  security-auditor + php-architect + ux-reviewer + qa-reviewer.

## Risks

- **`workflows.template` CHECK rebuild vs the `runs` FK** — mitigate by preserving ids + same table
  name (FK resolves post-rename; 0007 precedent) and asserting `foreign_key_check` in the migration
  test. Fallback if blocked: a carrier 'full' workflow + run-level nodes_json snapshot (no schema
  change).
- **`Nodes::expand` signature change** touches Engine (startRun + advance) + CostEstimator + tests —
  mitigate with a backward-compatible polymorphic input (string id OR `{node,settings}` entry).
- **Mock must emit a valid 9:16 clip with ffmpeg only** (zoompan) so the downstream normalize/publish
  path works — `MockStockProvider` already proves lavfi clips render; verify on the dev ffmpeg build.
- **ASSEMBLE omitted vs the original preview** — transparent rationale above; the AI clip is a
  finished video normalized at `final_render` (distribution parity), avoiding a silent-draft path.
- **Prompt length** is capped at 300 chars by `WorkflowValidator::MAX_STRING_LENGTH` (it rides in
  node settings) — adequate for V1; noted.
- **Real async** (minutes-long generation) is out of V1 — fine because the real provider is doc-gated
  flag-off; the mock is instant. Flagged for the real-integration phase.

## Representative files

- New: `database/migrations/0010_ai_video.sql`; `src/Media/VideoGenProvider.php`,
  `MockVideoGenProvider.php`, `FalVideoGenProvider.php`, `VideoResult.php`, `AiVideoExecutor.php`;
  `src/Controllers/QuickCreateController.php`; `templates/quick/index.php`.
- Changed: `src/Workflow/Nodes.php` (template + ai_video job + source-aware expand),
  `src/Workflow/Engine.php` (quick_create branch + pass node entries to expand),
  `src/Media/FinalRenderExecutor.php` (visual/AI-label from ai_video),
  `src/Compliance/ComplianceCheckExecutor.php` (ai_video AI-label),
  `src/Workflow/WorkflowRepository.php` (seed quick_create default),
  `src/routes.php`, `src/bindings/core.php`, `src/bindings/web.php`, `config/media.php`,
  `templates/layout/app.php` (nav), `public/assets/css/app.css`, `tests/run.php`.

## Gate

Implementation starts **only** on the exact token: **`START PHASE 12`**. I will wait for it.
