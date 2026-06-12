# Phase 5 Follow-ups (deferred by design/review, NOT forgotten)

Status: final at phase close. All reviewer SHOULD-FIX items were APPLIED during
the phase with regression tests (suite: 331 PASS / 0 FAIL), plus the cheap
nice-to-haves. Reviews: security-auditor **PASS**, integration-reviewer **PASS
WITH SHOULD-FIX**, php-architect **PASS WITH SHOULD-FIX** — 0 blockers.

## Applied during the phase (from reviews)
- **Vendor-blind failure tag** (php-architect SF1): ContentExecutor no longer
  hardcodes `'openai'`; the provider names itself via `TextProvider::name()`,
  so a future Claude adapter is never mislabeled. Regression test added.
- **No duplicated content-type list / no redefinition warning** (php-architect
  SF2): removed the file-scope `const CONTENT_JOB_TYPES`; the binding reads
  `ContentExecutor::contentTypes()` (single source = `TYPE_KIND`).
- **`$lastUsage` hidden state removed** (all three reviewers): `call()` returns
  the decoded response; `generate()` reads `usage` from it directly.
- **Explicit TLS pinning** (security N2): `CurlHttpClient` sets
  `CURLOPT_SSL_VERIFYPEER=true` + `CURLOPT_SSL_VERIFYHOST=2`.
- **Mock fails loudly on unknown kind** (php-architect N4): `MockTextProvider`
  throws `TextProviderException` instead of a silent empty result — contract
  identical to the real provider. Regression test added.

## Plan deviations (accepted, documented)
- **JobResult::awaitingApproval + Engine::finalizeAwaiting now carry cost_cents.**
  Not in the Phase 4 contract, but a real script generation spends money BEFORE
  the human approval gate — recording it on the paused job is the honest path.
  The change stays inside the existing short guarded transaction with the
  `status='processing' AND worker_id=?` race guard intact (verified by all
  three reviewers). Net new behavior: paused script_draft rows store real cost.
- **MockExecutor no longer generates content** (9 types, not 13). The 4 content
  types are served by ContentExecutor + a TextProvider. Tests layer
  ContentExecutor(MockTextProvider) over MockExecutor in `makeRig`, mirroring
  the production binding.
- **~44 new tests (331 total), not the ~55 estimated** — quality over count;
  every acceptance criterion is pinned.

## Deferred to later phases (from reviews)
### Integration / Security
- **401/403 (auth) classified as retryable** (integration #3, security): a bad
  key currently retries `max_retries` times then dead-letters (safe, no leak,
  no infinite loop) instead of failing fast. A non-retryable failure path needs
  a JobResult/Engine signal that does not exist yet — fold into Phase 11/13
  hardening alongside the cost/quota work.
- **Semantic prompt injection from REAL trends** (security N3): `Sanitizer`
  strips control chars + clamps length (sufficient for Phase 5's mock upstream).
  A real Phase 6 trend literally named "ignore previous instructions…" is not
  neutralized by that — Phase 6/9 owns instruction/data separation + output
  validation. Impact is already bounded: the provider re-validates output shape
  (recomputes word_count, normalizes hashtags, rejects wrong shapes), so an
  injected instruction cannot corrupt the persisted schema.
- **`cost_usd` shape uniformity** (integration #1): the real provider adds
  `cost_usd` to result_json; mock does not (cost is null by policy). Templates
  guard with `isset(...) && > 0`, so no display bug. Left intentional.
- **OpenAI quota counter** (integration-policy O1): not built (Phase 5 has no
  rate-limited primary). Wire `api_quota_usage` when Pexels/YouTube arrive
  (Phase 6/7), per integration-policy.md.

## Phase 5+ trigger items carried forward
- **Anthropic Claude as a second TextProvider** (deferred by user): genuinely
  one class (`AnthropicTextProvider implements TextProvider`) + one binding
  branch + config block. The seam was verified vendor-neutral.
- **Studio UI / Create composer assisted modes** (engine-only decision): the
  engine is ready; the richer authoring surface is a later UI phase.
- **awaiting_recording + shooting-brief PAUSE flow** (carried from Phase 4): no
  "face" trigger until trends recommend format (Phase 6). Brief TEXT may ride in
  the script result; the pause-status code path + "mark recorded" gate are
  Phase 6/7.
- **reject-to-revise loop** (carried): still reject = cancel run.
- **Variation scoring on rendered output** (integration #8): Phase 9 slop
  scoring should measure the actual rendered script/caption text (reusing
  `VariationEngine::similarity`), not just the seed pick.
- **EventLog clock injection, autoloader extraction** (carried from Phase 4):
  still open, still low priority.
