# Kuyash — Phase 8 Plan: Cloudflare R2 (Storage Abstraction)

> Token to start: **`START PHASE 8`**. This document is the plan only — no product
> code is written until that exact token is issued. Plan approval confirms direction;
> it does **not** unlock coding on its own.

## Context — why now

Phases 3 & 7 made Kuyash produce real bytes: Library uploads land on local disk
(`storage/assets/{ws}/`) and ffmpeg renders/cache land on local disk
(`storage/{renders,cache,work}/{ws}/`). Two local-disk authorities serve those
bytes by streaming through authed PHP routes (`MediaController`, `RenderController`,
HTTP-range aware). That is correct for a single box but not durable, not
horizontally scalable, and forces all video egress through the PHP app. Phase 8
introduces the `StorageProvider` seam the architecture rule has always required, so
durable storage and serving become a swappable adapter — Local today, Cloudflare R2
when a bucket exists — with a safe, gradual migration path. The integration stays
**mock-first / flag-OFF**: nothing changes for the default local deployment.

## Locked decisions (confirmed 2026-06-12)

1. **R2 adapter = real, flag-OFF default.** Build a working `R2StorageProvider`
   (S3-compatible, AWS SigV4 hand-rolled over the existing `CurlHttpClient`, **no AWS
   SDK / no new Composer dep**), unit-tested against `FakeHttpClient`. Default driver
   stays `local`. Mirrors the Phase 5 (OpenAI) / 6 (YouTube+Google) / 7 (TTS+Pexels)
   "real-but-flagged" pattern.
2. **Serving = presigned redirect.** When an object lives on R2, the serve route
   **302-redirects to a short-TTL presigned GET URL** (the plan's literal "signed
   URLs" goal; offloads video bandwidth). The **tenant check runs BEFORE** the
   redirect is issued. Local-located objects keep today's authed range-streaming
   route unchanged.
3. **Migration = per-object marker + CLI backfill.** A `storage_disk` column per
   durable object lets Local and R2 objects coexist; a `bin/` backfill copies
   local→R2 and flips the marker per object — gradual, resumable, idempotent,
   rollback-safe. Local copies are **never deleted in Phase 8** (delete-after-verify
   is a Phase 13 eviction concern).

## Current state (verified, recap)

- **Built & green:** Phases 0–7 accepted, `b90cb8e` = HEAD, origin/main synced,
  **432 PASS / 0 FAIL**, zero network in suite.
- **Storage authorities today (both local disk):**
  - `Library/AssetStorage` — uploads, `storage/assets/{ws}/{32hex}.{ext}`; write via
    `AssetIngest::store`, serve via `MediaController::serve`.
  - `Media/MediaPaths` — `render`/`cache`/`asset`/`work` roots; tagged refs
    (`store:ws:name`) cross the job seam; ffmpeg I/O + `RenderController` serve.
  - Serve = local stream + HTTP single-range (`MediaController::parseRange`, reused by
    `RenderController`), `private, max-age`, `nosniff`, `default-src 'none'; sandbox`.
- **Carried HARD GATE (overlaps this phase):** Pexels download must stream + hard-cap
  before real stock in prod (today buffers whole clip). Folded in — see Scope §7.

## Scope (precise)

### 1. `StorageProvider` interface (`src/Storage/StorageProvider.php`)
Provider-agnostic, keyed by a logical object key `"{store}/{ws}/{name}"`
(`store ∈ {assets, renders, cache}`; reuses the existing `NAME_RE` traversal guard —
user input never reaches a key). Methods:
- `put(string $key, string $localPath, string $contentType): void` — **streams** the
  finished local file to the durable store (no whole-file buffering).
- `getToLocal(string $key, string $destPath): void` — **streams + byte-caps** a
  durable object down to a local scratch path (for ffmpeg input / serve fallback).
- `delete(string $key): bool`, `exists(string $key): bool`, `size(string $key): ?int`.
- `temporaryUrl(string $key, int $ttl, array $responseHeaders = []): ?string` —
  presigned GET URL; **`null` when unsupported** (Local) → caller streams instead.

### 2. `LocalStorageProvider` (`src/Storage/LocalStorageProvider.php`)
Default. Maps key → local roots (reuses `MediaPaths`/`AssetStorage` path logic +
name validation). `temporaryUrl()` returns `null` → serve route streams exactly as
Phase 7 (byte-identical, zero behavior change for default deployments).

### 3. `R2StorageProvider` (`src/Storage/R2StorageProvider.php`) — real, flag-OFF
- S3-compatible against `https://{account}.r2.cloudflarestorage.com/{bucket}/{key}`,
  region `auto`, service `s3`, over `CurlHttpClient` (the existing HTTP seam).
- `src/Storage/SigV4Signer.php` — hand-rolled AWS SigV4 (HMAC-SHA256 chain) for
  header-signed PUT/GET/DELETE **and** query-signed (presigned) GET URLs.
- `put` streams the request body from a file handle; `getToLocal` streams the
  response to disk with a byte cap; **private bucket only** (no public ACL).
- Enabled only when `STORAGE_DRIVER=r2` + credentials. Real connectivity is a
  **doc/credential-gated enable-time HARD GATE** (smoke against a real bucket before
  flipping in any deployment).

### 4. `StorageManager` (`src/Storage/StorageManager.php`)
`disk(string $name): StorageProvider` + `default(): StorageProvider`. Serving and
backfill resolve the provider **per object** from `row.storage_disk`, so a single box
can serve local + R2 objects simultaneously during migration. Bound in
`src/bindings/{web,worker,core}.php`.

### 5. Seam placement (critical: ffmpeg can't write to R2)
The provider is the **persistence + serving boundary**, not an ffmpeg replacement:
- ffmpeg always reads/writes **local scratch/work** (`MediaPaths` work dir stays).
- **Uploads:** `move_uploaded_file` → local temp → `put()` → record `storage_disk`.
- **Renders:** ffmpeg writes final to local render path → `put()` → record disk
  (`AssemblyEngine`, `FinalRenderExecutor`).
- **Asset input for assembly:** `AssetFetchExecutor` calls `getToLocal()` to stage an
  R2 object into scratch before ffmpeg (no-op passthrough on Local).
- **Cache:** `asset_cache` gets the `storage_disk` column for uniformity but stays a
  **local reuse layer** in Phase 8 (fast ffmpeg input; R2 offload/eviction = Phase 13).

### 6. Serving (`MediaController::serve`, `RenderController::serve`/`poster`)
Load row → **tenant check** → resolve provider from `storage_disk` → if
`temporaryUrl()` non-null → `302` to the presigned URL (content-type +
content-disposition pinned in the presign); else stream local with the existing range
path. Other-workspace object → `404` before any URL is minted.

### 7. Folded HARD GATE — streaming download (clears Pexels gate)
The streaming+capped download utility built for `getToLocal()` is also applied to
`PexelsStockProvider` so the real stock download streams to file with a hard byte cap
instead of buffering. Clears the carried Phase 7 HARD GATE (still flag-OFF).

### 8. Schema, config, env
- `database/migrations/0006_storage_location.sql`: `ALTER TABLE` add
  `storage_disk TEXT NOT NULL DEFAULT 'local'` to `assets`, `renders`, `asset_cache`
  (additive, default literal — SQLite-safe).
- `config/storage.php`: `driver` (`local`|`r2`, default `local`), `r2` block
  (account_id, access_key_id, secret_access_key, bucket, endpoint, `presign_ttl`
  default 300s), all from `Config::env`.
- `.env.example`: `STORAGE_DRIVER=local`, `R2_ACCOUNT_ID=`, `R2_ACCESS_KEY_ID=`,
  `R2_SECRET_ACCESS_KEY=`, `R2_BUCKET=` — **placeholder names only**.

### 9. `bin/migrate-storage.php` (CLI backfill)
Args `--disk=r2 --workspace=<id|all> --dry-run --batch=N`. For each row where
`storage_disk='local'` and the local file exists: `put()` → verify (`exists`+`size`)
→ `UPDATE storage_disk='r2'`. Idempotent (skips already-`r2`), resumable, tenant-
scoped, logs progress, missing-file rows skipped + logged. **Never deletes local.**

## Non-goals

- Public buckets, CDN / custom domains, R2 lifecycle rules, multi-region.
- Render/cache **disk eviction** + delete-after-verify (Phase 13 hardening).
- Enabling real R2 or real Pexels in any deployment (both stay flag-OFF; enable is
  doc/credential-gated).
- New Composer dependencies / AWS SDK (SigV4 is hand-rolled).
- ElevenLabs, AI video, accounts, Zernio — later phases.

## Acceptance criteria / verification

1. **No regression:** default `driver=local` → byte-identical Phase 7 behavior; full
   existing suite stays green (432 PASS) after wiring the seam.
2. **New unit tests (zero network, `FakeHttpClient`):**
   - `SigV4Signer` known-answer vectors (canonical request, signing key, signature).
   - `R2StorageProvider`: `put` issues a signed streamed PUT; `temporaryUrl` returns a
     well-formed presigned GET (params + expiry); `getToLocal` streams + caps;
     `delete` signs DELETE.
   - `LocalStorageProvider`: put/get/delete/exists/size round-trip; `temporaryUrl`=null.
   - `StorageManager::disk('local'|'r2')` resolution.
   - Serving: R2-located object → `302` to presigned URL **with tenant check first**
     (other-workspace → `404`, no URL minted); local-located → `200/206` range
     unchanged.
   - Backfill: seeded local rows → run against a fake R2 provider → markers flip to
     `r2`; **re-run is a no-op**; missing-file rows skipped; `--dry-run` mutates nothing.
   - Pexels streaming download cap (oversized response → aborted, no balloon).
3. **Security:** secret grep clean; `.env.example` placeholders only; presign TTL
   short; private bucket / no public ACL; tenant check precedes redirect; key
   validation traversal-proof; R2 secret never logged or echoed.
4. **Live two-terminal smoke (Local default):** [Terminal-1] server, [Terminal-2]
   worker → full run → draft render → `/render` serve + poster + queue `<video>`
   200/206; library upload + `/media` serve OK. (R2 real path flag-OFF — verified at
   unit level; no live bucket.)
5. **Reviewers (3, parallel):** `security-auditor` (signing/secrets/tenant/presign/
   streaming — recommended-mandatory for this storage phase), `php-architect` (seam
   modularity), `integration-reviewer` (R2 mock-first + payload). Resolve blockers;
   apply cheap should-fixes.
6. **Close-out:** VERDICT block; checkpoint update; auto-push after accept.

## Risks → mitigations

1. **SigV4 correctness without a live bucket** → known-answer vectors + fake
   transport; real path flag-OFF; first real-bucket connect documented as an
   enable-time HARD GATE (smoke before `STORAGE_DRIVER=r2` in prod).
2. **Seam mis-placement (ffmpeg can't write R2)** → boundary sits after ffmpeg
   (local scratch always); `put()` post-render, `getToLocal()` pre-render; covered by
   smoke.
3. **Presigned redirect bypasses app CSP/nosniff/Range** → short TTL, private bucket,
   pin response content-type + disposition in the presign, tenant check before
   minting; R2 serves correct content-type and native ranges.
4. **Migration dual-state / data loss** → idempotent resumable per-object backfill;
   verify exists+size before flipping marker; never delete local in Phase 8; rollback
   = flip marker back (local copy intact).
5. **Large-object memory** → streaming `put`/`getToLocal` + byte cap; also retrofits
   Pexels download (clears carried HARD GATE).
6. **No new deps** → SigV4 via `hash_hmac`/`openssl_*`; `CurlHttpClient` reused
   (pure-php rule honored).
7. **SQLite `ALTER ADD COLUMN`** → additive with default literal; sequential `.sql`
   migrator already supports it.

## Files

**New:** `src/Storage/{StorageProvider,LocalStorageProvider,R2StorageProvider,StorageManager,SigV4Signer,StorageException}.php`;
`config/storage.php`; `database/migrations/0006_storage_location.sql`;
`bin/migrate-storage.php`; tests under `tests/` (storage providers, sigv4, serving
redirect, backfill, pexels stream).

**Modified:** `src/bindings/{web,worker,core}.php` (bind StorageManager/providers);
`src/Library/{AssetIngest,AssetStorage,AssetRepository}.php` (persist via provider +
`storage_disk`); `src/Controllers/{MediaController,RenderController}.php` (per-object
provider → redirect/stream); `src/Media/{AssemblyEngine,FinalRenderExecutor,
AssetFetchExecutor,PexelsStockProvider,RenderRepository}.php`; `.env.example`;
`config/media.php` (only if a root needs threading through the provider).

## Approval

Implementation begins **only** on the exact token **`START PHASE 8`**. Plan approval
confirms direction; it does **not** unlock coding on its own.
