-- Phase 23: Planned publishing — a repeating weekly plan on top of the
-- single-instant scheduling that already works.
-- Conventions per 0008/0014: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only, ADD COLUMN only.
--
-- WHAT ALREADY EXISTS (unchanged by this file): approving a render can carry a
-- publish time → runs.publish_after → the publish job's run_after gate → the
-- worker fires it when due → the adapter sends scheduledFor. This migration adds
-- only the SLOT TEMPLATES that produce such a time, plus the timezone those
-- templates are written in.
--
-- DELIBERATELY NOT A CRON ENGINE (no-overbuild rule): a slot is a weekday plus a
-- wall-clock time, nothing more. No cron expressions, no intervals, no
-- calendars, no recurrence exceptions.

-- 1. The timezone a workspace thinks in -------------------------------------
--    Storage and scheduling stay UTC end to end; this is the zone slot times
--    are WRITTEN in, so "Mon 09:00" means 09:00 where the operator lives and
--    survives daylight-saving shifts.

ALTER TABLE workspaces ADD COLUMN timezone TEXT NOT NULL DEFAULT 'UTC';

-- 2. Weekly slot templates ---------------------------------------------------

CREATE TABLE publish_slots (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    -- NULL = applies to every connected account. A per-account row narrows a
    -- slot to one channel; the run itself still publishes at one instant
    -- (per-account fan-out at DIFFERENT times remains a later phase).
    account_id   INTEGER REFERENCES accounts (id),
    -- ISO-8601 weekday: 1 = Monday … 7 = Sunday (matches PHP's date('N'))
    weekday      INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    -- 'HH:MM' wall-clock time in the workspace timezone
    -- the GLOB alone would accept 24:00-29:59, so the hour is split: a row the
    -- resolver would reject must not be storable in the first place
    time_hhmm    TEXT NOT NULL CHECK (length(time_hhmm) = 5
                 AND (time_hhmm GLOB '[01][0-9]:[0-5][0-9]' OR time_hhmm GLOB '2[0-3]:[0-5][0-9]')),
    enabled      INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

-- workspace-scoped read, ordered the way the picker lists them
CREATE INDEX idx_publish_slots_workspace ON publish_slots (workspace_id, weekday, time_hhmm);

-- No duplicate slot for the same target. COALESCE is required because SQLite
-- treats NULLs as distinct in a UNIQUE index, so two "every account" rows for
-- the same weekday+time would otherwise both be allowed.
CREATE UNIQUE INDEX uq_publish_slots_target
    ON publish_slots (workspace_id, COALESCE(account_id, 0), weekday, time_hhmm);
