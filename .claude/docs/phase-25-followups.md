# Phase 25 — deferred follow-ups (editing the post's text)

Deliberately NOT done in the phase. Each has enough detail to act on directly.

## Needs a decision before it can be done

- **The platform limits are UNVERIFIED, and that is why they only warn.**
  `config/platforms.php` carries Instagram 2200/30, TikTok 2200/30, YouTube
  5000/15. These are platform *product* limits, not anything checked against a
  documented API contract, and the integrations rule forbids asserting what has
  not been verified. Before any of them may BLOCK a save, the number has to be
  confirmed against that platform's current documentation — and re-confirmed
  periodically, because they move. `ContentGate` is written so flipping one is a
  branch, not a rewrite.

## Behaviour / product

- **Hashtags are one shared list for all three platforms.** That is how the
  generator produces them (`hashtags: []`, not per platform). Per-platform tag
  editing means changing the generated shape, which then widens the publish read
  path and the slop history reader too.
- **No "write me another one".** The editor can restore what Kuyash wrote, but
  cannot ask for a fresh draft. That is a second generation call with its own
  cost and its own compliance pass — a separate piece of work.
- **The YouTube title is still derived from the caption's first line** and capped
  at 100 characters by the adapter. Editing the caption silently changes the
  title, and there is no separate title field. Worth surfacing at least as a
  preview line.
- **No revision history.** Storing the edit over `captions` keeps exactly one
  previous state (`captions_ai`, the AI's own). A second edit overwrites the
  first with no trace beyond the event log. A real history needs the separate
  table this phase deliberately did not build.
- **What was actually published is still not archived.** `posts` has no caption
  column; "what went out" is answered today by the current contents of
  `jobs.result_json`. Correct while nothing edits after publishing (the editor
  locks then), but a permanent post-text archive is its own job.
- **Bulk editing** — one run at a time, by design.

## Raised by the closing reviewers, deliberately deferred

- **With scripting off, nothing stops Approve from discarding unsaved text.**
  The editor and the approve button are two sibling forms, so the server never
  sees the typed text when Approve is clicked. Today's defences are the
  always-visible line above the button and the JS confirm; both were added for
  exactly this. A real server-side belt means putting the editor's fields inside
  the approve form (`formaction` on the save button) and having
  `QueueController::approve` refuse — or save — when a submitted body differs
  from what is stored. That restructures a partial shared with `/runs/{id}`,
  where there is no approve form at all, so it is its own piece of work.
- **A hash mismatch is unrecoverable.** `ZernioPublishExecutor` dead-letters the
  publish permanently; the run then reads `failed`, and `lockReason()` returns
  `run_stopped`, so the text can never be corrected and re-saved. That is the
  right default for "this text did not pass the gate", but it leaves no operator
  remedy. Consider re-opening the editor for a run whose ONLY failed job is
  `publish` with a `content.edit_unverified` cause, so re-saving through the gate
  re-arms the hash and the existing retry can succeed.
- **The AI notice is written in the workspace owner's locale**, so switching the
  UI language changes the character count without changing what publishes (it
  moves 158 → 161 between the EN and TR screenshots). Pre-existing ADR-021
  behaviour; this is the first screen where it is visible, and nothing says so.
- **The gate still cannot photograph `disclosure_off` or `locked_publishing`.**
  Both need workspace settings or job states that would change other screens'
  screenshots. The seed now covers editable, edited, near-limit, read-only and
  two-editors-on-one-screen.

- **A missing interpolation param renders as the literal token.** `I18n::interpolate`
  leaves `{user}` on screen when a value is absent or non-scalar. That is how a
  seed slip surfaced as "Post text edited by {user}" on the compliance feed — the
  product code always passes a real address, but a fallback for actor
  placeholders would mean an incomplete event row can never show template syntax
  to an operator. Shared helper, so its own change.
- **The queue card states the same two facts twice** — the meta chips ("AI label
  will be set", "checks passed") and, ~400px lower, the note "Compliance: passed ·
  AI label required". Pre-existing; the widened chip row makes it more visible.
- **The dashboard's warn chip is not actionable** — "one thing to check" sits
  beside Approve & publish with no way to learn what without opening the run.

- **The showcase run in `bin/visual-seed.php` depicts a chain that cannot happen.**
  Run #2 has `script_draft` still `awaiting_approval` while its caption, hashtag,
  compliance and render_review rows are already done — the pipeline stops at an
  approval gate, so nothing downstream could have run. It also has no TREND /
  IDEA / VOICE / VISUALS / ASSEMBLE rows, so those nodes read "pending" on a run
  that produced captions. Pre-existing (Phase 21 showcase seed) and untouched
  here; fixing it means deciding what the queue's flagship card should show,
  because either the script gate or the downstream rows has to go.

- **The dashboard's accounts card can still take the page down.** `Cockpit::snapshot`
  reads `AccountRepository::listFor`, which LEFT JOINs `account_metrics` — the
  second-newest table on that page, and so the next one to go missing on a
  database behind on its migrations. It is a side card: the dashboard is fully
  useful without it, exactly like the plan line. It was NOT guarded here on
  purpose: catching it and returning `[]` would reproduce the bug just fixed —
  a failed read borrowing the "you have none" wording — so doing it properly
  needs the same third state the plan line now has, on a card this change was
  not asked to touch. Guard it there and stop; the reads that remain
  (`kpis`, `activeRuns`, `awaiting`, `nextPublish`, `business`) ARE the
  dashboard, and if `runs` or `jobs` cannot be read there is nothing honest
  left to render — those must keep failing loudly.
- **`error_log()` does not reach the app log.** The guard logs the same way the
  rest of the codebase does (13 call sites), but that goes to the SAPI log,
  while every Kuyash exception goes to `storage/logs/app-YYYY-MM-DD.log`. An
  operator tailing the app log never sees a degraded plan band. Pre-existing
  convention, worth revisiting as one change across all the call sites.

## Correctness / ops

- **Slop is re-scored at save only.** The reasoning is in ADR-023: corpus drift
  would let a post approved on Monday be blocked on Friday by other runs. The
  publish-time hash check is what keeps "no edit bypasses compliance" true. If
  that trade ever needs revisiting, the place is `ZernioPublishExecutor`'s guard.
- **Edited text becomes future slop history.** `SlopScorer::historyTexts` reads
  `captions`, which is now the edited value — right, because that is what the
  audience saw, but worth knowing when reading old scores.
- **The rate limiter keys on `REMOTE_ADDR`** like the others; behind a proxy
  every client collapses to one bucket. Same pre-existing note as Phase 24.

## Copy / a11y

- **"of about 2200 characters"** leans on the word "about" to carry the fact that
  the number is unverified. If limits ever become verified, that word should go.
- **The counter is live only with scripting on.** The server-rendered count is
  correct either way, but with JS off it updates on save rather than as you type.
