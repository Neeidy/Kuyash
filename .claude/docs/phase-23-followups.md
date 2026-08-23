# Phase 23 — deferred follow-ups (planned publishing)

Raised by the closing gates (security-auditor, ux-reviewer) and deliberately NOT
fixed in the phase. The blockers and the fail-open they found were fixed in the
same round; what remains is listed here with enough detail to act on directly.

## Behaviour / product

- **Per-account fan-out at different times.** `publish_slots.account_id` exists
  and is tenant-validated in `SlotRepository::add()`, but nothing reads it: a run
  publishes to all its targets at one instant. The UI deliberately offers no
  per-account control (a control nothing reads would claim more than the system
  does). Shipping this needs an engine change — per-target publish jobs or
  per-target `run_after` — which is why it was kept out of Phase 23.
- **Discoverability (ux S9).** Nothing on Queue or Dashboard points at the weekly
  plan. With no slots configured the approval form shows only the exact-time
  field, and the dashboard's "Nothing scheduled" links nowhere. The two empty
  states are the natural entry points.
- **Dashboard empty state vs a configured plan (ux S3).** "Nothing scheduled —
  approved videos publish straight away" is true about the queue but reads oddly
  once slots exist. With a plan set, the honest line is closer to "Nothing queued
  — next planned time Monday 09:00".
- **Slot placement on Settings (ux S6).** The plan card sits last, after the
  read-only Quality score. An interactive card the operator returns to belongs
  nearer "Approval mode & guardrails".
- **Slot identity in the picker (ux S4).** Options show day + time only. Once
  per-account slots do something, two slots at the same weekday/time for
  different accounts would render identically.

## Correctness / ops

- **Audit events for plan changes (security L5).** `saveTimezone`, `addSlot`,
  `removeSlot`, `toggleSlot` write no `EventLog` entry, while `save()` and
  `killSwitch()` do — and these settings decide *when* content reaches live
  accounts. Record `guardrail.*` events with the acting user, matching the
  kill-switch pattern.
- **`slots.invalid` conflates two cases (security L7).** `SlotRepository::add()`
  uses `INSERT OR IGNORE` and returns null both for bad input and for "this slot
  already exists", so re-adding an existing time reports it as invalid.
- **Rate limiting on the new POST routes.** `Core/RateLimiter` already guards
  login and the webhook. The four slot/timezone routes are authenticated and
  capped at 50 slots per workspace, so the blast radius is self-inflicted, but
  they are still unthrottled.

## Copy / a11y

- **Unlabeled controls (ux S5).** In the "Add time" row the time input and (on
  the approval form) the slot select sit inside `<label>`s with no text, so they
  have no accessible name. `slots.use_slot` exists in both languages and is
  rendered nowhere — it is the missing label for the picker.
- **"Slot" jargon (ux S7).** `slots.timezone_hint` says "Slot times follow this
  zone" / "Slot saatleri…" while every other string in the card says "times" /
  "saatler".
- **TR phrasing (ux S8 + nit).** `queue.schedule_label` reads as a fragment —
  `(saat dilimi: {zone})` is cleaner. `slots.next_at` + `time.in_hours` compose
  to "sıradaki 15 sa içinde"; `sıradaki: {when}` or `{n} sa sonra` reads better.
- **Duplicate zone display.** The card head chip repeats the value of the select
  three lines below it.
- **`Save zone` button baseline.** `.slot-zone { align-items: flex-end }` aligns
  the button to the hint line rather than the select, so it sits visibly low.
