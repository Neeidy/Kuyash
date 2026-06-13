# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (**FAZ 9 İNŞA EDİLDİ — KABUL/COMMIT BEKLİYOR.** Commit YAPILMADI.
  Sıra: kullanıcı kabul + commit onayı → güvenlik kapısı + auto-push)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 9 (Compliance Agent + Approval Modes) İNŞA EDİLDİ; kabul bekliyor** (2026-06-12).
  HEAD hâlâ **Faz 8 `ddc5cf9`** (Faz 7 `b90cb8e`, Faz 6 `393d666`). origin/main = `ddc5cf9`. Working tree'de
  Faz 9 değişiklikleri (commit'lenmemiş). NOT: model hatası nedeniyle build Fable→Opus geçişiyle tamamlandı.
- Faz 9 özeti: migration 0007 (workspaces compliance kolonları + approvals rebuild **truthful-record CHECK**:
  manual⇒gerçek user+policy NULL / auto⇒user NULL+policy). src/Compliance/ (CompliancePolicy kuyash-v1, SlopScorer
  script+caption max-Jaccard, ComplianceCheckExecutor ai_label/format/slop → pass/pass_with_ai_label/warn/block,
  QualityScore risk formülü breach<60&sample≥5, AutoApprovalGate sıralı guardrail'ler + GateDecision,
  PublishGateExecutor defer, DigestReport). Engine: compliance branch (block=run cancel), finalizeAwaiting gate
  consult + finalizeAutoApproved (truthful auto kayıt), JobResult::deferred + finalizeDeferred (retry artmaz).
  /settings + /digest controller/template, truthful badge'ler (runs/show + queue chip). **Tam detay → faz kapanınca ADR-015.**
- Doğrulama: **541 PASS, 0 FAIL** (+74); 0 PHP uyarısı; **3 reviewer GO** (compliance ZORUNLU=GO/0 blocker,
  security GO/0 blocker, ux KOŞULLU→3 düzeltme uygulandı: kill-switch confirm form'a, .field__hint cascade,
  sample<5'te quality score "—"). Canlı smoke (8082, smoke4): /settings+/digest render+persist, kill switch,
  CSRF 403, worker temiz. Ertelenenler → `.claude/docs/phase-9-followups.md`. dev DB manual varsayılana döndü.
- Auto kapsamı (kilit karar 1): **pass + pass_with_ai_label** auto-onaylanır; warn/block ASLA. compliance-reviewer onayladı.
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

1. **Faz 9 KABUL onayı bekleniyor.** Kullanıcı onayı gelince: güvenlik kapısı (secret grep — temiz) →
   `git add` + commit (`feat(compliance): …`) → `git push origin main` (auto-push kuralı). Sonra checkpoint
   temizliği: Faz 9 implementasyon detayını ADR-015'e taşı, "Mevcut durum"u ~4 satıra indir.
2. Açık HARD GATE'ler (Faz 8'den): STORAGE_DRIVER=r2 enable-time canlı-bucket SigV4 smoke + PRIVATE/no-ACL
   teyidi; Faz 13'e ertelenen assembly-side staging + render/cache eviction. Detay: ADR-014.
3. Faz 9 ertelenenler: `.claude/docs/phase-9-followups.md` (UX N3-N5, güvenlik L1-L3, Faz 10 per-account
   sayaç birleştirme, Faz 11 budget ledger). Sonraki faz: **Faz 10 (Zernio publishing)** — doc-gated.

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — START PHASE 9: Compliance Agent + Approval Modes İNŞA EDİLDİ (model hatası → Fable'dan Opus'a geçişle tamamlandı). migration 0007 (truthful-record CHECK + workspaces compliance kolonları); src/Compliance/ 8 sınıf (Policy/Slop/Executor/Quality/Gate/GateDecision/PublishGate/Digest); Engine compliance+auto-approve+defer branch'leri; /settings+/digest UI; truthful badge'ler. 541 PASS (+74), 0 uyarı. 3 reviewer GO (compliance ZORUNLU=GO/0 blocker, security GO/0 blocker, ux KOŞULLU→3 düzeltme uygulandı). Canlı smoke OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 9 (Compliance Agent + Approval Modes) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-9-plan.md`'e kaydedildi. Kullanıcı kararı: Auto onay kapsamı = pass + pass_with_ai_label (yalnızca-pass reddedildi; warn/block asla auto). Kilitli: truthful-record şema CHECK'i, settings = workspaces kolonları, block = cancel, kuyash-v1 policy sabitleri, PublishGate + deferred. Kod YAZILMADI — START PHASE 9 bekleniyor.
- 2026-06-12 — FAZ 8 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 8 `ddc5cf9` commit'lendi, origin/main'e push edildi (auto-push). Ardından checkpoint temizliği: Faz 7 + Faz 8 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-013 Media Production, ADR-014 Storage abstraction), "Mevcut durum" 4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 9.
- 2026-06-12 — START PHASE 8: Cloudflare R2 storage abstraction İNŞA EDİLDİ. StorageProvider seam (Local varsayılan + real R2 flag-OFF) + el yazımı SigV4 (AWS ListUsers KAT'a karşı doğrulandı) + yeni Http/BlobClient streaming seam + StorageManager/StorageKey/StorageBackfill + migration 0006 (`storage_disk`). Serving per-object → R2 302 presigned (tenant-check önce) / local stream. Write seam put()+storage_disk; Pexels download stream+cap (Faz 7 HARD GATE temizlendi). bin/migrate-storage.php backfill. 467 PASS, 3 reviewer GO/0 blocker, ucuz should-fix (CURLPROTO_HTTPS pin vb.). Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 8 (Cloudflare R2) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-8-plan.md`'e kaydedildi. 3 kilitli karar: Real R2StorageProvider flag-OFF (el yazımı SigV4, FakeHttpClient) / presigned-redirect serving (tenant-check redirect'ten önce) / per-object `storage_disk` marker + bin/ backfill (coexist, lokal silinmez). Pexels stream+cap HARD GATE plana folded. Kod YAZILMADI — START PHASE 8 bekleniyor.
- 2026-06-12 — FAZ 7 KABUL: kullanıcı kabul + commit + push onayı + `awaiting_recording` etiket temizliği istedi (Messages/Format'tan çıkarıldı; şema stub kaldı). 432 test PASS. Faz 7 `b90cb8e` commit'lendi (Step A doc pivotu dahil), origin/main'e push edildi (auto-push). Sıra: /next-phase → START PHASE 8.
- 2026-06-12 — START PHASE 7: Media Production inşa edildi (src/Media ~24 sınıf: Ffmpeg arg-array wrapper + MediaPaths + WavWriter + AssetCache içerik-adresli + AssemblyEngine draft/final + TTS/Stock seam'leri [mock varsayılan, gerçek flag-kapalı] + AssetFetchExecutor reference→avatar→stock; Nodes final_render; RenderController; cockpit; migration 0005). GERÇEK ffmpeg varsayılan; build'de subtitles/drawtext YOK → SRT sidecar+mov_text. 3 reviewer 0 blocker.
- 2026-06-12 — /next-phase + PİVOT: Faz 7 planı ONAYLANDI. Kullanıcı reference-asset modelini tanımladı → shooting-brief/awaiting_recording KALDIRILDI (ADR-012). Step A doc güncellemeleri yapıldı. ffmpeg GERÇEK varsayılan kararı.
- 2026-06-12 — FAZ 6 KABUL: kullanıcı kabul + commit + push onayı verdi; key'li-URL redaction kanıt testi istedi (gerçek CurlHttpClient loopback:1 → exception'da key/query redact + uçtan uca). 386 test PASS. Faz 6 `393d666` commit'lendi, origin/main'e push edildi (auto-push).
- 2026-06-12 — START PHASE 6: Trend Radar Backend inşa edildi (TrendProvider seam: Mock varsayılan + gerçek-ama-kapalı YouTube/Google; HttpClient get(); TrendService read-through cache+TTL+serve-stale; QuotaCounter; create-from-trend Engine pin; /trends UI; migration 0004). 3 reviewer 0 blocker, ucuz should-fix uygulandı.
- 2026-06-12 — /next-phase: Faz 6 (Trend Radar Backend) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-6-plan.md`'e kaydedildi. Kullanıcı kararları: gerçek GoogleTrends+YouTube adapter'ları flag-KAPALI inşa (Faz 5 deseni); Creator Watch ERTELENDİ.