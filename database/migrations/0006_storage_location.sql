-- Phase 8: Cloudflare R2 (storage abstraction). Per-object durable-storage
-- marker so Local and R2 objects coexist during a gradual, resumable migration.
-- Conventions per 0001/0005: ADD COLUMN with a default literal is the only safe
-- in-place change in SQLite; forward-only; no BEGIN/COMMIT.
--
-- 'local' (default) = served by the authed range-stream route, as today.
-- 'r2'              = served by a short-TTL presigned redirect.
-- The backfill (bin/migrate-storage.php) flips this per object after verifying
-- the copy on the target; the local copy is never deleted in Phase 8.

ALTER TABLE assets ADD COLUMN storage_disk TEXT NOT NULL DEFAULT 'local';
ALTER TABLE renders ADD COLUMN storage_disk TEXT NOT NULL DEFAULT 'local';

-- asset_cache gets the column for uniformity, but cache stays a LOCAL reuse
-- layer in Phase 8 (fast ffmpeg input); R2 offload/eviction is Phase 13.
ALTER TABLE asset_cache ADD COLUMN storage_disk TEXT NOT NULL DEFAULT 'local';
