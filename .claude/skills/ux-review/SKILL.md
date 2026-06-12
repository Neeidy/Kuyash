---
name: ux-review
description: Review Kuyash dashboard and workflow UX - screens, states, node graph, approval queue, responsiveness. Use for /ux-review or at the end of UI phases.
---
# UX Review

When to use: end of Phase 0 and any UI-heavy phase; when screens or flows change significantly.

Inputs required: .claude/rules/frontend.md, .claude/docs/phase-0-demo-spec.md, the built screens.

Process:
1. Walk all screens (13 in Phase 0) against the spec.
2. Check the core flow: trend → content → approval → publish.
3. Check empty/loading/error states per screen.
4. Check node graph: selection, settings panel, connections, mobile stacked fallback.
5. Check approval queue and truthful badges.
6. Check responsive behavior at 375/768/1280px; console errors; offline operation (Phase 0).

Output format: per-screen issue list + prioritized fixes, then VERDICT (max ~500 tokens).

Safety checks: report only — never modify files during review.
