# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 2 KABUL EDİLDİ ve commit'lendi — START PHASE 3 bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 2 (Auth + SQLite) KABUL EDİLDİ ve commit'lendi** (2026-06-12).
  Kullanıcı onayıyla orphan `templates/home.php` silindi (View testleri auth/login'e
  taşındı; suite silme sonrası da 102 PASS). Faz 1 commit'i: `ee042fa`.
- Faz 2 içeriği: `src/Core/{Database,Session,Csrf}.php`, `src/Database/Migrator.php`,
  `src/Auth/{Auth,LoginThrottle,LoginResult}.php`, `src/Workspace/WorkspaceContext.php`
  (fail-closed), `src/Controllers/{Auth,Dashboard}Controller.php`, login/dashboard/403
  template'leri, `database/migrations/0001_init.sql` (users/workspaces/workspace_users+
  role CHECK/login_attempts), `bin/{migrate,create-user}.php`, Router 405+Allow/HEAD,
  `Response::redirect()`, index.php'de session start + blanket CSRF gate; `/` artık
  redirect switchboard; `/health` minimal `{"status":"ok"}`.
- Doğrulama: lint temiz; **102 PASS, 0 FAIL**; canlı smoke (php -S :8082): CSRF'siz/sahte
  token POST→403, login 303→dashboard, session id login'de değişti (fixation), 6. yanlış
  denemede lockout mesajı, logout POST-only (GET→405 Allow:POST), PUT /login→405
  Allow:GET,HEAD,POST, HEAD OK, `/_dev/boom`→500, migrate idempotent (2. koşu boş),
  pragma kanıtı wal/5000/1. Lokal dev kullanıcı: dev@kuyash.local (workspace #1 Default).
- Security-auditor (ZORUNLU kapı): ilk tur 1 blocker (B1: exception trace argümanları
  parolayı loga yazabilir) + 1 should-fix (S1: email case'i ile throttle bypass) →
  İKİSİ DE DÜZELTİLDİ (`ErrorHandler::hardenTraceLogging()` + `Auth::attempt`'te
  strtolower choke-point) + 3 regression testi. Auditor bağımsız yeniden test etti:
  **FINAL PASS**. Ertelenenler: `.claude/docs/phase-2-followups.md`.
- KURAL (kullanıcı talebi, 2026-06-11): tüm run/test komutları
  `cd ~/Desktop/Kuyash &&` önekiyle yazılır.
- Test etme: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`;
  sunucu: `... -S 127.0.0.1:8082 -t public public/index.php` (8080 başka süreçte dolu!)
  → `/login` (dev@kuyash.local — lokal dev parolası repo dışında), dashboard, logout.
- Faz 1 içeriği: `public/index.php` (cli-server passthrough + bootstrap try/catch),
  `src/bootstrap.php` (autoloader + explicit binding'ler), `src/routes.php`,
  `src/Core/{Router,Container,Config,ErrorHandler,View,Response}.php`,
  `src/Controllers/{Home,Health}Controller.php`, `config/app.php`, `templates/`
  (base layout + home + 404/500), `Caddyfile` (yazıldı, Faz 13'te valide edilecek),
  `.env.example` (prod/false güvenli default), `tests/run.php` (36 assert).
  PHP yolu: `/opt/homebrew/opt/php@8.3/bin/php` (8.3.31, keg-only — PATH'te yok).
- Doğrulama: lint 15/15 temiz; testler 36/36 PASS; HTTP smoke (php -S): `/`→200,
  `/health`→200 JSON, `/nope`→404, `/_dev/boom`→500+log (debug'da); debug=false
  sızıntı yok (unit); strict_types/composer-yok/secret-yok grep'leri temiz.
- Review: php-architect + security-auditor → 0 blocker, 8 should-fix TÜMÜ uygulandı
  (bootstrap try/catch, throw-safe log, JSON_THROW_ON_ERROR, Caddyfile http:// + CSP,
  .env.example prod default'ları, /health'ten PHP_VERSION kaldırıldı, routes.php
  config/→src/ taşındı). Nice-to-have'ler: `.claude/docs/phase-1-followups.md`.
- Test etme: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php -S
  127.0.0.1:8080 -t public public/index.php` → tarayıcıda `/`, `/health`, `/nope`,
  `/_dev/boom` (.env'de APP_DEBUG=true ise).
  Testler: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`.

## Verilmiş kararlar (özet)

- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 0 teknik: file:// + Chrome CORS nedeniyle ES module YOK — klasik script'ler +
  global `Kuyash` namespace; tüm mock veri data/mock-data.js'te; HTML'de veri yok.

## Sıradaki adım

1. `/next-phase` (Plan Mode) → Faz 3 (Content Library Backend) planı.
2. Faz 3 inşası yalnızca `START PHASE 3` token'ı ile başlar.

## Açık konular / bekleyenler

- `.env` lokal olarak dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev sunucusu için 8082 kullan.
- Followups: `.claude/docs/phase-1-followups.md` + `phase-2-followups.md`.

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 2 KABUL: kullanıcı kabul + commit onayı verdi; orphan home.php onayla silindi (testler auth/login'e taşındı, 102 PASS). Faz 2 commit'lendi. Sıra: /next-phase (Plan Mode) → START PHASE 3.
- 2026-06-11 — START PHASE 2: Auth+SQLite temeli inşa edildi (Database/Migrator/Session/Csrf/Auth/Throttle/WorkspaceContext + login UI + 405/HEAD). 102 test PASS; canlı smoke PASS. security-auditor: 1 blocker (trace-arg log sızıntısı) + 1 should-fix (case ile throttle bypass) düzeltildi, FINAL PASS. Kabul + commit onayı bekleniyor.
- 2026-06-11 — /next-phase: Faz 2 (Auth+SQLite) planı Plan Mode'da yazıldı ve ONAYLANDI. Kararlar: CLI seed (web /setup yok), /health public+minimal. Kapsam: Database/Migrator/Session/Csrf/Auth/Throttle/WorkspaceContext + login UI + ~45-55 yeni assert; security-auditor zorunlu. START PHASE 2 bekleniyor.
- 2026-06-11 — FAZ 1 KABUL: debug-kapalı sızıntı testi canlı çalıştırıldı (boom→404, sızıntı grep 0, health ok, /→200) ve PASS; .env geri alındı. Kullanıcı kabul + commit onayı verdi; Faz 1 commit'lendi. Yeni kural: run/test komutları `cd ~/Desktop/Kuyash &&` önekli. Sıra: /next-phase (Plan Mode) → START PHASE 2.
- 2026-06-12 — START PHASE 1: PHP skeleton inşa edildi (router/container/config/error-handler/view + Caddyfile + 36 test). php-architect+security-auditor: 0 blocker, 8 should-fix uygulandı. Lint+test+HTTP smoke PASS. Kabul + commit onayı bekleniyor.
- 2026-06-11 — Faz 0 KABUL EDİLDİ ("kabul ediyorum"). /next-phase → Faz 1 (PHP skeleton) planı yazıldı ve plan-mode'da onaylandı; PHP/Caddy yok tespit edildi, kullanıcı php@8.3 + Caddy-erteleme seçti. START PHASE 1 bekleniyor.
- 2026-06-11 — Kullanıcı onayıyla git init + ilk commit (mevcut durum: Faz 0 Iteration 2 demosu + tüm .claude talimat seti). Secret taraması temiz; .gitignore mevcut haliyle yeterli.
- 2026-06-11 — ITERATION 2: mission-control dash, global drawer, Create composer (#/create), ⌘K, J/K+A/R, platform-skin, Why?, density, motion genlik turu. 14×3 + 29/29 + EN/TR PASS; ux+qa reviewer 2 blocker + 15 düzeltme kapatıldı.
- 2026-06-11 — FULL VISUAL REDESIGN: yeni kimlik (Ops-Teal/Inter/JBMono), 10 motion deseni, canlı-ops ticker, TR/EN i18n (586 key). 13×3 QA + 17 davranış testi PASS; ux+qa reviewer should-fix'leri kapatıldı.
- 2026-06-11 — Kullanıcı ilk görünümü reddetti ("jenerik AI tasarımı"); ui-style-guide.md (BINDING) + followups eklendi; redesign planı onaylandı (Ops Teal + Inter Display/JBMono + font indirme onayı).