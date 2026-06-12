# Kuyash — Integration Policy

## Universal rules
- Mock-first: every provider ships with a mock adapter mirroring realistic success AND error responses.
- Adapter interfaces: core code never references vendor names/types. Config selects the adapter (per workspace where useful).
- Cost recording: every real call logs provider, model, units, cost_cents.
- Real integration gate: internal flow works + mock tested + error states represented + docs available + credentials defined + phase token approved.
- **Quota tracking (O1):** rate-limited providers (Pexels: 200 req/h & 20k/mo; YouTube Data: daily quota units; Google Trends: per-key limits) get a central per-provider quota counter (`api_quota_usage`: provider, window, used, limit). Adapters check before calling; near-limit → warn + serve cache; at limit → degrade gracefully (cached/stale data, never a pipeline crash). Quota state visible on the Usage screen.

## Per provider
- **OpenAI (text):** versioned prompt templates; structured outputs; input sanitization; mock mode first (Phase 5).
- **TTS:** OpenAI TTS base (Phase 7); ElevenLabs = V2 premium swap via TtsProvider.
- **Whisper:** subtitles (Phase 7).
- **Pexels:** free stock; respect rate limits (200/h, 20k/mo); cache search results (Phase 7).
- **Google Trends / YouTube Data:** official, primary trend sources (Phase 6); quota-aware; cached.
- **TikTok trends:** third-party only, best-effort, graceful degradation (Phase 6).
- **AI video:** single provider via aggregator (fal.ai-class), image-to-video only, credit-gated, mandatory AI label (Phase 12).
- **Music:** royalty-free library (e.g. Pixabay) or licensed-data AI music in V2; Suno/Udio NOT approved for commercial output; platform trend sounds = suggestion-only (cannot be published via API).
- **Zernio:** doc-gated — see zernio-notes.md. Mock client until every listed item is provided.
- **Stripe:** deferred to SaaS-ification; test mode only when it arrives; webhook signatures verified.
- **R2:** private by default; signed URLs; StorageProvider abstraction (Phase 8).
- **ffmpeg:** local binary; safe execution rules (Phase 7).
