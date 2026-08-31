# Visual-Test Harness (Phase 15.9 — dev-only)

A zero-dependency headless-screenshot harness that gives the autonomous loop's
**VISUAL gate** real eyes. It drives the **already-installed system Chrome** over
the DevTools Protocol using only Node built-ins — **no npm install, no
`package.json`, no Playwright**. The product app stays build-free.

> This whole directory is **dev tooling**, not product code. It is never served,
> never shipped, and touches no PHP/DB/route/template/CSS.

## What it does

`gate.sh` is the single entry point. It:

1. Resets an **isolated** visual DB (`storage/database/kuyash-visual.sqlite`) —
   never the real dev DB.
2. Migrates it and seeds deterministic mock content (`bin/visual-seed.php`).
3. Starts `php -S` with `APP_ENV=dev` (so the session cookie is **not**
   Secure-only and headless http login works) + mock providers.
4. Waits for `/health`, then runs `shot.mjs`, then tears the server down.

`shot.mjs` logs in once, then for every screen × width × locale:
- waits for load, records **console errors** (JS exceptions, `console.error`,
  network failures — `favicon.ico` excluded as browser noise),
- measures **horizontal overflow** (`scrollWidth − innerWidth`),
- captures a full-page PNG.

It writes `summary.json` and **exits non-zero** if any page had a console error
(exit `1`) or the harness/login failed to set up (exit `2`). Exit `0` = every
page clean. That non-zero exit is what lets the gate genuinely FAIL.

## Usage

```bash
# full run — every screen at 375/768/1280 × EN/TR  (~69 PNGs)
tools/visual/gate.sh --out storage/visual/baseline

# self-test — one screen → exactly 6 PNGs (3 widths × 2 locales)
tools/visual/gate.sh --only /dashboard --out storage/visual/selftest
```

Output PNGs and `summary.json` land in `--out` (default `storage/visual/run`),
which is gitignored. Extra args after `gate.sh` are forwarded to `shot.mjs`.

## How the loop's VISUAL gate uses it

The orchestrator runs `gate.sh` for the phase under test, then hands the PNG
paths in the output dir to the **`ux-reviewer`** subagent (the `Read` tool
renders PNGs, so the reviewer literally sees each screen) along with the §1.2
motion rules and the pass/fail checklist. The exact gate prompts live in
`.claude/docs/loop-gates.md`.

## Config (env)

| Var | Default | Purpose |
|---|---|---|
| `PHP_BIN` | `php` (from `PATH`) | PHP 8.3 binary — set this to pin a specific build |
| `NODE_BIN` | `node` | Node (≥ v22 for built-in `WebSocket`/`fetch`) |
| `CHROME_PATH` | `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome` | Chrome binary |
| `VISUAL_PORT` | `8099` | dev-server port (dodges the busy 8080/8082) |
| `VISUAL_TEST_EMAIL` | `visual@kuyash.local` | seeded login (shared by seed + harness) |
| `VISUAL_TEST_PASSWORD` | `visual-dev-only-password` | seeded login password — **dev-only fixture, never printed; the visual DB is gitignored** |

## Files

- `gate.sh` — orchestrator (seed → serve → shoot → teardown).
- `shot.mjs` — zero-dep CDP screenshot driver.
- `routes.json` — screen inventory (paths, widths, locales).
- `../../bin/visual-seed.php` — idempotent, media-free mock seed.

## Why media-free seed

The seed never references render/asset files on disk, so no `<img>`/`<video>`
points at a missing file → no 404 → no console error. Media-bearing screens
(library, quick, digest) render their **empty states** — which the visual gate
wants screenshotted anyway. A populated-but-honestly-green baseline.
