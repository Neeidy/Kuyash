# Kuyash — Compliance Policy

## Why
Platforms aggressively penalize unlabeled AI media and mass-produced "slop" (YouTube inauthentic-content policy; TikTok AI-label enforcement with strikes and reach suppression). Compliance failures kill accounts — and with them the product. Compliance is therefore core architecture.

## AI labeling
- Realistic AI-generated media (AI video, realistic synthetic voice in final audio) → platform AI label set AUTOMATICALLY at publish.
- AI-written scripts, captions, hashtags, text overlays → exempt (per platform rules).
- Label decisions recorded in the audit log.

## Slop / variation control
- Similarity scoring against the workspace's recent N posts (script text + structure + assets).
- High similarity → warn; extreme repetition → block with reasons routed back to Content Studio.
- Templates must inject variation (hooks, wording, assets, pacing).

## Format rules
15–45s duration; 9:16 aspect; per-platform constraints validated before publish.

## Approval modes & truthful records
- **Manual (default):** human approves every render. Record: user id + timestamp.
- **Auto (opt-in toggle, per workspace):** compliance agent auto-approves low-risk renders. Record: "auto-approved by compliance agent (policy vX)" + score snapshot.
- Records are never misrepresented — no fake "human approved" stamps anywhere (UI, API, logs, marketing). Violation = critical bug.

## Autonomy guardrails (required wherever Auto mode exists)
- Per-account daily post cap (default conservative, e.g. 1–3/day)
- Per-workspace credit/budget cap
- Kill switch (instant stop of all auto-publishing)
- Daily digest of all auto-approved/published items
- Auto-fallback to Manual when quality/compliance scores drop

## Quality score (must be concretely defined in the Phase 9 plan)
The fallback trigger is a defined, measurable score — not a vague feeling. Components (weights finalized in Phase 9):
- rolling slop-similarity average of recent renders (higher = worse)
- failed/rejected publish rate over a rolling window
- compliance-block rate (blocked renders / total renders)
Threshold breach → workspace flips to Manual automatically + user notified in daily digest. Phase 9 cannot close (compliance-reviewer gate) without this score and its thresholds implemented and tested.

## Audit log
Every compliance decision writes: input refs, checks run, scores, result, policy version, timestamp.
