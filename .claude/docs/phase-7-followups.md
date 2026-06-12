# Phase 7 Follow-ups (deferred by design/review, NOT forgotten)

Status: final at phase close. Three reviewers — security-auditor **GO**,
integration-reviewer **0 blockers**, php-architect **GO** — **0 blockers**. Cheap
should-fixes were APPLIED during the phase; the rest are tracked here. Suite:
**432 PASS / 0 FAIL** (real ffmpeg renders in-suite, ~8s), zero network in tests
(FakeHttpClient + local ffmpeg).

## Applied during the phase (from reviews)
- **Read path has no fs side-effect** (security S2): `RenderController` serves via
  `MediaPaths::resolve()` (read, no mkdir) instead of `pathFor()` (write, mkdir).
- **AssetCache race-catch narrowed** (php S1): only a UNIQUE(cache_key) violation
  is treated as a benign concurrent-producer race; any other error rethrows.
  `json_encode(meta)` moved before the INSERT so a non-encodable meta surfaces.
- **Best-effort download size cap** (security/php S2): `CurlHttpClient` sets
  `CURLOPT_MAXFILESIZE` (128 MiB, Content-Length based) so an oversized response
  can't balloon worker memory.
- **OpenAI TTS cost relabelled** (integration S1): documented as an APPROXIMATION —
  `gpt-4o-mini-tts` bills per token, not per character; Phase 11 reconciles.
- **Ffmpeg kill docblock aligned** (php N1 / security N3); **MediaPaths NAME_RE
  traversal-guard** made self-documenting (php N2); **final_render branch
  invariant** documented (php S3).

## Accepted tradeoffs / HARD GATES (explicit — do not "discover" later)
- **HARD GATE — Pexels download must stream + hard-cap before `STOCK_MOCK=false`
  in production** (security S1 / php S2). Today the real Pexels path buffers the
  whole clip into memory before `file_put_contents`; the 128 MiB
  `CURLOPT_MAXFILESIZE` is a best-effort guard (needs a Content-Length). Before
  enabling real stock in any deployment: stream the download to the target file
  with a write-callback byte cap. DORMANT now (mock is default).
- **HARD GATE — move the live trend fetch to the worker before enabling a real
  TrendProvider in production** (carried from Phase 6). Unchanged by Phase 7.
- **Burned-in captions need a libass/libfreetype ffmpeg build.** The dev box build
  lacks the `subtitles`/`drawtext` filters, so Phase 7 emits an SRT sidecar +
  muxes a soft `mov_text` track; burn-in is gated behind `media.burn_subtitles`
  (default off). Flip it only on a capable build; until then short-form on-screen
  captions are NOT burned into the pixels.

## Deferred to later phases (from reviews + plan)
### Media / rendering
- **Whisper word-level subtitle alignment** (plan): Phase 7 ships script-timed SRT
  (proportional cue timing). Real alignment needs the TTS audio and a Whisper step.
- **Render / cache disk eviction**: `renders` and `cache` files persist (temp work
  dirs ARE cleaned). An LRU/age eviction + R2 offload lands with Phase 8 (R2) /
  Phase 13 (hardening). No web path triggers a synchronous render (all rendering
  is on the worker) — confirmed by review.
- **final_render explicit mode signal** (php S3): branch is inferred from TTS-audio
  presence (safe today — distribution has no VOICE step). If a full run ever drops
  VOICE, add an explicit narrated/distribution flag.
- **ElevenLabs premium TTS** (V2); **music FILES** (MUSIC NOTE stays
  suggestion-only); **multi-clip / b-roll sequencing** (Phase 7 uses one primary
  visual looped to the narration).

### Integration / cost
- **OpenAI TTS per-token cost** (integration S1): switch the cost basis to tokens
  for `gpt-4o-mini-tts` (or reconcile against real usage) when the Phase 11 ledger
  lands. The per-character estimate is advisory only and the path is flag-OFF.
- **Pexels provenance** (integration N2): capture `video.id` / author into
  `StockResult.meta` for attribution/audit (cosmetic today).
- **Pexels download host allowlist** (integration N3): defense-in-depth — restrict
  the clip-download host to Pexels CDNs (the link comes from a trusted API; TLS
  pin + no-redirect already apply).
- **401/403 non-retryable classification** (carried from Phase 5): a bad TTS/stock
  key still retries then dead-letters; fold the non-retryable signal into
  Phase 11/13 hardening.

### Cosmetic
- `asset_cache.kind = 'stock'` covers both real stock clips AND generated
  still-clips (distinct sha256 keys, no collision); a separate `still` kind would
  read cleaner for future eviction (security N4).
- Ffmpeg timeout could process-group-kill for defense-in-depth (no shell today, so
  ffmpeg is the direct child — theoretical) (php N1).

## Reference-asset model (ADR-012) — Phase 7 scope delivered
- Workspace default avatar pointer (`workspaces.avatar_asset_id`) + per-run
  reference pick (`runs.reference_asset_id`) + VISUALS resolution order (per-run
  reference → workspace avatar on face format → stock). Photo references →
  ffmpeg still-clip; video references → referenced as-is. **NO AI generation**
  (that is Quick Create, Phase 12). Per-ACCOUNT avatar defaults arrive with the
  accounts table in Phase 10.
