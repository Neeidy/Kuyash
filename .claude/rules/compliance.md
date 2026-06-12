# Rule: Platform Compliance (Core Product Function)

- Realistic AI-generated media (AI video, realistic synthetic voice) MUST carry the platform AI label — set automatically by the system. AI-written captions, hashtags, and scripts are exempt (per platform rules).
- Slop/variation control: score similarity against the workspace's recent posts; warn on high similarity; block on extreme repetition. Template-identical mass output is forbidden by design.
- Format rules enforced: 15–45s duration, 9:16 aspect, per-platform constraints.
- Approval modes:
  - Manual (DEFAULT): every render requires human approval; record = real human approval (user, timestamp).
  - Auto (user-enabled toggle): compliance agent auto-approves low-risk items; record = "auto-approved by compliance agent (policy vX)".
  - Records are NEVER misrepresented. No fake "human approved" stamps, in UI, API, logs, or marketing copy.
- Autonomy guardrails (mandatory when Auto mode exists): per-account daily post caps, per-workspace credit/budget caps, kill switch, daily digest, automatic fallback to Manual on quality-score drop.
- Every compliance decision writes an audit log entry (input, checks run, result, policy version).
