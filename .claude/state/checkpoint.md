# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-13
- Güncelleyen: Claude (**FAZ 13 KABUL + commit + push** — detay ADR-019, 693 PASS/0 FAIL.
  **V1 phase-plan (0–13) TAMAMLANDI.** Sırada faz YOK; kalanlar V2 parking lot.)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 13 (Hardening) KABUL EDİLDİ, commit + push edildi** (2026-06-13) — **SON faz, V1 (0–13) TAMAM**.
  Faz feat'leri: F13 `9b68a67`, F12 `dd34bbb`, F11 `bd6b5a6`, F10 `c664604`, F9 `431e692`, F8 `ddc5cf9`. origin/main = HEAD.
- Faz 13 özeti: 401/403 non-retryable fast-fail (`Core/PermanentFailure`+`Engine::finalizeFailure` ilk denemede
  dead-letter); PostRepository UNIQUE backstop; webhook per-IP rate-limit (migration 0011 `rate_limits` +
  `Core/RateLimiter`); WAL-aware backup/restore (`Core/SqliteBackup` VACUUM INTO + `bin/backup.php`/`restore.php`);
  `bin/r2-smoke.php` enable-time gate; Caddy header/blocklist+HSTS; `production-readiness.md`. **Tam detay → ADR-019.**
- Doğrulama: **693 PASS, 0 FAIL**; 3 reviewer GO (security ZORUNLU GO/0). **Ertelenenler → `phase-13-followups.md`.**
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

0. **V1 PHASE-PLAN (0–13) TAMAMLANDI.** Yeni faz token'ı YOK. Bundan sonrası ya (a) V2 parking lot
   (Stripe, multi-tenant UI, onboarding, AI avatars, ElevenLabs, ek AI-video sağlayıcıları, Creator Watch,
   branching graph, MRR paneli), ya (b) followup'ların ele alınması, ya da (c) operatör enable-time adımları
   (R2 smoke + STORAGE_DRIVER=r2, Zernio doc-gate 12 madde, AI-video 7 madde, `caddy validate` + tunnel).
   Yeni bir iş için kullanıcı yönlendirmesi bekle.
1. **Operatör enable-time (production-readiness.md):** R2 → `bin/r2-smoke.php` PASS + PRIVATE teyidi sonra
   `STORAGE_DRIVER=r2`; backup cron (`bin/backup.php`); `caddy validate` + canlı tunnel; prod `.env`
   APP_DEBUG=false + gerçek key'ler. Not: gerçek dev DB 0011'e migrate edildi (WAL-safe yedek:
   `storage/database/kuyash.pre-0011.bak.sqlite`).
2. Faz 12 ertelenenler: `.claude/docs/phase-12-followups.md` (localSourcePath dedup, startRun branch
   stratejisi, ai_video units=seconds gerçek-fiyatla, executor real-cost passthrough testi, async/poll
   gerçek-entegrasyon → ai-video-notes.md 7 madde).
3. Faz 11 ertelenenler: `.claude/docs/phase-11-followups.md` (MTD basis-change deploy notu, model/units
   executor seam'inden surface etme, OpenAI/Pexels quota /usage'da, 401/403 non-retryable fast-fail).
4. Açık HARD GATE'ler (Faz 8'den): STORAGE_DRIVER=r2 enable-time canlı-bucket SigV4 smoke + PRIVATE/no-ACL
   teyidi; Faz 13'e ertelenen assembly-side staging + render/cache eviction. Detay: ADR-014.
5. Faz 10 ertelenenler: `.claude/docs/phase-10-followups.md` (cockpit countdown, account-subset UI, webhook
   rate-limit, cap-unification asymmetry [S1]).

## Açık konular / bekleyenler

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-13 — FAZ 13 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 13 feat commit + `git push origin main` (auto-push). Faz 13 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-019), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). **V1 phase-plan (0–13) TAMAMLANDI** — sırada faz YOK; bundan sonrası V2 parking lot / followup'lar / operatör enable-time adımları.
- 2026-06-13 — START PHASE 13: Hardening (final faz 13/13) İNŞA EDİLDİ. (1) 401/403 non-retryable fast-fail: `Core/PermanentFailure(+Exception)`, `JobResult::failedPermanent()`+retryable bayrak, `Engine::finalizeFailure` non-retryable→ilk denemede dead-letter (backoff yok), `Worker` uncaught PermanentFailure sınıflandırır, OpenAI text/TTS+Pexels 401/403→PermanentFailureException (domain değil → executor catch'i geçer → Worker). (2) PostRepository `insertPublishing` UNIQUE backstop (collision→mevcut id). (3) webhook per-IP rate-limit: migration 0011 `rate_limits` + `Core/RateLimiter` (120/60s, clock-injectable) → `WebhookController` 429 (HMAC/fail-closed önce çalışır). (4) WAL-aware backup/restore: `Core/SqliteBackup` (wal_checkpoint+VACUUM INTO+integrity), `bin/backup.php` (DB+media+manifest, --db-only), `bin/restore.php` (dry-run/--force, DB move-aside, integrity). (5) `bin/r2-smoke.php` enable-time gate (put→presign GET→anon GET 401/403 PRIVATE teyidi→delete; exit 0/1/2). (6) Caddyfile `(app)` snippet + blocklist genişletme (/database,/bin,/tests) + prod HTTPS+HSTS bloğu. (7) `production-readiness.md` + `release-test-checklist.md` + `phase-13-followups.md`. 693 PASS (+20). 3 reviewer: security **ZORUNLU GO/0**, compliance GO/0, ux GO (1 polish UYGULANDI: queue `non-retryable:`→"(no auto-retry)"). Ertelenenler: CF-Connecting-IP per-IP (tunnel ardında REMOTE_ADDR global), restore symlink containment, rate-limit write-amp. Smoke: backup/restore round-trip OK, real-DB 0011 (WAL-safe yedek) + HTTP boot OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — /next-phase: Faz 13 (Hardening) — final faz (13/13) — planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-13-plan.md`'e kaydedildi. Kapsam: tam kümülatif güvenlik incelemesi (security-auditor ZORUNLU) + taşınan hardening followup'ları (webhook per-IP rate-limit, PostRepository UNIQUE backstop, 401/403 non-retryable fast-fail); test-checklist + 2 regresyon (executor real-cost passthrough, recorder-no-rollback); WAL-aware backup/restore (bin/backup.php + restore.php, round-trip integrity_check); Caddy/Tunnel header incelemesi; failure-recovery doğrulaması (watchdog/dead-letter/kill switch); R2 enable-time smoke tooling (bin/r2-smoke.php SigV4+PRIVATE, operator-gated); production-readiness.md. 2 KİLİTLİ KARAR: (a) R2 staging/eviction operator-gated (tooling+doküman, spekülatif kod YOK); (b) LOW php refactor'ları tech-debt dokümante, YAPILMAZ. Non-goal: yeni feature YOK, real entegrasyon flip YOK. Kod YAZILMADI — START PHASE 13 bekleniyor.
- 2026-06-13 — FAZ 12 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 12 feat `dd34bbb` commit + `git push origin main` (auto-push). Faz 12 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-018), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 13 (Hardening).
- 2026-06-13 — START PHASE 12: Quick Create AI image-to-video İNŞA EDİLDİ. migration 0010 (workflows.template rebuild → 'quick_create'); **Migrator FK-off+foreign_key_check kapısı** (parent-tablo rebuild güvenli; gerçek dev DB 12 run/0 ihlal). VideoGenProvider seam (Mock ffmpeg-zoompan/$0 + Fal doc-gated flag-off stub + VideoResult/Exception); AiVideoExecutor (AssetCache içerik-adresli cache-hit=null cost, draft render, **ai_label_required=true HEP**); Nodes source-aware expand (VISUALS source=ai→ai_video, polymorphic back-compat); Engine quick_create branch (prompt nodes_json snapshot + re-validate); CostEstimator source-aware; FinalRender/Compliance/MockExecutor ai_video okur; WorkflowRepository seed+exclude+findByTemplate; /quick sayfası (QuickCreateController + template + nav "Create" + CSS). 673 PASS (+43). 5 reviewer: compliance GO/0 (ZORUNLU), security GO/0, php GO/0, qa GO/0, ux KOŞULLU→2 should-fix (caps hint→field__hint, upload-trap→delete+ayrı mesaj) + nitler (radiogroup, focus-visible, .env.example) UYGULANDI. Smoke OK (real-DB 0010 + VIDEO_MOCK=false doc-gated + HTTP boot). Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — /next-phase: Faz 12 (Quick Create AI video, credit-gated) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-12-plan.md`'e kaydedildi. Kullanıcı 3 kilitli karar: (1) kısa brief-faithful zincir (no trend/idea/script/voice); (2) mock-first + doc-gated flag-off real stub (async submit/poll YOK V1); (3) özel /quick sayfası. Mühendislik incelemesi: ASSEMBLE atlandı — AI klip final_render'da distribution gibi normalize edilir (AssemblyExecutor tts+asset_fetch zorunlu kılıyor). migration 0010 workflows.template rebuild ('quick_create'); VideoGenProvider seam + Mock (ffmpeg zoompan) + Fal flag-off stub + AiVideoExecutor; Nodes source-aware expand (VISUALS source=ai→ai_video). Kod YAZILMADI — START PHASE 12 bekleniyor.
- 2026-06-12 — FAZ 11 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 11 feat `bd6b5a6` commit'lendi, güvenlik kapısı (secret grep temiz) + `git push origin main` (auto-push). Faz 11 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-017), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 12 (Quick Create AI video, credit-gated).
- 2026-06-12 — START PHASE 11: Usage/Costs/Credit Ledger İNŞA EDİLDİ. migration 0009 (usage_events append-only UNIQUE(job_id) + credit_transactions grant/spend/adjust); src/Usage/ 6 sınıf (UsageRecorder [Engine::finalize tx içinde tek yazım yolu, yalnız gerçek non-null+pozitif maliyet → mock/cache 0 satır=truthful, idempotent], CostEstimator [config-driven det.], PreflightGate [startRun HARD-BLOCK + guardrail.preflight_block], UsageRepository [MTD tek doğruluk kaynağı], CreditLedger, BudgetExceededException); AutoApprovalGate MTD→usage_events (parity); /usage + nav + footer; bin/grant-credits.php; config/usage.php; Format::cents; WorkflowException un-final. 630 PASS (+43). 5 reviewer GO (security ZORUNLU GO/0). 2 MED qa fix (ayrıştırıcı parity testi + finalizeAwaiting e2e) + ux polish + recorder non-positive-skip uygulandı. Canlı smoke (preflight block uçtan-uca) OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-12 — /next-phase: Faz 11 (Usage, Costs & Credit Ledger) planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-11-plan.md`'e kaydedildi. Kullanıcı 2 kilitli karar: (1) ledger **cents-cinsinden + budget-cap geçidi** (prepaid kredi ekonomisi reddedildi — krediler cents üzerine görüntü katmanı, grant manuel/bin script, Stripe yok); (2) preflight **hard-block** (over-budget run reddedilir). `usage_events` = MTD tek doğruluk kaynağı (AutoApprovalGate repoint). Kod YAZILMADI — START PHASE 11 bekleniyor.
- 2026-06-12 — FAZ 10 KABUL: kullanıcı kabul + commit + push onayı verdi. Faz 10 feat `c664604` commit'lendi, origin/main'e push edildi (auto-push). Faz 10 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-016), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 11 (Usage/costs/credits).
