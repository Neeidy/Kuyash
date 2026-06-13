# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (**FAZ 10 (Zernio Publishing) KABUL EDİLDİ, commit'lendi + push edildi**.
  feat `c664604`. Detay ADR-016'ya taşındı. Sıra: **/next-phase → Faz 11**)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 10 (Zernio Publishing) KABUL EDİLDİ, commit + push edildi** (2026-06-12).
  **Faz 10 feat `c664604`** (Faz 9 `431e692`, Faz 8 `ddc5cf9`, Faz 7 `b90cb8e`). origin/main = HEAD.
  Working tree temiz (bu state commit'i hariç).
- Faz 10 özeti: PUBLISH artık **mock-first + doc-gated** yayın alt-sistemi — accounts + per-account posts +
  webhook inbox + reconciliation, PublishProvider seam arkasında. migration 0008. **Tam mimari detay → ADR-016.**
- Doğrulama: **587 PASS, 0 FAIL** (+46); **3 reviewer**: security **GO**/0 blocker, compliance ZORUNLU **GO**/0 blocker,
  ux KOŞULLU→2 should-fix uygulandı. Canlı smoke (8082, smoke4) regresyonsuz; secret grep temiz; auto-push yapıldı.
  **Ertelenenler → `.claude/docs/phase-10-followups.md`.**
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

1. **/next-phase → Faz 11 (Usage/costs/credits).** Budget/credit ledger + preflight estimation
   burada; Faz 9'un observed `SUM(jobs.cost_cents)` yaklaşımının ve Faz 10 cap-unification S1'in
   yerini alır. Plan Mode önerilir.
2. Açık HARD GATE'ler (Faz 8'den): STORAGE_DRIVER=r2 enable-time canlı-bucket SigV4 smoke + PRIVATE/no-ACL
   teyidi; Faz 13'e ertelenen assembly-side staging + render/cache eviction. Detay: ADR-014.
3. Faz 10 ertelenenler: `.claude/docs/phase-10-followups.md` (cockpit countdown widget + account_metrics,
   account-subset UI, webhook rate-limit, cap-unification asymmetry [S1], UX in-flight affordance).

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 10 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 10 feat `c664604` commit'lendi, origin/main'e push edildi (auto-push). Faz 10 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-016), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 11 (Usage/costs/credits).
- 2026-06-12 — START PHASE 10: Zernio Publishing İNŞA EDİLDİ. migration 0008 (accounts/posts/webhook_events + runs.publish_after); src/Publish/ 13 sınıf (PublishProvider seam + Mock + Zernio doc-gated flag-off stub + Account/Post repo + PublishCounter + ZernioPublishExecutor [per-account fan-out, idempotent, AI-label truthful] + WebhookInbox + WebhookController [HMAC, CSRF-exempt, fail-closed, 64KB cap] + Reconciler); PublishGate per-account cap (posts); Engine schedule (run_after defer); /accounts (mock OAuth state nonce, token YOK) + nav; runs/show Published-targets kartı; worker webhook+reconcile sweeps. 587 PASS (+46). 3 reviewer: security GO/0 (MEDIUM external_url scheme → düzeltildi), compliance GO/0 (ZORUNLU), ux KOŞULLU→2 should-fix uygulandı (chip dot tone, row hizalama). DI + canlı smoke OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 10 (Zernio Publishing) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-10-plan.md`'e kaydedildi. Kararlar: trim cockpit/metrics (followups), gerçekçi iki-bacaklı mock OAuth (token YOK), schedule + immediate (run_after defer), doc-gate SIKI (flag-off stub). Kod YAZILMADI — START PHASE 10 bekleniyor.
- 2026-06-12 — START PHASE 9: Compliance Agent + Approval Modes İNŞA EDİLDİ (model hatası → Fable'dan Opus'a geçişle tamamlandı). migration 0007 (truthful-record CHECK + workspaces compliance kolonları); src/Compliance/ 8 sınıf (Policy/Slop/Executor/Quality/Gate/GateDecision/PublishGate/Digest); Engine compliance+auto-approve+defer branch'leri; /settings+/digest UI; truthful badge'ler. 541 PASS (+74), 0 uyarı. 3 reviewer GO (compliance ZORUNLU=GO/0 blocker, security GO/0 blocker, ux KOŞULLU→3 düzeltme uygulandı). Canlı smoke OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 9 (Compliance Agent + Approval Modes) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-9-plan.md`'e kaydedildi. Kullanıcı kararı: Auto onay kapsamı = pass + pass_with_ai_label (yalnızca-pass reddedildi; warn/block asla auto). Kilitli: truthful-record şema CHECK'i, settings = workspaces kolonları, block = cancel, kuyash-v1 policy sabitleri, PublishGate + deferred. Kod YAZILMADI — START PHASE 9 bekleniyor.
- 2026-06-12 — FAZ 8 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 8 `ddc5cf9` commit'lendi, origin/main'e push edildi (auto-push). Ardından checkpoint temizliği: Faz 7 + Faz 8 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-013 Media Production, ADR-014 Storage abstraction), "Mevcut durum" 4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 9.
- 2026-06-12 — START PHASE 8: Cloudflare R2 storage abstraction İNŞA EDİLDİ. StorageProvider seam (Local varsayılan + real R2 flag-OFF) + el yazımı SigV4 (AWS ListUsers KAT'a karşı doğrulandı) + yeni Http/BlobClient streaming seam + StorageManager/StorageKey/StorageBackfill + migration 0006 (`storage_disk`). Serving per-object → R2 302 presigned (tenant-check önce) / local stream. Write seam put()+storage_disk; Pexels download stream+cap (Faz 7 HARD GATE temizlendi). bin/migrate-storage.php backfill. 467 PASS, 3 reviewer GO/0 blocker, ucuz should-fix (CURLPROTO_HTTPS pin vb.). Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 8 (Cloudflare R2) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-8-plan.md`'e kaydedildi. 3 kilitli karar: Real R2StorageProvider flag-OFF (el yazımı SigV4, FakeHttpClient) / presigned-redirect serving (tenant-check redirect'ten önce) / per-object `storage_disk` marker + bin/ backfill (coexist, lokal silinmez). Pexels stream+cap HARD GATE plana folded. Kod YAZILMADI — START PHASE 8 bekleniyor.
- 2026-06-12 — FAZ 7 KABUL: kullanıcı kabul + commit + push onayı + `awaiting_recording` etiket temizliği istedi (Messages/Format'tan çıkarıldı; şema stub kaldı). 432 test PASS. Faz 7 `b90cb8e` commit'lendi (Step A doc pivotu dahil), origin/main'e push edildi (auto-push). Sıra: /next-phase → START PHASE 8.
- 2026-06-12 — START PHASE 7: Media Production inşa edildi (src/Media ~24 sınıf: Ffmpeg arg-array wrapper + MediaPaths + WavWriter + AssetCache içerik-adresli + AssemblyEngine draft/final + TTS/Stock seam'leri [mock varsayılan, gerçek flag-kapalı] + AssetFetchExecutor reference→avatar→stock; Nodes final_render; RenderController; cockpit; migration 0005). GERÇEK ffmpeg varsayılan; build'de subtitles/drawtext YOK → SRT sidecar+mov_text. 3 reviewer 0 blocker.