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
