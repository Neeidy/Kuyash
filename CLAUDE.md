# Kuyash — Claude Code Project Instructions

## What Kuyash Is

Kuyash is an AI-assisted short-form content studio covering the full loop **research → create → approve → publish → operate** for Instagram Reels, TikTok, and YouTube Shorts.

Target format: **15–45 second vertical (9:16) videos** — one render distributed to all three platforms with per-platform caption/hashtag variations.

Core capabilities: Trend Radar (niche trend discovery), Content Studio (AI ideas/scripts/hooks/captions/hashtags + shooting briefs for face content + Quick Create photo-to-video), Content Library, visual workflow builder, stock-mode production (TTS + Pexels + ffmpeg), Compliance Agent (AI labels, slop/variation control, truthful approval records), autonomy guardrails, multi-workspace operations, publishing via Zernio.

Business model: **personal-first, SaaS-ready** — multi-tenant data model (workspace_id on all tenant data) from day one, single-user UI in V1; Stripe, onboarding, and multi-tenant UI are deferred to SaaS-ification (V2).

Full product definition: see `.claude/docs/product-brief.md`.

## Current Active Stage

**Instruction setup only.** No application code exists yet. No phase has been started.

Claude Code must NOT create or modify product application code unless the user explicitly starts a phase with the exact approval token (see below).

## Session Continuity (Checkpoint)

The session state lives in `.claude/state/checkpoint.md` and is imported below, so it loads automatically at the start of every session:

@.claude/state/checkpoint.md

Rules:

1. **On session start:** treat the checkpoint as the authoritative "where we left off". Continue from its "Sıradaki adım" section; do not re-ask the user for context that the checkpoint already answers.
2. **On session end / after meaningful work:** update the checkpoint — refresh "Son güncelleme", "Mevcut durum", "Sıradaki adım", append one line to "Oturum logu" (newest first, keep max 10 lines). Updating the checkpoint after meaningful work is part of the task, not optional.
3. **Keep it short:** the checkpoint must stay around one page — it is loaded into context every session. Summarize, never paste long transcripts. Durable decisions belong in `.claude/docs/architecture-decisions.md`, not here.
4. Updating this checkpoint file is always allowed and does NOT require a phase token (it is instruction/state infrastructure, not product code).

## Operating Protocol (Every Session)

1. **Plan Mode advisory.** At the start of any non-trivial task, explicitly tell the user whether they should switch to Plan Mode. Recommend it for: phase planning, architectural decisions, changes touching more than 2–3 files, anything hard to reverse. Do not recommend it for: reviews, questions, single-file edits.
2. **Subagent advisory.** Proactively remind the user when reviewer subagents should run: `security-auditor` + `ux-reviewer` at the end of every phase; `security-auditor` mandatory before closing Phases 2, 10, and 11; `compliance-reviewer` mandatory before closing Phases 9, 10, and 12. Build single-agent; review with subagents.
3. **Verdict reporting.** Every completed task ends with a compact `VERDICT` block (max ~500 tokens): Status (DONE / PARTIAL / BLOCKED), What changed, Risks, Next step, Approval needed (yes/no + which token). No long prose recaps beyond the verdict.

## Phase Discipline & Approval Tokens

Development is strictly phase-based. The authoritative plan is `.claude/docs/phase-plan.md` (Phases 0–13).

- A phase starts ONLY when the user writes the exact token: `START PHASE 0`, `START PHASE 1`, … `START PHASE 13`.
- No token, no implementation. Plan feedback or general approval words never unlock coding.
- No backend work of any kind before the Phase 0 static demo is built and approved.
- Every phase ends with a verdict report and waits for the next token.

## Canonical Workflow Nodes (Single Source of Truth)

`TREND → IDEA → SCRIPT → VOICE → VISUALS → ASSEMBLE → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE → PUBLISH`

- VISUALS sources: **LIBRARY** (own/face clips), **STOCK** (Pexels), **AI** (image-to-video, credit-gated).
- Distribution-only subset: `LIBRARY → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE → PUBLISH`.
- Never rename these nodes. No "DRIVE" node — no Google Drive in V1.
- MUSIC NOTE / STYLE = music mood/audio note/platform suggestion, never copyrighted music publishing.
- COMPLIANCE is mandatory in every workflow. PUBLISH is mock-only until Phase 10.

## Fixed Technical Stack (Final — change only with explicit user approval)

- Backend: Pure PHP 8.3 — Laravel, Symfony, CodeIgniter, Slim and all full frameworks are FORBIDDEN
- Web server: Caddy · Tunnel: Cloudflare Tunnel
- Database: SQLite with WAL mode (multi-tenant schema)
- Storage: Cloudflare R2
- AI text: OpenAI API · TTS: OpenAI TTS base, ElevenLabs premium (V2)
- Stock assets: Pexels API · Trend data: Google Trends API + YouTube Data API (official, primary); TikTok third-party (best-effort); Instagram (weakest, best-effort)
- AI video: single provider in V1, image-to-video only, credit-gated, behind an abstraction (candidates: Kling/Sora/Veo/Wan via aggregator such as fal.ai)
- Video processing: ffmpeg · Publishing: Zernio API (doc-gated)
- Payments: Stripe — design for it, do NOT implement in V1
- Frontend: Vanilla JavaScript + modern custom CSS (no Tailwind build system unless explicitly approved)

Every external integration is **mock-first** and sits behind a **provider-agnostic adapter interface** (see `.claude/rules/integrations.md`). No heavy dependencies, no frontend/backend frameworks, no build tools without explicit approval.

## Compliance-First Principle

Platform compliance is core architecture, not a feature: automatic AI-label setting for realistic AI media; slop/variation control; truthful approval records (Manual mode default; Auto mode = "auto-approved by compliance agent", never misrepresented as human approval); autonomy guardrails (per-account daily caps, budget caps, kill switch, daily digest, auto-fallback to Manual). See `.claude/rules/compliance.md` and `.claude/docs/compliance-policy.md`.

## Scope Boundaries — Kuyash Must NOT Become

A full n8n clone · an enterprise social media suite · a CRM · a generic automation platform · a general-purpose video generation platform (API-driven, compliance-checked short-form creation IS in scope) · a spam/slop mass-production engine · a complex ads platform · an overbuilt enterprise SaaS.

## Phase Plan Summary

Phase 0 static demo (13 screens) → 1 PHP skeleton → 2 Auth+SQLite → 3 Content Library → 4 Workflow engine → 5 Script & Caption Engine → 6 Trend Radar → 7 Media Production (TTS+stock+ffmpeg) → 8 R2 → 9 Compliance Agent → 10 Zernio publishing → 11 Usage/costs/credits → 12 Quick Create AI video → 13 Hardening. V2 parking lot: Stripe, multi-tenant UI, onboarding, AI avatars, extra AI-video providers. Details: `.claude/docs/phase-plan.md`.

## Security & Safety Essentials

- Never commit secrets. Never print API keys. `.env` is gitignored.
- No destructive commands. No sudo. No file deletion without explicit approval.
- Do not initialize git or create commits unless explicitly approved.
- Do not install dependencies or frameworks without explicit approval.
- OAuth tokens for social accounts live at Zernio; Kuyash never stores platform passwords.
- Full checklist: `.claude/docs/security-checklist.md`.

## Reporting & Quality Bar

Every phase ends with a verdict report including: what changed, files created/modified, how to run/test, what works, what is mocked, known limitations, security notes, acceptance-criteria self-check, recommended next phase. Code must be readable, modular, secure by default, production-minded, never over-engineered, never framework-dependent.

## Binding Rule Files

The following rule files are BINDING for all work in this repository. Claude Code must read the relevant rule files before starting any phase work. They are imported here:

- @.claude/rules/phase-discipline.md
- @.claude/rules/no-overbuild.md
- @.claude/rules/architecture.md
- @.claude/rules/security.md
- @.claude/rules/compliance.md
- @.claude/rules/pure-php.md
- @.claude/rules/sqlite.md
- @.claude/rules/frontend.md
- @.claude/rules/integrations.md
- @.claude/rules/testing.md
- @.claude/rules/git-safety.md

Key docs (read when relevant): `.claude/docs/product-brief.md`, `.claude/docs/phase-plan.md`, `.claude/docs/content-pipeline.md`, `.claude/docs/compliance-policy.md`, `.claude/docs/trend-sources.md`, `.claude/docs/cost-model.md`, `.claude/docs/architecture-decisions.md`, `.claude/docs/security-checklist.md`, `.claude/docs/integration-policy.md`, `.claude/docs/zernio-notes.md`, `.claude/docs/sqlite-queue-notes.md`, `.claude/docs/phase-0-demo-spec.md`.
