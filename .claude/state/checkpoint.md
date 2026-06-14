# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-14
- Güncelleyen: Claude (**FAZ 21 — TAM DENEYİM DÖNÜŞÜMÜ (TÜM EKRANLAR) BİTTİ + branch-commit'li. İNSAN KAPISI
  (tek, sonda): kullanıcı 12 ekranı baştan sona gezip onaylar; PUSH/MERGE YOK.** Salt sunum+i18n+mock veri;
  engine/route/DB/gerçek-API DOKUNULMADI. 3 ana iş: (1) **§1 hesap canlı-akış widget'ı** — yeni
  `templates/partials/account-card.php` (gradient video-tile ken-burns + ♥/💬/↗ + takipçi + büyüme + sağlık/durum);
  dashboard "Bağlı Hesaplar" + /accounts grid; metrikler DETERMİNİSTİK ÖRNEK (crc32 seed, medya-free → 404 yok) ve
  DÜRÜST çerçeveli (`acct.sample` çip + `acct.sample_note` bölüm notu EN&TR; Cockpit hâlâ fabricate ETMEZ).
  (2) **tam jargon temizliği** — `Messages::jobType()`/`platform()` facade'ları (status() kalıbı) + ~45 lang düzeltme:
  `php bin/worker.php`/`mock`/`Zernio`/`render_review`/`script_draft`/`prompt_version`/`{n} nodes`/`COMPLIANCE locked`/
  `Faz N`/`pipeline` kopya/`worker`-`işçi` flash'ları/logs SQL-jargonu KALDIRILDI; canonical node adları + "Full
  pipeline" iş akışı ADI (DB) KORUNDU. (3) **kuyruk kırık-kırmızı-blok DÜZELTİLDİ** — ham `<video>` → `.inline-player`
  (poster+preload=none+pending placeholder). + gradient screen-head başlık + v3 markalı login. `tests/run.php` +9
  (jobType/platform map, account-card determinist+örnek-dürüst+medya-free, **durable jargon guard** [non-event lang'da
  worker/işçi/pipeline yasak], AI-label korundu). **773 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**.
  **4 gate PASS/0 blocker**: ux (3 blocker [worker-flash/template-enum-çip/pipeline-kopya] DÜZELTİLDİ+yeniden doğrulandı
  → GO), qa (773, scope/parite 561=561/build-free/engine dokunulmadı), security (0 HIGH/MED; 2 LOW: literal-array
  bg-image [safe] + pre-existing runs.state_ dyn-key), **compliance hard-gate: ÖRNEK metrik dürüst + truthful records +
  AI label EN&TR**. Branch `feat/phase-21-full-conversion` (F20'den stack). Baseline `storage/visual/phase-21/`.)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 16→21 HEPSİ BİTTİ + commit'li; İNSAN KAPISI bekliyor** (6 stacked branch; push/merge YOK).
  **STACK (en üst = F21, hepsini içerir):** `feat/phase-21-full-conversion` → `feat/phase-20-polish-a11y` →
  `feat/phase-19-live-sse` → `feat/phase-18-pipeline-viz` → `feat/phase-17-signature-dashboard` →
  `feat/phase-16-motion-core` → main. **Commit'ler:** F21 `feat/phase-21-full-conversion` tip, F20 `fd80455`,
  F19 `460e487`, F18 `1a4486d`, F17 `231b709`, F16 `8a6f561`. origin/main `ec795ba` (PUSH YAPILMADI).
  **İNCELEME:** her faz kendi commit'inde; beğenmediğin fazı `git revert <commit>` ile geri al; main'e merge insan
  onayıyla. Test: **773 PASS**. Visual baseline: `storage/visual/phase-21/` (69 PNG, tüm 12 ekran v3).
- **F21** (Tam Deneyim Dönüşümü; TÜM ekranlar v3 + jargon=0 + §1 hesap widget'ı + kuyruk defekt fix): yeni
  `account-card.php` + `Messages::jobType/platform` + ~45 lang scrub + login branding + gradient başlık. Spec
  `phase-21-full-conversion.md`. **Açık takip:** /logs `event.*` {type}/{node} ham token interpolation (Faz-14 borcu,
  test guard'da event.* muaf) → ayrı bilet; "Full pipeline" iş akışı ADI (DB/seed) korundu → istenirse bootstrap
  fazında yumuşatılır; runs/show `entity_type` (trend/library) detay-sayfa diagnostiği bırakıldı.
- **F16** (Motion Core): teal `#2ff0d2`, ambient gradient, kayan-pill, ⌘K palette + genel drawer, KPI count-up,
  `.main>*` CSS giriş. Plan `phase-16-plan.md`.
- **F17** (İmza Dashboard; GERÇEK+DÜRÜST): business KPI strip + inline-player onay kartları + connected-accounts
  widget (fabricate YOK). `Cockpit` business/accounts/rich-awaiting. Plan `phase-17-plan.md`.
- **F18** (Pipeline node-graph; GERÇEK job state, ENGINE DOKUNULMADI): `Cockpit::pipeline()` + node-graph partial
  + `node-graph.js` (fill-flow) + drawer (escaped template, jargonsuz desc) + mobil stacked. Spec §6.
- **F19** (Canlı/SSE; TEK gerçek backend yüzeyi): `LiveController` IMMEDIATE-CLOSE `/live` + `Cockpit::liveSnapshot`
  (read-only, tenant-scope) + `live-client.js` + topbar live göstergesi (no-JS gizli). Plan `phase-19-plan.md`.
- **F20** (Cila/Perf/A11y kapanışı): `--text-3`→`#8a8a93` (faint tier HER yüzeyde AA), `nav.foot` jargon temizliği,
  pipeline node `title`. §1.2/perf/a11y/dürüst-rozet/güvenlik son denetimleri PASS. Plan `phase-20-plan.md`.
- **Kalan açık takip (KABUL/ertelendi, F20'de değerlendirildi):** worker-down banner (`php bin/worker.php`) =
  meşru self-host operatör bilgisi, KORUNDU; JobRepo `SELECT *` read-model (LOW, güvenli — template whitelisted
  alan okur); SSE router-level unauth test (LOW — controller backstop + `$protected` zaten kapsıyor); inline
  `<video>` no-native-controls JS-off (view-run linki var). **ENABLE-TIME ops:** Caddy/CF `text/event-stream`
  no-cache. **Pre-existing (Experience dışı):** logs/event TR placeholder interpolation (Faz 14 borcu) → ayrı bilet.
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

0. **FAZ 21 BİTTİ (Tam Deneyim Dönüşümü) — branch-commit'li, İNSAN KAPISI BEKLİYOR.** Branch
   `feat/phase-21-full-conversion` (F20'den stack; PUSH/MERGE YOK). Kullanıcı 12 ekranı baştan sona gezip onaylar
   (screenshot `storage/visual/phase-21/`). Onaylanınca → 16→21 stack'i main'e merge **insan onayıyla**; beğenilmeyen
   faz `git revert <commit>`. **773 PASS, 4 gate PASS/0 blocker.** Spec `.claude/docs/phase-21-full-conversion.md`.
   Açık takip (ertelendi): /logs `event.*` ham token interpolation (Faz-14 borcu, ayrı bilet); "Full pipeline" iş
   akışı adı (bootstrap/seed fazına bırakıldı); runs/show `entity_type` detay diagnostiği.

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

- 2026-06-14 — **FAZ 21 KABUL (Tam Deneyim Dönüşümü — TÜM EKRANLAR).** `START PHASE 21` ile koşuldu (taban: F20 tipi). Salt sunum+i18n+mock veri; engine/route/DB/gerçek-API DOKUNULMADI. (1) **§1 hesap canlı-akış widget'ı** yeni `templates/partials/account-card.php` (gradient video-tile ken-burns + ♥/💬/↗ + takipçi/büyüme + sağlık/durum; dashboard "Bağlı Hesaplar" + /accounts grid; metrikler DETERMİNİSTİK ÖRNEK [crc32 seed], **medya-free → 404 yok**, DÜRÜST çerçeveli [`acct.sample` çip + `acct.sample_note` bölüm notu EN&TR]; Cockpit hâlâ fabricate ETMEZ). (2) **`Messages::jobType()`/`platform()`** facade'ları (status() kalıbı) + ~45 lang scrub: `php bin/worker.php`/`mock`/`Zernio`/`render_review`/`script_draft`/`prompt_version`/`{n} nodes`→`{n} steps`/`COMPLIANCE locked`→"Compliance required"/`Faz N`/`pipeline` kopya/`worker`-`işçi` flash'ları/logs SQL-jargonu KALDIRILDI; canonical node adları + "Full pipeline" iş akışı ADI (DB) KORUNDU. (3) **kuyruk kırık-kırmızı-blok FIX**: ham `<video controls>` → `.inline-player` (poster+preload=none+pending placeholder, dashboard pattern reuse). + gradient screen-head başlık (@supports fallback) + v3 markalı login (logo+tagline; create-user komutu kaldırıldı). `tests/run.php` +9 (jobType/platform map, account-card determinist+örnek-dürüst+medya-free, **durable jargon guard** [non-event lang'da worker/işçi/pipeline YASAK], jobtype/platform/acct parite, AI-label korundu) + 3 mevcut assertion güncellendi. **773 PASS/0 FAIL**; visual **69 PNG / 0 console-error / 0 overflow / exit 0**; CC gözüyle dashboard 1280-EN (gradient başlık + jargonsuz "Script draft/Preview approval" + §1 kartlar + softened banner) + accounts/login/375-TR teyit. **4 gate PASS/0 blocker**: ux (CONDITIONAL→GO: 3 blocker [worker-flash/template-enum-çip/pipeline-kopya] DÜZELTİLDİ+bağımsız yeniden doğrulandı), qa (773, scope: lang/templates/css/Messages-facade/tests; engine/route/DB/controller DOKUNULMADI; parite 561=561; build-free), security (0 HIGH/MED; 2 LOW: literal-array bg-image style [safe, seed in-bounds] + pre-existing runs.state_ dyn-key), **compliance hard-gate GO** (ÖRNEK metrik çift-katman dürüst + truthful approval records + AI label EN&TR korundu). Branch `feat/phase-21-full-conversion` (F20'den stack; push/merge YOK). **İNSAN KAPISI (tek, sonda) BEKLİYOR.**
- 2026-06-13 — **`/go` LOOP — FAZ 20 KABUL (Cila/Perf/A11y) → LOOP TAMAMLANDI (16→20), (H3) DUR.** Son faz, salt cila+kapanış. 3 değişiklik: (1) **a11y kontrast** `base.css --text-3 #7c7c85→#8a8a93` — faint tier ölçülü HER yüzeyde WCAG AA (bg 5.78/surface 5.51/**surface-2 5.23**/**surface-3 4.86** ≥4.5; eski 4.33/4.02 sub-AA → phase-15-followups A11Y-1+A11Y-2 ÇÖZÜLDÜ; --text-2'den hâlâ belirgin faint). (2) **dürüst kopya** `nav.foot_title/text` EN+TR — "Phase 12 · …/credit-gated/mock-first" jargonu KALDIRILDI → "Quick Create / Turn a photo into a short, AI-labeled video." (AI-label compliance gerçeği KORUNDU). (3) `pipeline.php` node button `title` attr (768px ellipsis truncation yardımı; aria-label zaten vardı). **Doğrulama (kod yok):** §1.2 tam tarama → her @keyframes (rise-in/pl-rise/pl-fade/pl-pop/pl-hb) opacity/transform-only, backdrop-filter yalnız `.cmdk`+`.drawer__scrim`, spinner 0, 3 pl-hb heartbeat yalnız `.pl-node--active`/`.topbar__live.is-live` (boşta GPU ~0), reduced-motion tam (explicit override + token-zero); keyboard a11y (⌘K focus-trap+restore, drawer Esc/scrim+focus, real `<button>`'lar `:focus-visible`, aria-current); dürüst rozet; güvenlik son geçiş. `tests/run.php` +3 (PHP'de WCAG ratio yeniden-hesap ≥4.5 ×4 surface + jargon-free + AI-label korundu guard). **764 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle dashboard honest foot ("Quick Create") + brighter faint tier teyit. **4 gate PASS/0 blocker:** ux (AA bağımsız recompute, §1.2 temiz, klavye a11y, 48 --text-3 consumer regresyonsuz), qa (764, scope: yalnız base.css+lang+pipeline.php+tests, parite 524=524), **security SON SIGN-OFF 0 BULGU** (drawer innerHTML invariant + /live tenant-read-only + escaping + CSRF hepsi geçerli; AccountRepo JOIN hardening bonus), **compliance SON hard-gate** (truthful records DB-invariant 0007 CHECK ile garantili + AI label + 0 fabricated metric EN&TR). Branch `feat/phase-20-polish-a11y` (F19'dan stack). **LOOP DURDU → kullanıcı 5-faz stack'i inceler.**
- 2026-06-13 — **`/go` LOOP — FAZ 19 KABUL (Canlı Katman / SSE).** TEK gerçek backend yüzeyi, güvenli. Tasarım kararı: **IMMEDIATE-CLOSE SSE** — `/live` tek workspace-scoped snapshot (`retry: 5000\nevent: snapshot\ndata: {active,awaiting,ts}`) emit edip KAPANIR; tarayıcı EventSource ~5s'de reconnect = canlı tick. Held-connection/loop/sleep YOK → single-thread `php -S`+harness'ı BLOKE ETMEZ (H2 risk çözüldü), PHP session lock TUTMAZ (`session_write_close`), uzun transaction YOK, kaynak tükenmesi YOK (güvenlik artısı). Yeni `src/Controllers/LiveController.php` (`stream`: auth fail-closed backstop + tenant `workspace->id()` [request param YOK] + `Cockpit::liveSnapshot` + `session_write_close` guard'lı + text/event-stream Response [no-cache/no-transform/X-Accel-Buffering:no/nosniff]), `Cockpit::liveSnapshot(int $ws)` (tek `WHERE workspace_id=?` SELECT, read-only, O(1)), `GET /live` `$protected` (routes.php + import), `web.php` binding (+import), `public/assets/js/live-client.js` (EventSource('/live')→'snapshot'→`[data-live].is-live` + `[data-live-text]` "updated just now" + `[data-live-awaiting]` textContent; EventSource yoksa erken return; onerror→is-live kaldır+retry; beforeunload→close). `app.php` topbar `.topbar__live` (heartbeat dot + live-text; `html:not(.js)→display:none` DÜRÜST no-JS gizle), `dashboard.php` awaiting KPI `data-live-awaiting`, `app.css` F19 (`.topbar__live.is-live .dot` `pl-hb` opacity heartbeat + reduced-motion override + ≤640 text gizle/≤460 tüm gizle), `lang/en+tr` +2 `live.*` parite, `tests/run.php` +8 (liveSnapshot count/tenant-sibling-zero, SSE content-type/retry+event/JSON-data, **READ-ONLY no-write**, unauth→/login backstop, parite). **761 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0** (0-console-error ⇒ EventSource /live'a temiz bağlandı, doğru content-type). **3 gate PASS/0 blocker**: security AĞIR (tenant-izol session-only fail-closed, immediate-close=no-DoS/no-held-conn, read-only test, session_write_close, secret yok; 2 LOW: router-level unauth test granularity + enable-time Caddy/CF no-cache → takip), ux (§1.2 temiz opacity heartbeat [v3 glow'unu bile düşürdü], no-JS dürüst gizle, jargonsuz "Live"/"Canlı"), qa (761, scope/parite/build-free/JS-off, immediate-close kod doğrulandı). Branch `feat/phase-19-live-sse` (F18'den stack). LOOP DEVAM → Faz 20 (SON: cila/perf/a11y).
- 2026-06-13 — **`/go` LOOP — FAZ 18 KABUL (Pipeline/Workflow node-graph).** GERÇEK job state'inden, **ENGINE DOKUNULMADI** (salt visualize). Yeni `Cockpit::pipeline()` (top active run [running/awaiting], `nodes_json` decode + jobsByNode → her node done/active/wait/failed; salt-okuma SELECT workspace-scope; idle→`null`) + `nodeGraphState()` (runs/show.php `$nodeState` precedence'ını 4 state'e fold: failed>awaiting/processing/queued>cancelled>count>=expected?done:active). `templates/partials/pipeline.php` (`.pipeline-flow` SVG#pipeline-conns + `.pipeline-nodes` button chip'leri [chip ikonu + canonical ad + status ikonu ✓/⚡/dashed-ring] + her node için `<template id=node-tpl-{i}>` drawer içeriği: `nodestat` + PLAIN `node.desc.*` + "Gelen→İşlem→Çıktı" + auto-note; `$st` View::e). `node-graph.js` (vanilla IIFE: chip rect'lerinden SVG line çiz — done→done solid-green kanal, done→active **fill-flow** stroke-dashoffset SMIL + leading dot [reduced-motion'da skip], rest faint dashes; token renkleri hexA(); resize redraw; mobil SVG display:none→bail). `app.css` F18 bloğu (pl-node done[ok]/active[accent + statik glow box-shadow + `@keyframes pl-hb` opacity heartbeat + reduced-motion override]/wait[opacity.5]/failed[err]; `.nodestat`/`.drawer-desc`/`.drawer-flow`; **mobil ≤720px STACKED rows**, conns hidden). `dashboard.php` ("Üretim hattı · içerik #N" kartı KPI ile grid arası; `pipeline===null`→kart gizli). `app.php` (+node-graph.js script). `lang/en+tr` +21 `pipeline.*`/`node.desc.*` (PLAIN dil, jargonsuz; canonical node adları çevrilmedi; `runs.state_*` reuse). `bin/visual-seed.php` Run A'ya TREND/IDEA/SCRIPT(ready=done)+VOICE(processing=active) job — dev-seed, **media-free**. Drawer içeriği Faz16 `data-drawer-open`→`openTemplate` (escaped template, XSS-safe — drawer innerHTML invariant KORUNDU). `tests/run.php` +7 (done/active/wait mapping, canonical-order, **idle→null honest empty**, parite, **jargon-free desc**). **753 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle 1280-EN (yatay graph: done-yeşil/active-teal-glow/wait-faint + connectors/fill-flow) + 375-TR (STACKED: TREND✓ IDEA✓ SCRIPT✓ VOICE⚡ → dashed-ring wait, canonical adlar). **3 gate PASS/0 blocker**: ux (jargonsuz teyit, mobil stacked, §1.2 temiz; should_fix 768px chip truncation cosmetic), qa (753, **engine untouched** doğrulandı, scope/parite/build-free/JS-off), security (0 HIGH; 2 LOW: `$st` enum [View::e UYGULANDI] + statik SVG glyph [safe]). Branch `feat/phase-18-pipeline-viz` (F17'den stack). LOOP DEVAM → Faz 19 (SSE).
- 2026-06-13 — **`/go` LOOP — FAZ 17 KABUL (İmza Dashboard).** GERÇEK dev-DB verisine bağlı + DÜRÜST (uydurma metrik YOK). `Cockpit` genişletildi (+CreditLedger/UsageRepository/AccountRepository/JobRepository; `snapshot($ctx,$now)` → `business` [balance_cents/spent_mtd/charges_mtd/granted_week/cost_per_content_cents NULL→"—"/awaiting] + `accounts` [listFor, yalnız platform/handle/health/reference] + rich `awaiting` [JobRepo::awaitingApproval reuse]; salt-okuma tenant-scope; ölü `awaiting()` silindi). `web.php` binding +4 servis; `DashboardController` $now passthrough. `dashboard.php` baştan yazıldı: business KPI strip (count-up money `data-count`, gerçek delta) + 2-col grid (inline-player onay kartları | connected-accounts widget) + active-runs. Yeni `inline-player.js` (overlay→video.play, gerçek timeupdate→progress scaleX, `<video preload=none>` → media-free seed'de 404 YOK, **drawer AÇMAZ** [eski bug structurally imkânsız: data-drawer-open yok + type=button]). `motion.js` count-up money modu (data-count/prefix/decimals; reduced-motion bail korundu). `app.css` F17 bloğu (inline-player/appr-card/acct-row; transform/opacity, yeni backdrop YOK). `lang/en+tr` +16 `dash.*`/`player.*` parite (eski "scheduling arrives in Phase 10" jargon satırı KALDIRILDI). `AccountRepository::listFor` JOIN'e `asset.workspace_id` hardening (security LOW). `tests/run.php` +8 (balance/cost-null/granted-week/rich-awaiting/accounts-no-fabricate/**tenant-isolation sibling-empty**/parite). **746 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle 1280-EN (KPI $43.90 +grant delta / inline-player placeholder / accounts health) + 375-TR (stacked, tam Türkçe, truthful onay notu "kayıtlar hiçbir şekilde sahte tutulmaz"). **4 gate PASS/0 blocker**: ux (jargon node-id → P20), qa (746, scope/parite/build-free/JS-off), security (0 HIGH; 2 LOW: listFor JOIN [UYGULANDI] + JobRepo SELECT* [P20]), **compliance GATE: truthful records + AI label + 0 fabricated metric EN&TR**. Branch `feat/phase-17-signature-dashboard` (F16'dan stack). LOOP DEVAM → Faz 18.
- 2026-06-13 — **`/go` LOOP — FAZ 16 KABUL (Motion & Interaction Core).** Salt client-side enhancement; PHP/DB/route/screen DOKUNULMADI. Yeni: `motion.js` (PL namespace: `durOf` token okur, kayan-pill sidebar [mouseenter/focus → aktife döner], integer-only KPI count-up rAF), `palette.js` (⌘K palette: aç/filtrele/ok-Enter-Esc/focus-trap+restore, pure nav window.location), `drawer.js` (genel sağ panel `PL.drawer.open/openTemplate`, innerHTML yalnız escaped `<template>`'ten), `command-palette.php` + `drawer.php` partial'ları. Değişen: `base.css` (teal `#2dd4bf`→`#2ff0d2` global + `--glow`/`--accent-line` + statik ambient gradient [teal+violet radial, animasyonsuz, attach fixed] + overflow-x hidden), `app.css` (Faz 16 bloğu +~140: `.main>*` CSS giriş [reduced-motion sıfır, flash-free], pill, kpi hover-lift, ⌘K trigger, cmdk + drawer CSS [backdrop-filter YALNIZ bunların scrim'inde]), `app.php` (head'de `html.js` sync script + topbar ⌘K trigger + 2 partial require + 3 script defer), `lang/en+tr` (+9 `cmd.*`/`help.*` parite), `tests/run.php` (+6 p16 testi). Doğrulama: **738 PASS/0 FAIL**; visual gate **69 PNG / 0 console-error / 0 overflow / exit 0**; CC gözüyle dashboard 1280-EN (pill+brighter-teal+ambient+count-up) ve 375-TR (hamburger, icon-only ⌘K, tam Türkçe, stacked, no-overflow) teyit. §1.2 motion temiz: yalnız transform/opacity keyframe (pl-rise/fade/pop), backdrop-filter sadece `.cmdk`+`.drawer__scrim`, spinner/animasyonlu-blur/kalıcı-backdrop YOK, her animasyon state'e bağlı, reduced-motion tüm token sıfır, no-JS güvenli (html.js gate, content JS'e bağımlı gizlenmiyor). 3 gate **PASS/0 blocker**: ux (pill height-transition should_fix UYGULANDI → yalnız transform/opacity), qa (738 PASS, scope temiz, parite, build-free, JS-off fallback), security (0 HIGH, 2 LOW: drawer innerHTML invariant F17/18 + data-label F17). Branch `feat/phase-16-motion-core` (main'den). LOOP DEVAM → Faz 17.
- 2026-06-13 — START PHASE 15.9: Loop & Visual-Test Infra İNŞA EDİLDİ (Experience Layer altyapı fazı; ürün kodu DOKUNULMADI). `go.md`'in dayandığı GERÇEK görsel-gate altyapısı kuruldu. Karar: **sıfır-bağımlılık Node CDP harness** (sistem Chrome `/Applications/...` + Node v26 built-in `WebSocket`/`fetch` → npm/package.json/Playwright YOK; app build-free kaldı) + **izole visual DB** (`DB_PATH=storage/database/kuyash-visual.sqlite`, `APP_ENV=dev` → cookie non-Secure, http login çalışır). Yeni: `tools/visual/shot.mjs` (CDP driver: login→form-submit, locale-switch CSRF-form-submit, route×{375/768/1280}×{en/tr}, console-error[favicon hariç]+overflow yakala, full-page PNG, exit 1/2/0), `tools/visual/gate.sh` (seed→`php -S` 8099→/health→shot→teardown), `tools/visual/routes.json` (11 nav+login), `bin/visual-seed.php` (idempotent, MEDIA-FREE seed: awaiting job result_json'da draft_render_id YOK → 0 broken-media 404; `Nodes::defaultNodes()` ile doğru nodes_json), `tools/visual/README.md`, `.claude/docs/loop-gates.md` (3-gate görev şablonları). `.gitignore`(+storage/visual/) + `Caddyfile`(+/tools/* blok) ±2. Doğrulama: self-test `--only /dashboard`→6 PNG; tam baseline→**69 PNG, 0 console-error/0 overflow/exit 0**; fail-path (3000px overflow + console.error)→**exit 1**; **732 PASS**; ürün dosyası 0; package.json/node_modules YOK. Görsel teyit (CC gözüyle): dashboard EN/TR gerçek render (KPI 2/1/1/2/0, awaiting strip card--primary accent band, AI rozet), TR/375 tam Türkçe+responsive (hamburger, stacked, "ÖNBELLEK İSABETİ"). 3 reviewer **GO/0 blocker**: qa (scope/idempotent/exit-logic), security (0 blocker, 2 LOW), ux (baseline dürüstçe yeşil, gerçek i18n/responsive/empty-state/dürüst-rozet). Hardening UYGULANDI: visual-seed DB_PATH 'visual' guard (bare-run→exit 2) + Caddy /tools/* parity. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — FAZ 15.5 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı temiz (secret grep: yalnız doc/fixture eşleşmeleri, gerçek anahtar yok), 732 PASS korundu → Faz 15.5 feat `840d1bb` (app.css+base.css+dashboard.php+queue/index.php+phase-15-followups A11Y-2/UX-1) + chore(state) (checkpoint+phase-plan+experience-layer-plan+go.md+design/) + `git push origin main` (auto-push). Aynı push'ta önceki oturumun Experience Layer replan (15.9→20) + `/go` loop altyapısı da gitti (daha önce uncommitted'di). Sıra: `START PHASE 15.9` (Loop & Visual-Test Infra).
- 2026-06-13 — EXPERIENCE LAYER YENİDEN PLANLANDI + OTONOM LOOP (plan/altyapı; ürün kodu YAZILMADI). Kullanıcı 3 iterasyonla v3 tasarım prototipini onayladı (v1→v2 GPU %100 yakıyordu→v3 GPU-light: animasyonlu blur→statik gradient, kalıcı backdrop kaldırıldı, dönen ring→heartbeat glow, sadece transform/opacity/dashoffset; pipeline workflow fill-flow + durum simgeleri + kutuya-tıkla yan panel + onay kartı inline player). Muğlak Faz 16-18 → detaylı **15.9→20** ile değiştirildi (phase-plan.md). Yeni dosyalar: `.claude/docs/experience-layer-plan.md` (tam spec: v3 token tablosu + §1.2 GPU-light kuralları + bileşen envanteri + loop/3-gate spec + faz detayları), `.claude/docs/design/prototype-v3.html` (onaylı görsel kaynak), `.claude/commands/go.md` (`/go` loop: plan→kur→3 paralel gate [ux-reviewer görsel / qa-reviewer kod / security-auditor güvenlik]→verdict→`feat/phase-<N>` branch-commit [no auto-push]→/clear; fail-cap 2→stop-and-report; insan kapısı tablosu). 3 KARAR ONAYLANDI: teal accent #2ff0d2 global, 15.9 ayrı altyapı fazı, plan repoya entegre. Görsel gate ŞART: gerçek Caddy+PHP render + headless screenshot (yoksa sahte). Sonra: `START PHASE 15.9`.
- 2026-06-13 — START PHASE 15.5: Elevation İNŞA EDİLDİ (Experience Layer 2. faz; gate'te kullanıcı "gerekli" + 2 kaldıraç seçti, bento/state SEÇİLMEDİ). Salt sunum, PHP/DB/route/i18n DOKUNULMADI. (A) **surface-depth** (ton+border, gölge/glow/gradient YOK): `.card/.panel/.kpi/.wf-card/.asset-card/.trend-card` üst-kenar `--border-strong` ışık-yakalama; `.card__head`/`__foot` `--surface-2` bantlı (köşe `calc(--r-card - 1px)`); `.card--primary` accent head-band + accent başlık (**yalnız dashboard awaiting + queue approvals**, 2 markup hook). (B) **tipografi/ritim**: `.screen-head h1` 23px (≤480→19px), screen-head margin s5→s6, kart aralığı s3→s4, `.kpi__label` uppercase+tracking. base.css `--text-3` #6b6b74→**#7c7c85** (A11Y-1 çözüldü). Dosyalar: app.css (+41), base.css (±1), dashboard.php+queue (birer class). Identity audit: yeni shadow/gradient/blur=0, status-only renk, 15.5 blokta raw hex=0. 732 PASS. ux-reviewer **GO/0 must-fix** (real-Chrome 375/768/1280 EN+TR: depth perceptible, corner math exact, **0px overflow**, TR "ÖNBELLEK İSABETİ" tek satır, --text-3 AA --bg 4.79/--surface 4.56; nice-to-have'ler kaydedildi → `phase-15-followups.md` A11Y-2 [surface-2/3 faint ~4.0-4.3] + UX-1 [dash primary half-width]). Commit YAPILMADI — kabul bekliyor.