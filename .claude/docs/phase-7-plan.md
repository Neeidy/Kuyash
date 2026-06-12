# Phase 7 Plan — Media Production (Stock Mode) + Reference-Asset Pivot (APPROVED, awaiting `START PHASE 7`)

> Plan approved in Plan Mode (2026-06-12). Step A (doc updates to the reference-asset
> model) was executed immediately after approval — see ADR-012. The BUILD (Step B) is
> NOT unlocked — implementation starts ONLY on the exact token `START PHASE 7`.
> User decisions locked: REAL ffmpeg by default (local binary; mock providers feed it
> real inputs); shooting-brief/awaiting_recording REMOVED → reference-asset model.

## Context

Phase 6 accepted (`393d666`, pushed, 386 tests). Product pivot (ADR-012): VISUALS can
take a reference subject — a library reference asset (avatar default / any photo or
clip / per-run pick). No human recording step. Phase boundaries: P7 = resolution only
(NO AI generation); P12 = photo/reference + prompt → AI image-to-video; V2 = avatar
generation. "account.avatar_asset_id" maps to the WORKSPACE level in Phase 7 (no
accounts table until Phase 10).

## Step B — build scope (token-gated)

1. **TtsProvider seam** (`src/Media/`): interface + `TtsResult` VO. `MockTtsProvider`
   (DEFAULT) writes a REAL silent/tone WAV (pure-PHP writer, duration from script word
   count) — offline, deterministic. `OpenAiTtsProvider` (real `audio/speech`, flag-OFF
   `TTS_MOCK=true`, reuses `HttpClient`). Serves the `tts` job type.
2. **Subtitles**: `SubtitleBuilder` — deterministic SRT from script text chunked across
   the TTS duration. Whisper alignment = follow-up (needs real TTS audio to matter).
3. **StockProvider seam**: `MockStockProvider` (DEFAULT — ffmpeg lavfi color/gradient
   9:16 clips, cached) + `PexelsStockProvider` (real, flag-OFF, key, GET via
   HttpClient; quota recorded in existing `api_quota_usage`). Serves `asset_fetch`
   (stock path).
4. **Reference-asset slice**: migration `0005` — `workspaces.avatar_asset_id`
   (nullable ADD COLUMN) + `runs.reference_asset_id` (nullable) + `asset_cache` table.
   Run-trigger UI: optional reference picker (ready library video/photo,
   tenant-scoped). `asset_fetch` resolution order: per-run reference → workspace
   avatar (face format) → stock. Photo → ffmpeg looped still-clip; video → trimmed
   segment.
5. **AssemblyEngine (REAL ffmpeg)**: TTS WAV + visual clips + SRT burn-in → 9:16 MP4.
   Safe execution: `proc_open` arg-array (never shell strings), validated paths,
   timeout, temp dir + cleanup. **Draft-first**: ASSEMBLE renders low-res draft
   (540×960, fast preset); `render_review` card shows it; new `final_render` job type
   (PUBLISH node expands `render_review → final_render → publish`) renders 1080×1920
   ONLY after approval. Artifacts in `storage/renders/` (private), served via an
   authenticated route (MediaController pattern).
6. **Content-addressed asset cache**: `asset_cache` keyed by sha256(inputs) for TTS
   audio + stock clips; reuse hits, record savings; workspace-scoped.
7. **Dashboard cockpit first pass** (ui-style-guide spec): KPI strip + active runs
   panel + awaiting-approval strip with render thumbnails (ffmpeg frame grab) — REAL
   data; countdown/deltas stay placeholders until Phase 10.
8. `FormatRecommender` docblock update (`face` = reference-subject format).

## Non-goals

AI video generation (P12) · ElevenLabs (V2) · R2 (P8 — local storage now) · real
Zernio (P10) · music FILES (MUSIC NOTE stays suggestion-only) · avatar generation
(V2) · accounts table (P10) · Whisper alignment (follow-up) ·
shooting-brief/awaiting_recording (REMOVED — not deferred) · moving web trend-fetch to
worker (gated on enabling a real TrendProvider, not P7).

## Acceptance / verification

- Suite green (386 + new: WAV validity, SRT timing, ffmpeg arg-safety/injection,
  cache hit/reuse, reference resolution + tenant isolation, draft→final flow, binding
  selection for TTS/Pexels). **Zero network in tests** (ffmpeg = local binary; tiny
  1–2s render fixtures only).
- Defaults: `TTS_MOCK=true`, stock mock default; real paths flag-OFF + key, fail-safe
  to mock (binding tests like P5/P6).
- Live smoke (two terminals, [Terminal-1] server / [Terminal-2] worker): faceless
  stock run → draft preview visible in render_review card → approve → final render →
  completed; reference run (photo + video reference) resolves correctly; cockpit shows
  KPI/active/awaiting with thumbnails.
- No secrets; tenant isolation on every new query; temp dirs cleaned after assembly.
- Reviewers before close: security-auditor (ffmpeg execution, path safety) +
  integration-reviewer (TTS/Pexels payload fidelity, mock-first) + php-architect.
  Then security gate → commit → auto-push.

## Risks

ffmpeg arg injection / path traversal (arg-array + validated paths + tests) · worker
render time (900s timeout + watchdog exist) · disk growth (temp cleanup + cache;
eviction = follow-up) · cockpit scope creep (keep to the 3 specified strips) ·
reference-photo motion quality (simple loop/zoompan acceptable for V1) · chain change
(`final_render` job type) touches Nodes/engine — covered by e2e tests.

## Approval token

Build starts ONLY when the user writes: **`START PHASE 7`**
