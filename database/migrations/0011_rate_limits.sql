-- Phase 13 (Hardening): generic per-IP rate-limit ledger.
-- Conventions per 0003/0007/0008/0009: TEXT ISO-8601 UTC timestamps from PHP,
-- no BEGIN/COMMIT (the Migrator wraps each file), forward-only.
--
-- One append-only row per request hit, keyed by a logical bucket + client IP.
-- The trailing-window COUNT bounds request FREQUENCY (the body-size cap +
-- HMAC verification already bound per-request cost). Used by RateLimiter to
-- throttle the unauthenticated POST /webhooks/zernio endpoint (Phase 10 LOW
-- follow-up) — NOT tenant data, so no workspace_id. Old rows are pruned
-- opportunistically by RateLimiter (no cron), and an operator may delete any
-- row by hand (break-glass: plain SQLite data).

CREATE TABLE rate_limits (
    id      INTEGER PRIMARY KEY,
    bucket  TEXT NOT NULL,   -- logical limiter, e.g. 'webhook:zernio'
    ip      TEXT NOT NULL,   -- client IP (REMOTE_ADDR)
    hit_at  TEXT NOT NULL    -- ISO-8601 UTC
);

-- trailing-window count + retention prune both hit (bucket, ip, hit_at)
CREATE INDEX idx_rate_limits_lookup ON rate_limits (bucket, ip, hit_at);
