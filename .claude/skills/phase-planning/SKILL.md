---
name: phase-planning
description: Plan exactly one Kuyash phase - scope, non-goals, acceptance criteria, risks. Use when the user asks to plan a phase or after /next-phase.
---
# Phase Planning

When to use: planning any single phase from .claude/docs/phase-plan.md. Never plan multiple phases at once.

Inputs required: current repo state, the phase's entry in phase-plan.md, relevant rules (phase-discipline, no-overbuild, the phase's domain rules).

Process:
1. Read the phase definition (goals, non-goals, token).
2. Recommend Plan Mode to the user.
3. Define: precise scope, explicit non-goals, files/folders to create, mock vs real boundaries, acceptance criteria (measurable), manual test steps, risks.
4. List open questions.
5. State the exact token required to start.

Output format: structured plan (scope / non-goals / deliverables / acceptance criteria / test steps / risks / questions / token). No code.

Safety checks: no scope creep into later phases; no real integrations unless doc-gated requirements met; compliance and security requirements of the phase included.
