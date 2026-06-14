# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-13
- Güncelleyen: Claude (**`/go` SÜREKLİ LOOP ÇALIŞIYOR — FAZ 16 KABUL + commit'li, 3 gate PASS/0 blocker.
  SIRADA: FAZ 17 (loop devam).** Faz 16 = Motion & Interaction Core, salt client-side enhancement
  (PHP/DB/route/screen DOKUNULMADI). Yeni: motion.js (PL namespace + kayan pill + count-up), palette.js
  (⌘K), drawer.js (genel yan panel), command-palette.php + drawer.php partial'ları. Değişen: base.css
  (teal `#2dd4bf`→`#2ff0d2` global + `--glow` + statik ambient gradient), app.css (Faz 16 bloğu: pill,
  kpi hover-lift, `.main>*` giriş animasyonu, palette+drawer CSS), app.php (html.js + ⌘K trigger + partial
  + script'ler), lang/en+tr (+9 `cmd.*`/`help.*` parite), tests/run.php (+6). Doğrulandı: **738 PASS/0 FAIL**,
  visual gate **69 PNG / 0 console-error / 0 overflow / exit 0**, §1.2 motion kuralları temiz (yalnız
  transform/opacity; backdrop-filter sadece ⌘K/drawer scrim; spinner/animasyonlu-blur YOK), reduced-motion
  + no-JS güvenli. 3 reviewer GO: ux (0 blocker; pill height-transition should_fix UYGULANDI), qa (0 fail,
  scope temiz, build-free), security (0 HIGH; 2 LOW → F17/18 drawer innerHTML invariant takip). Branch:
  `feat/phase-16-motion-core` (main'den). [F15.9 `42a7bda`, F15.5 `840d1bb`, F15 `3fda7d0`].)

## Mevcut durum (kaldığımız yer)

- Aşama: **`/go` SÜREKLİ LOOP — FAZ 16 KABUL + commit'li** (branch `feat/phase-16-motion-core`, main'den, push YOK).
  Loop fazları branch'leri STACK eder: F17 → `feat/phase-16-motion-core`'tan branch'lenir (F16 motion katmanı F17'nin temeli; main'den branch'lersen motion gider). Run sonunda kullanıcı tüm stack'i inceler, beğenmediği fazı `git revert <faz-commit>` ile geri alır. Faz feat'leri (local): F15.9 `42a7bda`, F15.5 `840d1bb`, F15 `3fda7d0`. origin/main = `ec795ba` (15.9+16 push EDİLMEDİ; loop push yapmaz).
- **Faz 16 özeti** (Motion & Interaction Core; salt client-side, PHP/DB/route/screen DOKUNULMADI): plan `.claude/state/phase-16-plan.md`. Detay yukarıda "Son güncelleme"de. Ana etkiler: global teal `#2ff0d2`, statik ambient gradient, kayan-pill sidebar (JS, no-JS marker fallback), ⌘K palette + genel drawer (display:none modal, backdrop-filter yalnız bunlarda), KPI count-up (yalnız integer), `.main>*` CSS giriş animasyonu (flash-free, reduced-motion sıfır). Açık takip: pre-existing jargon (`nav.foot_title` "Phase 12 · Quick Create", worker banner, node-id) → F20 honest-copy; drawer `innerHTML` invariant → F17/18 (dinamik içerik escaped olmalı).
- Faz 15.9 özeti (salt DEV ARAÇ; ürün PHP/DB/route/template/CSS DOKUNULMADI): `go.md`'in dayandığı görsel-gate
  altyapısı. **Yeni dosyalar:** `tools/visual/shot.mjs` (sıfır-bağımlılık Node CDP driver — sistem Chrome'u
  `--remote-debugging-port` ile sürer, salt Node built-in `WebSocket`/`fetch`; login→route×{375/768/1280}×{en/tr}→
  console-error+overflow yakalar→PNG; exit 1=sayfa-fail/exit 2=setup-fail/exit 0=temiz), `tools/visual/gate.sh`
  (izole DB seed→`php -S` `APP_ENV=dev`→/health bekle→shot→teardown; port 8099), `tools/visual/routes.json`
  (11 nav ekran + login), `bin/visual-seed.php` (idempotent, MEDIA-FREE mock seed — DB_PATH 'visual' guard'lı,
  gerçek dev-DB'ye dokunamaz), `tools/visual/README.md`, `.claude/docs/loop-gates.md` (3-gate görev şablonları:
  ux GÖRSEL/qa KOD/security GÜVENLİK + fail-cap-2→stop-and-report). `.gitignore`+`Caddyfile` (`/tools/*` blok) ±2.
  Build-free korundu (package.json/node_modules YOK). Doğrulama: 6+69 PNG, fail-path exit 1, 732 PASS.
  3 reviewer **GO/0 blocker** (qa: scope/idempotent/exit-logic; security: 0 blocker, 2 LOW [tools/ Caddy parity
  UYGULANDI + dev-pass by-design]; ux: baseline dürüstçe yeşil, gerçek i18n/responsive/empty-state/dürüst-rozet).
  qa hardening UYGULANDI: visual-seed DB_PATH guard (bare-run→exit 2). KABUL + commit'li (`42a7bda`).
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

0. **`/go` LOOP DEVAM → SIRADA FAZ 17** (İmza Dashboard: business KPI + count-up/sparkline + hesap canlı-akış
   widget'ları + onay kartı INLINE PLAYER [drawer açmaz, kart içinde oynat] + dürüst rozetler). Spec:
   `experience-layer-plan.md` §5. **Branch: `feat/phase-16-motion-core`'tan `feat/phase-17-<slug>` aç** (F16 motion
   katmanı üstüne stack). Görsel kaynak: `prototype-v3.html` (onay kartları + hesap widget bölümü). F17 gerçek
   dev-DB verisine bağlanır; eksik veri → "veri yok" (asla uydurma). **Koşullu 4. gate: `compliance-reviewer`**
   (dürüst rozet). Drawer `innerHTML`'e dinamik içerik girerse ESCAPED olmalı (security LOW takip).
0b. **Loop akışı (her faz):** READ→PLAN(`phase-<N>-plan.md`)→BUILD→3 gate PARALEL (ux görsel / qa kod / security)
   [+compliance F17/F20]→hepsi PASS ise VERDICT→branch commit (push YOK)→checkpoint+log→/clear→sonraki faz.
   Gate FAIL → düzelt + yalnız düşen gate'i tekrar, max 2 deneme; aşılırsa `/stop-and-report` + DUR (commit yok).
   Hard-stop: gate 2 denemede geçmez / görsel araç render edemez (harness exit 2) / Faz 20 biter / context dolar.
   Görsel gate: `tools/visual/gate.sh --out storage/visual/phase-<N>` (exit 0 ŞART) → ux-reviewer PNG'leri yargılar.
   **Kalan loop fazları:** 17 (dashboard) → 18 (pipeline fill-flow+drawer) → 19 (SSE canlı) → 20 (cila/perf/a11y).
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

- 2026-06-13 — **`/go` LOOP — FAZ 16 KABUL (Motion & Interaction Core).** Salt client-side enhancement; PHP/DB/route/screen DOKUNULMADI. Yeni: `motion.js` (PL namespace: `durOf` token okur, kayan-pill sidebar [mouseenter/focus → aktife döner], integer-only KPI count-up rAF), `palette.js` (⌘K palette: aç/filtrele/ok-Enter-Esc/focus-trap+restore, pure nav window.location), `drawer.js` (genel sağ panel `PL.drawer.open/openTemplate`, innerHTML yalnız escaped `<template>`'ten), `command-palette.php` + `drawer.php` partial'ları. Değişen: `base.css` (teal `#2dd4bf`→`#2ff0d2` global + `--glow`/`--accent-line` + statik ambient gradient [teal+violet radial, animasyonsuz, attach fixed] + overflow-x hidden), `app.css` (Faz 16 bloğu +~140: `.main>*` CSS giriş [reduced-motion sıfır, flash-free], pill, kpi hover-lift, ⌘K trigger, cmdk + drawer CSS [backdrop-filter YALNIZ bunların scrim'inde]), `app.php` (head'de `html.js` sync script + topbar ⌘K trigger + 2 partial require + 3 script defer), `lang/en+tr` (+9 `cmd.*`/`help.*` parite), `tests/run.php` (+6 p16 testi). Doğrulama: **738 PASS/0 FAIL**; visual gate **69 PNG / 0 console-error / 0 overflow / exit 0**; CC gözüyle dashboard 1280-EN (pill+brighter-teal+ambient+count-up) ve 375-TR (hamburger, icon-only ⌘K, tam Türkçe, stacked, no-overflow) teyit. §1.2 motion temiz: yalnız transform/opacity keyframe (pl-rise/fade/pop), backdrop-filter sadece `.cmdk`+`.drawer__scrim`, spinner/animasyonlu-blur/kalıcı-backdrop YOK, her animasyon state'e bağlı, reduced-motion tüm token sıfır, no-JS güvenli (html.js gate, content JS'e bağımlı gizlenmiyor). 3 gate **PASS/0 blocker**: ux (pill height-transition should_fix UYGULANDI → yalnız transform/opacity), qa (738 PASS, scope temiz, parite, build-free, JS-off fallback), security (0 HIGH, 2 LOW: drawer innerHTML invariant F17/18 + data-label F17). Branch `feat/phase-16-motion-core` (main'den). LOOP DEVAM → Faz 17.
- 2026-06-13 — START PHASE 15.9: Loop & Visual-Test Infra İNŞA EDİLDİ (Experience Layer altyapı fazı; ürün kodu DOKUNULMADI). `go.md`'in dayandığı GERÇEK görsel-gate altyapısı kuruldu. Karar: **sıfır-bağımlılık Node CDP harness** (sistem Chrome `/Applications/...` + Node v26 built-in `WebSocket`/`fetch` → npm/package.json/Playwright YOK; app build-free kaldı) + **izole visual DB** (`DB_PATH=storage/database/kuyash-visual.sqlite`, `APP_ENV=dev` → cookie non-Secure, http login çalışır). Yeni: `tools/visual/shot.mjs` (CDP driver: login→form-submit, locale-switch CSRF-form-submit, route×{375/768/1280}×{en/tr}, console-error[favicon hariç]+overflow yakala, full-page PNG, exit 1/2/0), `tools/visual/gate.sh` (seed→`php -S` 8099→/health→shot→teardown), `tools/visual/routes.json` (11 nav+login), `bin/visual-seed.php` (idempotent, MEDIA-FREE seed: awaiting job result_json'da draft_render_id YOK → 0 broken-media 404; `Nodes::defaultNodes()` ile doğru nodes_json), `tools/visual/README.md`, `.claude/docs/loop-gates.md` (3-gate görev şablonları). `.gitignore`(+storage/visual/) + `Caddyfile`(+/tools/* blok) ±2. Doğrulama: self-test `--only /dashboard`→6 PNG; tam baseline→**69 PNG, 0 console-error/0 overflow/exit 0**; fail-path (3000px overflow + console.error)→**exit 1**; **732 PASS**; ürün dosyası 0; package.json/node_modules YOK. Görsel teyit (CC gözüyle): dashboard EN/TR gerçek render (KPI 2/1/1/2/0, awaiting strip card--primary accent band, AI rozet), TR/375 tam Türkçe+responsive (hamburger, stacked, "ÖNBELLEK İSABETİ"). 3 reviewer **GO/0 blocker**: qa (scope/idempotent/exit-logic), security (0 blocker, 2 LOW), ux (baseline dürüstçe yeşil, gerçek i18n/responsive/empty-state/dürüst-rozet). Hardening UYGULANDI: visual-seed DB_PATH 'visual' guard (bare-run→exit 2) + Caddy /tools/* parity. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — FAZ 15.5 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı temiz (secret grep: yalnız doc/fixture eşleşmeleri, gerçek anahtar yok), 732 PASS korundu → Faz 15.5 feat `840d1bb` (app.css+base.css+dashboard.php+queue/index.php+phase-15-followups A11Y-2/UX-1) + chore(state) (checkpoint+phase-plan+experience-layer-plan+go.md+design/) + `git push origin main` (auto-push). Aynı push'ta önceki oturumun Experience Layer replan (15.9→20) + `/go` loop altyapısı da gitti (daha önce uncommitted'di). Sıra: `START PHASE 15.9` (Loop & Visual-Test Infra).
- 2026-06-13 — EXPERIENCE LAYER YENİDEN PLANLANDI + OTONOM LOOP (plan/altyapı; ürün kodu YAZILMADI). Kullanıcı 3 iterasyonla v3 tasarım prototipini onayladı (v1→v2 GPU %100 yakıyordu→v3 GPU-light: animasyonlu blur→statik gradient, kalıcı backdrop kaldırıldı, dönen ring→heartbeat glow, sadece transform/opacity/dashoffset; pipeline workflow fill-flow + durum simgeleri + kutuya-tıkla yan panel + onay kartı inline player). Muğlak Faz 16-18 → detaylı **15.9→20** ile değiştirildi (phase-plan.md). Yeni dosyalar: `.claude/docs/experience-layer-plan.md` (tam spec: v3 token tablosu + §1.2 GPU-light kuralları + bileşen envanteri + loop/3-gate spec + faz detayları), `.claude/docs/design/prototype-v3.html` (onaylı görsel kaynak), `.claude/commands/go.md` (`/go` loop: plan→kur→3 paralel gate [ux-reviewer görsel / qa-reviewer kod / security-auditor güvenlik]→verdict→`feat/phase-<N>` branch-commit [no auto-push]→/clear; fail-cap 2→stop-and-report; insan kapısı tablosu). 3 KARAR ONAYLANDI: teal accent #2ff0d2 global, 15.9 ayrı altyapı fazı, plan repoya entegre. Görsel gate ŞART: gerçek Caddy+PHP render + headless screenshot (yoksa sahte). Sonra: `START PHASE 15.9`.
- 2026-06-13 — START PHASE 15.5: Elevation İNŞA EDİLDİ (Experience Layer 2. faz; gate'te kullanıcı "gerekli" + 2 kaldıraç seçti, bento/state SEÇİLMEDİ). Salt sunum, PHP/DB/route/i18n DOKUNULMADI. (A) **surface-depth** (ton+border, gölge/glow/gradient YOK): `.card/.panel/.kpi/.wf-card/.asset-card/.trend-card` üst-kenar `--border-strong` ışık-yakalama; `.card__head`/`__foot` `--surface-2` bantlı (köşe `calc(--r-card - 1px)`); `.card--primary` accent head-band + accent başlık (**yalnız dashboard awaiting + queue approvals**, 2 markup hook). (B) **tipografi/ritim**: `.screen-head h1` 23px (≤480→19px), screen-head margin s5→s6, kart aralığı s3→s4, `.kpi__label` uppercase+tracking. base.css `--text-3` #6b6b74→**#7c7c85** (A11Y-1 çözüldü). Dosyalar: app.css (+41), base.css (±1), dashboard.php+queue (birer class). Identity audit: yeni shadow/gradient/blur=0, status-only renk, 15.5 blokta raw hex=0. 732 PASS. ux-reviewer **GO/0 must-fix** (real-Chrome 375/768/1280 EN+TR: depth perceptible, corner math exact, **0px overflow**, TR "ÖNBELLEK İSABETİ" tek satır, --text-3 AA --bg 4.79/--surface 4.56; nice-to-have'ler kaydedildi → `phase-15-followups.md` A11Y-2 [surface-2/3 faint ~4.0-4.3] + UX-1 [dash primary half-width]). Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — START PHASE 15: Design Foundation (konsolidasyon) İNŞA EDİLDİ. Keşif premisi çürüttü → premium karanlık sistem app'te zaten vardı; iş = salt CSS **drift-fix** (yalnız `base.css`+`app.css`, +~18; template DEĞİŞMEDİ). 5 düzeltme: tanımsız `var(--radius)`→`--r-card` (trend/KPI kare köşe); off-palette `var(--text-dim,#8b949e)`→`--text-3` ×6; `var(--surface-2,#0d1117)`→`--surface-2`; ölü selektör base.css `.kpi__value`→`.kpi__num`+quality+trend (tabular-nums); 3 sayı idiomu (kpi/quality/trend) → mono+500 (JetBrains yalnız 400/500 yüklü, 700 faux-bold'du). Audit: 0 off-palette fallback, her `var(--token)` çözülüyor (yalnız inline `--i` stagger hariç), kalan raw hex sadece kasıtlı `#000` letterbox + `#fff` danger. 732 PASS. HTTP smoke: 11 ekran 200/0-hata, error sayfaları 404/302, 0 harici istek, TR toggle `lang="tr"` %100. ux-reviewer KOŞULLU-GO (0 blocker, 1 should-fix): `--text-3` (#6b6b74) faint tier küçük metinde WCAG AA altı (~3.4-3.8:1) — UYGULANDI: talimat metni `.field__hint`→`--text-2` (AA geçer); kalan faint-tier app-geneli pre-existing borç açıkça kaydedildi → `phase-15-followups.md` A11Y-1 (elevation 16/18). KABUL: kullanıcı ayrıca dark temada beyaz gelen native date/time input fix'ini istedi → `input[type=datetime-local/date/time]` `color-scheme:dark` + token-uyumlu form stili (yalnız app.css; queue'da 4 canlı publish-schedule input'a uygulanıyor; served-CSS+/queue 200 smoke OK; 732 PASS korundu). feat `3fda7d0` commit + `git push origin main` (auto-push). phase-plan.md'ye **Elevation Decision Gate** notu eklendi (chore) → kullanıcı ekrana bakıp elevation kararını ŞİMDİ verir: gerekmez→F16, gerekir→F15.5 ("sonra bakılır" yok).
- 2026-06-13 — chore(state) push (`2ac3a4f`): Experience Layer fazları (15-18) phase-plan.md'ye + checkpoint Faz 15'e işaretlendi. Sonra **/next-phase → Faz 15 planı** (Plan Mode): keşif premisi çürüttü — premium karanlık tasarım sistemi GERÇEK app'te ZATEN var (`base.css`/`app.css` demo'dan faz faz portlandı, app düz değil). Kullanıcı **"konsolide et şimdi, sonra yükselt"** seçti → Faz 15 restyle DEĞİL = **drift-fix + tutarlılık denetimi** (tanımsız `--radius`, off-palette `--text-dim`/`#0d1117`, ölü `.kpi__value` selektörü, 3 sayı idiomu). Plan ONAYLANDI → `~/.claude/plans/lovely-spinning-cupcake.md`. Dokunulan: yalnız 2 CSS dosyası; ux-reviewer ZORUNLU. Kod YAZILMADI — `START PHASE 15` bekleniyor.
- 2026-06-13 — PLAN GENİŞLETİLDİ: kullanıcı phase-plan.md'ye **Experience Layer fazları (15-18)** ekledi — 15 Design Foundation (look, salt restyle/token/komponent, 21 template; PHP/DB/route+i18n DOKUNULMAZ), 16 Motion & Interaction (feel; View Transitions, Cmd+K, drawer, reduced-motion), 17 Live Ops/SSE (alive; pure-PHP SSE, tenant-scoped, **tek gerçek backend yüzeyi**, security+ux ZORUNLU), 18 Signature Visualizations (distinctive; node graph read-only, platform-skin preview, ticker — additive). Checkpoint "Sıradaki adım" güncellendi → **SIRADA Faz 15**. Bir sonraki adım: `/next-phase` ile Faz 15 planı (Plan Mode). Kod YAZILMADI; bu sadece plan+checkpoint güncellemesi (faz token'ı YOK).
- 2026-06-13 — FAZ 14 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı (secret grep temiz) → Faz 14 feat `2e4bd41` commit + `git push origin main` (auto-push). Faz 14 implementasyon detayı `architecture-decisions.md`'ye taşındı (ADR-020), "Mevcut durum" ~4 satıra indirildi (~1 sayfa kuralı). V1 sonrası rota: operatör enable-time / V2 parking lot / followups.
- 2026-06-13 — START PHASE 14: i18n (TR/EN) İNŞA EDİLDİ. `Core/I18n` static çevirmen (setLocale clamp en/tr, `t()` fallback locale→en→key, `interpolate {name}`, lookup() null-on-miss seam, test-only setLangDir) + `View::t()`=e(I18n::t()) escaped. `lang/en.php`+`lang/tr.php` (478 anahtar parite; eski `Messages::MAP`→flat, `EVENTS`→`event.*`, `STATUS`→`status.*` foldlandı; `Messages` artık I18n facade — public API + ~16 call-site değişmedi → "tek sınıf swap" gerçekleşti). migration **0012** `users.locale` (NOT NULL DEFAULT 'en' CHECK en/tr; Migrator additive). Locale resolution: `Auth::SESSION_LOCALE` login'de cache + `sessionLocale()`/`setSessionLocale()`, `public/index.php` `I18n::setLocale(I18n::resolve(session, APP_LOCALE))`; `config/app.php` `app.locale`. `LocaleController` + `POST /locale` (`$protected`, CSRF blanket-gate, allowlist+CHECK, path-only redirect-back + backslash-guard). `base.php`/`app.php` `<html lang>` + topbar `.lang-switch` EN/TR (no-JS form POST) + CSS. 21 template'te ~250 literal → `View::t()` (gömülü-link cümleler segment-split; canonical node adları TREND/COMPLIANCE/PUBLISH/LIBRARY çevrilmedi). 732 PASS (+39: i18n fallback/interp/clamp/resolve, 0012 CHECK, /locale CSRF+redirect+backslash, TR-render smoke, BOTH-lang compliance truthfulness, parite+template-key tarayıcı). 3 reviewer: **compliance GO/0 (GATE)**, security GO (1 LOW backslash open-redirect → regex guard + test UYGULANDI), ux GO (slop-chip `chip--wrap` + `dash.kpi_cache` TR kısaltma UYGULANDI; aria/iki-nokta nit'leri ertelendi). Dev DB 0012'ye migrate (WAL-safe yedek `kuyash.pre-0012.bak.sqlite`); HTTP smoke: login→EN dash→`/locale`→TR dash (`lang="tr"`, "Panel"/"Çıkış yap", 0 "Sign out"), DB persist OK, smoke4 'en'e geri alındı. Commit YAPILMADI — kabul bekliyor.
