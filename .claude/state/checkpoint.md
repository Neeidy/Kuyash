# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-13
- Güncelleyen: Claude (**FAZ 15.5 (Elevation) KABUL + commit + push**. Güvenlik kapısı temiz (secret yok),
  732 PASS korundu → feat `840d1bb` + chore(state) (bu commit) + `git push origin main` (auto-push). Aynı push'ta
  önceki oturumun Experience Layer replan + `/goal` loop altyapısı da gidiyor (phase-plan 15.9→20, experience-layer-plan,
  goal.md, design/). **Sırada: `START PHASE 15.9`** (görsel-test altyapısı). [F15.5 `840d1bb`, F15 `3fda7d0`].)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 15.5 (Elevation) KABUL + commit + push** (2026-06-13). feat `840d1bb`. Faz feat'leri:
  F15.5 `840d1bb`, F15 `3fda7d0`, F14 `2e4bd41`, F13 `9b68a67`. origin/main = bu chore(state) HEAD.
- Faz 15.5 özeti (salt sunum; PHP/DB/route/i18n DOKUNULMADI): Elevation Gate'te kullanıcı "gerekli" + 2 kaldıraç
  seçti (bento/state lever'ları SEÇİLMEDİ). (A) **surface-depth** (ton+border, gölge/glow/gradient YOK):
  `.card/.panel/.kpi/...` üst-kenar `--border-strong` ışık-yakalama; `.card__head`/`__foot` `--surface-2` bantlı
  (köşe `calc(--r-card - 1px)`); `.card--primary` (accent head band + accent başlık, **yalnız dashboard+queue**).
  (B) **tipografi/ritim**: `.screen-head h1` 23px (≤480 → 19px), screen-head margin s5→s6, kart aralığı s3→s4,
  `.kpi__label` uppercase+tracking. base.css `--text-3` #6b6b74→**#7c7c85** (A11Y-1 çözüldü: --bg 4.79 / --surface
  4.56 AA geçer). Dosyalar: app.css (+41 blok), base.css (±1), dashboard.php+queue/index.php (birer class).
  ux GO/0; ertelenen nice-to-have'ler → `phase-15-followups.md` A11Y-2 (surface-2/3 faint ~4.0-4.3) + UX-1 (dash primary half-width).
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

0. **SIRADA: `START PHASE 15.9` (Loop & Visual-Test Infra).** Faz 15.5 KABUL+commit+push'lu (feat `840d1bb`).
   **EXPERIENCE LAYER YENİDEN PLANLANDI (15.9→20) + `/goal` LOOP.** Tam spec: `.claude/docs/experience-layer-plan.md`.
   Görsel kaynak: `.claude/docs/design/prototype-v3.html` (onaylı v3). Loop komutu: `.claude/commands/goal.md`.
   Faz sırası: **15.9** (loop+görsel-test altyapısı) → **16** (motion+⌘K+drawer) → **17** (KPI+hesap widget+inline
   player) → **18** (pipeline fill-flow+tıkla-panel) → **19** (SSE canlı) → **20** (cila/perf/a11y). İnsan kapısı
   15.9/16/17/18 ZORUNLU, 19/20 opsiyonel. Teal accent global ONAYLANDI. **Sonra: `START PHASE 15.9`** (görsel-test
   altyapısı önce kurulmalı; yoksa görsel gate sahte olur). NOT: 15.9 ürün kodu değil altyapı; loop'tan sonra fazlar
   `/goal` ile koşulur.
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

- 2026-06-13 — FAZ 15.5 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı temiz (secret grep: yalnız doc/fixture eşleşmeleri, gerçek anahtar yok), 732 PASS korundu → Faz 15.5 feat `840d1bb` (app.css+base.css+dashboard.php+queue/index.php+phase-15-followups A11Y-2/UX-1) + chore(state) (checkpoint+phase-plan+experience-layer-plan+goal.md+design/) + `git push origin main` (auto-push). Aynı push'ta önceki oturumun Experience Layer replan (15.9→20) + `/goal` loop altyapısı da gitti (daha önce uncommitted'di). Sıra: `START PHASE 15.9` (Loop & Visual-Test Infra).
- 2026-06-13 — EXPERIENCE LAYER YENİDEN PLANLANDI + OTONOM LOOP (plan/altyapı; ürün kodu YAZILMADI). Kullanıcı 3 iterasyonla v3 tasarım prototipini onayladı (v1→v2 GPU %100 yakıyordu→v3 GPU-light: animasyonlu blur→statik gradient, kalıcı backdrop kaldırıldı, dönen ring→heartbeat glow, sadece transform/opacity/dashoffset; pipeline workflow fill-flow + durum simgeleri + kutuya-tıkla yan panel + onay kartı inline player). Muğlak Faz 16-18 → detaylı **15.9→20** ile değiştirildi (phase-plan.md). Yeni dosyalar: `.claude/docs/experience-layer-plan.md` (tam spec: v3 token tablosu + §1.2 GPU-light kuralları + bileşen envanteri + loop/3-gate spec + faz detayları), `.claude/docs/design/prototype-v3.html` (onaylı görsel kaynak), `.claude/commands/goal.md` (`/goal` loop: plan→kur→3 paralel gate [ux-reviewer görsel / qa-reviewer kod / security-auditor güvenlik]→verdict→`feat/phase-<N>` branch-commit [no auto-push]→/clear; fail-cap 2→stop-and-report; insan kapısı tablosu). 3 KARAR ONAYLANDI: teal accent #2ff0d2 global, 15.9 ayrı altyapı fazı, plan repoya entegre. Görsel gate ŞART: gerçek Caddy+PHP render + headless screenshot (yoksa sahte). Sonra: `START PHASE 15.9`.
- 2026-06-13 — START PHASE 15.5: Elevation İNŞA EDİLDİ (Experience Layer 2. faz; gate'te kullanıcı "gerekli" + 2 kaldıraç seçti, bento/state SEÇİLMEDİ). Salt sunum, PHP/DB/route/i18n DOKUNULMADI. (A) **surface-depth** (ton+border, gölge/glow/gradient YOK): `.card/.panel/.kpi/.wf-card/.asset-card/.trend-card` üst-kenar `--border-strong` ışık-yakalama; `.card__head`/`__foot` `--surface-2` bantlı (köşe `calc(--r-card - 1px)`); `.card--primary` accent head-band + accent başlık (**yalnız dashboard awaiting + queue approvals**, 2 markup hook). (B) **tipografi/ritim**: `.screen-head h1` 23px (≤480→19px), screen-head margin s5→s6, kart aralığı s3→s4, `.kpi__label` uppercase+tracking. base.css `--text-3` #6b6b74→**#7c7c85** (A11Y-1 çözüldü). Dosyalar: app.css (+41), base.css (±1), dashboard.php+queue (birer class). Identity audit: yeni shadow/gradient/blur=0, status-only renk, 15.5 blokta raw hex=0. 732 PASS. ux-reviewer **GO/0 must-fix** (real-Chrome 375/768/1280 EN+TR: depth perceptible, corner math exact, **0px overflow**, TR "ÖNBELLEK İSABETİ" tek satır, --text-3 AA --bg 4.79/--surface 4.56; nice-to-have'ler kaydedildi → `phase-15-followups.md` A11Y-2 [surface-2/3 faint ~4.0-4.3] + UX-1 [dash primary half-width]). Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — START PHASE 15: Design Foundation (konsolidasyon) İNŞA EDİLDİ. Keşif premisi çürüttü → premium karanlık sistem app'te zaten vardı; iş = salt CSS **drift-fix** (yalnız `base.css`+`app.css`, +~18; template DEĞİŞMEDİ). 5 düzeltme: tanımsız `var(--radius)`→`--r-card` (trend/KPI kare köşe); off-palette `var(--text-dim,#8b949e)`→`--text-3` ×6; `var(--surface-2,#0d1117)`→`--surface-2`; ölü selektör base.css `.kpi__value`→`.kpi__num`+quality+trend (tabular-nums); 3 sayı idiomu (kpi/quality/trend) → mono+500 (JetBrains yalnız 400/500 yüklü, 700 faux-bold'du). Audit: 0 off-palette fallback, her `var(--token)` çözülüyor (yalnız inline `--i` stagger hariç), kalan raw hex sadece kasıtlı `#000` letterbox + `#fff` danger. 732 PASS. HTTP smoke: 11 ekran 200/0-hata, error sayfaları 404/302, 0 harici istek, TR toggle `lang="tr"` %100. ux-reviewer KOŞULLU-GO (0 blocker, 1 should-fix): `--text-3` (#6b6b74) faint tier küçük metinde WCAG AA altı (~3.4-3.8:1) — UYGULANDI: talimat metni `.field__hint`→`--text-2` (AA geçer); kalan faint-tier app-geneli pre-existing borç açıkça kaydedildi → `phase-15-followups.md` A11Y-1 (elevation 16/18). KABUL: kullanıcı ayrıca dark temada beyaz gelen native date/time input fix'ini istedi → `input[type=datetime-local/date/time]` `color-scheme:dark` + token-uyumlu form stili (yalnız app.css; queue'da 4 canlı publish-schedule input'a uygulanıyor; served-CSS+/queue 200 smoke OK; 732 PASS korundu). feat `3fda7d0` commit + `git push origin main` (auto-push). phase-plan.md'ye **Elevation Decision Gate** notu eklendi (chore) → kullanıcı ekrana bakıp elevation kararını ŞİMDİ verir: gerekmez→F16, gerekir→F15.5 ("sonra bakılır" yok).
- 2026-06-13 — chore(state) push (`2ac3a4f`): Experience Layer fazları (15-18) phase-plan.md'ye + checkpoint Faz 15'e işaretlendi. Sonra **/next-phase → Faz 15 planı** (Plan Mode): keşif premisi çürüttü — premium karanlık tasarım sistemi GERÇEK app'te ZATEN var (`base.css`/`app.css` demo'dan faz faz portlandı, app düz değil). Kullanıcı **"konsolide et şimdi, sonra yükselt"** seçti → Faz 15 restyle DEĞİL = **drift-fix + tutarlılık denetimi** (tanımsız `--radius`, off-palette `--text-dim`/`#0d1117`, ölü `.kpi__value` selektörü, 3 sayı idiomu). Plan ONAYLANDI → `~/.claude/plans/lovely-spinning-cupcake.md`. Dokunulan: yalnız 2 CSS dosyası; ux-reviewer ZORUNLU. Kod YAZILMADI — `START PHASE 15` bekleniyor.
- 2026-06-13 — PLAN GENİŞLETİLDİ: kullanıcı phase-plan.md'ye **Experience Layer fazları (15-18)** ekledi — 15 Design Foundation (look, salt restyle/token/komponent, 21 template; PHP/DB/route+i18n DOKUNULMAZ), 16 Motion & Interaction (feel; View Transitions, Cmd+K, drawer, reduced-motion), 17 Live Ops/SSE (alive; pure-PHP SSE, tenant-scoped, **tek gerçek backend yüzeyi**, security+ux ZORUNLU), 18 Signature Visualizations (distinctive; node graph read-only, platform-skin preview, ticker — additive). Checkpoint "Sıradaki adım" güncellendi → **SIRADA Faz 15**. Bir sonraki adım: `/next-phase` ile Faz 15 planı (Plan Mode). Kod YAZILMADI; bu sadece plan+checkpoint güncellemesi (faz token'ı YOK).
- 2026-06-13 — FAZ 14 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 14 feat `2e4bd41` commit + `git push origin main` (auto-push). Faz 14 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-020), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). V1 sonrası rota: operatör enable-time / V2 parking lot / followups.
- 2026-06-13 — START PHASE 14: i18n (TR/EN) İNŞA EDİLDİ. `Core/I18n` static çevirmen (setLocale clamp en/tr, `t()` fallback locale→en→key, `interpolate {name}`, lookup() null-on-miss seam, test-only setLangDir) + `View::t()`=e(I18n::t()) escaped. `lang/en.php`+`lang/tr.php` (478 anahtar parite; eski `Messages::MAP`→flat, `EVENTS`→`event.*`, `STATUS`→`status.*` foldlandı; `Messages` artık I18n facade — public API + ~16 call-site değişmedi → "tek sınıf swap" gerçekleşti). migration **0012** `users.locale` (NOT NULL DEFAULT 'en' CHECK en/tr; Migrator additive). Locale resolution: `Auth::SESSION_LOCALE` login'de cache + `sessionLocale()`/`setSessionLocale()`, `public/index.php` `I18n::setLocale(I18n::resolve(session, APP_LOCALE))`; `config/app.php` `app.locale`. `LocaleController` + `POST /locale` (`$protected`, CSRF blanket-gate, allowlist+CHECK, path-only redirect-back + backslash-guard). `base.php`/`app.php` `<html lang>` + topbar `.lang-switch` EN/TR (no-JS form POST) + CSS. 21 template'te ~250 literal → `View::t()` (gömülü-link cümleler segment-split; canonical node adları TREND/COMPLIANCE/PUBLISH/LIBRARY çevrilmedi). 732 PASS (+39: i18n fallback/interp/clamp/resolve, 0012 CHECK, /locale CSRF+redirect+backslash, TR-render smoke, BOTH-lang compliance truthfulness, parite+template-key tarayıcı). 3 reviewer: **compliance GO/0 (GATE)**, security GO (1 LOW backslash open-redirect → regex guard + test UYGULANDI), ux GO (slop-chip `chip--wrap` + `dash.kpi_cache` TR kısaltma UYGULANDI; aria/iki-nokta nit'leri ertelendi). Dev DB 0012'ye migrate (WAL-safe yedek `kuyash.pre-0012.bak.sqlite`); HTTP smoke: login→EN dash→`/locale`→TR dash (`lang="tr"`, "Panel"/"Çıkış yap", 0 "Sign out"), DB persist OK, smoke4 'en'e geri alındı. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — TEMİZLİK (faz değil): `phase-0-demo/` statik mock main'den KALDIRILDI (`git rm -r`, 37 dosya/~8.1k satır; git history'de duruyor) — `chore(cleanup)` `758e1d7`; tek ölü referans `ui-style-guide.md` font yolu `phase-0-demo/assets/fonts`→`public/assets/fonts` güncellendi. `KULLANIM_REHBERI.md` (TR kullanım rehberi) eklendi — `docs` `8a556a7`. Push edildi. **i18n (TR/EN) bir gözden kaçış**: gerçek backend tek dil (EN), ~21 template/~350 string; öneri = ayrı **Phase 14 — i18n** mini-fazı (Messages.php zaten key-routed, "tek sınıf swap" tasarımı hazır). Kod YAZILMADI — `START PHASE 14`/`/next-phase` bekliyor.
- 2026-06-13 — FAZ 13 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 13 feat commit + `git push origin main` (auto-push). Faz 13 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-019), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). **V1 phase-plan (0–13) TAMAMLANDI** — sırada faz YOK; bundan sonrası V2 parking lot / followup'lar / operatör enable-time adımları.
