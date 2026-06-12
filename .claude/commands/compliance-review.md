---
description: Run a platform-compliance review (AI labels, slop, approval integrity)
---
Run a platform-compliance review using .claude/rules/compliance.md and .claude/docs/compliance-policy.md.

Check:
1. AI-label correctness — realistic AI media flagged for platform AI labels; AI captions/hashtags correctly exempt
2. Slop/variation risk — template repetition across recent content
3. Per-account daily post caps configured and enforced
4. Approval-record integrity — records truthful (human vs compliance agent), no misrepresentation anywhere
5. Audit log completeness for compliance decisions
6. Guardrail status — budget caps, kill switch, daily digest, auto-fallback to Manual

Report findings by severity with concrete fixes. Do not modify files. End with a VERDICT block (max ~500 tokens).
