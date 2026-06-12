# Kuyash — Security Checklist (later phases)

## Secrets & config
- [ ] No secrets in repo; .env gitignored; .env.example placeholders only
- [ ] API keys never in frontend, logs, error messages, or UI

## Auth & sessions (Phase 2)
- [ ] Argon2id password hashing
- [ ] Sessions: HttpOnly, Secure, SameSite; regenerate on login (fixation)
- [ ] CSRF tokens on all state-changing requests
- [ ] Rate limiting + brute-force lockout on auth endpoints

## Database (Phase 2+)
- [ ] Prepared statements only
- [ ] workspace_id filter in every tenant query (isolation test exists)
- [ ] Migrations reviewed; no raw user input in DDL

## Uploads & media (Phase 3+)
- [ ] MIME/type/size/extension validation; reject by allowlist
- [ ] Private storage; signed URLs only (Phase 8)
- [ ] No user-controlled file paths

## ffmpeg (Phase 7)
- [ ] Escaped arguments; validated input paths; timeouts; temp cleanup
- [ ] Never user-controlled command strings

## Webhooks & external (Phase 10+)
- [ ] Zernio/Stripe webhook signature verification
- [ ] Idempotent webhook handling
- [ ] OAuth tokens stored at Zernio only

## Output & headers
- [ ] Output escaping everywhere user data renders
- [ ] Caddy security headers (CSP, X-Frame-Options, nosniff, referrer-policy)

## Operations (Phase 13)
- [ ] Audit logs for sensitive actions
- [ ] SQLite WAL-aware backup/restore tested
- [ ] GDPR-minded delete/export paths
