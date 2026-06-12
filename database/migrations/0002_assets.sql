-- Phase 3: Content Library asset metadata.
-- Conventions per 0001: TEXT ISO-8601 UTC timestamps from PHP, no BEGIN/COMMIT,
-- forward-only. CHECK enums are deliberately generous (stock/ai arrive in later
-- phases) because changing a CHECK in SQLite means a full table rebuild.
-- ai_label_required is NOT a column — it derives from type = 'ai'.

CREATE TABLE assets (
    id                INTEGER PRIMARY KEY,
    workspace_id      INTEGER NOT NULL REFERENCES workspaces (id),
    kind              TEXT NOT NULL CHECK (kind IN ('video', 'photo')),
    type              TEXT NOT NULL CHECK (type IN ('own', 'face', 'stock', 'ai')),
    title             TEXT NOT NULL,
    original_filename TEXT NOT NULL,           -- display only, never path-bearing
    stored_name       TEXT NOT NULL,           -- {32-hex}.{ext}, server-generated
    mime              TEXT NOT NULL,
    size_bytes        INTEGER NOT NULL,
    sha256            TEXT NOT NULL,           -- Phase 7 content-addressed cache groundwork
    duration_s        REAL,                    -- NULL = unknown (probe fallback)
    width             INTEGER,                 -- display dims, rotation-corrected
    height            INTEGER,
    aspect            TEXT,                    -- '9:16'|'16:9'|'1:1'|'4:5'|'other'|NULL
    tags              TEXT NOT NULL DEFAULT '[]',  -- JSON array of strings
    status            TEXT NOT NULL DEFAULT 'ready' CHECK (status IN ('processing', 'ready')),
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL,
    UNIQUE (workspace_id, stored_name)
);

CREATE INDEX idx_assets_workspace ON assets (workspace_id, created_at DESC);
