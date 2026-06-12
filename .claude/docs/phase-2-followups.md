# Phase 2 Follow-ups (deferred by review, NOT forgotten)

Source: security-auditor review at Phase 2 close. The blocker (B1: trace args
→ log leak) and should-fix (S1: throttle case bypass) were FIXED during
Phase 2, with regression tests. Below is the deliberately deferred tier.

## Security (from the audit)
- **IP throttle behind Cloudflare Tunnel (Phase 10/13):** `REMOTE_ADDR` will be
  the local cloudflared/Caddy address in production, so the 20-failures/IP cap
  collapses into one global bucket (fails safe, not open). When real traffic
  arrives, key on `CF-Connecting-IP` trusted ONLY for requests originating from
  Cloudflare; decide at Caddy/Tunnel validation time.
- **zend.exception_ignore_args in php.ini (Phase 13):** the runtime `ini_set`
  in `ErrorHandler::hardenTraceLogging()` covers the app; set it in php.ini /
  FPM pool too as defense-in-depth during the production-readiness pass.
- **login_attempts pruning (Phase 4+):** rows are only cleaned opportunistically
  on failed attempts; a success-only stream never prunes. Fold a periodic prune
  into the job queue/worker once it exists (Phase 4).
- **Logout cookie expiry (cosmetic):** logout clears the session and rotates the
  id (old server-side file deleted) — sufficient; an explicit expired Set-Cookie
  would be tidier when a cookie helper exists.
- **Input length caps on login fields (Phase 3):** email/password lengths are
  unbounded before hashing/queries; add sane maxima (e.g. 254/1024) when the
  shared form-validation helper arrives.
- **CSRF token rotation on login (Phase 3+):** the per-session token survives
  the login id rotation; rotating it on privilege change is defense-in-depth.
- **DB file permissions (Phase 13):** chmod 0600 on storage/database/*.sqlite
  at creation; verify in the production-readiness pass.

## Application
- **templates/home.php is orphaned:** `/` is a redirect switchboard since
  Phase 2; the old skeleton page is unreachable (still exercised by a View
  unit test). Delete with user approval (git-safety: no deletion without
  approval) or repurpose in the Phase 3 UI pass.
- **Failed-login HTTP status is 200:** the login form re-renders with a generic
  error at 200. Consider 401/422 + form re-render when the real UI ships, if
  observability wants status-code signal for failed logins.
- **Session idle-timeout enforcement is GC-based:** native gc (probabilistic)
  enforces the 7200s lifetime server-side. If stricter guarantees are ever
  needed (SaaS), add a last-activity timestamp check in Session::start().

## Phase 1 leftovers still pending (unchanged)
- Router first-match shadowing guard (when param routes multiply, Phase 3+).
- ErrorHandler CLI/worker plain-text registration mode (Phase 4).
- bootstrap.php per-controller bindings → split into a services file when it
  hurts (Phase 3–4).
- Caddyfile validation + CSP unsafe-inline removal + HSTS-at-edge decision
  (Phase 13). NOTE: login/dashboard templates use inline style attributes —
  fold into a served stylesheet during the Phase 3 UI pass alongside the CSP
  cleanup.
- ErrorHandler secret redaction for log MESSAGE content (Phase 5+, when real
  API keys exist; trace-arg leakage is already fixed — B1).
