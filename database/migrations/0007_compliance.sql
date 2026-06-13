-- Phase 9: Compliance Agent + Approval Modes.
-- Conventions per 0001/0005: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only.
--
-- Two changes:
--  1. Workspace compliance settings as columns on the workspaces row (matches
--     the avatar_asset_id pattern from 0005 — no settings table, no-overbuild).
--  2. approvals rebuild for TRUTHFUL records: 'auto' rows carry NO user and a
--     policy version; 'manual' rows carry a real user and NO policy version.
--     The CHECK makes "records are never misrepresented" a database invariant,
--     not an application convention.

-- 1. Workspace compliance settings -----------------------------------------

-- Manual is the DEFAULT approval mode (compliance rule); Auto is a deliberate
-- per-workspace human act in /settings.
ALTER TABLE workspaces ADD COLUMN approval_mode TEXT NOT NULL DEFAULT 'manual'
    CHECK (approval_mode IN ('manual', 'auto'));

-- Kill switch: 1 stops auto-approvals instantly and defers queued auto-approved
-- publishes. Never affects manual decisions (guardrails constrain autonomy).
ALTER TABLE workspaces ADD COLUMN kill_switch INTEGER NOT NULL DEFAULT 0
    CHECK (kill_switch IN (0, 1));

-- Per-workspace daily post cap (per-ACCOUNT caps arrive with accounts, Phase 10).
-- Default 2 = the compliance policy's conservative 1–3 band.
ALTER TABLE workspaces ADD COLUMN daily_post_cap INTEGER NOT NULL DEFAULT 2
    CHECK (daily_post_cap BETWEEN 1 AND 10);

-- Month-to-date budget cap in cents; NULL = no cap. Phase 9 checks it against
-- SUM(jobs.cost_cents) — the truthful minimal source until the Phase 11 ledger.
ALTER TABLE workspaces ADD COLUMN budget_cap_cents INTEGER
    CHECK (budget_cap_cents IS NULL OR budget_cap_cents > 0);

-- 2. approvals rebuild (truthful-record CHECK) ------------------------------
-- SQLite cannot ALTER a CHECK or drop NOT NULL in place: CREATE new →
-- INSERT…SELECT → DROP → RENAME → recreate index. Safe: no FK references
-- approvals, and all existing rows are mode='manual' with a real decided_by.

CREATE TABLE approvals_new (
    id             INTEGER PRIMARY KEY,
    workspace_id   INTEGER NOT NULL REFERENCES workspaces (id),
    run_id         INTEGER NOT NULL REFERENCES runs (id),
    job_id         INTEGER NOT NULL REFERENCES jobs (id),
    node           TEXT NOT NULL,
    decision       TEXT NOT NULL CHECK (decision IN ('approved', 'rejected')),
    mode           TEXT NOT NULL DEFAULT 'manual' CHECK (mode IN ('manual', 'auto')),
    decided_by     INTEGER REFERENCES users (id),
    decided_at     TEXT NOT NULL,
    policy_version TEXT,
    score_json     TEXT,
    -- truthfulness invariant: a manual record IS a human (real user, no policy
    -- stamp); an auto record IS the agent (no user, policy version required).
    CHECK (
        (mode = 'manual' AND decided_by IS NOT NULL AND policy_version IS NULL)
        OR
        (mode = 'auto' AND decided_by IS NULL AND policy_version IS NOT NULL)
    )
);

INSERT INTO approvals_new (id, workspace_id, run_id, job_id, node, decision, mode, decided_by, decided_at)
    SELECT id, workspace_id, run_id, job_id, node, decision, mode, decided_by, decided_at
    FROM approvals;

DROP TABLE approvals;

ALTER TABLE approvals_new RENAME TO approvals;

-- serves both the run-detail read and the gate's "auto approvals today" count
CREATE INDEX idx_approvals_workspace ON approvals (workspace_id, decided_at DESC);
