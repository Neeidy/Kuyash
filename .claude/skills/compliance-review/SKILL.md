---
name: compliance-review
description: Review Kuyash output/pipeline for platform-compliance - AI labels, slop scores, caps, truthful approval records, guardrails. Use for /compliance-review or before closing Phases 9, 10, 12.
---
# Compliance Review

When to use: mandatory before closing Phases 9, 10, 12; whenever approval logic, labeling, or autonomy settings change.

Inputs required: .claude/rules/compliance.md, .claude/docs/compliance-policy.md, relevant pipeline/UI code or specs.

Process:
1. Verify AI-label logic: realistic AI media → platform label set automatically; captions/hashtags exempt.
2. Verify slop/variation scoring and block thresholds.
3. Verify per-account daily caps and budget caps.
4. Verify approval records: Manual = real human record; Auto = "auto-approved by compliance agent (policy vX)". Any misrepresentation = CRITICAL finding.
5. Verify guardrails: kill switch, daily digest, auto-fallback to Manual.
6. Verify audit log entries for every compliance decision.

Output format: findings by severity + pass/fail per check, then VERDICT (max ~500 tokens).

Safety checks: report only — never modify files during review.
