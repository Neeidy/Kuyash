---
description: Build the Phase 0 static demo (requires exact token START PHASE 0)
---
Build the Phase 0 static clickable dashboard demo.

GATE: Refuse to run unless the user has written exactly `START PHASE 0` in this conversation. If the token is absent, stop and say so.

Constraints:
- Static HTML/CSS/vanilla JS only. Mock data only (centralized in data/mock-data.js).
- NO backend, NO PHP, NO database, NO auth.
- NO Stripe/OpenAI/R2/Zernio/ffmpeg/TTS/trend/AI-video calls.
- NO external network requests: no CDN fonts, icons, or libraries. Must work offline via file://.
- All thirteen screens from .claude/docs/phase-0-demo-spec.md.
- Truthful approval badges ("Approved by you" / "Auto-approved by compliance agent").
- Mock confirmation dialogs for risky actions.

End with: exact run/test steps, the acceptance-criteria self-check from phase-0-demo-spec.md, and a VERDICT block (max ~500 tokens). Remind the user to run security-auditor, compliance-reviewer, and ux-reviewer subagents before closing the phase.
