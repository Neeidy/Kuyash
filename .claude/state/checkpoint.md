# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 6 İNŞA EDİLDİ — kullanıcı kabulü + commit onayı bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 6 (Trend Radar Backend) İNŞA EDİLDİ — kullanıcı kabulü + commit onayı bekleniyor**
  (2026-06-12). Working tree'de COMMIT EDİLMEMİŞ. Commit'ler: Faz 1 `ee042fa`, Faz 2 `b9728ed`,
  Faz 3 `f7121e0`, Faz 4 `f56d4ab`, Faz 5 `d293145`, plan-doc `b673b1c` = HEAD.
- Faz 6 içeriği: executor seam'e **ikinci gerçek sağlayıcı adapter'ı** — `trend_fetch` artık
  `TrendProvider` soyutlamasının arkasında (Faz 5 deseni). `src/Trend/*`: TrendProvider (interface)
  + TrendResult/TrendFeed VO + TrendProviderException + FormatRecommender (face/faceless, det.) +
  MockTrendProvider (VARSAYILAN, niş-bazlı, offline, deterministik) + YouTubeTrendsProvider (GERÇEK,
  Data API v3 search.list, flag-KAPALI, key gerekir) + GoogleTrendsProvider (GERÇEK, public
  dailytrends `)]}',` prefix, flag-KAPALI, key'siz) + TrendRepository (cache batch'leri, raw int
  workspace_id scope) + TrendConfigRepository (niş/region, allowlist) + QuotaCounter
  (`api_quota_usage` günlük, sadece gerçek sağlayıcı) + TrendService (read-through cache + TTL +
  serve-stale degradation) + TrendExecutor (niche path + create-from-trend selected path).
  `src/Http/*`: HttpClient'a `get()` eklendi (CurlHttpClient shared send(); FakeHttpClient get+post).
  Engine::startRun'a opsiyonel `$trendId` (full run'ı seçili cached trend'e pinler; tenant-scoped).
  MockExecutor `trend_fetch`'i bıraktı. TrendController + 4 route (/trends, /niche, /refresh,
  /create) + trends/index.php (niş seçici, freshness banner, kota chip'leri, trend wall) + nav
  "Trends" + footer "Phase 6 · Trend Radar" + CSS. migration 0004_trends.sql (trends + trend_config
  + api_quota_usage). config/trends.php + .env.example (TREND_MOCK=true varsayılan).
- Doğrulama: lint temiz; **384 PASS, 0 FAIL** (47 yeni); sıfır ağ (testler FakeHttpClient); secret
  yok; canlı iki-terminal smoke: /trends mock wall (fresh) → create-from-trend (trend_id=1 "cheap
  meal prep") → run #8 entity=trend:1 → [Terminal-2] worker → trend_fetch origin=selected, idea
  trend'i refere ediyor → script approval → approve → render approve → completed; niş değişimi
  (fitness), geçersiz niş reddi, refresh çalışıyor; kota 0 (mock kaydedilmez).
- Review (3 paralel): security-auditor **GO**, integration-reviewer **0 blocker**, php-architect
  **GO** → **0 blocker**. Ucuz should-fix uygulandı: idx_trends_lookup'a `rank` (kapsayıcı index);
  CurlHttpClient $url credential-guard yorumu; GoogleTrends `$titleQuery` shadow düzeltmesi.
  Ertelenenler: `.claude/docs/phase-6-followups.md`. **KABUL EDİLMİŞ TRADEOFF:** web read-path
  canlı fetch yapıyor (mock'ta dormant) — gerçek sağlayıcı PROD'da açılmadan önce fetch worker'a
  taşınmalı (HARD GATE). GoogleTrends resmi-olmayan endpoint → prod'da mock kalır.
- KURAL (kullanıcı, 2026-06-11): tüm run/test komutları `cd ~/Desktop/Kuyash &&` önekiyle.
- Test: `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`.
  Smoke (iki terminal): **[Terminal-1]** sunucu (8080 dolu → 8082)
  `... php -S 127.0.0.1:8082 -t public public/index.php`; **[Terminal-2]** worker
  `... php bin/worker.php` (tek tur: `--once`). Smoke user: smoke4@kuyash.local / SmokePassword123.
  Gerçek yol: `.env`'de OPENAI_MOCK=false + OPENAI_API_KEY=... (varsayılan mock).
- PHP yolu: `/opt/homebrew/opt/php@8.3/bin/php` (8.3.31, keg-only).

## Verilmiş kararlar (özet)

- **GitHub remote kuruldu + push edildi** (2026-06-12): `origin` =
  https://github.com/Neeidy/Kuyash.git (SSH host-key başarısızdı → HTTPS). origin/main =
  local HEAD (10 commit push edildi). **KURAL: her ana faz kabul+commit'inden sonra
  güvenlik kapısını çalıştır → `git push origin main`'i OTOMATİK yap (kullanıcıya sorma;
  memory: auto-push-after-phase).** Force push YASAK — `.claude/settings.json` allow `git push`,
  deny `--force/-f/--force-with-lease`.
- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 5 kararları: engine-only UI (yeni sayfa yok); gerçek OpenAI yolu kurulu ama varsayılan
  KAPALI (flag+key); Claude ertelendi (tek-sınıf sonra); migration yok (result_json + cost_cents);
  cost-on-awaiting (gerçek script harcaması paused job'a yazılır).

## Sıradaki adım

1. **Faz 6 kullanıcı kabulü + commit onayı bekleniyor.** Onay gelince: commit (atomic, açıklayıcı
   mesaj) → güvenlik kapısı (secret tara) → `git push origin main` OTOMATİK (memory:
   auto-push-after-phase; force YASAK).
2. Sonra `/next-phase` (Plan Mode) → **Faz 7 (Media Production: TTS+Pexels+ffmpeg)**. Faz 6
   tetikleyicileri dahil: web read-path canlı fetch'i worker'a taşı (gerçek sağlayıcı PROD gate'i);
   awaiting_recording/shooting-brief pause (face format); draft-first render; asset cache; dashboard
   cockpit first pass. Detay: `.claude/docs/phase-6-followups.md`.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — START PHASE 6: Trend Radar Backend inşa edildi (TrendProvider seam: Mock varsayılan + gerçek-ama-kapalı YouTube/Google; HttpClient get(); TrendService read-through cache+TTL+serve-stale; QuotaCounter; create-from-trend Engine pin; /trends UI; migration 0004). 384 test PASS (47 yeni), canlı smoke OK. 3 reviewer 0 blocker, ucuz should-fix uygulandı. Kabul + commit onayı bekleniyor.
- 2026-06-12 — /next-phase: Faz 6 (Trend Radar Backend) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-6-plan.md`'e kaydedildi. Kullanıcı kararları: gerçek GoogleTrends+YouTube adapter'ları flag-KAPALI inşa (Faz 5 deseni); Creator Watch ERTELENDİ.
- 2026-06-12 — FAZ 5 KABUL: kullanıcı kabul + commit onayı + 3 ek istedi (footer Phase 5; worker heartbeat → Dashboard/Queue "worker çalışmıyor" bandı; faz-kapanış smoke'larında [Terminal-2] etiketi → memory). 3 ek uygulandı, 337 test PASS, canlı doğrulandı. Faz 5 `d293145` commit'lendi; kullanıcının Faz 7 cockpit plan-düzenlemesi ayrı `b673b1c` docs commit'i. Sıra: /next-phase → START PHASE 6.
- 2026-06-12 — START PHASE 5: Script & Caption Engine inşa edildi (TextProvider seam: Mock + gerçek-ama-kapalı OpenAI; HttpClient seam + sahte transport; PromptLibrary versiyonlu; VariationEngine tohumlu slop kontrolü; CostCalculator; ContentExecutor; cost-on-awaiting). 3 reviewer 0 blocker, tüm should-fix+NTH uygulandı.
- 2026-06-12 — /next-phase: Faz 5 (Script & Caption Engine) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-5-plan.md`'e kaydedildi. Kararlar: engine-only UI, gerçek OpenAI yolu flag-kapalı, Claude ertelendi, migration yok.
- 2026-06-12 — FAZ 4 KABUL: kullanıcı kabul + commit onayı verdi; Faz 4 `f56d4ab` olarak commit'lendi. Kullanıcının üç plan-doc düzenlemesi (Creator Watch, countdown, V2) ayrı `730456c` docs commit'iyle alındı.
- 2026-06-12 — START PHASE 4: Workflow Engine inşa edildi (0003 şema+append-only events, Nodes/Validator/Engine/MockExecutor/Worker/Watchdog/Maintenance, bootstrap split, ErrorHandler CLI, bin/worker.php, 4 sayfa UI). 285 test PASS; 3 reviewer 0 blocker.
- 2026-06-12 — /next-phase: Faz 4 planı Plan Mode'da ONAYLANDI. Karar: builder read-only + run trigger.
- 2026-06-12 — FAZ 3 KABUL: kullanıcı kabul + commit onayı verdi; Faz 3 commit'lendi.
- 2026-06-12 — START PHASE 3: Content Library inşa edildi. 180 test PASS; 3 reviewer 0 blocker, 14 should-fix uygulandı.
