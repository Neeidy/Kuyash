# AI image-to-video — doc-gate (Phase 12)

The real `VideoGenProvider` (`FalVideoGenProvider`) is a **doc-gated flag-off stub**: it is
built only when `VIDEO_MOCK=false` + a key is set, and it throws **"doc-gated"** before any HTTP.
Mock-first stays the default (synchronous ffmpeg Ken-Burns clip, `$0` spend). Mirror of the Zernio
gate (`zernio-notes.md`): **no real call until every item below is supplied with official docs +
payload examples** (integration rule: never hallucinate an external API).

Candidate aggregator: **fal.ai** (Kling / Sora / Veo / Wan behind one API) — chosen to avoid
vendor lock-in; V1 is **image-to-video only, single provider, credit-gated**.

## Required before flipping `VIDEO_MOCK=false`

1. **Auth** — header/scheme, key rotation, per-call vs per-key limits.
2. **Image upload** — how the source still is supplied (multipart upload → handle, or a signed URL
   the model fetches); size/format/aspect constraints.
3. **Submit** — endpoint + request payload for image-to-video: prompt field, model id, clip
   duration/seconds bounds, aspect/resolution params (must yield 9:16).
4. **Poll / async** — status endpoint + states; expected latency (minutes). Decide V1 async story
   (queue job + poll vs. webhook) — currently OUT of V1 (the stub is synchronous-only).
5. **Output fetch** — how the finished mp4 is retrieved (URL TTL, byte cap) → streamed to the
   content-addressed cache like a stock clip.
6. **Pricing** — **cents per second** (and any per-request floor) so `VideoResult.costCents` is
   truthful; units = seconds (`usage_events.unit_type`).
7. **Errors & format** — error envelope, rate-limit signal (429), non-retryable auth (401/403),
   guaranteed output container/codec.

Until all seven are documented, generation stays blocked and Quick Create runs on the mock.
