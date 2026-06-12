# Kuyash — Architecture Decision Records

## ADR-001: Pure PHP 8.3, no framework
Small, understandable, dependency-light backend for a single-server product. Frameworks add lock-in and surface area.

## ADR-002: SQLite with WAL as the only database
Single-server scale target; WAL gives concurrent reads + serialized writes. Job queue lives in SQLite — no Redis/queue infra in V1.

## ADR-003: Caddy + Cloudflare Tunnel
Automatic TLS, simple config, no exposed ports.

## ADR-004: Cloudflare R2, private by default
Media storage with signed URLs only; no public buckets.

## ADR-005: Vanilla JS + custom CSS
No frontend framework; the product surface is dashboard CRUD + one simple node graph.

## ADR-006: Mock-first integrations behind adapter interfaces
Every provider (OpenAI, TTS, Pexels, trends, AI-video, Zernio, Stripe, R2, ffmpeg) implements a PHP interface; mock adapters are first-class. Swap = one adapter + one config line. Aggregator (e.g. fal.ai) preferred for AI video to avoid vendor lock-in.

## ADR-007: Phase 0 static demo before any backend
Cheapest validation of the full 13-screen product vision.

## ADR-008: Multi-tenant schema, single-user UI
workspace_id on all tenant data from day one; SaaS-ification becomes feature-flipping, not migration.

## ADR-009: Compliance agent as core architecture
AI labels, slop control, truthful approval records, guardrails — platform penalties are an existential risk, so compliance is in the pipeline, not bolted on.

## ADR-010: Stock-mode production before AI video
<$0.10/video economics first; AI video (Phase 12) is credit-gated premium.

## ADR-011: Truthful approval records
Manual = real human record; Auto = "auto-approved by compliance agent". Never misrepresented — legal/trust requirement, enforced as a rule.
