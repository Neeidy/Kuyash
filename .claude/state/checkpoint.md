# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-13
- Güncelleyen: Claude (**Faz 15 (Design Foundation — konsolidasyon) KABUL + commit `3fda7d0` + push edildi**.
  Salt CSS drift-fix + dark date/time input fix; 732 PASS, ux GO. **AKTİF: Elevation Decision Gate** — kullanıcı
  ekrana bakıp ŞİMDİ karar verir: gerekmez→Faz 16, gerekir→Faz 15.5. Faz 14 KABUL'dü [`2e4bd41`, chore `b94d534`].)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 15 (Design Foundation — konsolidasyon) KABUL + commit `3fda7d0` + push edildi** (2026-06-13).
  Experience Layer'ın 1. fazı. Faz feat'leri: F15 `3fda7d0`, F14 `2e4bd41`, F13 `9b68a67`, F12 `dd34bbb`. origin/main = HEAD.
- Faz 15 özeti (salt sunum, PHP/DB/route/i18n/motion DOKUNULMADI): keşif premisi çürüttü — premium karanlık
  sistem app'te ZATEN vardı (demo'dan faz faz portlanmış); iş = **drift-fix**. 6 düzeltme: (1) tanımsız
  `var(--radius)`→`--r-card` (trend/KPI kare köşeydi); (2) off-palette `var(--text-dim,#8b949e)`→`--text-3` ×6;
  (3) `var(--surface-2,#0d1117)`→`--surface-2`; (4) ölü selektör base.css `.kpi__value`→`.kpi__num`+quality+trend
  (tabular-nums); (5) 3 sayı idiomu (kpi 26/quality 40/trend 20) → mono+500 (JetBrains yalnız 400/500 yüklü →
  700 faux-bold'du); (6) native date/time input'lar `color-scheme:dark` + token-uyumlu stil (dark temada beyaz
  geliyordu; 4 canlı publish-schedule input'a uygulanıyor). Yalnız `base.css`+`app.css` (+~30). Template DEĞİŞMEDİ.
  ux should-fix UYGULANDI: `.field__hint` (talimat metni) `--text-3`→`--text-2` (WCAG AA). Kalan faint-tier
  a11y borcu → `phase-15-followups.md` A11Y-1 (elevation fazına).
- Faz 14 özeti: `Core/I18n` static çevirmen (locale→en→key fallback, `{name}`) + `View::t()` escaped;
  `lang/en.php`+`lang/tr.php` (478 anahtar parite, Messages MAP/EVENTS/STATUS foldlandı → Messages I18n facade,
  "tek sınıf swap"); migration **0012** `users.locale`; per-user locale (session-cache + `/locale` CSRF switch);
  `<html lang>` + topbar EN/TR toggle; 21 template ~250 literal → `View::t()`. **Tam detay → ADR-020.**
- Doğrulama: **732 PASS, 0 FAIL**; 3 reviewer GO (compliance TR-truthfulness GATE GO/0). Ertelenenler (kozmetik):
  aria-current SR etiketi, birkaç enum data olarak çevrilmedi.
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

0. **SIRADA: Elevation Decision Gate (AKTİF) — kullanıcı ŞİMDİ karar verir.** Faz 15 KABUL + commit `3fda7d0`
   + push edildi (origin/main = HEAD). Kullanıcı ekrandaki sonucu görür ve elevation kararını ŞİMDİ verir
   ("sonra bakılır" YOK): **gerekmez → `/next-phase` + `START PHASE 16` (Motion & Interaction)**; **gerekir →
   net `Faz 15.5` (Elevation: hiyerarşi/yoğunluk, yükseltilmiş kart/yüzey, zengin empty/loading/error;
   salt sunum, PHP/DB/route/i18n DOKUNULMAZ) planı yaz, sonra token**. Detay: phase-plan.md "Elevation
   Decision Gate" notu. Hangi yola gidilirse a11y borcu taşınır → `phase-15-followups.md` A11Y-1 (faint
   `--text-3` tier küçük metinde WCAG AA altı).
   *Alternatif rota (Experience Layer durdurulursa): operatör enable-time / V2 parking lot / followups (aşağıda).*
1. **Operatör enable-time (production-readiness.md):** R2 → `bin/r2-smoke.php` PASS + PRIVATE teyidi sonra
   `STORAGE_DRIVER=r2`; backup cron (`bin/backup.php`); `caddy validate` + canlı tunnel; prod `.env`
   APP_DEBUG=false + gerçek key'ler. Not: gerçek dev DB **0012'ye** migrate edildi (WAL-safe yedek:
   `storage/database/kuyash.pre-0012.bak.sqlite` + `storage/backups/20260613T215117Z/`).
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

- 2026-06-13 — START PHASE 15: Design Foundation (konsolidasyon) İNŞA EDİLDİ. Keşif premisi çürüttü → premium karanlık sistem app'te zaten vardı; iş = salt CSS **drift-fix** (yalnız `base.css`+`app.css`, +~18; template DEĞİŞMEDİ). 5 düzeltme: tanımsız `var(--radius)`→`--r-card` (trend/KPI kare köşe); off-palette `var(--text-dim,#8b949e)`→`--text-3` ×6; `var(--surface-2,#0d1117)`→`--surface-2`; ölü selektör base.css `.kpi__value`→`.kpi__num`+quality+trend (tabular-nums); 3 sayı idiomu (kpi/quality/trend) → mono+500 (JetBrains yalnız 400/500 yüklü, 700 faux-bold'du). Audit: 0 off-palette fallback, her `var(--token)` çözülüyor (yalnız inline `--i` stagger hariç), kalan raw hex sadece kasıtlı `#000` letterbox + `#fff` danger. 732 PASS. HTTP smoke: 11 ekran 200/0-hata, error sayfaları 404/302, 0 harici istek, TR toggle `lang="tr"` %100. ux-reviewer KOŞULLU-GO (0 blocker, 1 should-fix): `--text-3` (#6b6b74) faint tier küçük metinde WCAG AA altı (~3.4-3.8:1) — UYGULANDI: talimat metni `.field__hint`→`--text-2` (AA geçer); kalan faint-tier app-geneli pre-existing borç açıkça kaydedildi → `phase-15-followups.md` A11Y-1 (elevation 16/18). KABUL: kullanıcı ayrıca dark temada beyaz gelen native date/time input fix'ini istedi → `input[type=datetime-local/date/time]` `color-scheme:dark` + token-uyumlu form stili (yalnız app.css; queue'da 4 canlı publish-schedule input'a uygulanıyor; served-CSS+/queue 200 smoke OK; 732 PASS korundu). feat `3fda7d0` commit + `git push origin main` (auto-push). phase-plan.md'ye **Elevation Decision Gate** notu eklendi (chore) → kullanıcı ekrana bakıp elevation kararını ŞİMDİ verir: gerekmez→F16, gerekir→F15.5 ("sonra bakılır" yok).
- 2026-06-13 — chore(state) push (`2ac3a4f`): Experience Layer fazları (15-18) phase-plan.md'ye + checkpoint Faz 15'e işaretlendi. Sonra **/next-phase → Faz 15 planı** (Plan Mode): keşif premisi çürüttü — premium karanlık tasarım sistemi GERÇEK app'te ZATEN var (`base.css`/`app.css` demo'dan faz faz portlandı, app düz değil). Kullanıcı **"konsolide et şimdi, sonra yükselt"** seçti → Faz 15 restyle DEĞİL = **drift-fix + tutarlılık denetimi** (tanımsız `--radius`, off-palette `--text-dim`/`#0d1117`, ölü `.kpi__value` selektörü, 3 sayı idiomu). Plan ONAYLANDI → `~/.claude/plans/lovely-spinning-cupcake.md`. Dokunulan: yalnız 2 CSS dosyası; ux-reviewer ZORUNLU. Kod YAZILMADI — `START PHASE 15` bekleniyor.
- 2026-06-13 — PLAN GENİŞLETİLDİ: kullanıcı phase-plan.md'ye **Experience Layer fazları (15-18)** ekledi — 15 Design Foundation (look, salt restyle/token/komponent, 21 template; PHP/DB/route+i18n DOKUNULMAZ), 16 Motion & Interaction (feel; View Transitions, Cmd+K, drawer, reduced-motion), 17 Live Ops/SSE (alive; pure-PHP SSE, tenant-scoped, **tek gerçek backend yüzeyi**, security+ux ZORUNLU), 18 Signature Visualizations (distinctive; node graph read-only, platform-skin preview, ticker — additive). Checkpoint "Sıradaki adım" güncellendi → **SIRADA Faz 15**. Bir sonraki adım: `/next-phase` ile Faz 15 planı (Plan Mode). Kod YAZILMADI; bu sadece plan+checkpoint güncellemesi (faz token'ı YOK).
- 2026-06-13 — FAZ 14 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 14 feat `2e4bd41` commit + `git push origin main` (auto-push). Faz 14 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-020), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). V1 sonrası rota: operatör enable-time / V2 parking lot / followups.
- 2026-06-13 — START PHASE 14: i18n (TR/EN) İNŞA EDİLDİ. `Core/I18n` static çevirmen (setLocale clamp en/tr, `t()` fallback locale→en→key, `interpolate {name}`, lookup() null-on-miss seam, test-only setLangDir) + `View::t()`=e(I18n::t()) escaped. `lang/en.php`+`lang/tr.php` (478 anahtar parite; eski `Messages::MAP`→flat, `EVENTS`→`event.*`, `STATUS`→`status.*` foldlandı; `Messages` artık I18n facade — public API + ~16 call-site değişmedi → "tek sınıf swap" gerçekleşti). migration **0012** `users.locale` (NOT NULL DEFAULT 'en' CHECK en/tr; Migrator additive). Locale resolution: `Auth::SESSION_LOCALE` login'de cache + `sessionLocale()`/`setSessionLocale()`, `public/index.php` `I18n::setLocale(I18n::resolve(session, APP_LOCALE))`; `config/app.php` `app.locale`. `LocaleController` + `POST /locale` (`$protected`, CSRF blanket-gate, allowlist+CHECK, path-only redirect-back + backslash-guard). `base.php`/`app.php` `<html lang>` + topbar `.lang-switch` EN/TR (no-JS form POST) + CSS. 21 template'te ~250 literal → `View::t()` (gömülü-link cümleler segment-split; canonical node adları TREND/COMPLIANCE/PUBLISH/LIBRARY çevrilmedi). 732 PASS (+39: i18n fallback/interp/clamp/resolve, 0012 CHECK, /locale CSRF+redirect+backslash, TR-render smoke, BOTH-lang compliance truthfulness, parite+template-key tarayıcı). 3 reviewer: **compliance GO/0 (GATE)**, security GO (1 LOW backslash open-redirect → regex guard + test UYGULANDI), ux GO (slop-chip `chip--wrap` + `dash.kpi_cache` TR kısaltma UYGULANDI; aria/iki-nokta nit'leri ertelendi). Dev DB 0012'ye migrate (WAL-safe yedek `kuyash.pre-0012.bak.sqlite`); HTTP smoke: login→EN dash→`/locale`→TR dash (`lang="tr"`, "Panel"/"Çıkış yap", 0 "Sign out"), DB persist OK, smoke4 'en'e geri alındı. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — TEMİZLİK (faz değil): `phase-0-demo/` statik mock main'den KALDIRILDI (`git rm -r`, 37 dosya/~8.1k satır; git history'de duruyor) — `chore(cleanup)` `758e1d7`; tek ölü referans `ui-style-guide.md` font yolu `phase-0-demo/assets/fonts`→`public/assets/fonts` güncellendi. `KULLANIM_REHBERI.md` (TR kullanım rehberi) eklendi — `docs` `8a556a7`. Push edildi. **i18n (TR/EN) bir gözden kaçış**: gerçek backend tek dil (EN), ~21 template/~350 string; öneri = ayrı **Phase 14 — i18n** mini-fazı (Messages.php zaten key-routed, "tek sınıf swap" tasarımı hazır). Kod YAZILMADI — `START PHASE 14`/`/next-phase` bekliyor.
- 2026-06-13 — FAZ 13 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 13 feat commit + `git push origin main` (auto-push). Faz 13 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-019), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). **V1 phase-plan (0–13) TAMAMLANDI** — sırada faz YOK; bundan sonrası V2 parking lot / followup'lar / operatör enable-time adımları.
- 2026-06-13 — START PHASE 13: Hardening (final faz 13/13) İNŞA EDİLDİ. (1) 401/403 non-retryable fast-fail: `Core/PermanentFailure(+Exception)`, `JobResult::failedPermanent()`+retryable bayrak, `Engine::finalizeFailure` non-retryable→ilk denemede dead-letter (backoff yok), `Worker` uncaught PermanentFailure sınıflandırır, OpenAI text/TTS+Pexels 401/403→PermanentFailureException (domain değil → executor catch'i geçer → Worker). (2) PostRepository `insertPublishing` UNIQUE backstop (collision→mevcut id). (3) webhook per-IP rate-limit: migration 0011 `rate_limits` + `Core/RateLimiter` (120/60s, clock-injectable) → `WebhookController` 429 (HMAC/fail-closed önce çalışır). (4) WAL-aware backup/restore: `Core/SqliteBackup` (wal_checkpoint+VACUUM INTO+integrity), `bin/backup.php` (DB+media+manifest, --db-only), `bin/restore.php` (dry-run/--force, DB move-aside, integrity). (5) `bin/r2-smoke.php` enable-time gate (put→presign GET→anon GET 401/403 PRIVATE teyidi→delete; exit 0/1/2). (6) Caddyfile `(app)` snippet + blocklist genişletme (/database,/bin,/tests) + prod HTTPS+HSTS bloğu. (7) `production-readiness.md` + `release-test-checklist.md` + `phase-13-followups.md`. 693 PASS (+20). 3 reviewer: security **ZORUNLU GO/0**, compliance GO/0, ux GO (1 polish UYGULANDI: queue `non-retryable:`→"(no auto-retry)"). Ertelenenler: CF-Connecting-IP per-IP (tunnel ardında REMOTE_ADDR global), restore symlink containment, rate-limit write-amp. Smoke: backup/restore round-trip OK, real-DB 0011 (WAL-safe yedek) + HTTP boot OK. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — /next-phase: Faz 13 (Hardening) — final faz (13/13) — planı Plan Mode'da yazıldı ve ONAYLANDI; `.claude/docs/phase-13-plan.md`'e kaydedildi. Kapsam: tam kümülatif güvenlik incelemesi (security-auditor ZORUNLU) + taşınan hardening followup'ları (webhook per-IP rate-limit, PostRepository UNIQUE backstop, 401/403 non-retryable fast-fail); test-checklist + 2 regresyon (executor real-cost passthrough, recorder-no-rollback); WAL-aware backup/restore (bin/backup.php + restore.php, round-trip integrity_check); Caddy/Tunnel header incelemesi; failure-recovery doğrulaması (watchdog/dead-letter/kill switch); R2 enable-time smoke tooling (bin/r2-smoke.php SigV4+PRIVATE, operator-gated); production-readiness.md. 2 KİLİTLİ KARAR: (a) R2 staging/eviction operator-gated (tooling+doküman, spekülatif kod YOK); (b) LOW php refactor'ları tech-debt dokümante, YAPILMAZ. Non-goal: yeni feature YOK, real entegrasyon flip YOK. Kod YAZILMADI — START PHASE 13 bekleniyor.
- 2026-06-13 — FAZ 12 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 12 feat `dd34bbb` commit + `git push origin main` (auto-push). Faz 12 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-018), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). Sıra: /next-phase → Faz 13 (Hardening).
