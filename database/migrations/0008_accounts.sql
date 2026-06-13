-- Phase 10: Zernio Publishing — social accounts, per-target posts, raw webhook
-- inbox, and a per-run publish schedule pointer.
-- Conventions per 0001/0003/0005: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only. ADD COLUMN is the
-- only safe in-place change in SQLite.
--
-- Tenancy: accounts + posts are tenant tables (workspace_id, filtered in every
-- query). webhook_events is a RAW pre-resolution inbox (like login_attempts):
-- the workspace/account are resolved during processing, from the matched post —
-- so it carries no workspace_id (a forged inbound id can never assert a tenant).
--
-- Tokens/passwords are NEVER stored: Zernio owns OAuth tokens; Kuyash keeps an
-- account reference + health only (security rule).

-- 1. Social accounts (publish targets) -------------------------------------

CREATE TABLE accounts (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    platform     TEXT NOT NULL CHECK (platform IN ('instagram', 'tiktok', 'youtube')),
    handle       TEXT NOT NULL,
    -- Zernio account id; NULL until the (mock) connect flow completes.
    external_ref TEXT,
    status       TEXT NOT NULL DEFAULT 'connected'
                 CHECK (status IN ('connected', 'reauth_needed', 'disconnected')),
    health       TEXT NOT NULL DEFAULT 'unknown'
                 CHECK (health IN ('ok', 'degraded', 'unknown')),
    -- per-account default reference subject (wires the Phase 7 reference-asset
    -- seam: per-run pick → per-account default → workspace avatar → stock).
    default_reference_asset_id INTEGER REFERENCES assets (id),
    connected_at TEXT,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

CREATE INDEX idx_accounts_workspace ON accounts (workspace_id, id DESC);

-- 2. Posts (one publish target = one (run, account)) -----------------------
-- Per-account granularity is what drives caps, next-up, reconciliation and
-- idempotency. ai_label_applied is truthful: 1 ONLY when compliance required it.

CREATE TABLE posts (
    id               INTEGER PRIMARY KEY,
    workspace_id     INTEGER NOT NULL REFERENCES workspaces (id),
    run_id           INTEGER NOT NULL REFERENCES runs (id),
    job_id           INTEGER REFERENCES jobs (id),
    account_id       INTEGER NOT NULL REFERENCES accounts (id),
    platform         TEXT NOT NULL CHECK (platform IN ('instagram', 'tiktok', 'youtube')),
    status           TEXT NOT NULL DEFAULT 'scheduled'
                     CHECK (status IN ('scheduled', 'publishing', 'published', 'failed', 'cancelled')),
    external_post_id TEXT,
    external_url     TEXT,
    ai_label_applied INTEGER NOT NULL DEFAULT 0 CHECK (ai_label_applied IN (0, 1)),
    scheduled_for    TEXT,                 -- NULL = immediate
    -- "run:{run}:acct:{acct}:publish" — the per-target idempotency guarantee:
    -- a re-enqueued publish job re-attempts only NON-terminal targets, never
    -- double-posts a published one. Globally unique (the UNIQUE index below).
    idempotency_key  TEXT NOT NULL,
    error_message    TEXT,
    created_at       TEXT NOT NULL,
    posted_at        TEXT,
    updated_at       TEXT NOT NULL
);

CREATE UNIQUE INDEX uq_posts_idempotency ON posts (idempotency_key);
CREATE INDEX idx_posts_workspace ON posts (workspace_id, id DESC);
CREATE INDEX idx_posts_run ON posts (run_id, id DESC);
-- per-account daily-cap counter: COUNT published rows for an account in a day
CREATE INDEX idx_posts_account_day ON posts (account_id, status, posted_at);
-- reconciliation sweep: in-flight rows ordered by staleness
CREATE INDEX idx_posts_reconcile ON posts (status, updated_at);
-- webhook processing: match a delivery to its post by the provider's id
CREATE INDEX idx_posts_external ON posts (external_post_id) WHERE external_post_id IS NOT NULL;

-- 3. Webhook inbox (raw-first, idempotent) ---------------------------------
-- Persist RAW before any processing; processing is a separate, replayable step
-- (reset processed_at to re-run). A duplicate delivery hits the UNIQUE
-- external_event_id and is a no-op — at-most-once side effects.

CREATE TABLE webhook_events (
    id                INTEGER PRIMARY KEY,
    source            TEXT NOT NULL DEFAULT 'zernio',
    external_event_id TEXT NOT NULL UNIQUE,
    payload_json      TEXT NOT NULL,
    signature         TEXT,
    received_at       TEXT NOT NULL,
    processed_at      TEXT,
    process_error     TEXT
);

CREATE INDEX idx_webhook_events_unprocessed ON webhook_events (processed_at, id);

-- 4. Per-run publish schedule ----------------------------------------------
-- Set at the render_review approval ("schedule for"): the publish job inserted
-- after final_render takes its run_after from this, so a future publish defers
-- on the existing queue (run_after gate) and fires when due. NULL = publish now.
ALTER TABLE runs ADD COLUMN publish_after TEXT;
