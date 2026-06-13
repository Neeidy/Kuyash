-- Phase 12: Quick Create AI video — widen the workflows.template CHECK to admit
-- the new 'quick_create' template (image-to-video pipeline entry).
-- Conventions per 0003/0007: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only.
--
-- SQLite cannot ALTER a CHECK in place: CREATE new → INSERT…SELECT preserving
-- ids → DROP → RENAME → recreate index (the 0007 approvals recipe). The
-- difference here is that workflows is a PARENT table (runs.workflow_id REFERENCES
-- workflows(id)), so dropping it would trip that FK on the implicit row-delete.
-- The Migrator disables foreign-key enforcement around each migration's
-- transaction precisely for this, and verifies PRAGMA foreign_key_check after —
-- ids are preserved, so every existing run still resolves to its workflow.
--
-- runs.entity_type already allows 'quick_create' (0003 schema-only stub) and
-- runs.reference_asset_id already exists (0005): no other schema change is
-- needed. The Quick Create prompt rides in the run's nodes_json VISUALS
-- settings snapshot, not in a new column.

CREATE TABLE workflows_new (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    name         TEXT NOT NULL,
    template     TEXT NOT NULL CHECK (template IN ('full', 'distribution', 'quick_create')),
    nodes_json   TEXT NOT NULL,
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

INSERT INTO workflows_new (id, workspace_id, name, template, nodes_json, created_at, updated_at)
    SELECT id, workspace_id, name, template, nodes_json, created_at, updated_at
    FROM workflows;

DROP TABLE workflows;

ALTER TABLE workflows_new RENAME TO workflows;

CREATE INDEX idx_workflows_workspace ON workflows (workspace_id);
