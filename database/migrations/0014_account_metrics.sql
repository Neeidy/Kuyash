-- Phase 22: Panel + Real Data — audience & engagement snapshots.
-- Conventions per 0008/0009: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only, ADD COLUMN only.
--
-- WHY: the dashboard's connected-account cards had NO metric source at all, so
-- every figure was a deterministic sample. The provider does expose a real
-- audience number (followersCount, verified live), while per-post engagement is
-- reported through an analytics endpoint that returned an EMPTY post list at
-- integration time. The storage below deliberately covers BOTH, so the day the
-- provider starts reporting posts the existing chore lights up with no schema
-- change:
--   • accounts.followers_count — the hot value the UI reads on every render.
--   • account_metrics          — one immutable row per account per UTC day.
--
-- TRUTHFULNESS (core compliance value, mirrors usage_events): every metric
-- column is NULLABLE and NULL means "the provider did not report it". Nothing
-- here is ever defaulted to 0 to look populated — a screen that shows a number
-- from this table is showing a number the provider actually returned. `raw_json`
-- keeps the provider payload verbatim so an unmapped-but-present metric is
-- recoverable instead of silently lost.

-- 1. Hot audience value on the account row ---------------------------------

ALTER TABLE accounts ADD COLUMN followers_count INTEGER;
ALTER TABLE accounts ADD COLUMN followers_synced_at TEXT;

-- 2. Daily snapshot (append-only time series) -------------------------------

CREATE TABLE account_metrics (
    id            INTEGER PRIMARY KEY,
    workspace_id  INTEGER NOT NULL REFERENCES workspaces (id),
    account_id    INTEGER NOT NULL REFERENCES accounts (id),
    -- 'YYYY-MM-DD' UTC, like api_quota_usage.usage_date
    snapshot_date TEXT NOT NULL,
    -- NULL = not reported by the provider (never a zero stand-in)
    followers     INTEGER,
    -- provider-level flag: does this workspace's plan expose analytics at all
    has_analytics INTEGER NOT NULL DEFAULT 0 CHECK (has_analytics IN (0, 1)),
    -- number of per-post rows the provider returned (0 = none yet, honest empty)
    post_count    INTEGER NOT NULL DEFAULT 0 CHECK (post_count >= 0),
    -- window aggregates across those posts; NULL when the metric is unreported
    views         INTEGER,
    likes         INTEGER,
    comments      INTEGER,
    shares        INTEGER,
    -- per-post rows as returned (vendor-neutral shape), '[]' when none
    posts_json    TEXT NOT NULL DEFAULT '[]',
    -- provider payload verbatim: lets an unmapped metric be recovered later
    raw_json      TEXT,
    provider      TEXT NOT NULL,
    created_at    TEXT NOT NULL
);

-- at most one snapshot per account per UTC day → the chore is idempotent and
-- can run on the ordinary 5-minute worker cadence (INSERT OR IGNORE)
CREATE UNIQUE INDEX uq_account_metrics_day ON account_metrics (workspace_id, account_id, snapshot_date);
-- workspace-scoped time-series read (newest first)
CREATE INDEX idx_account_metrics_workspace ON account_metrics (workspace_id, snapshot_date DESC);
