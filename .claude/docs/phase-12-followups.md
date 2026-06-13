# Phase 12 — Deferred follow-ups

Phase 12 (Quick Create AI image-to-video) closed with the full suite green and a 5-dimension
review all **GO / CONDITIONAL GO** (compliance mandatory gate **GO / 0 blockers**; security, php,
qa **GO / 0**; ux **CONDITIONAL GO** — both should-fix items were applied before close). The items
below were consciously deferred — none block the phase; most are Phase 13 (hardening) candidates.

## Applied during review (NOT deferred — done in this phase)

- **ux should-fix 1:** hint text rendered ALL-CAPS (nested `.muted` inside `.field__label` inherited
  `text-transform: uppercase`) → switched both hints to the `.field__hint` helper.
- **ux should-fix 2:** an accidental non-photo upload was ingested then rejected, littering the
  Library and silently ignoring a valid pick → `QuickCreateController::resolvePhoto` now deletes the
  just-created row and returns a distinct `quick.photo_not_image` message; the upload field hint now
  says "Leave this empty to use a picked photo below."
- ux nits: `.photo-pick` wrapped in a `<fieldset role="radiogroup">` with an aria-label; keyboard
  `:has(input:focus-visible)` highlight added; stale `$active` layout docblock refreshed.
- security LOW: `.env.example` now documents the Phase 12 `VIDEO_*` / `FAL_API_KEY` block.
- qa LOW: added `QuickCreateController` POST tests (empty/over-long prompt, no photo, video-id
  rejected, happy path → /queue).

## Deferred (non-blocking)

1. **`localSourcePath` duplication (php-architect LOW, → 13).** `AiVideoExecutor::localSourcePath`
   and `AssetFetchExecutor::localSourcePath` are near-identical local/remote staging (differ only in
   default ext). Extract a small `ReferenceStager` collaborator if a third consumer appears. The
   docblock already cites the source.

2. **`Engine::startRun` branch growth (php-architect LOW, → 13).** startRun now has three template
   branches (full / distribution / quick_create) plus the preflight/transaction tail (~145 lines).
   Readable today; if a fourth entry type arrives, extract per-template "resolve entity + reference"
   strategies (a `RunStarter`/strategy split).

3. **`usage_events.units` for ai_video (compliance NIT + Phase 11 follow-up, → 13).** `config/usage.php`
   keeps `ai_video = 700¢` as a flat pre-flight estimate while `unit_types['ai_video'] = 'seconds'`,
   but `AiVideoExecutor` records `units = NULL` (the executor seam does not thread seconds through
   `VideoResult` → `JobResult` yet). Fine for V1 (the estimate is a budget gate, not a charge); when
   the real per-second pricing lands, surface `units = seconds` and a per-second cost.

4. **Executor real-cost passthrough untested (qa LOW, → 13).** `AiVideoExecutor.php` `$cost = cached
   ? null : meta['cost_cents']` is never exercised with a real >0 value (the mock returns null; the
   real provider is doc-gated). The recording side is proven separately via `Engine::finalize` with
   an injected 700¢. Same coverage profile as `MockStockProvider`. A focused test with a stub
   non-null-cost provider would close it.

## Real-integration follow-up (when the AI-video provider is un-gated)

5. **Async submit/poll/reconcile + per-second pricing + clip-length-vs-band.** V1 is mock-first +
   doc-gated flag-off, synchronous only. The real fal.ai-class flow is minutes-long (submit → poll)
   and emits short clips (~5s) that may need looping/extension to hit the 15–45s band. See
   `.claude/docs/ai-video-notes.md` for the seven required doc items before flipping `VIDEO_MOCK=false`.
