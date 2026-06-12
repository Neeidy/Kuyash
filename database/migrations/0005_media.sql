-- Phase 7: Media Production — render artifacts, a content-addressed intermediate
-- cache, the workspace default avatar pointer, and the per-run reference asset.
-- Conventions per 0001/0003: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT, forward-only. ADD COLUMN is the only safe in-place change in
-- SQLite (a new CHECK or FK on an existing table would need a full rebuild).

-- Workspace default avatar = a pre-selected reference asset (reference-asset
-- model, ADR-012). Nullable; per-ACCOUNT defaults arrive with accounts (Phase 10).
ALTER TABLE workspaces ADD COLUMN avatar_asset_id INTEGER REFERENCES assets (id);

-- Per-run reference subject pick ("make this one with my cat"). Nullable;
-- resolution order at asset_fetch: this → workspace avatar (face format) → stock.
ALTER TABLE runs ADD COLUMN reference_asset_id INTEGER REFERENCES assets (id);

-- Render artifacts: a draft (low-res, for approval) and, after approval, a final
-- (full-res). Files live under storage/renders/{workspace_id}/{32-hex}.mp4 —
-- server-generated names, never user input (security rule). Served only through
-- the authenticated, tenant-scoped /render route.
CREATE TABLE renders (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    run_id       INTEGER NOT NULL REFERENCES runs (id),
    job_id       INTEGER REFERENCES jobs (id),
    kind         TEXT NOT NULL CHECK (kind IN ('draft', 'final')),
    stored_name  TEXT NOT NULL,                 -- {32-hex}.mp4
    poster_name  TEXT,                          -- {32-hex}.jpg first-frame thumbnail
    mime         TEXT NOT NULL DEFAULT 'video/mp4',
    width        INTEGER,
    height       INTEGER,
    duration_s   REAL,
    size_bytes   INTEGER,
    created_at   TEXT NOT NULL,
    UNIQUE (workspace_id, stored_name)
);

CREATE INDEX idx_renders_run ON renders (run_id, id DESC);
CREATE INDEX idx_renders_workspace ON renders (workspace_id, id DESC);

-- Content-addressed intermediate cache (TTS audio, stock clips). cache_key is a
-- sha256 of the inputs; a hit reuses the file and is recorded as a saving. Files
-- live under storage/cache/{workspace_id}/{32-hex}.{ext}. Workspace-scoped.
CREATE TABLE asset_cache (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    cache_key    TEXT NOT NULL,                 -- sha256(kind|inputs)
    kind         TEXT NOT NULL,                 -- 'tts' | 'stock'
    stored_name  TEXT NOT NULL,                 -- {32-hex}.{ext}
    meta_json    TEXT NOT NULL DEFAULT '{}',    -- duration, provider, dims, etc.
    hits         INTEGER NOT NULL DEFAULT 0,    -- reuse counter (saving visibility)
    created_at   TEXT NOT NULL,
    UNIQUE (workspace_id, cache_key)
);

CREATE INDEX idx_asset_cache_workspace ON asset_cache (workspace_id, id DESC);
