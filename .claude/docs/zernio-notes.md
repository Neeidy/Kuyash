# Kuyash — Zernio Integration Notes (DOC-GATED)

Real Zernio integration is BLOCKED until the user provides ALL of the following. Until then, the mock Zernio client is the only client.

Required before any real call:
1. Official documentation link
2. Authentication method (API key/OAuth details)
3. Endpoint list relevant to publishing
4. Media upload flow (direct vs URL-based, size/format limits)
5. Publish payload example (per platform: IG Reels, TikTok, YouTube Shorts)
6. Schedule payload example
7. Account-connection (OAuth) flow details for end-user accounts
8. Webhook format + signature verification method
9. Rate limits
10. Error response examples
11. Pricing/tier confirmation (free tier = 2 accounts; multi-account cost)
12. AI-label / content-flag fields per platform, if exposed by the API

Mock client must simulate: success, platform rejection, rate-limit, auth failure, webhook delivery, partial multi-platform failure, **lost webhook (reconciliation poll path)**, and **duplicate webhook delivery (inbox idempotency)** — see the webhook inbox + reconciliation sections in content-pipeline.md.

---

## STATUS — Phase 10: real adapter BUILT (doc-gate cleared) ✅

All 12 items above were supplied (see `zernio-integration-spec.md`, compiled from
the live `openapi.yaml` + docs.zernio.com). The real `ZernioPublishProvider` is
implemented — presign+PUT upload, `POST /v1/posts`, status reconciliation, read-only
`GET /v1/accounts`, 429 backoff, the `{error,code,reason}` envelope — with EVERY field
taken verbatim from the spec (no fabrication). **`ZERNIO_MOCK` stays `true` by default;
no live publish.** Setting `ZERNIO_MOCK=false` (+ `ZERNIO_API_KEY`) makes it real.

## AI-label reality — CORRECTED (verified from the raw openapi.yaml)

An earlier note claimed Zernio exposes NO AI-disclosure field. **That was wrong** (a
truncated-fetch artifact). The raw spec defines native AI-disclosure flags for two of
Kuyash's three platforms:

- **YouTube** → `platformSpecificData.containsSyntheticMedia` (boolean)
- **TikTok**  → `platformSpecificData.videoMadeWithAi` (boolean)
- **Instagram** → **no native field** → Kuyash appends a "Made with AI" / "AI ile üretildi"
  caption line instead.

Kuyash sets these from the compliance flag (`aiLabelApplied`), gated by per-platform
operator toggles in Settings (default ON). Turning one off is recorded as a truthful
`compliance.ai_disclosure_suppressed` audit event. Decision + rationale: **ADR-021**.
