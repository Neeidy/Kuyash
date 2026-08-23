-- Phase 22: Panel + Real Data — account de-duplication + the constraint that
-- makes the bug unrepeatable.
--
-- THE BUG: AccountRepository::connect() did a blind INSERT with no uniqueness on
-- (workspace_id, platform, handle). Re-connecting an already-connected account
-- (or re-connecting after a disconnect, which only flips status) appended a
-- SECOND row. Real symptom: one Instagram handle held two rows — a stale
-- 'disconnected' one and the live 'connected' one — both rendering as cards.
-- The code path is fixed in connect() (revive-existing-then-insert); this file
-- repairs existing data and adds the constraint so it cannot recur.
--
-- ORDER MATTERS (and is not additive — it is a one-time data repair):
--   1. re-point every FK reference onto the group's canonical row
--   2. delete only the non-canonical duplicates
--   3. add the UNIQUE index (would fail if 1–2 had not run first)
-- On a clean install every statement is a no-op.
--
-- CANONICAL ROW = the one a human would keep: a 'connected' row wins over a
-- disconnected/reauth one; ties break on the newest id.
--
-- MATCHING KEY = lower(ltrim(handle,'@')) — the SAME normalization the sync
-- reconcile already uses, so '@AI.Neeidy' and 'ai.neeidy' are one account.
-- NOTE: this repairs duplicates WITHIN one workspace only; two workspaces may
-- legitimately connect the same handle (multi-tenant), which the index allows.

-- 1. Re-point FK references onto the canonical row ---------------------------
--    NEVER orphan a row that points at an account: anything attached to a
--    duplicate moves to that duplicate's canonical row before it disappears.
--    Both children of accounts are handled — posts (published history) and
--    account_metrics (created one file earlier in 0014; normally still empty
--    here, but a worker writing a snapshot between the two migrations would
--    otherwise be orphaned by the DELETE below).

UPDATE posts
SET account_id = (
    SELECT c.id
    FROM accounts c
    JOIN accounts o ON o.id = posts.account_id
    WHERE c.workspace_id = o.workspace_id
      AND c.platform = o.platform
      AND lower(ltrim(c.handle, '@')) = lower(ltrim(o.handle, '@'))
    ORDER BY (c.status = 'connected') DESC, c.id DESC
    LIMIT 1
)
WHERE EXISTS (SELECT 1 FROM accounts o WHERE o.id = posts.account_id);

UPDATE account_metrics
SET account_id = (
    SELECT c.id
    FROM accounts c
    JOIN accounts o ON o.id = account_metrics.account_id
    WHERE c.workspace_id = o.workspace_id
      AND c.platform = o.platform
      AND lower(ltrim(c.handle, '@')) = lower(ltrim(o.handle, '@'))
    ORDER BY (c.status = 'connected') DESC, c.id DESC
    LIMIT 1
)
WHERE EXISTS (SELECT 1 FROM accounts o WHERE o.id = account_metrics.account_id);

-- 2. Delete the non-canonical duplicates -------------------------------------
--    A row goes only when a BETTER row exists in its own group (same workspace,
--    same platform, same normalized handle).

DELETE FROM accounts
WHERE EXISTS (
    SELECT 1
    FROM accounts b
    WHERE b.workspace_id = accounts.workspace_id
      AND b.platform = accounts.platform
      AND lower(ltrim(b.handle, '@')) = lower(ltrim(accounts.handle, '@'))
      AND b.id <> accounts.id
      AND (
            (b.status = 'connected') > (accounts.status = 'connected')
            OR ((b.status = 'connected') = (accounts.status = 'connected') AND b.id > accounts.id)
          )
);

-- 3. Make it unrepeatable ----------------------------------------------------
--    Per-workspace uniqueness on the normalized handle. connect() now revives
--    the existing row; this index is the defense-in-depth backstop.

CREATE UNIQUE INDEX uq_accounts_ws_platform_handle
    ON accounts (workspace_id, platform, lower(ltrim(handle, '@')));
