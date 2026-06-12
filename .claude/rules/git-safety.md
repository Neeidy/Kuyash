# Rule: Git & Command Safety

- Small atomic changes with clear summaries.
- Do NOT delete files without explicit user approval.
- Do NOT run destructive commands (rm -rf, broad deletes, force pushes).
- Do NOT use sudo.
- Do NOT initialize git or create commits unless explicitly approved by the user.
- Do NOT commit secrets — ever.
- Project instruction files (.claude/, CLAUDE.md) stay committed; local overrides (CLAUDE.local.md, settings.local.json) stay ignored.
