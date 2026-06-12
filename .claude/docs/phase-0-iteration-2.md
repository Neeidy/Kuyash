# Phase 0 — Iteration 2 Spec (BINDING; user-approved 2026-06-11)

User verdict on redesign v2: visual identity approved, but five gaps + an approved improvement package. This iteration stays within Phase 0 scope (phase-0-demo/ only, all mock, zero external runtime requests). ui-style-guide.md remains binding.

## A. Dashboard → Mission Control (info-dense monitor, not a greeting page)

Rebuild Dashboard as a live monitor answering "what is my system doing right now" at a glance:

- Live KPI strip (counts tick): publishing today, in pipeline now, awaiting approval, failed, credits left vs budget cap
- **Active runs panel:** currently flowing pipeline jobs with per-stage progress (stage chips lit along TREND→…→PUBLISH), each row opens the Detail Drawer
- **Awaiting approval strip:** render thumbnails (9:16 minis) with one-click open-to-approve
- Account health strip: per-account status dots + today's post count vs daily cap
- Cost meter (today/this month vs budget) + trend ticker (existing marquee)
- Compact live activity feed (existing FLIP stream)
- Keep density HIGH: this screen may break the whitespace-first rule — it is the one deliberately dense screen (bento grid).

## B. Motion Visibility Pass (user has Reduce Motion OFF and still cannot feel the motion)

Current motion is too subtle. Amplify perceptibly while keeping the premium bar:

- View Transitions: increase travel (content slides 16–24px + fade, not 4px), stagger headers vs body
- Stagger reveals: raise per-item delay to 50–60ms and travel to 12–16px so cascades are visible
- Count-ups: slower (800–1200ms) and visible on every dashboard KPI on load AND on tick
- Drawer/modal entrances: clear slide (drawer translates full 100%→0 with --spring feel on expand)
- Hover lifts: -3px + stronger shadow step
- Add choreography moments: approval action → card check-flash then FLIP-exits the queue; publish mock → brief success pulse on the account chip
- Hard cap stays: 60fps, compositor properties, reduced-motion zeroes all.

## C. Global Detail Drawer (the pattern from the user's reference screenshot)

One reusable right slide-over component (≈420–480px, @starting-style entrance, Esc/overlay closes, focus management):

- **Render/Queue item drawer:** compliance score breakdown (per-check bars), status timeline (QUEUED→PROCESSING→READY→PUBLISHED with timestamps), linked assets/script, audit entries, primary actions (Approve / Reject / Retry)
- **Trend drawer:** source, velocity history mini-chart, recommended format + why, "Create from trend" action
- **Account drawer:** health history, today's posts vs cap, recent publishes, reconnect action
- Every clickable list row/card in Queue, Trends, Accounts, Library opens its drawer. Replaces ad-hoc detail panels.

## D. Create Composer — primary manual pipeline entry

Promote creation to a first-class flow (sidebar top: "+ Create" prominent button → full-screen composer; Quick Create tab content merges into this):

1. **Media step:** upload mock (e.g. user's cat photo) or pick from Library/Stock
2. **Prompt step:** prompt editor with THREE modes (segmented control):
   - **Claude-assisted** — co-write: user types intent ("kedim mutfakta yemek yapsın, arkada şöyle bir mutfak..."), assistant refines into a production prompt (mock conversation UI)
   - **ChatGPT-assisted** — same pattern, second provider
   - **Manual** — plain textarea, no assistance
3. **Settings step:** target platforms, duration, voice on/off, music note
4. **Pre-flight panel:** estimated cost breakdown (TTS chars, AI-video seconds → credits) + mandatory AI-label notice + credit-gate (insufficient → blocked with reason)
5. **Launch:** pipeline starts (mock) → user lands on Queue with the new run animating in.

Stack note (user-approved): prompt-assist providers sit behind the TextProvider adapter; Anthropic Claude API is approved as an optional second text provider (real implementation in Phase 5; Phase 0 is mock UI only).

## E. UX additions (all mock in Phase 0)

1. **Command Palette (⌘K / Ctrl+K):** fuzzy actions — go to screen, approve render X, create content, switch workspace, toggle language. @starting-style entrance, keyboard-first.
2. **Keyboard approval flow:** in Queue/approval strip — J/K navigate, A approve, R reject, Enter opens drawer. Visible keycap hints.
3. **Platform-real preview:** approval drawer + Post Preview render the 9:16 content inside IG/TikTok/YT UI skins (platform chrome mock) with caption overlay per platform tab.
4. **"Why?" expandables:** ideas, scores, compliance results carry a disclosure ("Why? → from trend X, score components, policy v1 checks") fed by mock audit data.
5. **Density toggle:** comfortable/compact (CSS var switch, persisted like language).
6. **Teaching empty states:** every empty screen = one-line explanation + single CTA into the right flow.

## F. Engineering items (DO NOT build in Phase 0 — recorded for their phases)

Already added to phase-plan.md: self-healing queue watchdog + append-only event log (Phase 4), seeded variation engine (Phase 5), draft-first rendering + content-addressed asset cache (Phase 7), real cost pre-flight (Phase 11). Phase 0 only mocks the cost pre-flight UI (composer) and the event timeline UI (drawer).

## Acceptance (in addition to the existing 13 + 6)

1. Dashboard answers "what is running right now" without any click (active runs visible with stage progress)
2. Motion is perceptible: a first-time viewer notices screen transitions, cascades, and count-ups unprompted
3. Detail Drawer opens from Queue, Trends, Accounts, Library rows; Esc closes; focus returns
4. ⌘K palette opens, filters, navigates; J/K+A/R works in Queue
5. Create composer completes the full mock journey (media → 3-mode prompt → pre-flight with cost + AI-label → launches into Queue)
6. Platform-skin preview shows per-platform variation tabs
7. Density toggle + "Why?" expandables functional
8. Still: zero external requests, no libraries, no console errors, 375/768/1280 responsive, reduced-motion zeroes everything
