---
name: php-review
description: Review pure PHP backend architecture - services, routing, adapters, SQLite discipline, dependency hygiene. Use at the end of backend phases.
---
# PHP Review

When to use: end of every backend phase (1+); when core services or adapters change.

Inputs required: .claude/rules/architecture.md, pure-php.md, sqlite.md; changed files.

Process:
1. Check no framework imports, no unapproved Composer packages.
2. Check service structure: small classes, constructor injection, strict types, central error handling.
3. Check adapter pattern: providers behind interfaces, no vendor names/types in core, mock adapters present.
4. Check SQLite discipline: WAL/busy_timeout, short transactions, no external calls in transactions, idempotent jobs, workspace_id filters.
5. Check job queue: states, retries, error logging.

Output format: findings by severity with file references + refactor suggestions, then VERDICT (max ~500 tokens).

Safety checks: report only — never modify files during review.
