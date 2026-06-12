# Phase 1 Follow-ups (deferred by review, NOT forgotten)

Source: php-architect + security-auditor advisory reviews at Phase 1 close.
All SHOULD-FIX items were applied during Phase 1; the list below is the
deliberately deferred NICE-TO-HAVE tier, with the phase where each belongs.

## Router (when routing needs grow — Phase 2+)
- HEAD requests currently 404; fall back to GET routes for HEAD (uptime checks use HEAD).
- Wrong method returns 404, not 405 — fine for now, revisit with real forms (Phase 2).
- First-match-wins: a `{param}` route registered before a static sibling shadows it;
  duplicate registration silently overwrites. Document or guard if route count grows.

## View / Response (when UI/auth arrive — Phase 2/3)
- View: data keys `content`/`file`/`template`/`data` are reserved/shadowed
  (extract EXTR_SKIP + layout `$content`). Add docblock note when templates multiply.
- Response: header map can't repeat headers (multiple Set-Cookie). Phase 2 sessions
  must use PHP-native setcookie, not this map.
- Home page discloses env/debug state — trim for prod builds when real UI lands.

## ErrorHandler (Phase 4 workers / Phase 2 secrets)
- Web-shaped output only; Phase 4 CLI workers need a plain-text/log-only registration mode.
- Log lines carry raw exception messages; add secret redaction once DSNs/API keys exist (Phase 2).

## Bootstrap (Phase 3–4)
- Per-controller bindings will bloat src/bootstrap.php; split into an explicit
  services file (still no autowiring) when it hurts.

## Caddy / deploy (Phase 13 validation pass)
- `caddy validate` + real `caddy run` (Caddy intentionally not installed in Phase 1).
- Dotfile block matcher only covers top-level (`/.*`); add `*/.*` for nested paths.
- HSTS decision: TLS terminates at Cloudflare edge — set HSTS at the edge, not Caddy.
- CSP currently allows `style-src 'unsafe-inline'` for the skeleton's inline <style>;
  move to a served stylesheet and drop the exception when the real UI ships.
- Local dev `php -S` exposes X-Powered-By (expose_php) — irrelevant in prod (Caddy strips);
  optionally set expose_php=Off.

## /health (Phase 2)
- Decide: keep minimal public payload (status only?) vs. gate detailed view behind auth.
  PHP_VERSION already removed in Phase 1.
