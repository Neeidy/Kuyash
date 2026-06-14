# /go — Kuyash CONTINUOUS Phase Loop (repo-specific; Experience Layer 16→20)

> Repo-only command. Does NOT touch the global `/go` command. Runs Experience-Layer phases
> **16 → 17 → 18 → 19 → 20 back-to-back, WITHOUT stopping between phases**, per
> `.claude/docs/experience-layer-plan.md` §2. Build single-agent; gate with three subagents.
> The user reviews everything at the END (branch commits, no push) — NOT per phase.

## Prerequisite
Phase **15.9** (Loop & Visual-Test Infra) must be COMPLETE before `/go`. 15.9 builds the local
render+screenshot harness the visual gate depends on, and is run separately via `START PHASE 15.9`
(token-gated, NOT part of this loop). If 15.9 is not done, `/go` STOPS and tells the user to run it first.

## Usage
- `/go` — run every pending phase from 16 through 20 continuously, stopping only when ALL are done
  (or a hard stop condition fires). Do NOT ask the user between phases.

## Per-phase loop (strict order, repeat 16→20)
1. **READ (fresh context):** checkpoint.md, binding rules, `ui-style-guide.md`,
   `docs/design/prototype-v3.html` (visual source of truth), this phase's section in `experience-layer-plan.md`.
2. **PLAN:** write `.claude/state/phase-<N>-plan.md` (scope, files, acceptance, manual+visual+code+security tests). Extended thinking.
3. **BUILD:** implement ONLY this phase. Pure PHP 8.3, build-free, vanilla JS, custom CSS, progressive
   enhancement. No new deps/frameworks/build tools without approval. No scope-creep.
4. **TEST — 3 subagents IN PARALLEL, collect verdicts:**
   - **ux-reviewer (VISUAL):** real Caddy+PHP render → headless screenshots 375/768/1280 × EN/TR; 0px overflow;
     no console errors; empty/loading/error states; visual match to prototype-v3; **§1.2 motion-rule compliance**
     (transform/opacity/dashoffset only; no animated blur; no persistent backdrop-filter; no spinner);
     reduced-motion zeroes animation; no UI tech-jargon leak.
   - **qa-reviewer (CODE):** full existing suite green (no regression) + new tests; acceptance self-check;
     no scope-creep; build-free/vanilla-JS preserved; JS-off fallback works.
   - **security-auditor (SECURITY):** no secrets; output escaping; CSRF/tenant-isolation where relevant; no command-injection surface.
   - **+ compliance-reviewer** on phases touching approval badges / AI labels (P17, P20) — truthful-badge gate.
5. **EVALUATE (orchestrator):**
   - **All gates PASS** → VERDICT (≤500 tokens) → COMMIT to branch `feat/phase-<N>-<slug>` (**NO auto-push**)
     → update checkpoint.md + log → **immediately /clear and START THE NEXT PHASE.** No user prompt, no pause.
   - **Any gate FAIL** → fix, re-run ONLY the failed gate. **Max 2 fix attempts.** Still failing →
     `/stop-and-report`, do NOT commit this phase, and STOP the whole loop.

## Run is CONTINUOUS — no human gate between phases
Halts ONLY on a hard stop condition (seatbelt, not approval):
- **(H1)** A gate cannot pass after 2 fix attempts → stop-and-report; never force-pass.
- **(H2)** Visual tool cannot render → STOP; never substitute code-reading for visual testing, never fake a gate.
- **(H3)** Phase 20 complete → present per-phase verdict list + branch names; stop.
- **(H4)** Context/token budget runs out → checkpoint already current; stop cleanly; user types `/go` once to resume.

## Hard rules (never relax)
- Never fake a gate; never force a failing gate to pass to keep the loop going.
- One phase at a time, in order; no future-phase bleed.
- Truthful approval badges; no UI tech-jargon; GPU-light motion rules (§1.2); mobile stacked node-graph fallback.
- No destructive git, no auto-push, no secrets in commits, no sudo, no dependency installs without approval.
- Updating checkpoint.md + the phase plan after each phase is part of the task.
