# Rule: Testing

- Every phase defines manual test steps in its plan and runs them before the phase report.
- Backend phases (1+) include basic PHP test scripts/checks for critical paths (routing, queue transitions, validation, tenant isolation).
- Test happy path AND failure states (failed jobs, API errors, invalid uploads, blocked compliance checks).
- Compliance tests: AI label set when required; slop block triggers; caps enforced; approval records truthful.
- Verify no scope creep and no secrets before every phase report.
- Phase 0: verify all 13 screens, responsive breakpoints, zero external requests, no console errors.
