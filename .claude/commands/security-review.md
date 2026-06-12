---
description: Run a security review of current or planned implementation
---
Run a security review using .claude/rules/security.md and .claude/docs/security-checklist.md.

Check: secrets in repo/code/logs; auth/session risks; CSRF/XSS/SQL injection; upload validation; API key exposure (frontend, logs, errors); tenant/workspace isolation in queries; webhook signature verification; file permissions; unsafe shell/ffmpeg commands; dependency risks.

Report findings by severity (critical/high/medium/low) with file references and concrete fixes. Do not modify files. End with a VERDICT block (max ~500 tokens).
