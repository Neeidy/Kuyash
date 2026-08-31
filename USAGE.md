# Kuyash — Operator's Guide

How to run Kuyash and work a day inside it. For what the project *is* — the
architecture, the stack, the guarantees — see [README.md](README.md).

---

## 1. Before you start

- **PHP 8.3**, with `ext-pdo_sqlite` and `ext-curl`. No Composer, no build step.
- **ffmpeg** — this is what actually renders video. It is not looked up on `PATH`:
  set `FFMPEG_BIN` in `.env` to its absolute path (the built-in default assumes a
  Homebrew install on macOS).
- Every external provider is **mock-first**. Copy `.env.example` to `.env` and Kuyash
  runs end to end with **no API keys at all**: it still writes real files and real
  9:16 renders, it just doesn't call anyone or publish anything.

```bash
cp .env.example .env
php bin/migrate.php
```

`bin/migrate.php` creates the SQLite schema (WAL) and is safe to re-run — migrations
are additive and forward-only.

You'll need one account to log in with. There is no sign-up page, by design:

```bash
php bin/create-user.php      # prompts for email, name, workspace and password
```

It creates the user and their first workspace together. The password is read from
stdin with echo disabled, never from a command-line argument.

---

## 2. Running it

Kuyash is **two processes**: a web server (what you look at) and a worker (what does
the work). Start each in its own terminal, from the repository root.

**Terminal 1 — web server**

```bash
php -S 127.0.0.1:8082 -t public public/index.php
```

**Terminal 2 — worker**

```bash
php bin/worker.php
```

Then open <http://127.0.0.1:8082/login>. `Ctrl+C` in each terminal stops it.

If the worker isn't running, the queue page says so plainly rather than looking idle —
nothing is silently stuck.

---

## 3. The screens

| Screen | What it's for |
|---|---|
| **Dashboard** | The day at a glance: what's waiting on you, what's running, budget, connected accounts, this week's plan |
| **Create** | Quick Create — turn a photo plus a prompt into a vertical clip (see §5) |
| **Trends** | Trend Radar: rising topics in your niche, and *"Create from trend"* |
| **Library** | Your own clips and photos — the source material videos are built from |
| **Workflows** | The two production templates (full pipeline, distribution-only) and their fixed step order |
| **Queue** | Everything in production, and the **approval cards** where you approve or reject |
| **Weekly plan** | A calendar of publishing times, and what is on each day |
| **Accounts** | Connected Instagram / TikTok / YouTube accounts, their health, and today's post count against the cap |
| **Logs** | The append-only audit trail: compliance decisions with their policy version, guardrail events |
| **Digest** | The daily summary — what went out, what was blocked, what needs you |
| **Usage** | Charges, month-to-date spend, budget cap, cost per render |
| **Settings** | Approval mode, guardrails, the weekly plan and timezone, per-platform AI disclosure, kill switch |

---

## 4. A day in the system

1. **Connect an account** — *Accounts → Connect*. OAuth tokens live at Zernio;
   Kuyash stores only an account reference and its health.
2. **Set your niche** (per workspace). Trend Radar reads it to know what to scan.
3. **Start something.** Three ways in — see §5.
4. **The worker builds it.** Jobs run in order: script, voice, visuals, assembly,
   caption, hashtags, compliance. Watch it on Queue or Logs.
5. **Approve it.** In Manual mode (the default) an approval card appears in the
   Queue with the video, the post text and the compliance result. You can edit
   the caption and hashtags there before approving.
6. **It publishes** — immediately, or at the time you picked, or on its weekly slot.
7. **Check what happened** — Digest for the day, Logs for the audit trail, Usage
   for what it cost.

**A render with no preview cannot be approved.** The button is withheld and the
server refuses the request, because an approval record says a person watched the
video — and it has to be true.

---

## 5. Three ways content starts

- **Trend-driven** — Trend Radar → *Create from trend*. Runs the full pipeline:
  research → script → voice → visuals → assembly → compliance → publish.
- **Distribution-only** — upload your own finished video to the Library and let
  Kuyash do captions, hashtags, compliance and scheduling. This is the fastest
  path, and the one this operator uses most.
- **Quick Create** — a photo plus a prompt becomes a 15–45s vertical clip. No
  trend, no script, no voice: your prompt is the brief. Output is always
  AI-labelled and is charged against the workspace budget cap.

---

## 6. Approval: Manual and Auto

**Manual (default).** Every render waits for you. The record stores your account
and the timestamp, and the screen says *"Approved by you"* — to you, and it names
the account that decided.

**Auto** (you turn it on in Settings). The compliance agent approves low-risk
items itself; anything it is unsure about still comes to you. Those records read
*"auto-approved by the compliance agent (policy vX)"* and are **never** dressed up
as human approval — not in the UI, not in the logs, not in the digest.

Auto mode is capped: a limited number of automatic approvals per day, and a
quality-score drop puts the workspace back into Manual on its own.

---

## 7. The guardrails (Settings)

| Guardrail | What it does |
|---|---|
| **Daily post cap** | Per account. A publish that would exceed it doesn't go out. |
| **Monthly budget cap** | Per workspace, with a **cost pre-flight** — a run that can't afford to finish never starts, so you don't pay for half a video. |
| **Kill switch** | Stops everything immediately. |
| **Auto → Manual fallback** | Triggered by a quality-score drop; the system reduces its own autonomy. |
| **Daily digest** | One page telling you what the system did without you. |

---

## 8. The weekly plan

The plan is a calendar of publishing times, each with one of two modes:

- **I add the video** — you drop a Library clip on a day, and it goes out at that time.
- **Kuyash makes one** — the system produces something for that slot ahead of time.

Either way it still passes compliance and an approval gate before it publishes.
Times follow the workspace timezone, including across daylight-saving changes.

---

## 9. Keeping it running

While Kuyash runs on your machine, the loop lives as long as those two terminals
do. Close them, or let the machine sleep, and production stops. That's fine for
working sessions; it is not an always-on system.

For genuine 24/7 operation, move it to a small always-on server (a €5/month VPS is
enough) and run the worker under `systemd` or supervisor. Caddy and Cloudflare
Tunnel are already the stack for exactly this. **This changes no application
code — it's deployment only.** See `.claude/docs/production-readiness.md` for the
checklist.

---

## 10. Where things actually stand

Honest status, so nothing here reads as a bigger claim than it is:

- **Publishing is integrated, and real.** The Zernio adapter is implemented against
  the live API schema, and real AI-assisted Reels have been published to Instagram
  end to end. It ships **mock-first**: `ZERNIO_MOCK=true` is the default, and the
  mock exercises success plus every failure mode without touching a live account.
  Set `ZERNIO_MOCK=false` with a key to publish for real.
- **Video quality is stock + text-to-speech.** That is functional, not cinematic.
  Quick Create's AI image-to-video path is deliberately **doc-gated**: with
  `VIDEO_MOCK=true` an offline ffmpeg mock produces a real 9:16 AI-labelled clip at
  zero cost, and the real provider adapter refuses to make a live call until its
  integration notes are supplied. No live AI-video generation has run.
- **This is a personal-first V1.** One operator, one workspace in use. The schema is
  multi-tenant from day one, but Stripe billing, a multi-tenant UI and onboarding
  are parked for a later SaaS phase.
- **There is no public hosted demo.** Kuyash runs locally behind Caddy and a
  Cloudflare Tunnel. The evidence is the code, the test suite and real screenshots.

---

## 11. The demo dataset (and how to remove it)

There is a labelled showcase dataset for screenshots and walkthroughs. It matters
that you can tell it apart from your real work:

```bash
php bin/demo-seed.php --yes        # install it
php bin/demo-teardown.php --yes    # remove exactly what it wrote
```

- Everything it creates is marked `[SAMPLE]` wherever an operator can see it.
- It is **inert**: it never triggers the worker, never spends money, never publishes,
  and never writes to the append-only audit log.
- Teardown removes precisely its own rows and files, and refuses to touch anything else.
- It **refuses to install** into a workspace in Auto mode, or one with live publishing
  switched on, unless you explicitly override — because demo runs would otherwise
  become evidence in the compliance agent's own quality window.

This is one half of a rule that holds across the product: **every fabricated figure
on screen carries its marker, and an unmarked number is a measured one.** A demo
account shows sample metrics with a `SAMPLE` chip; a real connected account never
wears invented ones, even chipped.

---

## 12. Checking your install

```bash
php tests/run.php                  # the full suite
php bin/health.php                 # logs in and checks every route's status AND body
tools/visual/gate.sh               # headless screenshots: console errors, overflow, missing previews
```

`bin/health.php` takes its credentials from `HEALTH_EMAIL` / `HEALTH_PASSWORD` in the
environment — there is no default in the file. It only accepts an `http`/`https` base
URL, and it refuses to send a password in the clear to anything but loopback: any
other host must be `https`.

The visual gate builds its own isolated database and never touches your real one.
