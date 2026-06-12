# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 3 KABUL EDİLDİ ve commit'lendi — START PHASE 4 bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 3 (Content Library) KABUL EDİLDİ ve commit'lendi** (2026-06-12).
  Commit'ler: Faz 1 `ee042fa`, Faz 2 `b9728ed`, Faz 3 = HEAD.
- Faz 3 içeriği: `assets` şeması (0002, workspace_id+kind+type+sha256+rotasyon-düzeltilmiş
  boyutlar+JSON tags), saf-PHP `MediaProbe` (ISO BMFF: mvhd v0/v1 + tkhd + rotasyon
  matrisi; her anomali → null, upload bloklanmaz), katmanlı `AssetValidator` (boyut→uzantı
  allowlist→finfo→MIME tutarlılık), `AssetStorage` ({32-hex}.{ext}, traversal-guard'lı),
  `AssetIngest` (validate→probe→hash→store→create; DB hatasında dosya geri alınır),
  workspace-scoped `AssetRepository`, `Library/Media` controller'ları (tek-aralık Range:
  206/416, CSP sandbox, nosniff, HEAD'de gövde atlanır), `Flash`, `Format`,
  `Response::file()` (512KB chunk streaming). UI: BINDING stil rehberi portu —
  fontlar+base.css verbatim, app.css (shell+bileşenler), app shell (sidebar/topbar),
  library grid+detay, dashboard reskin, login inline-stil temizliği; i18n ertelendi,
  tüm mesajlar message-KEY (tek map).
- Doğrulama: lint temiz; **180 PASS, 0 FAIL**; canlı smoke (php -S :8082 + upload -d
  flag'leri): sentetik rotasyonlu portre mp4 → **9:16 + 0:22** (rotasyon işlendi), foto
  upload, garbage/png-as-mp4 redleri flash'le, /media 200+206 Range doğru, login'siz
  /media→redirect, cross-tenant 404, delete satır+dosya kaldırdı, config'ten türetilen
  upload caption sayfada.
- Review (3 paralel): security-auditor + php-architect + ux-reviewer → 0 blocker,
  PASS WITH SHOULD-FIX ×3. TÜM should-fix'ler uygulandı (sec: $_FILES array crash,
  stored_name traversal guard, sha256 false; arch: AssetIngest çıkarımı + orphan
  temizliği + config'ten template copy + Format helper; ux: klavyeyle erişilebilir file
  input, reduced-motion stagger, renk=status, role=alert, uploading state, aria-label)
  + regression testleri. Ertelenenler: `.claude/docs/phase-3-followups.md`.
- KURAL (kullanıcı talebi, 2026-06-11): tüm run/test komutları
  `cd ~/Desktop/Kuyash &&` önekiyle yazılır.
- Test etme: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`;
  sunucu: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8082
  -d upload_max_filesize=200M -d post_max_size=210M -t public public/index.php`
  (8080 dolu!) → login (dev@kuyash.local) → /library: upload/ara/filtrele/detay/sil.
- PHP yolu: `/opt/homebrew/opt/php@8.3/bin/php` (8.3.31, keg-only — PATH'te yok).

## Verilmiş kararlar (özet)

- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 0 teknik: file:// + Chrome CORS nedeniyle ES module YOK — klasik script'ler +
  global `Kuyash` namespace; tüm mock veri data/mock-data.js'te; HTML'de veri yok.

## Sıradaki adım

1. `/next-phase` (Plan Mode) → Faz 4 (Workflow Engine: workflow JSON modeli,
   SQLite job queue + worker, watchdog, append-only event log) planı.
2. Faz 4 inşası yalnızca `START PHASE 4` token'ı ile başlar.

## Açık konular / bekleyenler

- `.env` lokal olarak dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev sunucusu için 8082 kullan.
- Followups: phase-1/2/3-followups.md (Faz 4 tetikleyicileri: bootstrap split,
  login_attempts prune, orphan sweep, library pagination).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 3 KABUL: kullanıcı kabul + commit onayı verdi; Faz 3 commit'lendi. Sıra: /next-phase (Plan Mode) → START PHASE 4 (Workflow Engine).
- 2026-06-12 — START PHASE 3: Content Library inşa edildi (assets şeması, saf-PHP BMFF probe + rotasyon, strict validation, AssetIngest, Range'li /media, BINDING stil portu + app shell). 180 test PASS; canlı smoke PASS. 3 reviewer (sec/arch/ux): 0 blocker, 14 should-fix TÜMÜ uygulandı. Kabul + commit onayı bekleniyor.
- 2026-06-12 — /next-phase: Faz 3 (Content Library) planı Plan Mode'da yazıldı ve ONAYLANDI. Kararlar: tam kimlik portu, i18n ertele/key-hazır. Kapsam: assets şeması, saf-PHP BMFF probe (rotasyon dahil), strict upload validation, Range'li /media servisi, library UI + stil portu, ~45 yeni assert. START PHASE 3 bekleniyor.
- 2026-06-12 — FAZ 2 KABUL: kullanıcı kabul + commit onayı verdi; orphan home.php onayla silindi (testler auth/login'e taşındı, 102 PASS). Faz 2 commit'lendi. Sıra: /next-phase (Plan Mode) → START PHASE 3.
- 2026-06-11 — START PHASE 2: Auth+SQLite temeli inşa edildi (Database/Migrator/Session/Csrf/Auth/Throttle/WorkspaceContext + login UI + 405/HEAD). 102 test PASS; canlı smoke PASS. security-auditor: 1 blocker (trace-arg log sızıntısı) + 1 should-fix (case ile throttle bypass) düzeltildi, FINAL PASS. Kabul + commit onayı bekleniyor.
- 2026-06-11 — /next-phase: Faz 2 (Auth+SQLite) planı Plan Mode'da yazıldı ve ONAYLANDI. Kararlar: CLI seed (web /setup yok), /health public+minimal. Kapsam: Database/Migrator/Session/Csrf/Auth/Throttle/WorkspaceContext + login UI + ~45-55 yeni assert; security-auditor zorunlu. START PHASE 2 bekleniyor.
- 2026-06-11 — FAZ 1 KABUL: debug-kapalı sızıntı testi canlı çalıştırıldı (boom→404, sızıntı grep 0, health ok, /→200) ve PASS; .env geri alındı. Kullanıcı kabul + commit onayı verdi; Faz 1 commit'lendi. Yeni kural: run/test komutları `cd ~/Desktop/Kuyash &&` önekli. Sıra: /next-phase (Plan Mode) → START PHASE 2.
- 2026-06-12 — START PHASE 1: PHP skeleton inşa edildi (router/container/config/error-handler/view + Caddyfile + 36 test). php-architect+security-auditor: 0 blocker, 8 should-fix uygulandı. Lint+test+HTTP smoke PASS. Kabul + commit onayı bekleniyor.
- 2026-06-11 — Faz 0 KABUL EDİLDİ ("kabul ediyorum"). /next-phase → Faz 1 (PHP skeleton) planı yazıldı ve plan-mode'da onaylandı; PHP/Caddy yok tespit edildi, kullanıcı php@8.3 + Caddy-erteleme seçti. START PHASE 1 bekleniyor.
- 2026-06-11 — Kullanıcı onayıyla git init + ilk commit (mevcut durum: Faz 0 Iteration 2 demosu + tüm .claude talimat seti). Secret taraması temiz; .gitignore mevcut haliyle yeterli.