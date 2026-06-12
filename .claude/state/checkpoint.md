# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (FAZ 7 İNŞA EDİLDİ — kullanıcı kabulü + commit onayı bekleniyor)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 7 (Media Production) İNŞA EDİLDİ — kullanıcı kabulü + commit onayı bekleniyor**
  (2026-06-12). Working tree'de COMMIT EDİLMEMİŞ (Step A doc pivotu + Faz 7 birlikte gidecek).
  Commit'ler: ... Faz 5 `d293145`, plan-doc `b673b1c`, **Faz 6 `393d666` = HEAD**, origin/main=HEAD.
- Faz 7 içeriği: executor seam'e **gerçek ffmpeg + TTS + stock** (mock-first sağlayıcılar GERÇEK
  ffmpeg'i besler). `src/Media/*` (~24 sınıf): Ffmpeg (proc_open arg-array, timeout, temp cleanup) +
  MediaPaths (tagged ref'ler, traversal-proof) + WavWriter (saf-PHP WAV) + AssetCache (içerik-adresli
  sha256, hit'te respend yok) + RenderRepository + AssemblyEngine (narrated + distribution; draft
  540x960 + final 1080x1920) + TtsProvider seam (Mock gerçek WAV / OpenAi flag-KAPALI) + TtsExecutor +
  SubtitleBuilder (script-timed SRT; **bu ffmpeg build'inde subtitles/drawtext YOK → SRT sidecar +
  mov_text soft-mux; burn-in flag arkasında, libass-build followup**) + StockProvider seam (Mock
  lavfi / Pexels flag-KAPALI) + AssetFetchExecutor (reference→avatar→stock çözümleme; foto→still-clip,
  video→ref) + AssemblyExecutor (draft) + FinalRenderExecutor (onay-sonrası final). Nodes: PUBLISH →
  render_review→**final_render**→publish (her iki template). RenderController + /render(authed,range) +
  /render/{id}/poster. WorkspaceSettings avatar pointer + Library avatar butonu. Dashboard cockpit ilk
  geçiş (KPI şerit + aktif run'lar + onay-bekleyen thumbnail'lar). migration 0005 (avatar_asset_id,
  reference_asset_id, renders, asset_cache). config/media.php + .env.example (TTS_MOCK/STOCK_MOCK=true).
- Doğrulama: lint temiz; **432 PASS, 0 FAIL** (46 yeni; ~8s, suite içinde GERÇEK ffmpeg render);
  sıfır ağ (OpenAiTts/Pexels FakeHttpClient, ffmpeg lokal); secret yok; ffmpeg arg-injection testi
  (shell metachar → literal dosya adı, komut DEĞİL); canlı iki-terminal smoke: full run → script
  approve → [Terminal-2] worker GERÇEK draft render (540x960 21.6s poster) → /render authed (200/206/
  poster) + queue <video> önizleme → render approve → final render (1080x1920) → completed; cockpit
  KPI/aktif/thumbnail; reference picker + avatar butonu render ediyor.
- Review (3 paralel): security **GO**, integration **0 blocker**, php-architect **GO** → **0 blocker**.
  Ucuz should-fix uygulandı: RenderController resolve() (read side-effect yok); AssetCache UNIQUE-only
  race-catch + json_encode dışarı; CurlHttpClient MAXFILESIZE 128MiB; OpenAiTts cost yorumu
  (per-token approx); Ffmpeg/MediaPaths/FinalRender yorum netliği. **HARD GATE'ler**
  (`.claude/docs/phase-7-followups.md`): Pexels download stream+cap (gerçek stock PROD öncesi);
  trend web-fetch worker'a (Faz 6); burn-in libass-build gerektirir.
- (Faz 6 referans: `trend_fetch` TrendProvider arkasında [Mock varsayılan / YouTube+Google flag-KAPALI];
  HttpClient.get(); create-from-trend [Engine $trendId]; `api_quota_usage`; commit `393d666`.)
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
- **Reference-asset modeli (ADR-012, 2026-06-12):** shooting-brief/awaiting_recording KALDIRILDI.
  `face` format = reference-subject (avatar varsayılan / herhangi foto-klip / per-run pick).
  F7 çözümleme-only, F12 AI üretim, V2 avatar üretimi. `awaiting_recording` şemada ölü stub.
- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 5 kararları: engine-only UI (yeni sayfa yok); gerçek OpenAI yolu kurulu ama varsayılan
  KAPALI (flag+key); Claude ertelendi (tek-sınıf sonra); migration yok (result_json + cost_cents);
  cost-on-awaiting (gerçek script harcaması paused job'a yazılır).

## Sıradaki adım

1. **Faz 7 kullanıcı kabulü + commit onayı bekleniyor.** Onay gelince TEK commit'e şunlar girer:
   (a) Step A reference-asset doc pivotu (product-brief, content-pipeline, phase-plan, CLAUDE.md,
   phase-6-followups SUPERSEDED, ADR-012) + phase-7-plan.md, (b) tüm Faz 7 kodu/UI/test. Sonra
   güvenlik kapısı (secret tara) → `git push origin main` OTOMATİK (force YASAK).
2. Sonra `/next-phase` (Plan Mode) → **Faz 8 (Cloudflare R2)**: private storage + signed URL +
   StorageProvider soyutlaması + lokal→R2 migrasyonu. Faz 7 tetikleyicileri (`phase-7-followups.md`):
   **Pexels download stream+cap HARD GATE** (gerçek stock PROD öncesi), render/cache eviction (R2
   offload ile örtüşür), trend web-fetch worker'a (Faz 6 HARD GATE). İnşa yalnızca `START PHASE 8` ile.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — START PHASE 7: Media Production inşa edildi (src/Media ~24 sınıf: Ffmpeg arg-array wrapper + MediaPaths + WavWriter + AssetCache içerik-adresli + AssemblyEngine draft/final + TTS seam [Mock gerçek WAV/OpenAi kapalı] + Stock seam [lavfi/Pexels kapalı] + AssetFetchExecutor reference→avatar→stock; Nodes final_render; RenderController /render authed; cockpit; reference-asset modeli; migration 0005). GERÇEK ffmpeg varsayılan. ffmpeg build subtitles/drawtext YOK → SRT sidecar+mov_text. 432 test PASS (46 yeni, suite-içi gerçek render), canlı smoke draft+final OK. 3 reviewer 0 blocker, ucuz should-fix uygulandı. Kabul + commit bekliyor.
- 2026-06-12 — /next-phase + PİVOT: Faz 7 planı ONAYLANDI. Kullanıcı reference-asset modelini tanımladı → shooting-brief/awaiting_recording KALDIRILDI (ADR-012). Step A doc güncellemeleri yapıldı. ffmpeg GERÇEK varsayılan kararı.
- 2026-06-12 — FAZ 6 KABUL: kullanıcı kabul + commit + push onayı verdi; key'li-URL redaction kanıt testi istedi (gerçek CurlHttpClient loopback:1 → exception'da key/query redact + uçtan uca). 386 test PASS. Faz 6 `393d666` commit'lendi, origin/main'e push edildi (auto-push).
- 2026-06-12 — START PHASE 6: Trend Radar Backend inşa edildi (TrendProvider seam: Mock varsayılan + gerçek-ama-kapalı YouTube/Google; HttpClient get(); TrendService read-through cache+TTL+serve-stale; QuotaCounter; create-from-trend Engine pin; /trends UI; migration 0004). 3 reviewer 0 blocker, ucuz should-fix uygulandı.
- 2026-06-12 — /next-phase: Faz 6 (Trend Radar Backend) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-6-plan.md`'e kaydedildi. Kullanıcı kararları: gerçek GoogleTrends+YouTube adapter'ları flag-KAPALI inşa (Faz 5 deseni); Creator Watch ERTELENDİ.
- 2026-06-12 — FAZ 5 KABUL: kullanıcı kabul + commit onayı + 3 ek istedi (footer Phase 5; worker heartbeat → Dashboard/Queue "worker çalışmıyor" bandı; faz-kapanış smoke'larında [Terminal-2] etiketi → memory). 3 ek uygulandı, 337 test PASS, canlı doğrulandı. Faz 5 `d293145` commit'lendi; kullanıcının Faz 7 cockpit plan-düzenlemesi ayrı `b673b1c` docs commit'i. Sıra: /next-phase → START PHASE 6.
- 2026-06-12 — START PHASE 5: Script & Caption Engine inşa edildi (TextProvider seam: Mock + gerçek-ama-kapalı OpenAI; HttpClient seam + sahte transport; PromptLibrary versiyonlu; VariationEngine tohumlu slop kontrolü; CostCalculator; ContentExecutor; cost-on-awaiting). 3 reviewer 0 blocker, tüm should-fix+NTH uygulandı.
- 2026-06-12 — /next-phase: Faz 5 (Script & Caption Engine) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-5-plan.md`'e kaydedildi. Kararlar: engine-only UI, gerçek OpenAI yolu flag-kapalı, Claude ertelendi, migration yok.
- 2026-06-12 — FAZ 4 KABUL: kullanıcı kabul + commit onayı verdi; Faz 4 `f56d4ab` olarak commit'lendi. Kullanıcının üç plan-doc düzenlemesi (Creator Watch, countdown, V2) ayrı `730456c` docs commit'iyle alındı.
- 2026-06-12 — START PHASE 4: Workflow Engine inşa edildi (0003 şema+append-only events, Nodes/Validator/Engine/MockExecutor/Worker/Watchdog/Maintenance, bin/worker.php, 4 sayfa UI). 285 test PASS; 3 reviewer 0 blocker.
