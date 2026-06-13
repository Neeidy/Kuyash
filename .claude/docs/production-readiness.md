# Kuyash — Production Readiness Checklist (Phase 13)

The go-live gate. Every line is either **[ ] verifiable now** or **[OP] operator
enable-time** (needs real infra/credentials this repo deliberately does not carry).
Default config is mock-first and safe; the [OP] items are what flips it live.

References: `security-checklist.md`, `release-test-checklist.md`, ADR-014 (R2),
`zernio-notes.md`, `ai-video-notes.md`, `architecture-decisions.md`.

## 1. Secrets & environment
- [ ] `.env` is gitignored; `.env.example` carries placeholder names only (no values).
- [ ] Production `.env`: `APP_ENV=prod`, `APP_DEBUG=false` (errors logged, never shown). *(Local dev `.env` sets debug=true — that is the conscious dev/prod split; prod MUST be false.)*
- [ ] No secret appears in code, logs, error messages, or UI. (`git grep -iE 'sk-|api_key|secret' -- ':!*.example' ':!.claude'` is clean of real values.)
- [OP] Every real key that will be used is present: `OPENAI_API_KEY`, `PEXELS_API_KEY`, `ZERNIO_*`, `R2_*`, `FAL_API_KEY` — set ONLY for the integrations you are turning on.

## 2. Database
- [ ] Migrations 0001–0011 applied: `php bin/migrate.php` → reports `journal_mode=wal busy_timeout=5000 foreign_keys=1`.
- [ ] Pragmas confirmed on every connect: WAL, busy_timeout=5000, foreign_keys=ON, recursive_triggers=ON.
- [ ] Backup/restore drill passed (see release-test-checklist) — `bin/backup.php` snapshot integrity=ok, `bin/restore.php` round-trip ok.
- [OP] Backups scheduled (cron `php bin/backup.php`), retained off-box, and a restore has been rehearsed at least once.

## 3. Web server & tunnel
- [OP] `caddy validate --config Caddyfile` passes (Caddy is not installed in dev — validate on the host).
- [OP] Production site block uncommented with the real hostname; `Strict-Transport-Security` emitted (HSTS).
- [ ] Security headers present (reviewed in `Caddyfile`): CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `-Server`, `-X-Powered-By`. Verify live: `curl -sI https://<host>/`.
- [ ] Sensitive paths 404 at the edge: `/.*`, `/storage/*`, `/src/*`, `/config/*`, `/templates/*`, `/database/*`, `/bin/*`, `/tests/*`.
- [OP] Cloudflare Tunnel up; no origin ports exposed publicly; TLS terminated at the edge.
- [OP] `php-fpm` (php@8.3) running for `php_fastcgi 127.0.0.1:9000`; the worker (`bin/worker.php`) runs as a managed/supervised process.

## 4. Auth, sessions, isolation
- [ ] Argon2id password hashing (bcrypt login still accepted for legacy).
- [ ] Sessions HttpOnly + SameSite; `secure` ON in prod (APP_ENV≠dev); fixation regen on login.
- [ ] CSRF tokens on all state-changing routes; webhook route is the only CSRF-exempt one (HMAC-protected instead).
- [ ] Login brute-force lockout (`LoginThrottle`) active.
- [ ] Tenant isolation: every tenant query filters by `workspace_id` (isolation tests green).

## 5. Storage (R2 enable-time HARD GATE)
- [ ] Default `STORAGE_DRIVER=local` — byte-identical to Phase 7; safe out of the box.
- [OP] **Before** `STORAGE_DRIVER=r2`: `php bin/r2-smoke.php` exits **0 (GATE PASSED)** — proves SigV4 put/presign-GET/delete AND that an unsigned GET is denied (bucket is PRIVATE / no public ACL). If it FAILS, do NOT enable R2.
- [OP] R2 object lifecycle (assembly-side staging for an evicted remote asset + delete-after-verify eviction) is **deferred** (no live bucket in V1 — locked Phase 13 scope decision). `bin/migrate-storage.php` backfills local→r2 and **never deletes the local copy**; eviction stays manual until the lifecycle ships.

## 6. Integration doc-gates (all firm — keep mocked until supplied)
- [ ] **Zernio publishing:** `ZERNIO_MOCK=true` until all 12 items in `zernio-notes.md` are supplied. The real provider is a flag-off stub that throws "doc-gated".
- [ ] **AI video (Quick Create):** `VIDEO_MOCK=true` until the 7 items in `ai-video-notes.md` are supplied (async submit/poll + per-second pricing + clip-length band). Real `FalVideoGenProvider` throws before any HTTP.
- [ ] **OpenAI text / TTS, Pexels:** real only when `*_MOCK=false` AND a key is present; otherwise they fail safe to mock. A 401/403 from any of them now dead-letters fast (no wasted retries) — fix the key and manually retry.
- [OP] `ZERNIO_WEBHOOK_SECRET` set (the webhook is fail-closed → 503 when empty); per-IP rate limit is on (120/60s) — **tune the cap to Zernio's real sending rate once known**.

## 7. Compliance & autonomy guardrails
- [ ] Approval mode defaults to **Manual** (record = real human). **Auto** only after deliberate enablement; its record is truthful ("auto-approved by compliance agent, policy vX") — the 0007 CHECK makes a fake "human approved" stamp a constraint violation.
- [ ] Realistic AI media (AI video / TTS) carries the platform AI label automatically; truthful AI-label flag on published posts.
- [ ] Guardrails reachable + set: per-account daily post cap, per-workspace budget cap, kill switch, daily digest, auto-fallback to Manual on quality drop.
- [ ] Slop/variation control active (warn ≥0.55, block ≥0.80 vs recent posts).

## 8. ffmpeg & media safety
- [ ] `FFMPEG_BIN` / `FFPROBE_BIN` resolve to real binaries on the host.
- [ ] ffmpeg invoked with escaped args (no shell, no user-controlled command strings), validated paths, timeouts, temp cleanup — covered by arg-safety tests.

## 9. Operations
- [ ] Audit trail: the append-only `events` table records every job/run/compliance/guardrail transition (immutability triggers enforced).
- [OP] `error_log` destination configured + monitored; worker heartbeat watched.
- [ ] GDPR-minded: tenant data is `workspace_id`-scoped and operator-deletable; a self-serve delete/export flow is a V2 item (documented, not in V1).

## 10. Final pre-release gate
- [ ] Full suite green (`… PASS, 0 FAIL`).
- [ ] `security-auditor` = GO / 0 blockers (mandatory). `ux-reviewer` + `compliance-reviewer` sanity pass.
- [ ] No new product features crept in (Phase 13 = hardening only).
