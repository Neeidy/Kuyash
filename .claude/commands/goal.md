# /goal — Autonomous Phase Loop (Experience Layer)

Run the next Experience-Layer phase end-to-end under the loop defined in
`.claude/docs/experience-layer-plan.md` §2. Build single-agent; gate with three subagents.

## Usage
- `/goal` — run the next pending phase (per `.claude/state/checkpoint.md`), then stop at its human gate.
- After a human-gated phase is accepted, the user issues the next `START PHASE <N>` token to continue.

## Per-phase loop (strict order)
1. **READ (fresh context):** checkpoint.md, the binding rule files, `ui-style-guide.md`,
   `docs/design/prototype-v3.html` (visual source of truth), and this phase's section in
   `experience-layer-plan.md`.
2. **PLAN:** write `.claude/state/phase-<N>-plan.md` (scope, files, acceptance, manual+visual+code+security
   test steps). Use extended thinking. Recommend Plan Mode for architectural phases.
3. **BUILD:** implement ONLY this phase's scope. Pure PHP 8.3, build-free, vanilla JS, custom CSS,
   progressive enhancement. No new deps/frameworks/build tools without explicit approval. No scope-creep.
4. **TEST — dispatch 3 subagents IN PARALLEL, collect verdicts:**
   - **ux-reviewer (VISUAL):** real Caddy+PHP render → headless screenshots 375/768/1280 × EN/TR;
     0px horizontal overflow; no console errors; empty/loading/error states; visual match to prototype-v3;
     **§1.2 motion-rule compliance** (transform/opacity/dashoffset only; no animated blur; no persistent
     backdrop-filter; no spinner); reduced-motion zeroes animation; no UI tech-jargon leak.
   - **qa-reviewer (CODE):** full existing suite green (no regression) + this phase's new tests;
     acceptance self-check; no scope-creep (only phase files changed); build-free/vanilla-JS preserved;
     JS-off fallback works.
   - **security-auditor (SECURITY):** no secrets; output escaping; CSRF/tenant-isolation where relevant;
     no command-injection surface. (Heavy on P19/P20; light on pure-presentation phases.)
   - **+ compliance-reviewer** when the phase touches approval badges / AI labels (P17, P20) — truthful-badge gate.
5. **EVALUATE (orchestrator):**
   - **All gates PASS** → write VERDICT (≤500 tokens: Status / What changed / Risks / Next / Approval needed)
     → COMMIT to branch `feat/phase-<N>-<slug>` (**NO auto-push**) → update checkpoint.md →
     if human-gate=YES: **STOP**, present screenshots + verdict, wait for user; else continue to next phase.
   - **Any gate FAIL** → fix, re-run ONLY the failed gate. **Max 2 fix attempts.** Still failing →
     run `/stop-and-report`, **do NOT commit**, surface to user.

## Hard rules (never relax)
- Never fake a gate. If the visual tool can't render, **STOP** — never substitute code-reading for visual testing.
- Respect phase discipline: only the active phase. If a task needs future-phase work, stop and tell the user.
- Truthful approval badges; no UI tech-jargon; GPU-light motion rules; mobile stacked fallback for node-graph.
- No destructive git, no auto-push, no secrets in commits, no sudo, no dependency installs without approval.
- Updating checkpoint.md + the phase plan file is part of the task, not optional.

## Human-gate table
15.9 YES · 16 YES · 17 YES · 18 YES · 19 optional · 20 optional.
At a YES gate: stop after verdict + screenshots; wait for the user to verify in browser and issue the next token.
