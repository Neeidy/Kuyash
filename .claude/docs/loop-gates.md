# Loop Gates — orchestrator + 3-gate task templates (Phase 15.9)

This file makes **step 4 of `/go`** (`.claude/commands/go.md`) executable. `go.md`
is the controller (which phases, in what order, fail-cap, branch-commit, `/clear`);
**this** file is the concrete *how* of the three gates it dispatches each phase.

The orchestrator is the main agent. It does NOT self-certify a phase — it spawns
each gate as a subagent (the `Agent` tool, `subagent_type` below), collects a
structured PASS/FAIL + reasons from each, and only commits when all required
gates PASS. Never fake or force a gate.

---

## 0. Before the gates — produce the visual evidence

The orchestrator runs the harness for the phase under test and notes the output dir:

```bash
tools/visual/gate.sh --out storage/visual/phase-<N>
```

- Exit `0` = every page rendered with 0 console errors and 0 horizontal overflow.
- Exit `1` = at least one page failed (see `summary.json` → `results[].errors/overflow`).
- Exit `2` = setup/harness failure (server unhealthy, login failed → usually an
  unseeded DB or bad creds). Exit `2` is **(H2)** "visual tool cannot render" →
  STOP the loop; do not substitute code-reading for visual testing.

A non-zero harness exit is itself a VISUAL-gate FAIL — but exit `0` is **necessary,
not sufficient**: the harness proves "no errors / no overflow", the ux-reviewer
judges whether it actually *looks* right (the part a machine can't score).

---

## 1. VISUAL gate — `ux-reviewer`

**Invoke:** `Agent(subagent_type: "ux-reviewer")` with the PNG paths from
`storage/visual/phase-<N>/`. The `Read` tool renders PNGs, so the reviewer sees
each screen.

**Task template:**
> Review the Phase `<N>` screenshots in `storage/visual/phase-<N>/` (naming:
> `<screen>__<width>__<locale>.png`; widths 375/768/1280; locales en/tr). The
> harness already confirmed 0 console errors and 0 horizontal overflow — your job
> is the visual judgment a machine can't make. Read a representative set across
> all three widths and both locales and check, against
> `.claude/docs/design/prototype-v3.html` (visual source of truth) and
> `.claude/docs/ui-style-guide.md`:
> 1. **§1.2 motion-rule compliance** (from `experience-layer-plan.md`): no
>    animated blur, no persistent `backdrop-filter`, no spinner/continuous spin,
>    no off-palette grays. (Motion can't be seen in a still — also grep the new
>    CSS/JS the phase added for `@keyframes`, `filter: blur`, `backdrop-filter`,
>    `animation:` and confirm each animates only transform/opacity/dashoffset and
>    maps to a state.)
> 2. Visual match to the prototype for THIS phase's component.
> 3. Empty / loading / error states present and styled.
> 4. No UI tech-jargon leak (ffmpeg/TTS/SSE/job/queue must not be user-visible).
> 5. Truthful approval badges ("Approved by you" vs "Auto-approved …").
> 6. TR layout not broken (overflowing labels, clipped text) at 375px.
> Return `{verdict: PASS|FAIL, blockers: [...], should_fix: [...], notes}`.

**FAIL examples:** broken layout, off-palette gray, animated blur / persistent
backdrop on a non-modal element, a spinner, jargon leak, dishonest badge, clipped
TR text. **PASS** = 0 blockers.

---

## 2. CODE gate — `qa-reviewer`

**Invoke:** `Agent(subagent_type: "qa-reviewer")` with the phase diff + plan.

**Task template:**
> Review Phase `<N>` (plan: `.claude/state/phase-<N>-plan.md`; diff: the phase
> branch vs main). Confirm:
> 1. `php tests/run.php` →
>    the full existing suite stays green (no regression) plus any new tests this
>    phase added.
> 2. Acceptance criteria from the plan are met (self-check, point by point).
> 3. **No scope-creep**: only this phase's files changed; for presentation
>    phases, no PHP/DB/route/i18n change unless the phase explicitly allows it.
> 4. Build-free preserved: no new `package.json`/`node_modules`/framework/build
>    tool; vanilla JS only; JS-off fallback still renders.
> Return `{verdict: PASS|FAIL, failures: [...], notes}`.

**PASS** = suite green + acceptance met + no scope-creep + build-free intact.

---

## 3. SECURITY gate — `security-auditor`

**Invoke:** `Agent(subagent_type: "security-auditor")` with the phase diff.

**Task template:**
> Audit Phase `<N>` against `.claude/rules/security.md` +
> `.claude/docs/security-checklist.md`. Most Experience phases are
> presentation-only (light surface); **Phase 19 (SSE)** and **Phase 20** are
> heavy. Check: no secrets committed/printed; output escaping on any new
> user-content render; CSRF on any new state-changing route; (SSE) tenant
> isolation + stream authorization + no long SQLite transaction + resource
> /timeout limits; no command-injection surface (ffmpeg/shell). Return
> `{verdict: PASS|FAIL, findings: [{severity, issue, fix}], notes}`.

**PASS** = no HIGH/CRITICAL findings (LOW/observational may pass with a recorded follow-up).

---

## 4. Conditional gate — `compliance-reviewer`

Add this gate when the phase touches **approval badges or AI labels** (P17, P20).

**Invoke:** `Agent(subagent_type: "compliance-reviewer")`.

**Task template:**
> Phase `<N>` touches approval/AI-label UI. Verify truthful approval records
> (no fake "human approved"; Auto = "auto-approved by compliance agent"), AI
> label shown where required, no slop/variation regressions, EN **and** TR copy
> both truthful. Return `{verdict: PASS|FAIL, issues: [...]}`. This is a hard gate.

---

## 5. Orchestrator evaluation

```
all required gates PASS
  → write VERDICT (≤500 tokens: Status / What changed / Risks / Next / Approval)
  → COMMIT to branch feat/phase-<N>-<slug>   (NO auto-push)
  → update checkpoint.md + Oturum logu
  → /clear → next phase   (continuous mode; no per-phase human stop)

any gate FAIL
  → fix the specific findings
  → re-run ONLY the failed gate (not all three — token economy)
  → fix attempts capped at 2 per phase
  → still FAIL after 2  →  run /stop-and-report, do NOT commit this phase,
                           and STOP the whole loop  (H1 hard stop)
```

Hard stops (seatbelt, not approval): **H1** gate unfixable after 2 tries · **H2**
harness exit 2 (can't render) · **H3** Phase 20 done · **H4** context budget out
(checkpoint already current → `/go` once to resume). The 3-agent gate is the only
automatic quality protection in continuous mode, so it stays strict — never a
fake PASS to keep the loop moving.
