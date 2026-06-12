# Kuyash — Cost Model

## Per-video expected costs (15–45s)
| Item | Stock mode (V1 default) | AI-video mode (Phase 12+) |
|---|---|---|
| Idea/script/captions (OpenAI) | ~$0.01 | ~$0.01 |
| TTS (OpenAI, $15/1M chars) | ~$0.01–0.05 | ~$0.01–0.05 |
| Subtitles (Whisper) | ~$0.01 | ~$0.01 |
| Visuals | $0 (Pexels) | ~$1–7 (image-to-video) |
| Assembly (ffmpeg, own server) | $0 | $0 |
| **Total** | **<$0.10** | **~$1–7** |

Monthly reference (90 videos/account): stock ~$9; mixed mid-tier ~$55–135; premium (V2 providers) ~$450+.

## Controls
- Every external call records: provider, model, units (tokens/chars/seconds), cost_cents → usage_events.
- Per-workspace credit ledger: credit_transactions (grant, spend, adjust), balance derivable and cached.
- Budget caps per workspace; AI-video jobs credit-gated (insufficient credits → job blocked before the call).
- Cost visibility: Usage/Credits & Costs screen breaks down by category and workspace.
- Upgrades (ElevenLabs voice/music, premium video models) are provider swaps via adapters + config — never architecture changes.
