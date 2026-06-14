# Phase 19 — Live layer / SSE (plan)

> `/go` loop, stacked on `feat/phase-18-pipeline-viz`. The ONE phase with a real backend surface —
> keep it isolated and SAFE. Gates: **security + ux (mandatory)** + qa. Spec: `experience-layer-plan.md` §7.

## Goal
Make the dashboard feel alive: a topbar "Live · updated just now" indicator (heartbeat dot) and a
live-ticking awaiting count, fed by a pure-PHP SSE endpoint bound to real dev-DB state. Degrades to
the static server render when JS/SSE is absent.

## Design — IMMEDIATE-CLOSE SSE (deliberate, safe)
A held SSE connection on the single-threaded dev server (`php -S`) would block every other request
(including the visual harness → H2) and hold the PHP session lock. So `/live` emits ONE snapshot
event + a `retry:` directive and CLOSES immediately; the browser's `EventSource` reconnects every few
seconds. This is reconnect-polling shaped as SSE: genuinely live (refreshes on each reconnect), but
with **no long-lived connection, no long transaction, no held session lock, no resource exhaustion** —
which is exactly what the heavy security gate wants, and it never stalls the harness.

## Scope (in)
1. `src/Workflow/Cockpit.php` — `liveSnapshot(int $ws): array` (public): ONE tiny tenant-scoped SELECT
   returning `{active, awaiting}`. Read-only.
2. `src/Controllers/LiveController.php` (new) — `stream()`: auth-guarded, tenant-scoped; builds the
   snapshot, calls `session_write_close()` to drop the lock, returns a `text/event-stream` Response
   (`retry: 5000` + `event: snapshot` + `data: {...}`). No write, no loop, no sleep.
3. `src/routes.php` — `GET /live` behind `$protected`. `src/bindings/web.php` — LiveController binding.
4. `public/assets/js/live-client.js` (new) — `EventSource('/live')`, on `snapshot` updates the topbar
   indicator (+ awaiting KPI if present); silent graceful degradation (no EventSource → return).
5. `templates/layout/app.php` — topbar live indicator (`html.js`-gated so no-JS hides it — honest);
   load live-client.js. `dashboard.php` — `data-live-awaiting` on the awaiting KPI.
6. `app.css` — `.topbar__live` + heartbeat dot (opacity pulse, §1.2-approved for a live state,
   reduced-motion-zeroed).
7. `lang/en+tr` — `live.label` / `live.updated` (parity).

## Scope (out)
- A held/streaming long-lived connection (rejected: unsafe on php -S + harness). No websockets, no new
  queue, no real external calls, no WRITE path, no new DB table/migration.

## Acceptance
- [ ] `/live` returns `text/event-stream` with a valid `snapshot` event + `retry`; closes immediately.
- [ ] Tenant-scoped: another workspace gets its own counts; unauth → redirected (route guard).
- [ ] Topbar shows the live indicator (JS on); hidden with JS off; static page fully works either way.
- [ ] No long transaction, no held session lock, no secrets; heartbeat reduced-motion-zeroed.
- [ ] Harness unaffected: 0 console errors / 0 overflow / exit 0 (the immediate-close /live must not stall it).
- [ ] Full suite green + new SSE tests.

## Tests
- LiveController: returns event-stream content-type + a parseable `data:` JSON snapshot; tenant
  isolation (two workspaces → different counts); no write (DB unchanged after a stream call).
- Cockpit::liveSnapshot: counts active/awaiting correctly, workspace-scoped.
- i18n parity for the new keys.

## Security notes (heavy gate)
- Auth: `/live` is `$protected`. Tenant: snapshot keyed by `$workspace->id()` (session workspace).
- No resource exhaustion: immediate close (no loop/sleep) — each request is O(1) short read.
- Session lock released via `session_write_close()` before returning.
- Output: the SSE body is `json_encode` of integer counts + an ISO timestamp — no user/HTML content.
