# Rule: Security

- Never commit secrets. Never print API keys in code, logs, errors, or UI. `.env` is gitignored; `.env.example` carries placeholder names only.
- Later phases (enforced when their phase arrives): secure sessions (HttpOnly, Secure, SameSite, fixation protection); CSRF tokens on all state-changing requests; prepared statements only; output escaping; strict upload validation (MIME/type/size/extension); private storage + signed URLs; webhook signature verification (Stripe, Zernio if supported); rate limiting + brute-force protection; role-based access control; tenant/workspace isolation enforced in every query; audit logs for sensitive actions; Caddy security headers; safe ffmpeg execution (escaped args, validated paths, timeouts, no user-controlled commands).
- OAuth tokens for social platforms live at Zernio. Kuyash stores account references and health state only — never platform passwords.
- password_hash with Argon2id (bcrypt fallback) when auth arrives.
- GDPR-minded deletion/export thinking from the schema design onward.
