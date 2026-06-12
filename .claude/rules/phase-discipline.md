# Rule: Phase Discipline

- The active phase must be respected at all times. No coding outside the active phase.
- A phase starts ONLY with the exact user token: `START PHASE 0` … `START PHASE 13`. Plan feedback, "looks good", or general approval words never unlock implementation.
- No backend work of any kind during Phase 0 (static demo only).
- No real external API calls before the corresponding mock flow works and the integration's phase is approved.
- Every phase must be verifiable: defined acceptance criteria, manual test steps, self-check in the report.
- Every phase ends with a VERDICT report (max ~500 tokens) and waits for the next token.
- If a task seems to require work from a future phase, stop and tell the user — do not quietly expand scope.
