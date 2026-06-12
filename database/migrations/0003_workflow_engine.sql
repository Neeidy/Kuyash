-- Phase 4: workflow engine — workflows, runs, durable job queue, append-only
-- event log, truthful approval records.
-- Conventions per 0001: TEXT ISO-8601 UTC timestamps from PHP, no BEGIN/COMMIT,
-- forward-only. CHECK enums are deliberately generous (changing a CHECK in
-- SQLite means a full table rebuild): 'awaiting_recording' (runs/jobs),
-- 'quick_create' (runs.entity_type) and 'auto' (approvals.mode) are schema-only
-- stubs until Phases 5/9/12 — no Phase 4 code path produces them.

CREATE TABLE workflows (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    name         TEXT NOT NULL,
    template     TEXT NOT NULL CHECK (template IN ('full', 'distribution')),
    nodes_json   TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

CREATE INDEX idx_workflows_workspace ON workflows (workspace_id);

-- A run snapshots nodes_json at start: history stays immutable even if the
-- workflow definition changes later.
CREATE TABLE runs (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    workflow_id  INTEGER NOT NULL REFERENCES workflows (id),
    entity_type  TEXT NOT NULL CHECK (entity_type IN ('trend', 'library', 'quick_create')),
    entity_id    INTEGER,
    nodes_json   TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'running'
                 CHECK (status IN ('running', 'awaiting_approval', 'awaiting_recording',
                                   'completed', 'failed', 'cancelled')),
    current_node TEXT,
    created_by   INTEGER NOT NULL REFERENCES users (id),
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

CREATE INDEX idx_runs_workspace ON runs (workspace_id, created_at DESC);

-- Durable queue (sqlite-queue-notes.md): claimed with one atomic UPDATE,
-- executed outside any transaction, finalized in a short guarded transaction.
CREATE TABLE jobs (
    id              INTEGER PRIMARY KEY,
    workspace_id    INTEGER NOT NULL REFERENCES workspaces (id),
    run_id          INTEGER NOT NULL REFERENCES runs (id),
    node            TEXT NOT NULL,
    step            INTEGER NOT NULL,
    type            TEXT NOT NULL,
    user_id         INTEGER REFERENCES users (id),
    entity_type     TEXT,
    entity_id       INTEGER,
    status          TEXT NOT NULL DEFAULT 'queued'
                    CHECK (status IN ('queued', 'processing', 'awaiting_approval',
                                      'awaiting_recording', 'ready', 'failed',
                                      'published', 'cancelled')),
    payload_json    TEXT NOT NULL DEFAULT '{}',
    result_json     TEXT,
    retry_count     INTEGER NOT NULL DEFAULT 0,
    max_retries     INTEGER NOT NULL DEFAULT 3,
    error_message   TEXT,
    idempotency_key TEXT,
    priority        INTEGER NOT NULL DEFAULT 100,
    run_after       TEXT NOT NULL,
    worker_id       TEXT,
    cost_cents      INTEGER,
    provider        TEXT,
    created_at      TEXT NOT NULL,
    started_at      TEXT,
    finished_at     TEXT
);

-- claim order: due first by (priority, id); the index serves the claim subquery
CREATE INDEX idx_jobs_claim ON jobs (status, run_after, priority, id);
CREATE INDEX idx_jobs_workspace ON jobs (workspace_id, created_at DESC);
CREATE INDEX idx_jobs_run ON jobs (run_id, step);
CREATE UNIQUE INDEX uq_jobs_idempotency ON jobs (idempotency_key)
    WHERE idempotency_key IS NOT NULL;

-- Append-only event log: every state transition writes one row in the SAME
-- transaction. key + params_json (not prose) keeps the future TR i18n pass
-- mechanical. UPDATE/DELETE are rejected at the SQL level — audit trail
-- integrity does not depend on application discipline.
CREATE TABLE events (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    run_id       INTEGER REFERENCES runs (id),
    job_id       INTEGER REFERENCES jobs (id),
    level        TEXT NOT NULL CHECK (level IN ('info', 'warn', 'error')),
    kind         TEXT NOT NULL CHECK (kind IN ('transition', 'compliance', 'guardrail')),
    key          TEXT NOT NULL,
    params_json  TEXT NOT NULL DEFAULT '{}',
    created_at   TEXT NOT NULL
);

CREATE INDEX idx_events_workspace ON events (workspace_id, id DESC);
CREATE INDEX idx_events_run ON events (run_id);

CREATE TRIGGER trg_events_no_update
BEFORE UPDATE ON events
BEGIN
    SELECT RAISE(ABORT, 'events is append-only');
END;

CREATE TRIGGER trg_events_no_delete
BEFORE DELETE ON events
BEGIN
    SELECT RAISE(ABORT, 'events is append-only');
END;

-- Truthful approval records (compliance rule): decided_by references a real
-- user; mode 'auto' exists in the schema for Phase 9 but no Phase 4 code
-- writes it. Records are never misrepresented.
CREATE TABLE approvals (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    run_id       INTEGER NOT NULL REFERENCES runs (id),
    job_id       INTEGER NOT NULL REFERENCES jobs (id),
    node         TEXT NOT NULL,
    decision     TEXT NOT NULL CHECK (decision IN ('approved', 'rejected')),
    mode         TEXT NOT NULL DEFAULT 'manual' CHECK (mode IN ('manual', 'auto')),
    decided_by   INTEGER NOT NULL REFERENCES users (id),
    decided_at   TEXT NOT NULL
);

CREATE INDEX idx_approvals_workspace ON approvals (workspace_id, decided_at DESC);
