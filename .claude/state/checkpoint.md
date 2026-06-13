# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (**FAZ 8 KABUL + commit `ddc5cf9` + push edildi**; checkpoint temizliği: Faz 7/8 mimari
  detayı `architecture-decisions.md` ADR-013/014'e taşındı, "Mevcut durum" özetlendi. Sıra: /next-phase → Faz 9)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 8 (Cloudflare R2 — storage abstraction) KABUL EDİLDİ, commit'lendi + push edildi** (2026-06-12).
  **Faz 8 `ddc5cf9` = HEAD** (Faz 7 `b90cb8e`, Faz 6 `393d666`, Faz 5 `d293145`). origin/main = HEAD. Working tree
  temiz (bu state commit'i hariç).
- Faz 8 özeti: StorageProvider seam (Local varsayılan + R2 flag-KAPALI), el yazımı SigV4 (AWS ListUsers KAT'a
  karşı doğrulandı), yeni Http/BlobClient streaming seam, serving per-object disk → R2 302 presigned (tenant-check
  önce) / local stream, `storage_disk` marker + `bin/migrate-storage.php` backfill (lokal SİLİNMEZ), Pexels
  download stream+cap (Faz 7 HARD GATE temiz). Lokal yol byte-aynı. **Tam mimari detay → ADR-014** (Faz 7 → ADR-013).
- Doğrulama: **467 PASS, 0 FAIL** (+35); 3 reviewer (security/php-architect/integration) **GO / 0 blocker**; canlı
  smoke (default local) regresyonsuz; secret grep temiz. **Enable-time HARD GATE:** STORAGE_DRIVER=r2 öncesi
  canlı-bucket SigV4 smoke + PRIVATE/no-ACL teyidi. **Faz 13'e ertelenen:** assembly-side staging + render/cache eviction.
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

1. **/next-phase → Faz 9 (Compliance Agent).** Faz 9 kapanışında `compliance-reviewer` ZORUNLU
   (+ security/ux). İnşa yalnızca **`START PHASE 9`** token'ı ile başlar.
2. Açık HARD GATE'ler (Faz 8'den): STORAGE_DRIVER=r2 enable-time canlı-bucket SigV4 smoke + PRIVATE/no-ACL
   teyidi; Faz 13'e ertelenen assembly-side staging + render/cache eviction. Detay: ADR-014.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 8 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 8 `ddc5cf9` commit'lendi, origin/main'e push edildi (auto-push). Ardından checkpoint temizliği: Faz 7 + Faz 8 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-013 Media Production, ADR-014 Storage abstraction), "Mevcut durum" 4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 9.
- 2026-06-12 — START PHASE 8: Cloudflare R2 storage abstraction İNŞA EDİLDİ. StorageProvider seam (Local varsayılan + real R2 flag-OFF) + el yazımı SigV4 (AWS ListUsers KAT'a karşı doğrulandı) + yeni Http/BlobClient streaming seam + StorageManager/StorageKey/StorageBackfill + migration 0006 (`storage_disk`). Serving per-object → R2 302 presigned (tenant-check önce) / local stream. Write seam put()+storage_disk; Pexels download stream+cap (Faz 7 HARD GATE temizlendi). bin/migrate-storage.php backfill. 467 PASS, 3 reviewer GO/0 blocker, ucuz should-fix (CURLPROTO_HTTPS pin vb.). Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 8 (Cloudflare R2) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-8-plan.md`'e kaydedildi. 3 kilitli karar: Real R2StorageProvider flag-OFF (el yazımı SigV4, FakeHttpClient) / presigned-redirect serving (tenant-check redirect'ten önce) / per-object `storage_disk` marker + bin/ backfill (coexist, lokal silinmez). Pexels stream+cap HARD GATE plana folded. Kod YAZILMADI — START PHASE 8 bekleniyor.
- 2026-06-12 — FAZ 7 KABUL: kullanıcı kabul + commit + push onayı + `awaiting_recording` etiket temizliği istedi (Messages/Format'tan çıkarıldı; şema stub kaldı). 432 test PASS. Faz 7 `b90cb8e` commit'lendi (Step A doc pivotu dahil), origin/main'e push edildi (auto-push). Sıra: /next-phase → START PHASE 8.
- 2026-06-12 — START PHASE 7: Media Production inşa edildi (src/Media ~24 sınıf: Ffmpeg arg-array wrapper + MediaPaths + WavWriter + AssetCache içerik-adresli + AssemblyEngine draft/final + TTS/Stock seam'leri [mock varsayılan, gerçek flag-kapalı] + AssetFetchExecutor reference→avatar→stock; Nodes final_render; RenderController; cockpit; migration 0005). GERÇEK ffmpeg varsayılan; build'de subtitles/drawtext YOK → SRT sidecar+mov_text. 3 reviewer 0 blocker.
- 2026-06-12 — /next-phase + PİVOT: Faz 7 planı ONAYLANDI. Kullanıcı reference-asset modelini tanımladı → shooting-brief/awaiting_recording KALDIRILDI (ADR-012). Step A doc güncellemeleri yapıldı. ffmpeg GERÇEK varsayılan kararı.
- 2026-06-12 — FAZ 6 KABUL: kullanıcı kabul + commit + push onayı verdi; key'li-URL redaction kanıt testi istedi (gerçek CurlHttpClient loopback:1 → exception'da key/query redact + uçtan uca). 386 test PASS. Faz 6 `393d666` commit'lendi, origin/main'e push edildi (auto-push).
- 2026-06-12 — START PHASE 6: Trend Radar Backend inşa edildi (TrendProvider seam: Mock varsayılan + gerçek-ama-kapalı YouTube/Google; HttpClient get(); TrendService read-through cache+TTL+serve-stale; QuotaCounter; create-from-trend Engine pin; /trends UI; migration 0004). 3 reviewer 0 blocker, ucuz should-fix uygulandı.
- 2026-06-12 — /next-phase: Faz 6 (Trend Radar Backend) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-6-plan.md`'e kaydedildi. Kullanıcı kararları: gerçek GoogleTrends+YouTube adapter'ları flag-KAPALI inşa (Faz 5 deseni); Creator Watch ERTELENDİ.
- 2026-06-12 — FAZ 5 KABUL: kullanıcı kabul + commit onayı + 3 ek istedi (footer Phase 5; worker heartbeat → Dashboard/Queue "worker çalışmıyor" bandı; faz-kapanış smoke'larında [Terminal-2] etiketi → memory). 3 ek uygulandı, 337 test PASS, canlı doğrulandı. Faz 5 `d293145` commit'lendi; kullanıcının Faz 7 cockpit plan-düzenlemesi ayrı `b673b1c` docs commit'i. Sıra: /next-phase → START PHASE 6.
