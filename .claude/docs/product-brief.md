# Kuyash — Product Brief

## Concept
An AI-assisted short-form content studio covering the full loop: **research → create → approve → publish → operate**. Kuyash discovers trends, generates scripts and assets, assembles 15–45s vertical (9:16) videos, runs every output through a compliance agent, and publishes to Instagram Reels, TikTok, and YouTube Shorts — with full operational visibility (jobs, logs, costs, credits).

## Target User
- V1: the owner (single operator) running their own content loop across multiple workspaces/brands (e.g. 3 companies, 10–15 accounts).
- V2 (SaaS-ification): solo creators, small social media managers, small agencies.

## Core Workflow (canonical node names — never rename)
`TREND → IDEA → SCRIPT → VOICE → VISUALS → ASSEMBLE → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE → PUBLISH`
- VISUALS sources: LIBRARY (own/reference clips & photos) | STOCK (Pexels) | AI (image-to-video, credit-gated)
- Distribution-only subset: `LIBRARY → CAPTION → HASHTAGS → MUSIC NOTE / STYLE → PREVIEW → COMPLIANCE → PUBLISH`

## Reference / Faceless Hybrid (reference-asset model)
The system recommends a format per trend. **Faceless** = TTS + stock/AI visuals, fully automated. **Reference** (formerly "face") = the video is built around a **reference asset**: the workspace/account avatar (a pre-selected default identity), ANY library photo/clip the user uploaded (own face, a cat, a product — anything), or a per-run pick ("make this one with my cat"). All reference assets live in the Content Library; resolution happens at VISUALS. There is NO human recording step and NO shooting brief (removed product assumption). Photo references become ffmpeg still-clips (Phase 7); photo/reference + prompt → AI image-to-video is Quick Create (Phase 12, credit-gated, mandatory AI label). HeyGen-class avatar generation stays V2.

## Quick Create
Second pipeline entry: user uploads a photo + writes a prompt → AI image-to-video → same pipeline from assembly onward. Credit-gated; mandatory platform AI label.

## Compliance-First Positioning
Kuyash protects accounts: automatic AI labels, slop/variation control, format rules, truthful approval records (Manual default / Auto toggle), autonomy guardrails (daily caps, budget caps, kill switch, digest, fallback-to-manual), full audit logs. Kuyash is the anti-slop studio.

## Business Model
Personal-first, SaaS-ready: multi-tenant schema from day one, single-user UI in V1. Stock-mode economics (<$0.10/video) default; AI video premium via credits. Stripe and customer onboarding deferred to V2.

## Non-Goals / MVP Boundaries
Not n8n, not a CRM, not an enterprise social suite, not a generic automation platform, not a general-purpose video generator, not a spam/slop engine, not an ads platform. V1 ends at Phase 13 (hardening); everything in the V2 parking lot stays out of V1.
