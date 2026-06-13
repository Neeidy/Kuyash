# Phase 13 — Deferred follow-ups

Phase 13 (Hardening) closed with the full suite green (**693 PASS / 0 FAIL**, +20)
and a 3-dimension review: **security-auditor = GO / 0 blockers** (the mandatory
Phase 13 gate), **compliance-reviewer = GO / 0 blockers**, **ux-reviewer = GO**
(minimal UI surface). The items below were consciously deferred — none block the
phase; they are operator-config-dependent or defense-in-depth.

## Applied during the review (NOT deferred — done in this phase)
- **ux polish:** a non-retryable dead-letter showed `non-retryable: … (retry 1/3)`
  in the queue, which can read as contradictory. `templates/queue/index.php` now
  shows `(no auto-retry)` instead of the `(retry N/M)` counter when the message is
  non-retryable — truthful and clear.

## Deferred (non-blocking)

1. **Per-IP webhook limit behind Cloudflare Tunnel (security MEDIUM, → follow-up).**
   `WebhookController` reads `$_SERVER['REMOTE_ADDR']`. In the production shape
   (Caddy origin behind `cloudflared`), `REMOTE_ADDR` is the tunnel daemon's local
   address, so the "per-IP" limit degrades to a single GLOBAL 120/60s throttle.
   Still bounds total DoS, but not per-IP. **Correct fix is config-dependent and
   must NOT be a naive header read:** trusting `CF-Connecting-IP` unconditionally
   lets a client spoof it to evade the limit. The fix needs a trusted-proxy
   allowlist (only honor `CF-Connecting-IP` when the request truly originates from
   the tunnel/Cloudflare), in one shared helper reused by `LoginThrottle` too.
   Deferred until the real tunnel/trusted-proxy config is known (documented in
   `production-readiness.md` §6 — "tune the cap to Zernio's real rate").

2. **Restore symlink containment (security LOW, → follow-up).** `restoreTree`/
   `copyTree` in `bin/restore.php` / `bin/backup.php` build targets as
   `$dst . '/' . $rel` from a `RecursiveDirectoryIterator` over an operator-supplied
   backup dir. A crafted backup with symlinks could theoretically write outside the
   configured root. Operator-trusted, CLI-only, restoring one's own backup — low
   risk. Defense-in-depth fix: skip `$item->isLink()` + `realpath()` containment
   check that each resolved target stays under `realpath($dst)`.

3. **Rate-limit write amplification (security LOW, optional).** Each webhook hit
   does prune-DELETE + SELECT + INSERT on `rate_limits` before HMAC. A high-volume
   bogus flood incurs 3 SQLite ops/request; rows are pruned only opportunistically
   (no cron, by design). Bounded in practice by the generous cap + 64 KiB body cap.
   Optional: a cheap in-process pre-check, or fold the cost into a load note.

## Already handled (no action)
- **Backups gitignored (security LOW #4):** `storage/backups/` is covered by
  `.gitignore` (`backups/`, `storage/database/`, `*.sqlite`) — DB snapshots can
  never be committed. Verified.

## Operator enable-time (carried, see production-readiness.md)
- R2 lifecycle (assembly-side staging for an evicted remote asset + delete-after-
  verify eviction) stays deferred — the locked Phase 13 scope decision. `bin/r2-smoke.php`
  is the enable-time gate; `bin/migrate-storage.php` never deletes the local copy.
- `caddy validate` + live tunnel verification are operator host steps (Caddy not
  installed in dev).
