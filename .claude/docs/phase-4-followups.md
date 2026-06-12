# Phase 4 Follow-ups (deferred by design/review, NOT forgotten)

Status: final at phase close. Reviewer SHOULD-FIX items (security ×1,
php-architect ×1, ux ×6) were ALL APPLIED during the phase with regression
tests (suite: 285 PASS / 0 FAIL), plus the cheap nice-to-haves (opaque worker
id, 1970-backoff guard, maintenance cadence hoist, index-aligned ORDER BY,
sweep unlink logging, status label map, compliance tone, confirm-on-reject,
record-chip wrap, dashboard copy, aria-current/labels). Below is the
deliberately deferred tier.

## Plan deviations (accepted, documented)

- **Plan said "full chain 14 jobs"** — the canonical 12-node full template
  expands to **13 jobs** (the 13 listed job types, each exactly once;
  PUBLISH → render_review + publish). 14 was a plan-author miscount; the
  same plan's own "Job tipleri (13)" list and distribution=8 confirm 13.
- **worker_id format** — plan specified `host:pid:4hex`; security review
  flagged the hostname leaking into the tenant-visible event feed
  (job.claimed params). Shipped as opaque `w{pid}-{4hex}`.
- **GET /workflows performs the idempotent default seed** (ensureDefaults).
  Accepted by security review while it stays parameter-free; move seeding to
  login/migration if it ever takes input.

## Deferred to later phases (from reviews)

### Security (nice-to-haves)
- **"Approved by you" wording is viewer-independent** — truthful in V1
  (single user; email + mode rendered beside it). When multi-user UI lands
  (V2), compare decided_by to the session user and render
  "Approved by {email}" otherwise. Compliance-wording hazard, not a defect.
- **Executor error strings flow into UI/events (escaped)** — fine for mocks;
  Phase 5/7/10 adapter reviews MUST enforce that vendor exceptions never
  embed API keys/signed URLs in messages (integration-reviewer checklist).
- **Engine::finalize throwing inside the tx kills the worker process**
  (e.g. a future executor returning invalid UTF-8 → JSON_THROW_ON_ERROR).
  Watchdog rescues the row, but add a targeted failed-attempt fallback when
  real executors arrive (Phase 5/7 entry item).

### Architecture (nice-to-haves)
- **EventLog ignores the injected clock** (uses gmdate; ordering is by id,
  by design). Accept or pass the Clock closure when timestamps start to
  matter (Phase 7 durations).
- **Autoloader duplicated** in bootstrap.php / bootstrap-worker.php —
  extract src/autoload.php when a THIRD entrypoint appears.
- **ExecutorRegistry bound in core bindings** though only the worker
  executes — move to bindings/worker.php if "web never executes" should be
  structural; web controllers never resolve it today (lazy factory, no cost).
- **Claim-SQL literal duplicated in one test** (raw double-claim proof) —
  behavioral tick-order tests cover drift; tolerated.

### UX (nice-to-haves)
- Render-review approval card lacks an entity line (workflow name / asset
  title) — add when renders carry real titles (Phase 7).
- "(run #N)" suffix is noise on /runs/{id} timeline (every line same run) —
  strip per-context when the Messages event map grows a context arg.
- /logs: date dividers when the 200-row window spans days.
- Mobile node track: demo had vertical connectors between stacked cards;
  current fallback hides connectors entirely. Pure polish.

## Phase 5+ trigger items carried forward
- Library pagination + tags json_each search precision (carried from Phase 3;
  trigger: library outgrows one screenful / tags become workflow-selectable).
- Workflow settings EDITING UI (user decision: read-only in Phase 4); the
  validator already enforces the schema-light settings shape.
- reject-to-revise loop (Phase 5) — today reject = cancel run (by plan).
- Real compliance scoring/warn/block + Auto mode + guardrails (Phase 9);
  schema stubs ('auto', awaiting_recording, quick_create) already in place.
- SSE/auto-refresh for /queue and /logs when jobs become genuinely slow
  (Phase 7); plain reload is deliberate today.
