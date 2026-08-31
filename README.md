# Kuyash — compliance-first short-form content studio

**An AI-assisted studio for the full short-form loop — research → create → approve → publish → operate — built to protect the accounts it posts to.**

Kuyash discovers trends, drafts scripts and captions, assembles 15–45s vertical (9:16) videos, runs **every** output through a compliance agent, and schedules/publishes to Instagram Reels, TikTok and YouTube Shorts — with full operational visibility (jobs, logs, costs, guardrails). It is the anti-slop studio: no template-identical mass output, no fabricated approval records, no unlabelled AI media.

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-777bb4) ![No frameworks](https://img.shields.io/badge/frameworks-none-2ea043) ![Tests](https://img.shields.io/badge/tests-~1126%20passing-2ea043) ![SQLite WAL](https://img.shields.io/badge/db-SQLite%20WAL-003b57) ![License](https://img.shields.io/badge/license-see%20below-lightgrey)

---

## Status — honestly

This is a **personal-first V1**, built by a single operator to run their own content loop. It is **not** an enterprise product and there are **no paying customers**. What it *is*: a real, running system with ~1126 tests, hard guardrails, a compliance agent, and a strict prod/demo boundary — and it has **published a real AI-assisted Reel to Instagram end-to-end**.

The honest limitation: V1 produces **stock + text-to-speech** video. That is functional, not cinematic — AI text-to-video generation is a deliberately deferred direction (credit-gated Quick Create / V2), not a claim made here. There is **no public hosted demo**: Kuyash runs locally behind Caddy + Cloudflare Tunnel, so the case study shows real screenshots and this code, not a click-through URL. I won't overstate it.

---

## What makes it different

- **Compliance is architecture, not a feature.** Realistic AI media is auto-labelled per platform. A slop/variation scorer blocks near-duplicate output. Format rules (15–45s, 9:16) are enforced. Every compliance decision writes an audit-log entry.
- **Truthful approval records.** Manual mode (default) records real human approval — user and timestamp. Auto mode records *"auto-approved by the compliance agent (policy vX)"*. Records are **never** misrepresented as human approval, anywhere — UI, API, logs.
- **Autonomy on a leash.** Per-account daily post caps, per-workspace budget caps with a **cost pre-flight** (an over-budget run never starts), a kill switch, a daily digest, and automatic fallback to Manual on a quality-score drop.
- **The honesty invariant.** Every fabricated figure on screen carries a `[SAMPLE]` chip; real numbers are unmarked; a value that can't be read shows *"couldn't read"* — never a `0` or `null` that would read as a measurement. A real connected account never wears fake metrics.
- **Mock-first by default.** Every external capability (OpenAI text/TTS, Pexels, Zernio publishing, Cloudflare R2, ffmpeg) sits behind a provider-agnostic adapter with a mock implementation. Core code never names a vendor; swapping one is a single adapter class plus one config line.

## The pipeline

Kuyash's production loop is a chain of durable, idempotent SQLite jobs:

```
TREND → IDEA → SCRIPT → VOICE → VISUALS → ASSEMBLE → CAPTION → HASHTAGS → MUSIC NOTE/STYLE → PREVIEW → COMPLIANCE → PUBLISH
```

- **VISUALS** sources: `LIBRARY` (own/reference clips & photos) · `STOCK` (Pexels) · `AI` (image-to-video, credit-gated, mandatory AI label).
- **Distribution-only** subset (bring your own video): `LIBRARY → CAPTION → HASHTAGS → MUSIC NOTE/STYLE → PREVIEW → COMPLIANCE → PUBLISH`.
- **Weekly plan:** a calendar with two per-slot modes — *your uploaded clip on the day you choose*, or *let Kuyash generate it* — everything still passes a human/compliance approval before it goes out.

## Architecture & stack

Deliberately dependency-light and inspectable — **no frameworks, no build step, no `composer.json`.**

| Layer | Choice |
|---|---|
| Backend | **Pure PHP 8.3** — no Laravel/Symfony/Slim; small typed service classes |
| Database | **SQLite (WAL)** — one durable job queue, worker separated from web |
| Web / tunnel | **Caddy** + **Cloudflare Tunnel** (no open ports) |
| Storage | **Cloudflare R2** — private by default, signed URLs |
| Frontend | **Vanilla JS + custom CSS** — no framework, no Tailwind build |
| AI text / voice | **OpenAI** (text + TTS) — behind adapters |
| Stock / media | **Pexels** · **ffmpeg** (escaped args, timeouts, validated paths) |
| Publishing | **Zernio** API (doc-gated; OAuth tokens live at Zernio, never here) |
| Multi-tenant | `workspace_id` on every tenant table, enforced in every query — single-user UI in V1, SaaS-ready schema |

## Proof, not claims

- **~1126** automated tests (happy paths **and** failure states — failed jobs, API errors, blocked compliance, tenant isolation).
- **26** Architecture Decision Records; **18** migrations; a phase-disciplined build (Phase 0 static demo → Phase 13 hardening, plus an experience layer, real integrations, and a demo-showcase pass).
- **Review-agent gates** at every phase close: security, UX and compliance reviewers must all sign off — and repeatedly caught real defects and over-claims before merge.
- A **prod/demo boundary you can verify:** the showcase dataset is labelled, **inert** (it never triggers the worker, spends money, or publishes) and **one-command reversible** (`bin/demo-teardown.php`) — real account data is never touched.

## Running it locally

> Requires PHP 8.3 (`ext-pdo_sqlite`, `ext-curl`). Copy `.env.example` → `.env` and fill in your own keys. Every integration is mock-first, so it runs with **no real API keys** out of the box.

```bash
cp .env.example .env            # all providers default to MOCK
php bin/migrate.php             # SQLite schema (WAL)
php -S 127.0.0.1:8082 -t public # dev server
php bin/worker.php              # job queue worker (separate process)
php tests/run.php               # the test suite
```

Creating your first login, working the approval queue, the guardrails, and running
it always-on are covered in the **[Operator's Guide](USAGE.md)**.

## Repository layout

```
bin/        CLI entrypoints — migrate, worker, seed/teardown, smoke checks
src/        Analytics · Auth · Compliance · Content · Controllers · Core
            Database · Demo · Http · Library · Media · Publish · Storage
            Trend · Usage · Workflow · Workspace  (provider-agnostic adapters throughout)
templates/  Server-rendered views (vanilla PHP)
public/     Entry point + assets (custom CSS, vanilla JS)
config/     Central config; platform limits; cost model
database/   Migrations (additive, forward-only)
tests/      Single runnable suite (php tests/run.php)
tools/      Zero-dependency visual-test harness (headless screenshots)
.claude/    The build's governance: product brief, phase plan, ADRs, rules
```

## Non-goals

Kuyash is **not** an n8n clone, a CRM, an enterprise social suite, a generic automation platform, a general-purpose video generator, or a spam/slop engine. V1 is personal-scale; Stripe billing, a multi-tenant UI and customer onboarding are explicitly parked for a later SaaS phase.

## License

Not yet licensed — all rights reserved for now. This repository is published as a portfolio work sample: read it, run it, judge the engineering. Open an issue if you'd like to discuss it.

---

*Built in Vienna. Evidence over hype — open the code and check.*
