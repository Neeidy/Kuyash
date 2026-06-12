# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 5 KABUL EDİLDİ ve commit'lendi — /next-phase → START PHASE 6 bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 5 (Script & Caption Engine) KABUL EDİLDİ ve commit'lendi** (2026-06-12).
  Commit'ler: Faz 1 `ee042fa`, Faz 2 `b9728ed`, Faz 3 `f7121e0`, Faz 4 `f56d4ab`,
  Faz 5 `d293145`, plan-doc (Faz 7 cockpit) `b673b1c` = HEAD. Working tree temiz.
- Faz 5 içeriği: executor seam'e **ilk gerçek sağlayıcı adapter'ı** — 4 içerik job tipi
  (idea/script/caption/hashtag) artık `TextProvider` soyutlamasının arkasında.
  `src/Content/*`: TextProvider (interface) + TextResult VO + Sanitizer + PromptLibrary
  (versiyonlu prompt'lar idea/script/caption/hashtag .v1) + VariationEngine (tohumlu hook/pacing,
  ölçülebilir slop düşüşü, similarity Jaccard) + MockTextProvider (varsayılan, zengin,
  deterministik, provider 'mock', cost null) + OpenAiTextProvider (GERÇEK, flag arkasında,
  varsayılan KAPALI) + CostCalculator (config-fiyatlı) + ContentExecutor (provider-agnostik glue,
  name()'den hata etiketi). `src/Http/*`: HttpClient (interface) + CurlHttpClient (TLS-pinned) +
  HttpResponse + HttpTransportException → sahte transport'la offline test. config/openai.php +
  .env.example (OPENAI_MOCK=true varsayılan). bindings/core.php: OPENAI_MOCK=false+key → OpenAi,
  yoksa Mock; ContentExecutor 4 tipte, MockExecutor 9 tipte (içerik case'leri kaldırıldı).
  JobResult::awaitingApproval + Engine::finalizeAwaiting artık cost_cents yazıyor (gerçek script
  onaydan önce harcar — dürüst kayıt; worker_id race guard korundu). /queue onay kartı
  (prompt_version + word/duration + cost notu) + /runs içerik özeti (idea/script/per-platform
  captions/hashtags) + minik CSS.
- Doğrulama: lint temiz; **337 PASS, 0 FAIL** (50 yeni); sıfır ağ (curl yalnız CurlHttpClient,
  testler FakeHttpClient); secret yok; canlı smoke: full run → worker → /queue zengin onay kartı →
  approve → render review → approve → completed; /runs per-platform caption + hashtag + script
  görünür; varsayılan provider 'mock'.
- Kabul sonrası 3 ek (kullanıcı isteği, Faz 5 commit'ine dahil): (1) footer "Phase 5 · Script &
  Caption Engine"; (2) **worker heartbeat** (`src/Workflow/WorkerHeartbeat.php` — worker
  `storage/worker.heartbeat`'e ISO yazar [≤5s], web 30s bayatlıkta uyarır) → Dashboard + Queue
  "background worker is not running" bandı; (3) faz-kapanış smoke'larında worker adımı **[Terminal-2]**
  etiketli (memory: phase-close-smoke-terminal2).
- Review (3 paralel): security-auditor **PASS**, integration-reviewer + php-architect **PASS WITH
  SHOULD-FIX** → **0 blocker**. TÜM should-fix + ucuz NTH uygulandı (vendor-blind hata etiketi =
  provider->name(); CONTENT_JOB_TYPES const kaldırıldı → ContentExecutor::contentTypes();
  $lastUsage hidden state kaldırıldı; TLS pin; mock unknown-kind throw) + regression testleri.
  API şekli (chat/completions, usage.prompt/completion_tokens) doğrulandı, halüsinasyon yok.
  Ertelenenler: `.claude/docs/phase-5-followups.md`.
- KURAL (kullanıcı, 2026-06-11): tüm run/test komutları `cd ~/Desktop/Kuyash &&` önekiyle.
- Test: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`.
  Smoke (iki terminal): **[Terminal-1]** sunucu (8080 dolu → 8082)
  `... php -S 127.0.0.1:8082 -t public public/index.php`; **[Terminal-2]** worker
  `... php bin/worker.php` (tek tur: `--once`). Smoke user: smoke4@kuyash.local / SmokePassword123.
  Gerçek yol: `.env`'de OPENAI_MOCK=false + OPENAI_API_KEY=... (varsayılan mock).
- PHP yolu: `/opt/homebrew/opt/php@8.3/bin/php` (8.3.31, keg-only).

## Verilmiş kararlar (özet)

- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 5 kararları: engine-only UI (yeni sayfa yok); gerçek OpenAI yolu kurulu ama varsayılan
  KAPALI (flag+key); Claude ertelendi (tek-sınıf sonra); migration yok (result_json + cost_cents);
  cost-on-awaiting (gerçek script harcaması paused job'a yazılır).

## Sıradaki adım

1. `/next-phase` (Plan Mode) → **Faz 6 (Trend Radar Backend)** planı. phase-5-followups.md'deki
   Faz 6 tetikleyicileri dahil edilmeli: gerçek-trend semantik prompt-injection savunması
   (Sanitizer yeterli değil), TrendProvider adapter'ı (mock-first, Google Trends + YouTube Data
   official / TikTok best-effort), OpenAI/Pexels quota counter (`api_quota_usage`), Creator Watch
   (opsiyonel alt-hedef), awaiting_recording/shooting-brief tetikleyicisi (face format).
2. İnşa yalnızca `START PHASE 6` token'ı ile başlar — plan onayı kodu AÇMAZ.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 5 KABUL: kullanıcı kabul + commit onayı + 3 ek istedi (footer Phase 5; worker heartbeat → Dashboard/Queue "worker çalışmıyor" bandı; faz-kapanış smoke'larında [Terminal-2] etiketi → memory). 3 ek uygulandı, 337 test PASS, canlı doğrulandı. Faz 5 `d293145` commit'lendi; kullanıcının Faz 7 cockpit plan-düzenlemesi ayrı `b673b1c` docs commit'i. Sıra: /next-phase → START PHASE 6.
- 2026-06-12 — START PHASE 5: Script & Caption Engine inşa edildi (TextProvider seam: Mock + gerçek-ama-kapalı OpenAI; HttpClient seam + sahte transport; PromptLibrary versiyonlu; VariationEngine tohumlu slop kontrolü; CostCalculator; ContentExecutor; cost-on-awaiting). 3 reviewer 0 blocker, tüm should-fix+NTH uygulandı.
- 2026-06-12 — /next-phase: Faz 5 (Script & Caption Engine) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-5-plan.md`'e kaydedildi. Kararlar: engine-only UI, gerçek OpenAI yolu flag-kapalı, Claude ertelendi, migration yok.
- 2026-06-12 — FAZ 4 KABUL: kullanıcı kabul + commit onayı verdi; Faz 4 `f56d4ab` olarak commit'lendi. Kullanıcının üç plan-doc düzenlemesi (Creator Watch, countdown, V2) ayrı `730456c` docs commit'iyle alındı.
- 2026-06-12 — START PHASE 4: Workflow Engine inşa edildi (0003 şema+append-only events, Nodes/Validator/Engine/MockExecutor/Worker/Watchdog/Maintenance, bootstrap split, ErrorHandler CLI, bin/worker.php, 4 sayfa UI). 285 test PASS; 3 reviewer 0 blocker.
- 2026-06-12 — /next-phase: Faz 4 planı Plan Mode'da ONAYLANDI. Karar: builder read-only + run trigger.
- 2026-06-12 — FAZ 3 KABUL: kullanıcı kabul + commit onayı verdi; Faz 3 commit'lendi.
- 2026-06-12 — START PHASE 3: Content Library inşa edildi. 180 test PASS; 3 reviewer 0 blocker, 14 should-fix uygulandı.
- 2026-06-12 — /next-phase: Faz 3 planı yazıldı ve ONAYLANDI (tam kimlik portu, i18n key-hazır).
- 2026-06-12 — FAZ 2 KABUL: kabul + commit onayı; orphan home.php onayla silindi (102 PASS).
- 2026-06-11 — START PHASE 2: Auth+SQLite temeli inşa edildi; security-auditor 1 blocker düzeltildi.
