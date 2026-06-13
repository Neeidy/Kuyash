-- Phase 11: Usage, Costs & Credit Ledger.
-- Conventions per 0003/0007/0008: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only.
--
-- Two append-only tables:
--  1. usage_events — one row per job that incurred REAL spend. This becomes the
--     single source of truth for month-to-date spend (AutoApprovalGate moves off
--     SUM(jobs.cost_cents) onto SUM(usage_events.cost_cents); jobs.cost_cents
--     stays as the per-job display rollup, no behaviour change there).
--     TRUTHFULNESS (core compliance value): a row exists ONLY for real spend —
--     mock providers and cache hits report a null cost and write NOTHING here.
--     A UNIQUE guard on job_id makes recording idempotent across worker retries.
--  2. credit_transactions — money-denominated ledger (grant | spend | adjust),
--     amount_cents signed so balance = SUM(amount_cents). "Credits" is a friendly
--     display layer over real cents — NO prepaid economy, no auto-allowance
--     refill, no Stripe in V1. Grants/top-ups are manual (seed / bin/grant-
--     credits.php). The enforced control is the month-to-date budget cap, not a
--     depleting credit pool.
--
-- model/units are nullable: V1 captures provider + category + cost truthfully;
-- surfacing token/char COUNTS through the executor seam is a Phase 13 follow-up.

-- 1. Usage events (append-only spend ledger) -------------------------------

CREATE TABLE usage_events (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    run_id       INTEGER REFERENCES runs (id),
    job_id       INTEGER NOT NULL REFERENCES jobs (id),
    provider     TEXT NOT NULL,
    category     TEXT NOT NULL CHECK (category IN ('ai_text', 'tts', 'stock', 'publish', 'ai_video')),
    model        TEXT,
    units        INTEGER,
    unit_type    TEXT CHECK (unit_type IS NULL OR unit_type IN ('tokens', 'chars', 'seconds', 'calls')),
    cost_cents   INTEGER NOT NULL CHECK (cost_cents >= 0),
    created_at   TEXT NOT NULL
);

-- one usage row per job: a re-enqueued / re-finalized job never double-counts
CREATE UNIQUE INDEX uq_usage_events_job ON usage_events (job_id);
-- MTD spend rollup + recent-charges feed, both workspace-scoped
CREATE INDEX idx_usage_events_workspace ON usage_events (workspace_id, created_at);

-- 2. Credit transactions (money ledger) ------------------------------------

CREATE TABLE credit_transactions (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    type         TEXT NOT NULL CHECK (type IN ('grant', 'spend', 'adjust')),
    -- signed: grant > 0, spend < 0, adjust either; balance = SUM(amount_cents)
    amount_cents INTEGER NOT NULL,
    reason       TEXT,
    ref_run_id   INTEGER REFERENCES runs (id),
    ref_job_id   INTEGER REFERENCES jobs (id),
    created_at   TEXT NOT NULL
);

-- balance rollup + recent-ledger feed
CREATE INDEX idx_credit_tx_workspace ON credit_transactions (workspace_id, id DESC);
-- a spend mirrors exactly one usage_event job → idempotent spend recording
CREATE UNIQUE INDEX uq_credit_tx_spend_job ON credit_transactions (ref_job_id)
    WHERE type = 'spend';
