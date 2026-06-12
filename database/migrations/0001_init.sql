-- Phase 2 schema v1: identity + tenancy foundation.
-- Conventions: timestamps are TEXT ISO-8601 UTC set from PHP;
-- files must NOT contain BEGIN/COMMIT (the Migrator wraps each file
-- in its own transaction); forward-only, no down migrations.

CREATE TABLE users (
    id            INTEGER PRIMARY KEY,
    email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    name          TEXT,
    created_at    TEXT NOT NULL,
    updated_at    TEXT NOT NULL
);

-- Tenant root: defines tenants, therefore itself global.
CREATE TABLE workspaces (
    id         INTEGER PRIMARY KEY,
    name       TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

-- Tenant table: workspace_id present and filtered in every query (isolation rule).
-- role CHECK is the whole RBAC story for V1 — no permission tables (no-overbuild).
CREATE TABLE workspace_users (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    user_id      INTEGER NOT NULL REFERENCES users (id),
    role         TEXT NOT NULL DEFAULT 'owner' CHECK (role IN ('owner', 'member')),
    created_at   TEXT NOT NULL,
    UNIQUE (workspace_id, user_id)
);

CREATE INDEX idx_workspace_users_user ON workspace_users (user_id);

-- Pre-auth security infrastructure (brute-force throttle) → no workspace_id.
CREATE TABLE login_attempts (
    id           INTEGER PRIMARY KEY,
    email        TEXT,
    ip           TEXT NOT NULL,
    succeeded    INTEGER NOT NULL DEFAULT 0,
    attempted_at TEXT NOT NULL
);

CREATE INDEX idx_login_attempts_email ON login_attempts (email, attempted_at);
CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, attempted_at);
