---
name: integration-mock-review
description: Decide whether each external integration should stay mocked or can become real - doc availability, error coverage, approval status. Use before any real-integration phase.
---
# Integration Mock Review

When to use: before Phases 5, 6, 7, 8, 10, 12 implement anything real; whenever the user asks "can X go real?".

Inputs required: .claude/rules/integrations.md, .claude/docs/integration-policy.md, .claude/docs/zernio-notes.md, current adapter/mocks.

Process — per provider (OpenAI, TTS, Pexels, Google Trends, YouTube Data, TikTok third-party, AI-video, Zernio, Stripe, R2, ffmpeg):
1. Does the mock adapter exist and mirror realistic error states?
2. Is the internal flow tested against the mock?
3. Are official docs + payload examples available? (Zernio: full doc-gate list)
4. Are credentials/config defined (env names, no secrets in repo)?
5. Has the user approved the phase with its token?

Output format: table (provider | mock status | docs status | verdict: STAY MOCKED / READY FOR REAL / BLOCKED-BY: …), then VERDICT (max ~500 tokens).

Safety checks: defaulting to STAY MOCKED on any uncertainty; never enable a real call during review.
