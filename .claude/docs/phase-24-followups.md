# Phase 24 — deferred follow-ups (the plan as a calendar)

Raised while building, or by the closing gates, and deliberately NOT fixed in the
phase. Each has enough detail to act on directly.

## Behaviour / product

- **Fully-unattended publishing (Task 8, the phase's own deferral).** An automatic
  run stops at `script_draft`, which is a human gate. Letting the compliance agent
  approve that too would widen ADR-015's LOCKED auto-approval scope — and there is
  no compliance verdict at `script_draft` to base it on. Needs an explicit product
  decision plus a compliance-reviewer GO, not a code change alone.
- **Per-account publishing at different times.** `publish_slots.account_id` is still
  read by nothing; a run publishes to all its targets at one instant. Unchanged from
  Phase 23 — it needs per-target publish jobs or per-target `run_after`.
- **Caption/hashtag editing on the approval screen.** The single most valuable
  addition for the distribution-only operator (upload your own videos, let Kuyash
  only schedule and publish): today the AI writes them and you can only approve or
  reject. Its own ticket, deliberately outside this phase.
- **Re-timing an assigned day.** You can take content off a day and put it on
  another, but not drag it. Fine for a two-week view; worth revisiting if the
  horizon grows.
- **A slot whose day/time is edited.** There is no edit — remove and re-add, which
  cascades (with confirmation). An in-place edit would need the same
  "already-committed days are not moved silently" rule that the timezone change has.

## Correctness / ops

- **`OccurrenceRepository::window()` uses correlated subqueries** (publish status,
  awaiting job, post counts) — five per row. Fine at ~30 rows for a two-week
  window; if the horizon ever grows, fold them into joins.
- **`SlotResolver::nextAmong()` is still production-dead** (Phase 23 finding). The
  queue resolves slots itself because it needs whole rows. Either use it or drop it.
- **`Cockpit::nextPublish()` and `PostRepository::nextScheduled()` remain the same
  query in two places** (Phase 23 finding, untouched here).
- **A cell blocked by `budget_cap` is retried on every chore tick** until its time
  passes. The audit line is written only once (the reason has not changed), but the
  preflight runs each time. Harmless — it is one cheap query — but a short backoff
  would be tidier.
- **Retention prunes cells 30 days past their time**, including `skipped` ones. The
  record of what was missed survives in `events`; if the digest ever wants to report
  further back than 30 days, that is the constraint to revisit.

## Copy / a11y

- **The calendar is an `<ol>` of days, not a table.** Correct for the 375px day
  list; at 768px+ it renders as a grid, where a screen reader gets no row/column
  relationship. A `role="grid"` treatment (or an explicit "week of" heading per row)
  would read better.
- **Cell state colour carries meaning alone** for scheduled/published (accent) and
  missed/stopped (`--err`). The text label always states the state too, so nothing
  is colour-only, but the two error-ish states are not otherwise distinguished.

## Left open by the closing gates (fixed items are NOT listed here)

Every blocker the three gates raised was fixed in the same round and pinned by a
test in the `p24/gatefix` group. What follows is what they raised and we chose
NOT to do.

- **Rate-limit buckets key on `REMOTE_ADDR` (security L3).** Behind Caddy →
  Cloudflare Tunnel with no `CF-Connecting-IP` handling, every remote client
  collapses to one IP, so one stuck script exhausts the bucket for everyone.
  Pre-existing (`LoginThrottle` has the same shape) and harmless at V1's
  single-operator scale; at SaaS-ification these authenticated endpoints should
  key on `workspace_id`/`user_id` instead, which is the right blast radius.
- **`GET /plan` performs writes (security L4).** `index()` materializes the
  calendar so the screen works before the worker has ever run. Idempotent and
  bounded (times × 14 days), but it is a state-changing GET outside the CSRF
  gate. The clean version lets the chore own materialization entirely.
- **`cancelRun` ignores in-flight `posts` (security L1).** The guard covers the
  publish JOB (`processing`/`published`) but not a dead-lettered job that already
  left a target in `posts.status='publishing'`. The reconciler could still land
  that post after the run is stamped cancelled. Add the `posts` EXISTS clause.
- **Defence-in-depth on the new worker seams (security L6).** `startRunFor`,
  `cancelRun`, `setPublishAfter` and `materialize` take raw ints with no
  membership assertion. Every caller derives them from scoped queries today, so
  nothing is reachable — but they are public seams now.
- **The week grid is anchored to today, not to a weekday (ux S12).** Columns
  shift one position per day and there is no weekday header row, so it reads as a
  7-wide list rather than a week. Aligning to week boundaries would also let the
  grid carry proper column semantics.
- **"run #N" vs "content #N" (ux S7).** Two names for the same thing now appear
  on the same screen. Pre-existing from Phase 21; the queue and dashboard were
  not in this phase's scope.
- **The video picker truncates in a grid column (ux S1).** ~110px cannot show a
  full title. Options now carry `title` and the open dropdown shows them in full,
  which is standard select behaviour — but a wider layout (or a different
  control) would be better than relying on it.
- **`plan.approval_promise` is still a single sentence for two modes (ux N-level).**
  It now branches on `approval_mode`, but a workspace running Auto sees a longer,
  more hedged line. Worth shortening once the Auto copy settles.

## Left open by the UI-fix round (themed controls + discoverable manual path)

- **The photo picker's radio on a dark thumbnail (ux S2).** `/quick`'s radio sits
  ON the 9:16 image. The global control style replaced a light native control
  with a token-dark one, so it was given a dark halo + `--text-2` border to keep
  it visible either way — but the visual seed has no photos, so that instance is
  **not covered by a screenshot**. Verify it the first time a real photo is in
  the library.
- **The calendar's video picker at 768px (ux S5).** A 7-column grid leaves the
  select ~85px, so every title truncates to the same prefix and the chevron
  overlaps the text. Each `<option>` carries a `title`, and the open dropdown
  shows the full names, so it is recoverable — but the closed state is not
  useful. Pre-existing; the real fix is a wider layout or a different control.
- **The automatic option's price is only shown once that option is selected
  (ux N1).** Defensible — it appears at the moment of choosing, before submit —
  but for a workspace with no times at all the number exists nowhere on the page
  until the radio is clicked.
- **A checked control's fill and its focus ring are both `--accent` (ux N3).**
  Separated only by the 2px outline offset. Legible on these dark surfaces;
  would collapse on a light one.
- **`settings.auto_slots` says "auto slots used today" (ux N4).** The last
  "slot" left in user-visible copy. Pre-existing on `/settings`, outside both
  Phase 24's and this round's scope.
- **`quick.no_photos_1` / `no_photos_2` compose with a stray double space and an
  orphaned period** ("…or add some in the  Library  ."). Pre-existing.
