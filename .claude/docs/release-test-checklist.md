# Kuyash — Release Test Checklist (Phase 13)

A consolidated map of what the automated suite covers per subsystem, plus the
manual smoke steps to run before a release. The suite is the source of truth;
this doc is the human-readable index + the things a machine can't assert.

**Run the suite:** `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`
→ expect `… PASS, 0 FAIL` (exit 0). Currently **693 PASS / 0 FAIL**.

The suite uses `:memory:` / temp-file SQLite + the real Migrator, fake HTTP
transports (ZERO network), and real ffmpeg when present (gracefully skipped if
absent). No secrets, no external calls.

## Coverage by subsystem (automated)

| Subsystem | Happy path | Failure / edge paths |
|---|---|---|
| Config / .env | parse, bool coercion, no-override | missing key default |
| Router / ErrorHandler | static+param routes, HEAD | 404, 405+Allow, 500 page, CLI mode |
| Database / pragmas | WAL, busy_timeout, FKs, prepared | tx rollback on throw |
| **Backup/restore (P13)** | `VACUUM INTO` snapshot integrity_check=ok, row-count parity (WAL captured) | refuses to overwrite a target |
| Migrator | applies 0001–0011 in order, idempotent | FK-safe parent-table rebuild |
| Schema CHECKs | — | dup email, FK, role, truthful-approval CHECK, usage CHECKs |
| Auth / sessions | login, bcrypt+argon, regen | wrong pw, unknown, orphan, **lockout after 5** |
| **RateLimiter (P13)** | under-cap passes, window ages out | (cap+1)th blocked, ip+bucket isolation |
| WorkspaceContext | own-workspace reads | cross-tenant read denied |
| Library / uploads | ingest, metadata | MIME/size/ext reject, traversal |
| Workflow engine | full + distribution + quick_create e2e | invalid workflow, reject=cancel |
| Queue / worker | atomic claim, order, prior-results | future jobs not claimable |
| **Retry / dead-letter** | backoff 2^n, manual retry heals | exhaustion → dead-letter; **non-retryable (401/403) fast-fail on 1st attempt**; ordinary throw still retried |
| Watchdog | stale processing → requeue | exhausted → dead-letter + run failed |
| Content / OpenAI | shaped output, cost, versions | 429, **401/403→PermanentFailure**, transport, malformed, key never leaks |
| Trend | cache TTL, degradation, quota | 403 quota, transport, bad JSON |
| Media / ffmpeg | TTS→stock→assembly→final, cache reuse | arg-safety (no shell), provider errors |
| Compliance | pass/warn/block, ai-label, format | block cancels run, slop bands, **kill switch / cap / quality fallback** |
| Approval modes | manual record, **auto record (truthful)** | guardrail denies, defer semantics |
| Publish (Zernio) | per-account fan-out, idempotent, AI-label truthful | auth-fail→post failed+reauth, rate-limit→retry, **UNIQUE backstop (P13)** |
| Webhooks | HMAC verify, idempotent inbox, reconcile | 401 bad sig, 503 no secret, 413 oversized, **429 rate-limit (P13)**, javascript: url rejected |
| Usage / ledger | real-cost event+spend, idempotent, MTD | mock=null records nothing, **recorder never rolls back finalize (P13)**, **ai_video real-cost passthrough (P13)** |
| Pre-flight budget | over-budget hard-block, no half-started run | quick_create + full both gated |
| Quick Create | photo+prompt→AI clip→compliance→publish e2e | empty/over-long prompt, video-as-photo reject, missing photo |

## Manual smoke (two terminals)

Default config is mock-first — no keys needed. Real paths are doc/flag-gated.

1. **[Terminal-1]** server: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8082 -t public public/index.php` (8080 may be busy).
2. **[Terminal-2]** worker: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/worker.php` (single tick: `--once`).
3. Log in (smoke user: `smoke4@kuyash.local` / `SmokePassword123`), start a run from a workflow, watch the queue advance, approve at render_review, confirm publish (mock).
4. Quick Create: `/quick` → pick a photo + prompt → run → AI-label notice + ~$7 estimate.
5. Settings: toggle Auto mode, set a budget cap, flip the kill switch — confirm guardrails on `/digest`.

## Failure-recovery smoke (operate-mode resilience)

- **Worker crash mid-job:** kill the worker while a job is `processing`; on next start the watchdog requeues it (or dead-letters when retries are exhausted) — no run hangs forever.
- **Retry exhaustion:** a job that keeps failing backs off (2^n·5s) then dead-letters; the run fails and the job is visible + manually retriable in the queue.
- **Non-retryable (401/403):** a bad provider key dead-letters on the FIRST attempt (no wasted backoff); fix the key, then manually retry the dead-lettered job.
- **Kill switch:** flipping it halts autonomous publishing (auto-approved publishes defer); Manual approval still works.
- **Over-budget:** a run whose estimate exceeds the cap is hard-blocked at start (no half-created state).

## Backup / restore drill (Phase 13)

1. `php bin/backup.php` → a timestamped dir under `storage/backups/` with `database.sqlite` (integrity=ok) + `media/` + `manifest.json`.
2. `php bin/restore.php storage/backups/<UTC>` → DRY-RUN (validates, changes nothing).
3. `php bin/restore.php storage/backups/<UTC> --force` → restores; current DB moved aside to `<db>.pre-restore-<UTC>` (reversible); restored DB re-checked integrity=ok.

## Pre-release gate

- [ ] Suite green (`… PASS, 0 FAIL`).
- [ ] `security-auditor` = GO / 0 blockers (mandatory for Phase 13).
- [ ] `ux-reviewer` run; `compliance-reviewer` sanity (truthful records, AI labels, caps intact).
- [ ] No secrets in the diff (`git grep` for key patterns).
- [ ] `production-readiness.md` items checked or marked operator-gated.
