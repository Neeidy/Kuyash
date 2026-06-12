# Kuyash — Content Pipeline (Durable Jobs)

## Main pipeline
`trend_fetch → idea_generation → script_draft (HUMAN APPROVAL) → tts → asset_fetch (LIBRARY | STOCK | AI) → assembly (ffmpeg) → compliance_check → render_review (APPROVAL MODE APPLIES) → publish (Zernio)`

## Entry points
1. **Trend-driven** (default): trend_fetch starts the chain.
2. **Quick Create**: photo + prompt → ai_video_generation job → joins at assembly. Credit-gated; AI label mandatory.
3. **Distribution-only**: existing LIBRARY asset → joins at caption/hashtag generation → compliance_check → publish.

## Face content flow
If a trend's recommended format is "face": script_draft also produces a **shooting brief** (what to record, duration, framing, hook timing). Pipeline pauses at `awaiting_recording` until the user uploads the clip to LIBRARY and marks it recorded; then continues at assembly.

## Job state fields
job_id, workspace_id, user_id (nullable), entity_type, entity_id, status (queued|processing|awaiting_approval|awaiting_recording|ready|failed|published|cancelled), retry_count, max_retries, error_message, idempotency_key (nullable), created_at, started_at, finished_at, cost_cents (nullable), provider (nullable).

## Webhook inbox (Phase 10)
Incoming Zernio webhooks are persisted RAW before any processing: `webhook_events` (id, source, payload_json, signature, received_at, processed_at nullable, process_error nullable). Processing reads from the inbox in a separate step; failures are replayable (reset processed_at). A webhook is acknowledged fast, processed durably.

## Reconciliation job (Phase 10)
`publish_reconcile` runs periodically: finds publish jobs in-flight beyond a threshold (e.g. >15 min without webhook), polls Zernio status, and converges job state (published / failed + reason). No publish may remain "pending" forever — lost webhooks are compensated by polling.

## Rules
- Jobs are idempotent; retries never duplicate side effects (idempotency keys on publish and AI-video jobs).
- No external call inside a DB transaction. Workers claim → release → call → update in separate short transactions.
- Every job transition is logged; user-visible pipeline logs derive from job logs.
- compliance_check can: pass / pass-with-AI-label / warn (slop) / block. Blocks route to Content Studio with reasons.
- render_review honors the workspace approval mode (Manual default / Auto) — records truthful.
