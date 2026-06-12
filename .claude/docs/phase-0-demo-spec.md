# Kuyash — Phase 0 Demo Spec (Static Clickable Dashboard)

## Purpose
Validate the FULL product vision as a clickable offline mock before any backend: trend discovery → content creation → production visibility → compliance/approval → multi-account publishing → operations.

## Constraints
Static HTML/CSS/vanilla JS only; mock data centralized in data/mock-data.js; NO backend/PHP/DB/auth; NO external APIs; ZERO external network requests (no CDN — works via file://); no chart/graph libraries.

## The 13 screens
1. **Dashboard Home** — overview cards, scheduled posts, failed jobs, credits used, recent activity, workflow health, approval-mode indicator, compliance summary, quick actions
2. **Trend Radar** — niche selector; trend cards with source badges (Google/YouTube/TikTok-3rd-party; IG best-effort note); velocity; recommended format (face/faceless); "Create from trend"; freshness; empty state
3. **Content Studio** — idea list (draft/approved); script editor (hook/body/CTA, regenerate, per-platform caption preview); shooting-brief view with "mark as recorded"; **Quick Create tab** (photo upload mock + prompt + credit cost + mandatory AI-label notice)
4. **Content Library** — cards with thumbnails, duration, 9:16 badges, asset type (own/face/stock/AI), platform suitability, tags, status, upload mock, filter/search, empty state
5. **Workflow Builder** — canonical node line (TREND → IDEA → SCRIPT → VOICE → VISUALS → ASSEMBLE → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE → PUBLISH); VISUALS source selector (LIBRARY/STOCK/AI); connections; selection; right settings panel; preview panel; save/run-test/undo-redo mocks; JSON summary. Simple — not n8n.
6. **Render Queue** — pipeline jobs (TTS, asset fetch, render) with progress; render preview cards; compliance result per render (passed / AI-label applied / blocked + slop reason); approval queue (approve/reject in Manual; "Auto-approved by compliance agent" badges in Auto); retry mock
7. **Creators / Accounts** — workspace switcher (2 mock workspaces); 5+ accounts across IG/TikTok/YT; OAuth connect mock (button → consent step → connected); health indicators; per-account daily cap display; warning state
8. **Post Preview** — phone-style; thumbnail; per-platform caption/hashtag variation tabs; AI-label indicator; platform selector; publish/schedule mocks with confirmation dialog; missing-data warning
9. **Live Logs / Jobs** — timeline; statuses queued/processing/ready/failed/published; error example; retry; job drawer; compliance audit entries
10. **Analytics** — posts published, success rate, platform distribution, per-account performance, usage; CSS/vanilla-JS visuals only
11. **Usage / Credits & Costs** — credit balance; cost breakdown (AI text/TTS/AI video/publishing); per-workspace usage; budget cap warning; plan card mock (SaaS placeholder); upgrade mock
12. **Settings** — approval-mode toggle (Manual default/Auto) with explanation; guardrails (daily cap, budget cap, kill switch mock, digest pref); workspace settings; connection placeholders; security notes; API-key placeholder warning; notifications
13. **Onboarding Wizard** — create workspace → connect account (mock OAuth) → pick niche → review first trend → create first content → run test post

## Mock data model
workspaces, accounts, trends, ideas, scripts, briefs, assets, renders, jobs, logs, compliance_decisions, credits/usage — all in data/mock-data.js.

## UX requirements
Modern premium SaaS feel; sidebar + topbar + workspace switcher; consistent cards/spacing/typography; polished empty/loading/error states; responsive 375/768/1280px; mobile node-graph fallback = stacked cards; truthful approval badges; mock confirmation dialogs for risky actions (publish, schedule, delete, enabling Auto mode).

## Non-goals
No real anything: no uploads, no generation, no publishing, no auth, no persistence beyond page state.

## Acceptance criteria
1. All 13 screens reachable from sidebar
2. Opens via file:// — no server, no build step
3. Zero external network requests (DevTools Network clean)
4. Usable at 375/768/1280px
5. No console errors on any screen
6. Node graph: selectable nodes update settings panel; COMPLIANCE node present; connections visible; mobile stacked fallback
7. All mock data in data/mock-data.js (none hardcoded in HTML)
8. Empty/loading/error states on Trend Radar, Library, Render Queue, Logs, Usage minimum
9. No external libraries
10. Risky actions show confirmation dialogs
11. Approval-mode toggle visually changes queue behavior + badges truthful
12. Quick Create shows credit cost + AI-label notice
13. Workspace switcher changes visible mock data context
