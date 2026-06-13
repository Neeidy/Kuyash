-- Phase 14 (i18n TR/EN): per-user UI language.
-- Conventions per prior migrations: no BEGIN/COMMIT (the Migrator wraps each
-- file), forward-only, additive. SQLite ALTER TABLE ADD COLUMN accepts a CHECK
-- constraint and a non-NULL default; the default 'en' satisfies the CHECK for
-- every existing row, so this is safe on a populated table.
--
-- EN is the source language and the universal fallback (lang/en.php); TR is the
-- only other supported locale today (lang/tr.php). The CHECK keeps the column
-- to the set the app actually ships translations for. Per-USER, not per-
-- workspace (SaaS-ready): each operator picks their own UI language.

ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en' CHECK (locale IN ('en', 'tr'));
