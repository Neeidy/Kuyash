# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 4 İNŞA EDİLDİ — kullanıcı kabulü + commit onayı bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 4 (Workflow Engine) inşa edildi, reviewer'lardan geçti, kabul bekliyor**
  (2026-06-12). Commit'ler: Faz 1 `ee042fa`, Faz 2 `b9728ed`, Faz 3 `f7121e0` = HEAD;
  Faz 4 HENÜZ COMMIT'LENMEDİ (kullanıcı onayı şart).
- Faz 4 içeriği: 0003 migration (workflows/runs/jobs/events/approvals; events'e
  UPDATE/DELETE'i SQL'de reddeden append-only trigger'lar + `PRAGMA recursive_triggers=ON`
  REPLACE bypass'ına karşı), `src/Workflow/*` (Nodes registry — full 13 job / distribution
  8 job, plandaki "14" yazım hatasıydı; WorkflowValidator; Workflow/Run/Job repo'ları;
  EventLog aynı-tx; Engine — guard'lı UPDATE+rowCount, worker_id kimlik guard'ı, terminal
  run'a job eklenmez; JobExecutor/JobResult/ExecutorRegistry seam + tek MockExecutor —
  deterministik, provider 'mock', compliance hep pass 'mock-v0', distribution'da GERÇEK
  library asset çözülür; Worker atomic claim UPDATE..RETURNING; Watchdog; Maintenance =
  login_attempts prune + orphan sweep), bootstrap split (bindings/{core,web,worker} —
  worker'da Session/Csrf/View/WorkspaceContext YOK), ErrorHandler CLI modu,
  bin/worker.php (--once/--max-jobs/--sleep-ms + SIGTERM), Messages sözlüğü (Library da
  geçti), 4 sayfa UI (workflows liste+read-only track+run trigger, queue onay kartları+
  job listesi+retry, runs/{id} node track+dürüst onay kayıtları+event timeline, logs
  terminal feed+filtre chip'leri), CSS portu, flash render layout'ta merkezi.
- Doğrulama: lint temiz; **285 PASS, 0 FAIL**; canlı smoke: login→workflows seed→upload→
  distribution run→worker --once→render review onayı→published+completed; watchdog
  (yaşlandırılmış processing job requeue), zorla fail→retry butonu, full run script onayı,
  SIGTERM temiz çıkış, sıfır ağ çağrısı grep'i temiz.
- Review (3 paralel): security-auditor + php-architect + ux-reviewer → **0 blocker,
  3× PASS WITH SHOULD-FIX; TÜM should-fix'ler + ucuz nice-to-have'ler uygulandı**
  (recursive_triggers, finalize worker_id guard'ı, opak worker id, chores cadence,
  status etiket haritası, compliance tonu info, reject confirm, 375px taşma, dashboard
  kopyası, aria'lar) + regression testleri. Ertelenenler: `.claude/docs/phase-4-followups.md`.
- KURAL (kullanıcı talebi, 2026-06-11): tüm run/test komutları `cd ~/Desktop/Kuyash &&` önekiyle.
- Test: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`;
  migrate: `... php bin/migrate.php`; worker: `... php bin/worker.php --once`;
  sunucu: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8082
  -d upload_max_filesize=200M -d post_max_size=210M -t public public/index.php`
  (8080 dolu!) → login → /workflows → run → worker → /queue onay → /runs/{id} → /logs.
  Smoke kullanıcısı: smoke4@kuyash.local / SmokePassword123 (lokal dev DB'de).
- PHP yolu: `/opt/homebrew/opt/php@8.3/bin/php` (8.3.31, keg-only — PATH'te yok).

## Verilmiş kararlar (özet)

- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 4 kararları: builder UI read-only + run trigger; reject = run cancel (revise Faz 5);
  kuyruk claim'i bilinçli global (tek kuyruk, satır workspace_id'siyle yazım); progress
  bar yok (mock'lar anlık); worker_id opak (hostname tenant feed'ine sızmasın).

## Sıradaki adım

1. **Kullanıcıdan Faz 4 KABUL + commit onayı bekleniyor** (verdict: KOŞULLU GO — tek
   koşul kullanıcının kendi canlı turu). Manuel tur adımları yukarıdaki "Test" satırında.
2. Kabul gelirse: Faz 4 commit'i (git-safety: açık onay şart) → `/next-phase` (Plan Mode)
   → Faz 5 (Script & Caption Engine) planı → `START PHASE 5` token'ı.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-4-followups.md (Faz 5/7 tetikleyicileri: finalize-throw fallback'i,
  vendor hata mesajı hijyeni, EventLog clock, autoload extraction, onay kartı entity
  satırı; V2: "Approved by you" çok-kullanıcı varyantı). phase-1/2/3 followup'larından
  Faz 4'te kapananlar: bootstrap split, ErrorHandler CLI, login_attempts prune, orphan
  sweep, Messages sözlüğü. Kalan: library pagination + tags json_each (Faz 5+).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — START PHASE 4: Workflow Engine inşa edildi (0003 şema+append-only events, Nodes/Validator/Engine/MockExecutor/Worker/Watchdog/Maintenance, bootstrap split, ErrorHandler CLI, bin/worker.php, 4 sayfa UI + CSS portu, Messages). 285 test PASS; canlı smoke (run→onay→publish, watchdog, retry, SIGTERM) PASS. 3 reviewer: 0 blocker, 8 should-fix TÜMÜ + ucuz NTH'ler uygulandı. Kabul + commit onayı bekleniyor.
- 2026-06-12 — /next-phase: Faz 4 (Workflow Engine) planı Plan Mode'da yazıldı ve ONAYLANDI. Karar: builder read-only + run trigger. START PHASE 4 bekleniyor.
- 2026-06-12 — FAZ 3 KABUL: kullanıcı kabul + commit onayı verdi; Faz 3 commit'lendi.
- 2026-06-12 — START PHASE 3: Content Library inşa edildi. 180 test PASS; 3 reviewer: 0 blocker, 14 should-fix uygulandı. Kabul sonrası commit'lendi.
- 2026-06-12 — /next-phase: Faz 3 planı yazıldı ve ONAYLANDI (tam kimlik portu, i18n key-hazır).
- 2026-06-12 — FAZ 2 KABUL: kabul + commit onayı; orphan home.php onayla silindi (102 PASS).
- 2026-06-11 — START PHASE 2: Auth+SQLite temeli inşa edildi; security-auditor 1 blocker düzeltildi, FINAL PASS.
- 2026-06-11 — /next-phase: Faz 2 planı yazıldı ve ONAYLANDI (CLI seed, /health public+minimal).
- 2026-06-11 — FAZ 1 KABUL: debug-kapalı sızıntı testi PASS; commit'lendi. Yeni kural: komutlar `cd ~/Desktop/Kuyash &&` önekli.
- 2026-06-12 — START PHASE 1: PHP skeleton inşa edildi; 0 blocker, 8 should-fix uygulandı; kabul sonrası commit.
