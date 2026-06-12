# Rule: Integrations

- NEVER hallucinate external API behavior. If docs are unknown, the integration stays mocked.
- Mock-first for ALL providers: OpenAI text, TTS (OpenAI/ElevenLabs), Pexels, Google Trends, YouTube Data, TikTok third-party trend data, AI-video provider, Zernio, Stripe, R2, ffmpeg.
- Real integration requires: internal flow working, mock tested, error states represented, credentials/config known, official docs + payload examples available, user-approved phase token.
- Zernio is doc-gated: see .claude/docs/zernio-notes.md for the exact list of required documentation before any real call.
- Trend providers: Google Trends + YouTube Data (official) are primary; TikTok/Instagram trend data is best-effort third-party and must degrade gracefully (cached, optional, never blocking the pipeline).
- AI video: single provider in V1, image-to-video only, credit-gated, behind VideoGenProvider adapter (aggregator like fal.ai preferred to avoid vendor lock-in).
- Music: platform-native trend sounds can NOT be published via API — MUSIC NOTE/STYLE node outputs suggestions only. Background music sources must be licensed-clean (royalty-free library or licensed-data AI music). Suno/Udio are not approved for commercial output.
- Stripe: test mode only, deferred to SaaS-ification. R2: private by default, signed URLs only.
- ffmpeg: validated input paths, escaped arguments, timeouts, temp cleanup, never user-controlled command strings.
