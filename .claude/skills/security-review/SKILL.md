---
name: security-review
description: Review Kuyash code or plans for security risks - secrets, injection, sessions, uploads, isolation, ffmpeg. Use for /security-review or before closing backend phases.
---
# Security Review

When to use: end of every backend phase; mandatory before closing Phases 2, 10, 11; any time security-sensitive code changes.

Inputs required: changed files list, .claude/rules/security.md, .claude/docs/security-checklist.md.

Process:
1. Scan for secrets/keys in code, config, logs, UI strings.
2. Check injection surfaces: SQL (prepared statements), XSS (escaping), shell (ffmpeg args).
3. Check session/CSRF/auth posture for the current phase.
4. Check upload validation, storage privacy, signed URLs.
5. Check tenant isolation: workspace_id filter in every query touched.
6. Check webhook verification, rate limiting where applicable.

Output format: findings table (severity, location, issue, fix), then VERDICT (max ~500 tokens).

Safety checks: report only — never modify files during review.
