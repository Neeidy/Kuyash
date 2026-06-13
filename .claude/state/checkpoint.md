# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-12
- Güncelleyen: Claude (**FAZ 11 KABUL + commit `bd6b5a6` + auto-push edildi**. Detay → ADR-017.
  630 PASS/0 FAIL. Sıra: **/next-phase → Faz 12 (Quick Create AI video, credit-gated)**)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 11 (Usage/Costs/Credit Ledger) KABUL EDİLDİ, commit + push edildi** (2026-06-12).
  **Faz 11 feat `bd6b5a6`** (F10 `c664604`, F9 `431e692`, F8 `ddc5cf9`, F7 `b90cb8e`). origin/main = HEAD.
- Faz 11 özeti: gerçek **usage_events** spend ledger (MTD tek doğruluk kaynağı) + para-cinsi **credit_transactions**
  (balance=SUM); UsageRecorder truthful+idempotent (mock/cache 0 satır), PreflightGate over-budget run HARD-BLOCK;
  AutoApprovalGate MTD→usage_events. /usage sayfası + bin/grant-credits.php (Stripe yok). **Tam detay → ADR-017.**
- Doğrulama: **630 PASS, 0 FAIL**; 5 reviewer GO (security ZORUNLU/0 blocker). **Ertelenenler → `phase-11-followups.md`.**
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

1. **`/next-phase` → Faz 12 (Quick Create AI video, credit-gated).** Faz 12 bu ledger'a dayanır:
   `ai_video` kategorisi estimator'da fiyatlı placeholder olarak hazır (config/usage.php); usage_events +
   credit gate yerinde. **compliance-reviewer ZORUNLU** Faz 12 kapanışında (AI-label + credit-gate).
2. Faz 11 ertelenenler: `.claude/docs/phase-11-followups.md` (MTD basis-change deploy notu, model/units
   executor seam'inden surface etme, OpenAI/Pexels quota /usage'da, 401/403 non-retryable fast-fail).
3. Açık HARD GATE'ler (Faz 8'den): STORAGE_DRIVER=r2 enable-time canlı-bucket SigV4 smoke + PRIVATE/no-ACL
   teyidi; Faz 13'e ertelenen assembly-side staging + render/cache eviction. Detay: ADR-014.
4. Faz 10 ertelenenler: `.claude/docs/phase-10-followups.md` (cockpit countdown, account-subset UI, webhook
   rate-limit, cap-unification asymmetry [S1]).

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-12 — FAZ 11 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 11 feat `bd6b5a6` commit'lendi, güvenlik kapısı (secret grep temiz) + `git push origin main` (auto-push). Faz 11 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-017), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 12 (Quick Create AI video, credit-gated).
- 2026-06-12 — START PHASE 11: Usage/Costs/Credit Ledger İNŞA EDİLDİ. migration 0009 (usage_events append-only UNIQUE(job_id) + credit_transactions grant/spend/adjust); src/Usage/ 6 sınıf (UsageRecorder [Engine::finalize tx içinde tek yazım yolu, yalnız gerçek non-null+pozitif maliyet → mock/cache 0 satır=truthful, idempotent], CostEstimator [config-driven det.], PreflightGate [startRun HARD-BLOCK + guardrail.preflight_block], UsageRepository [MTD tek doğruluk kaynağı], CreditLedger, BudgetExceededException); AutoApprovalGate MTD→usage_events (parity); /usage + nav + footer; bin/grant-credits.php; config/usage.php; Format::cents; WorkflowException un-final. 630 PASS (+43). 5 reviewer GO (security ZORUNLU GO/0). 2 MED qa fix (ayrıştırıcı parity testi + finalizeAwaiting e2e) + ux polish + recorder non-positive-skip uygulandı. Canlı smoke (preflight block uçtan-uca) OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 11 (Usage, Costs & Credit Ledger) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-11-plan.md`'e kaydedildi. Kullanıcı 2 kilitli karar: (1) ledger **cents-cinsinden + budget-cap geçidi** (prepaid kredi ekonomisi reddedildi — krediler cents üzerine görüntü katmanı, grant manuel/bin script, Stripe yok); (2) preflight **hard-block** (over-budget run reddedilir). `usage_events` = MTD tek doğruluk kaynağı (AutoApprovalGate repoint). Kod YAZILMADI — START PHASE 11 bekleniyor.
- 2026-06-12 — FAZ 10 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 10 feat `c664604` commit'lendi, origin/main'e push edildi (auto-push). Faz 10 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-016), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 11 (Usage/costs/credits).
- 2026-06-12 — START PHASE 10: Zernio Publishing İNŞA EDİLDİ. migration 0008 (accounts/posts/webhook_events + runs.publish_after); src/Publish/ 13 sınıf (PublishProvider seam + Mock + Zernio doc-gated flag-off stub + Account/Post repo + PublishCounter + ZernioPublishExecutor [per-account fan-out, idempotent, AI-label truthful] + WebhookInbox + WebhookController [HMAC, CSRF-exempt, fail-closed, 64KB cap] + Reconciler); PublishGate per-account cap (posts); Engine schedule (run_after defer); /accounts (mock OAuth state nonce, token YOK) + nav; runs/show Published-targets kartı; worker webhook+reconcile sweeps. 587 PASS (+46). 3 reviewer: security GO/0 (MEDIUM external_url scheme → düzeltildi), compliance GO/0 (ZORUNLU), ux KOŞULLU→2 should-fix uygulandı (chip dot tone, row hizalama). DI + canlı smoke OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 10 (Zernio Publishing) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-10-plan.md`'e kaydedildi. Kararlar: trim cockpit/metrics (followups), gerçekçi iki-bacaklı mock OAuth (token YOK), schedule + immediate (run_after defer), doc-gate SIKI (flag-off stub). Kod YAZILMADI — START PHASE 10 bekleniyor.
- 2026-06-12 — START PHASE 9: Compliance Agent + Approval Modes İNŞA EDİLDİ (model hatası → Fable'dan Opus'a geçişle tamamlandı). migration 0007 (truthful-record CHECK + workspaces compliance kolonları); src/Compliance/ 8 sınıf (Policy/Slop/Executor/Quality/Gate/GateDecision/PublishGate/Digest); Engine compliance+auto-approve+defer branch'leri; /settings+/digest UI; truthful badge'ler. 541 PASS (+74), 0 uyarı. 3 reviewer GO (compliance ZORUNLU=GO/0 blocker, security GO/0 blocker, ux KOŞULLU→3 düzeltme uygulandı). Canlı smoke OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 9 (Compliance Agent + Approval Modes) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-9-plan.md`'e kaydedildi. Kullanıcı kararı: Auto onay kapsamı = pass + pass_with_ai_label (yalnızca-pass reddedildi; warn/block asla auto). Kilitli: truthful-record şema CHECK'i, settings = workspaces kolonları, block = cancel, kuyash-v1 policy sabitleri, PublishGate + deferred. Kod YAZILMADI — START PHASE 9 bekleniyor.
- 2026-06-12 — FAZ 8 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 8 `ddc5cf9` commit'lendi, origin/main'e push edildi (auto-push). Ardından checkpoint temizliği: Faz 7 + Faz 8 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-013 Media Production, ADR-014 Storage abstraction), "Mevcut durum" 4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 9.
- 2026-06-12 — START PHASE 8: Cloudflare R2 storage abstraction İNŞA EDİLDİ. StorageProvider seam (Local varsayılan + real R2 flag-OFF) + el yazımı SigV4 (AWS ListUsers KAT'a karşı doğrulandı) + yeni Http/BlobClient streaming seam + StorageManager/StorageKey/StorageBackfill + migration 0006 (`storage_disk`). Serving per-object → R2 302 presigned (tenant-check önce) / local stream. Write seam put()+storage_disk; Pexels download stream+cap (Faz 7 HARD GATE temizlendi). bin/migrate-storage.php backfill. 467 PASS, 3 reviewer GO/0 blocker, ucuz should-fix (CURLPROTO_HTTPS pin vb.). Commit YAPILMADI — kabul bekliyor.
