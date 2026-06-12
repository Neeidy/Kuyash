-- Phase 6: Trend Radar — cached trend rows, per-workspace niche config, and a
-- daily API quota counter for the first rate-limited primaries (YouTube/Google).
-- Conventions per 0001/0003: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT, forward-only. CHECK enums stay deliberately generous (changing
-- a CHECK in SQLite means a full table rebuild).

-- Cached trend signals. A "batch" for a (workspace, niche, region) shares one
-- fetched_at; refreshing replaces the batch (DELETE + INSERT in one short tx).
-- Freshness is derived by comparing the batch's fetched_at against the TTL —
-- the cache layer never serves stale data as fresh without saying so.
CREATE TABLE trends (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    niche        TEXT NOT NULL,
    region       TEXT NOT NULL,
    source       TEXT NOT NULL,                       -- 'mock' | 'youtube' | 'google_trends'
    topic        TEXT NOT NULL,
    score        INTEGER NOT NULL DEFAULT 0,          -- 0..100 relative interest
    format       TEXT NOT NULL DEFAULT 'faceless'
                 CHECK (format IN ('face', 'faceless')),
    rank         INTEGER NOT NULL DEFAULT 0,          -- 0-based position within the batch
    raw_json     TEXT NOT NULL DEFAULT '{}',          -- small vendor metadata (sanitized)
    fetched_at   TEXT NOT NULL,
    created_at   TEXT NOT NULL
);

-- covers both the MAX(fetched_at) batch probe and the rank-ordered batch read
CREATE INDEX idx_trends_lookup ON trends (workspace_id, niche, region, fetched_at DESC, rank);
CREATE INDEX idx_trends_workspace ON trends (workspace_id, id DESC);

-- Per-workspace niche/region used to scope fetches. One row per workspace;
-- absent row = config defaults (general / US). Editing it is a small POST.
CREATE TABLE trend_config (
    workspace_id INTEGER PRIMARY KEY REFERENCES workspaces (id),
    niche        TEXT NOT NULL DEFAULT 'general',
    region       TEXT NOT NULL DEFAULT 'US',
    updated_at   TEXT NOT NULL
);

-- Daily quota accounting for rate-limited providers (Phase 5 follow-up). Phase 6
-- only RECORDS units per provider per UTC day; budget caps/enforcement are
-- Phase 11. Mock work is never recorded (mock spend is never real spend).
CREATE TABLE api_quota_usage (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    provider     TEXT NOT NULL,
    usage_date   TEXT NOT NULL,                       -- 'YYYY-MM-DD' (UTC)
    units        INTEGER NOT NULL DEFAULT 0,
    updated_at   TEXT NOT NULL,
    UNIQUE (workspace_id, provider, usage_date)
);

CREATE INDEX idx_quota_workspace ON api_quota_usage (workspace_id, usage_date DESC);
