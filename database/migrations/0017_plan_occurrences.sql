-- Phase 24: the weekly plan becomes a CALENDAR — concrete dated cells that
-- content can be bound to — plus a mode per publishing time.
--
-- Conventions per 0008/0014/0016: TEXT ISO-8601 UTC timestamps from PHP, no
-- BEGIN/COMMIT (the Migrator wraps each file), forward-only, ADD COLUMN only.
--
-- WHAT PHASE 23 LEFT (unchanged by this file): publish_slots is a weekly
-- TEMPLATE ("Mon 09:00" in workspaces.timezone) and SlotResolver turns it into
-- the next UTC instant. Approving a render can carry that instant →
-- runs.publish_after → the publish job's run_after gate → the adapter's
-- scheduledFor. All of that stays exactly as it is.
--
-- WHAT WAS MISSING: a template cannot HOLD anything. "Put this video on
-- Tuesday" needs a concrete (time, calendar day) row to attach content to.
-- That row is a slot OCCURRENCE, and it is the whole of this migration.
--
-- STILL DELIBERATELY NOT A CRON ENGINE (no-overbuild rule): no cron
-- expressions, no intervals, no recurrence exceptions, no holiday calendar.
-- An occurrence is a slot plus a date, nothing more.

-- 1. A publishing time knows WHO fills it ------------------------------------
--    'manual' = you assign one of your own library videos to that day.
--    'auto'   = Kuyash produces one, ahead of the time, into the approval queue.
--    Existing rows default to 'manual', which is the truth about them: today a
--    slot does nothing by itself until a human approves something into it.
--    The AUTONOMY POLICY (approval mode, kill switch, daily cap, budget cap)
--    deliberately stays on `workspaces` — one source, already worker-readable.

ALTER TABLE publish_slots ADD COLUMN mode TEXT NOT NULL DEFAULT 'manual'
    CHECK (mode IN ('manual', 'auto'));

-- 2. How far ahead an 'auto' time produces its content ------------------------
--    Default 3 h: the pipeline itself takes minutes, the rest of the window is
--    for a HUMAN to approve it. Phase 24 never publishes unapproved content.

ALTER TABLE workspaces ADD COLUMN auto_lead_minutes INTEGER NOT NULL DEFAULT 180
    CHECK (auto_lead_minutes BETWEEN 30 AND 1440);

-- 3. Pause automatic production ----------------------------------------------
--    NARROWER than the compliance kill switch on purpose: this stops Kuyash
--    from CREATING content. Posts a human already approved keep their time —
--    guardrails constrain autonomy, not people.

ALTER TABLE workspaces ADD COLUMN plan_paused INTEGER NOT NULL DEFAULT 0
    CHECK (plan_paused IN (0, 1));

-- 4. Slot occurrences — the calendar cells ------------------------------------

CREATE TABLE slot_occurrences (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    slot_id      INTEGER NOT NULL REFERENCES publish_slots (id),

    -- IDENTITY IS THE LOCAL CALENDAR DAY, not the UTC instant. A daylight-saving
    -- shift moves publish_at by an hour; it must never split one Monday into two
    -- rows, and re-resolving must land on the same row.
    local_date   TEXT NOT NULL CHECK (length(local_date) = 10),

    -- The resolved UTC instant (SlotResolver). Recomputed only while the cell is
    -- still 'open' or 'assigned'; once a publish job is queued on it the instant
    -- is a commitment and is never moved silently.
    publish_at   TEXT NOT NULL,

    -- Copied from the slot AT MATERIALIZATION (the runs.nodes_json snapshot
    -- rule): flipping a slot to 'auto' must not rewrite what already happened.
    mode         TEXT NOT NULL CHECK (mode IN ('manual', 'auto')),

    -- ONLY the states the plan itself owns. "preparing / waiting for you /
    -- scheduled / published" are READ from the run and its jobs, never copied
    -- here — Phase 22/23's rule: read the real job gate, not the plan. A second
    -- state machine to keep in sync is exactly what this avoids.
    status       TEXT NOT NULL DEFAULT 'open'
                 CHECK (status IN ('open', 'assigned', 'skipped')),

    asset_id     INTEGER REFERENCES assets (id),   -- manual mode: the chosen video
    run_id       INTEGER REFERENCES runs (id),

    -- Why a cell produced nothing. An honest record, shown to the operator on
    -- the calendar — never a silent gap.
    -- 'no_content'|'not_approved'|'missed'|'daily_cap'|'budget_cap'|
    -- 'kill_switch'|'plan_paused'|'compliance_block'|'no_owner'|
    -- 'no_workflow'|'no_account'|'cancelled'
    skip_reason  TEXT,

    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

-- one publishing time × one local day = one cell (materializer idempotency:
-- the chore may run every 5 minutes and must never duplicate a cell)
CREATE UNIQUE INDEX uq_slot_occurrences ON slot_occurrences (slot_id, local_date);

-- a run belongs to at most ONE cell, and a cell to at most one run — the lock
-- that makes "start content for this cell" safe to retry
CREATE UNIQUE INDEX uq_slot_occurrences_run ON slot_occurrences (run_id)
    WHERE run_id IS NOT NULL;

-- the calendar read and the due sweep both walk (workspace, time)
CREATE INDEX idx_slot_occurrences_due ON slot_occurrences (workspace_id, publish_at);
