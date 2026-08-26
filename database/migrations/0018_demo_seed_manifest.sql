-- Showcase seed manifest (DEV/demo infrastructure, NOT product data).
-- Conventions per 0001/0014/0017: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only.
--
-- WHY THIS EXISTS: `bin/demo-seed.php` fills the screens a case study is
-- captured from. Anything written into a live database for a screenshot has to
-- be removable again with certainty — "I think these were the demo rows" is not
-- a rollback. This table IS that certainty: every row the seed writes, and every
-- file it places on disk, is recorded here as it is created, and
-- `bin/demo-teardown.php` deletes exactly this set and nothing else.
--
-- NOT A TENANT TABLE: it records rows, not content. The rows it points at carry
-- their own workspace_id, and teardown deletes by primary key — so there is no
-- query here that could reach across tenants in the first place.
--
-- One entry is EITHER a database row (table_name + row_id) OR a file the seed
-- placed under media storage (table_name = '@file' + path). The CHECK makes
-- "exactly one of the two" a database invariant rather than a convention.

CREATE TABLE demo_seed_manifest (
    id         INTEGER PRIMARY KEY,
    -- the table the row lives in, or the literal '@file' for on-disk media
    table_name TEXT NOT NULL,
    row_id     INTEGER,
    -- absolute path of a file the seed created; NULL for database rows
    path       TEXT,
    created_at TEXT NOT NULL,
    CHECK (
        (row_id IS NOT NULL AND path IS NULL AND table_name <> '@file')
        OR
        (row_id IS NULL AND path IS NOT NULL AND table_name = '@file')
    )
);

-- Re-recording the same row is a no-op, so a re-run cannot double-count what
-- teardown will delete.
CREATE UNIQUE INDEX uq_demo_seed_manifest_row ON demo_seed_manifest (table_name, row_id)
    WHERE row_id IS NOT NULL;
CREATE UNIQUE INDEX uq_demo_seed_manifest_path ON demo_seed_manifest (path)
    WHERE path IS NOT NULL;
