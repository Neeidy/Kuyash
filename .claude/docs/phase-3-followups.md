# Phase 3 Follow-ups (deferred by design/review, NOT forgotten)

Status: final at phase close. All reviewer SHOULD-FIX items (security ×3,
php-architect ×4, ux ×7) were APPLIED during the phase with regression tests;
this file holds only the deliberately deferred tier.

## Deferred by the approved plan (non-goals)
- **Video thumbnails / poster frames (Phase 7):** cards show a placeholder tile
  until ffmpeg exists. Photos use the full image via /media/{id} (lazy-loaded);
  a real thumbnail pipeline (GD downscale or ffmpeg poster) belongs to Phase 7.
- **`used_in` tracking (Phase 4+):** no renders exist yet; the detail page shows
  a note instead of the demo's "used in" section.
- **webm/mkv:** different container family; allowlist stays mp4+mov (phone output).
- **Chunked/resumable upload, JSON API, FTS search, asset rename/retag,
  soft delete/undo:** all out of V1 Phase 3 scope (no-overbuild).
- **Server-page i18n (TR):** pages ship EN; ALL user-facing flash/validation
  strings are message KEYS resolved through `LibraryController::MESSAGES` —
  the TR pass later replaces one map with a dictionary, mechanically.
- **Orphan-file sweep (Phase 4):** delete unlinks after the DB commit; a failed
  unlink leaves a harmless orphan in the private dir — the Phase 4 worker can
  sweep `storage/assets/` against the assets table.
- **CONTENT_LENGTH pre-check:** a >210MB POST still hits the blank-$_POST CSRF
  403 (known-safe, documented); a friendlier 413 needs a front-controller check.
- **prod php.ini upload limits (Phase 13):** dev uses
  `-d upload_max_filesize=200M -d post_max_size=210M`; Caddy/FPM needs the same.

## Probe limitations (accepted, fail-soft)
- Fragmented MP4 (moof/mvex) reports mvhd duration 0 → metadata unknown (null);
  phone cameras do not produce fMP4. Exotic editor exports may also fall back to
  unknown — the upload is never blocked, the UI shows a subdued "unknown".
- Non-canonical rotation matrices (anything but 0/90/180/270) use unrotated
  dimensions (best effort).

## Reviewer findings deferred to later phases

### Security (audit nice-to-haves)
- **Per-workspace storage quota (Phase 11):** no total-bytes cap → an
  authenticated user can fill the disk. Wire into the usage/credits ledger.
- **Membership revalidation (V2):** session workspace_id is validated only at
  login; revoking a membership does not kill live sessions. Irrelevant
  single-user; add per-request revalidation when multi-user UI lands.
- **`http_build_query` echo strictness:** values are URL-encoded (no breakout);
  wrap in View::e() during the next template pass for belt-and-braces.
- **Tag dedupe order** — FIXED during phase (truncate before dedupe).
- HEAD-streaming, CSP-sandbox on /media, .env.example docs — APPLIED at close.

### Architecture (php-architect nice-to-haves)
- **bootstrap split trigger:** split `src/bootstrap.php` into core/web(/worker)
  binding files when `bin/worker.php` arrives (Phase 4) — not before.
- **Tags search precision (Phase 4+):** upgrade `tags LIKE` to
  `EXISTS (SELECT 1 FROM json_each(assets.tags) WHERE value LIKE ?)` when tags
  become workflow-selectable.
- **`AssetRepository::listFor()` has no LIMIT:** add pagination when the
  library outgrows one screenful (Phase 4 UI pass).
- **MediaProbe internals:** if ever touched post-Phase-7 (ffprobe likely
  demotes it to fallback), flatten the nested closures with a bounded
  `children()` helper and make the box counter per-call.
- **`finfo_open()` false guard** in AssetValidator (theoretical TypeError →
  central 500; fail-closed but inelegant).
- **MESSAGES → shared dictionary class** when a second flash-consuming
  controller appears (Phase 4); the TR i18n pass then replaces exactly one map.
- **Doc note:** rare mp4 variants that finfo sniffs as anything but video/mp4
  are rejected with `content_mismatch` (fail-closed, accepted).
- `WorkspaceContext::currentName()` queries per render — memoize only if
  profiling ever cares.

### UX (ux-reviewer nice-to-haves)
- No-match state could use a search icon (reuses library icon now).
- "Back to library" drops active q/type filters — carry them through.
- Drawer: `aria-controls="sidebar"` + move focus into the drawer on open.
- No-JS delete has no confirmation step (POST+CSRF still guards) — accepted.
- Status chip intentionally omitted from cards while status is constant
  'ready'; reintroduce when Phase 7 adds processing/failed states.
