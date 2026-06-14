# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-14
- Güncelleyen: Claude (**EXPERIENCE LAYER (FAZ 16–21) TAMAMLANDI + main'e MERGE + PUSH EDİLDİ.** Faz 21 3. tur
  4-madde düzeltmesi `806fdf8`'de commit'lendi (+`chore(state)`), tüm 16→21 stack'i fast-forward ile main'e geldi
  (lineer, çakışma yok), `git push origin main` yapıldı. Kapsam SADECE 4 madde,
  başka ekran/davranış/refactor YOK; engine/queue/worker/migration DOKUNULMADI. **(1)** Workspace adı kullanıcı
  düzenlenebilir: yeni `WorkspaceSettings::setName` (trim+collapse, ≤60, tenant-scoped prepared) + `SettingsController::saveName`
  + `POST /settings/name` ($protected, global CSRF) + /settings'te "Workspace name" kartı + topbar çipi `.mode-chip__name`
  TEAL GRADIENT (hardcoded değil, DB `workspaces.name` okur — ADDITIVE, migration yok). **(2)** Nötr metin rampası
  GÖRÜNÜR teal-slate: `--text #d7ece5 / --text-2 #8fbeb3 / --text-3 #84b2a9` (G−R +21/+47/+46 ≫ eski +6; AA her
  yüzeyde ≥7.06; luminance düşmedi/yükseldi). **(3)** Topbar live dot: yeni `@keyframes live-beat` (opacity+glow
  nabzı ~2s, `.is-live` bağlı, accent+--glow, reduced-motion sabit-glow). **(4)** Pipeline node drawer GERÇEK
  per-aşama çıktı: `Cockpit::pipeline` her node'a `results` (type→result_json, read-only tenant-scope SELECT) +
  pipeline.php her node tipini render (TREND başlık/skor, IDEA hook, SCRIPT gövde, VOICE, VISUALS, ASSEMBLE,
  CAPTION, HASHTAGS, MUSIC, PREVIEW, COMPLIANCE [pass/warn/block + benzerlik% + AI etiketi], PUBLISH) — hepsi
  `View::e` escape (XSS-safe <template> invariant), wait→"başlamadı", veri-yok→"çıktı yok"; visual-seed Run A
  TREND/IDEA/SCRIPT gerçekçi result_json (DEV-only). ~50 lang anahtarı (en+tr parite). **tests/run.php 776→796 PASS
  (+20 rev/item testi), 0 FAIL; visual gate 69 PNG / 0 err / 0 overflow / exit 0; canlı-app curl kanıtı: rename
  persisted+CSRF 403+tenant-izole, dashboard HTML'inde gerçek node çıktısı.** **4 GATE GO/0 blocker** (qa scope+parite,
  security 0 HIGH/MED + 2 LOW, ux piksel-örnekli teal teyit, compliance truthful eşleme). Önceki tur özeti aşağıda. ↓
  **FAZ 21 — İLK SUNUM REDDEDİLDİ (commit `e0f2541`) → 6 MADDELİK DÜZELTME (commit
  `cc8df98`). İNSAN KAPISI bekliyor; PUSH/MERGE YOK.** Salt sunum+i18n+mock veri; engine/route/DB/gerçek-API
  DOKUNULMADI. İlk F21'de §1 hesap widget'ı + dashboard/accounts/queue v3 + temel jargon scrub vardı; kullanıcı
  /logs+/queue artık jargonu + 5 iç ekranın "eski" durması + inline player'ın oynamaması üzerinden reddetti.
  **6 DÜZELTME:** (A1) /logs TAM temizlik — `Messages::event()` {type}/{platform}/{slop→%}/{node} DISPLAY-humanize
  (stored row HAM = audit korunur), event.* string reword (worker/watchdog/policy/WARN-BLOCK çıktı), {kind}
  "[compliance]"→"Uyumluluk", visual-seed GERÇEK event key+param. (A2) /queue render_review özeti
  "(mock):…policy mock-v0" → durum-bazlı "Compliance: passed · AI label required" (ham summary asla basılmaz).
  (A3) /settings+/digest standalone "policy kuyash-v1" çipleri + auto_desc sürümü KALDIRILDI; sürüm YALNIZ truthful
  onay KAYITLARINDA. (B) 5 ekran gerçek v3: glow primary CTA + trends gradient skor/hover/stagger + library
  play-affordance + populated grid + quick/digest/settings `.card--primary` focal. (C) metin rampası teal-fısıltı
  (sadece hue; `--text/-2/-3`; luminance korundu/yükseldi → AA, --text-3 ≥5.0 her yüzey). (D6) inline player GERÇEKTEN
  OYNAR — committed mock fixture (`tools/visual/fixtures/preview.{mp4,jpg}`) seed'le render storage'a + awaiting
  render_review'ye `draft_render_id`; **/render/1 → 200 video/mp4 + 206 range (curl-kanıt)**, SSE `/live` snapshot
  emit. **EK:** canonical node adları `Messages::node()` ile liste/feed'de humanize (VOICE→"Voiceover"; graph
  view'larda canonical KALIR), `[hidden]` CSS fix (boyut-uyarısı koşulsuz görünüyordu), published_today oranı reword.
  `tests/run.php` **776 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; parite **564=564**.
  **Gate'ler:** qa/security/compliance **GO** (truthful records + AI label + sample dürüstlüğü + audit korundu;
  scope: tek `src/` = Messages facade); **ux ilk tur CONDITIONAL** (2 blocker: /queue ham node enum + fixture
  commitsiz) → İKİSİ DE DÜZELTİLDİ → ux yeniden-doğrulama bekleniyor. F21 tip `cc8df98` (F20'den stack).)

## Mevcut durum (kaldığımız yer)

- Aşama: **EXPERIENCE LAYER (FAZ 16–21) TAMAMLANDI + main'e MERGE + PUSH EDİLDİ.** Tüm stack lineer fast-forward
  ile main'e geldi (çakışma yok). **main = origin/main = `806fdf8`** (push edildi). Merge olan commit'ler (eski→yeni):
  F16 `8a6f561` → F17 `231b709` → F18 `1a4486d` → F19 `460e487` → F20 `fd80455` → F21 `e0f2541` → F21-fix `cc8df98`
  → F21-r3 `806fdf8` (+ chore(state)). Test: **796 PASS**. Visual baseline: `storage/visual/phase-21-rev/` (69 PNG,
  tüm 12 ekran v3 + 4-madde düzeltme). feat/phase-16..21 branch'leri merge sonrası bırakılabilir/silinebilir.
- **F21** (Tam Deneyim Dönüşümü; TÜM 12 ekran v3 + jargon=0 [/logs+/queue dahil] + §1 hesap widget'ı + oynar inline
  player): `account-card.php` + `Messages::jobType/platform/node` + event display-humanize + ~50 lang scrub + login
  branding + gradient başlık + trends gradient-skor + glow CTA + teal-fısıltı metin + committed mock fixture. Spec
  `phase-21-full-conversion.md`. **Açık takip (ertelendi):** trends freshness chip ham ISO timestamp (kozmetik);
  workflows/show + runs/show NODE-TRACK canonical adları korundu (graph view = canonical-vocabulary evi, kasıtlı);
  /logs gerçek-DB event'leri artık humanize ediliyor (Messages::event {type}/{node}/{slop}/{platform}).
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

0. **EXPERIENCE LAYER (FAZ 16–21) KAPANDI — main'e merge + push EDİLDİ.** Yeni faz/iş yok; sıradaki çalışma
   aşağıdaki enable-time/followup kalemlerinden seçilir. main=origin/main=`806fdf8`, **796 PASS**, 4 gate GO.
   Açık takip (ertelendi, kozmetik): trends freshness chip ham ISO timestamp; workflows/show+runs/show NODE-TRACK
   canonical adları (graph = canonical-vocabulary evi, kasıtlı). feat/phase-16..21 branch'leri merge sonrası silinebilir.

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

- 2026-06-14 — **FAZ 21 — 3. TUR REDDİ → 4 MADDELİK HEDEFLİ DÜZELTME BİTTİ (UNCOMMITTED; COMMIT/PUSH/MERGE YOK).** Kullanıcı F21'i hâlâ kabul etmedi, SADECE 4 iş istedi (başka ekran/refactor YOK; engine/queue/worker/migration DOKUNULMADI). (1) Workspace adı düzenlenebilir: `WorkspaceSettings::setName`+`SettingsController::saveName`+`POST /settings/name` ($protected/CSRF/tenant-scoped/≤60, ADDITIVE `workspaces.name`, migration yok) + /settings kartı + topbar `.mode-chip__name` TEAL GRADIENT (DB adı okur). (2) Metin rampası GÖRÜNÜR teal: `--text #d7ece5/--text-2 #8fbeb3/--text-3 #84b2a9` (G−R +21/+47/+46 ≫ eski +6; AA ≥7.06; luminance düşmedi). (3) Live dot `@keyframes live-beat` nabız+glow (accent+--glow, reduced-motion sabit). (4) Pipeline drawer GERÇEK per-aşama çıktı: `Cockpit::pipeline` node'lara `results` (read-only SELECT) + pipeline.php 12 node tipini render (hepsi `View::e` escape, wait→"başlamadı"/veri-yok→"çıktı yok"); visual-seed Run A TREND/IDEA/SCRIPT gerçekçi result_json (DEV-only). ~50 lang anahtarı (en+tr parite). **796 PASS/0 FAIL (+20 test); visual 69 PNG/0 err/0 overflow/exit 0; canlı-app curl: rename persist+CSRF 403+tenant-izole, dashboard'da gerçek node çıktısı; topbar yakın-çekim teal teyit.** 4 GATE GO/0 blocker (qa/security 0 HIGH-MED+2 LOW/ux piksel-teal/compliance truthful). 13 dosya working tree'de; İNSAN KAPISI bekliyor.
- 2026-06-14 — **FAZ 21: İLK SUNUM `e0f2541` REDDEDİLDİ → 6 MADDELİK DÜZELTME `cc8df98` → 4 GATE GO; İNSAN KAPISI bekliyor.** İlk F21 (§1 hesap canlı-akış widget'ı `account-card.php` [gradient video-tile + ♥/💬/↗ + takipçi/büyüme; DETERMİNİSTİK ÖRNEK crc32, medya-free, DÜRÜST çerçeveli] + dashboard/accounts/queue v3 + `Messages::jobType/platform` + temel jargon scrub + inline player + login branding) salt sunum+i18n+mock; kullanıcı /logs+/queue artığı jargon + 5 iç ekranın "eski" durması + inline player'ın oynamaması üzerinden REDDETTİ. **6 DÜZELTME:** (A1) /logs TAM temizlik — `Messages::event()` {type}/{platform}/{slop→%}/{node} DISPLAY-humanize (stored row HAM=audit korunur) + event.* reword (worker/watchdog/policy/WARN-BLOCK) + {kind} "[compliance]"→"Uyumluluk" + visual-seed GERÇEK event key/param; (A2) /queue render_review "(mock)…policy mock-v0" → durum-bazlı "Compliance: passed · AI label required" (ham summary basılmaz); (A3) /settings+/digest standalone "policy kuyash-v1" çipleri + auto_desc sürümü KALDIRILDI (sürüm yalnız truthful onay KAYITLARINDA); (B) 5 ekran gerçek v3: glow primary CTA + trends gradient-skor/hover/stagger + library play-affordance+populated grid + quick/digest/settings `.card--primary` focal; (C) metin rampası teal-fısıltı (sadece hue, `--text/-2/-3`; luminance korundu/yükseldi → AA, --text-3 ≥5.0 her yüzey); (D6) inline player GERÇEKTEN OYNAR — committed mock fixture `tools/visual/fixtures/preview.{mp4,jpg}` seed'le render storage'a + awaiting render_review'ye `draft_render_id` → **/render/1 200 video/mp4 + 206 range (curl-kanıt)**, SSE `/live` snapshot emit. **+** canonical node `Messages::node()` ile liste/feed humanize (VOICE→"Voiceover"; graph view'larda canonical KALIR), `[hidden]` CSS fix (boyut-uyarısı koşulsuz görünüyordu), published_today oranı reword. `tests/run.php` **776 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; parite **564=564**. **4 gate GO:** ux (2 blocker [/queue ham node enum + fixture commitsiz] DÜZELTİLDİ+yeniden doğrulandı), qa (scope: tek `src/`=Messages facade; engine/route/DB/controller DOKUNULMADI; build-free), security (0 HIGH/MED; 2 LOW info), **compliance hard-gate** (truthful records [policy yalnız kayıtta] + AI label + sample dürüst + audit korundu). F21 tip `cc8df98`. **İNSAN KAPISI (tek, sonda) BEKLİYOR — push/merge YOK.**
- 2026-06-13 — **`/go` LOOP — FAZ 20 KABUL (Cila/Perf/A11y) → LOOP TAMAMLANDI (16→20), (H3) DUR.** Son faz, salt cila+kapanış. 3 değişiklik: (1) **a11y kontrast** `base.css --text-3 #7c7c85→#8a8a93` — faint tier ölçülü HER yüzeyde WCAG AA (bg 5.78/surface 5.51/**surface-2 5.23**/**surface-3 4.86** ≥4.5; eski 4.33/4.02 sub-AA → phase-15-followups A11Y-1+A11Y-2 ÇÖZÜLDÜ; --text-2'den hâlâ belirgin faint). (2) **dürüst kopya** `nav.foot_title/text` EN+TR — "Phase 12 · …/credit-gated/mock-first" jargonu KALDIRILDI → "Quick Create / Turn a photo into a short, AI-labeled video." (AI-label compliance gerçeği KORUNDU). (3) `pipeline.php` node button `title` attr (768px ellipsis truncation yardımı; aria-label zaten vardı). **Doğrulama (kod yok):** §1.2 tam tarama → her @keyframes (rise-in/pl-rise/pl-fade/pl-pop/pl-hb) opacity/transform-only, backdrop-filter yalnız `.cmdk`+`.drawer__scrim`, spinner 0, 3 pl-hb heartbeat yalnız `.pl-node--active`/`.topbar__live.is-live` (boşta GPU ~0), reduced-motion tam (explicit override + token-zero); keyboard a11y (⌘K focus-trap+restore, drawer Esc/scrim+focus, real `<button>`'lar `:focus-visible`, aria-current); dürüst rozet; güvenlik son geçiş. `tests/run.php` +3 (PHP'de WCAG ratio yeniden-hesap ≥4.5 ×4 surface + jargon-free + AI-label korundu guard). **764 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle dashboard honest foot ("Quick Create") + brighter faint tier teyit. **4 gate PASS/0 blocker:** ux (AA bağımsız recompute, §1.2 temiz, klavye a11y, 48 --text-3 consumer regresyonsuz), qa (764, scope: yalnız base.css+lang+pipeline.php+tests, parite 524=524), **security SON SIGN-OFF 0 BULGU** (drawer innerHTML invariant + /live tenant-read-only + escaping + CSRF hepsi geçerli; AccountRepo JOIN hardening bonus), **compliance SON hard-gate** (truthful records DB-invariant 0007 CHECK ile garantili + AI label + 0 fabricated metric EN&TR). Branch `feat/phase-20-polish-a11y` (F19'dan stack). **LOOP DURDU → kullanıcı 5-faz stack'i inceler.**
- 2026-06-13 — **`/go` LOOP — FAZ 19 KABUL (Canlı Katman / SSE).** TEK gerçek backend yüzeyi, güvenli. Tasarım kararı: **IMMEDIATE-CLOSE SSE** — `/live` tek workspace-scoped snapshot (`retry: 5000\nevent: snapshot\ndata: {active,awaiting,ts}`) emit edip KAPANIR; tarayıcı EventSource ~5s'de reconnect = canlı tick. Held-connection/loop/sleep YOK → single-thread `php -S`+harness'ı BLOKE ETMEZ (H2 risk çözüldü), PHP session lock TUTMAZ (`session_write_close`), uzun transaction YOK, kaynak tükenmesi YOK (güvenlik artısı). Yeni `src/Controllers/LiveController.php` (`stream`: auth fail-closed backstop + tenant `workspace->id()` [request param YOK] + `Cockpit::liveSnapshot` + `session_write_close` guard'lı + text/event-stream Response [no-cache/no-transform/X-Accel-Buffering:no/nosniff]), `Cockpit::liveSnapshot(int $ws)` (tek `WHERE workspace_id=?` SELECT, read-only, O(1)), `GET /live` `$protected` (routes.php + import), `web.php` binding (+import), `public/assets/js/live-client.js` (EventSource('/live')→'snapshot'→`[data-live].is-live` + `[data-live-text]` "updated just now" + `[data-live-awaiting]` textContent; EventSource yoksa erken return; onerror→is-live kaldır+retry; beforeunload→close). `app.php` topbar `.topbar__live` (heartbeat dot + live-text; `html:not(.js)→display:none` DÜRÜST no-JS gizle), `dashboard.php` awaiting KPI `data-live-awaiting`, `app.css` F19 (`.topbar__live.is-live .dot` `pl-hb` opacity heartbeat + reduced-motion override + ≤640 text gizle/≤460 tüm gizle), `lang/en+tr` +2 `live.*` parite, `tests/run.php` +8 (liveSnapshot count/tenant-sibling-zero, SSE content-type/retry+event/JSON-data, **READ-ONLY no-write**, unauth→/login backstop, parite). **761 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0** (0-console-error ⇒ EventSource /live'a temiz bağlandı, doğru content-type). **3 gate PASS/0 blocker**: security AĞIR (tenant-izol session-only fail-closed, immediate-close=no-DoS/no-held-conn, read-only test, session_write_close, secret yok; 2 LOW: router-level unauth test granularity + enable-time Caddy/CF no-cache → takip), ux (§1.2 temiz opacity heartbeat [v3 glow'unu bile düşürdü], no-JS dürüst gizle, jargonsuz "Live"/"Canlı"), qa (761, scope/parite/build-free/JS-off, immediate-close kod doğrulandı). Branch `feat/phase-19-live-sse` (F18'den stack). LOOP DEVAM → Faz 20 (SON: cila/perf/a11y).
- 2026-06-13 — **`/go` LOOP — FAZ 18 KABUL (Pipeline/Workflow node-graph).** GERÇEK job state'inden, **ENGINE DOKUNULMADI** (salt visualize). Yeni `Cockpit::pipeline()` (top active run [running/awaiting], `nodes_json` decode + jobsByNode → her node done/active/wait/failed; salt-okuma SELECT workspace-scope; idle→`null`) + `nodeGraphState()` (runs/show.php `$nodeState` precedence'ını 4 state'e fold: failed>awaiting/processing/queued>cancelled>count>=expected?done:active). `templates/partials/pipeline.php` (`.pipeline-flow` SVG#pipeline-conns + `.pipeline-nodes` button chip'leri [chip ikonu + canonical ad + status ikonu ✓/⚡/dashed-ring] + her node için `<template id=node-tpl-{i}>` drawer içeriği: `nodestat` + PLAIN `node.desc.*` + "Gelen→İşlem→Çıktı" + auto-note; `$st` View::e). `node-graph.js` (vanilla IIFE: chip rect'lerinden SVG line çiz — done→done solid-green kanal, done→active **fill-flow** stroke-dashoffset SMIL + leading dot [reduced-motion'da skip], rest faint dashes; token renkleri hexA(); resize redraw; mobil SVG display:none→bail). `app.css` F18 bloğu (pl-node done[ok]/active[accent + statik glow box-shadow + `@keyframes pl-hb` opacity heartbeat + reduced-motion override]/wait[opacity.5]/failed[err]; `.nodestat`/`.drawer-desc`/`.drawer-flow`; **mobil ≤720px STACKED rows**, conns hidden). `dashboard.php` ("Üretim hattı · içerik #N" kartı KPI ile grid arası; `pipeline===null`→kart gizli). `app.php` (+node-graph.js script). `lang/en+tr` +21 `pipeline.*`/`node.desc.*` (PLAIN dil, jargonsuz; canonical node adları çevrilmedi; `runs.state_*` reuse). `bin/visual-seed.php` Run A'ya TREND/IDEA/SCRIPT(ready=done)+VOICE(processing=active) job — dev-seed, **media-free**. Drawer içeriği Faz16 `data-drawer-open`→`openTemplate` (escaped template, XSS-safe — drawer innerHTML invariant KORUNDU). `tests/run.php` +7 (done/active/wait mapping, canonical-order, **idle→null honest empty**, parite, **jargon-free desc**). **753 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle 1280-EN (yatay graph: done-yeşil/active-teal-glow/wait-faint + connectors/fill-flow) + 375-TR (STACKED: TREND✓ IDEA✓ SCRIPT✓ VOICE⚡ → dashed-ring wait, canonical adlar). **3 gate PASS/0 blocker**: ux (jargonsuz teyit, mobil stacked, §1.2 temiz; should_fix 768px chip truncation cosmetic), qa (753, **engine untouched** doğrulandı, scope/parite/build-free/JS-off), security (0 HIGH; 2 LOW: `$st` enum [View::e UYGULANDI] + statik SVG glyph [safe]). Branch `feat/phase-18-pipeline-viz` (F17'den stack). LOOP DEVAM → Faz 19 (SSE).
- 2026-06-13 — **`/go` LOOP — FAZ 17 KABUL (İmza Dashboard).** GERÇEK dev-DB verisine bağlı + DÜRÜST (uydurma metrik YOK). `Cockpit` genişletildi (+CreditLedger/UsageRepository/AccountRepository/JobRepository; `snapshot($ctx,$now)` → `business` [balance_cents/spent_mtd/charges_mtd/granted_week/cost_per_content_cents NULL→"—"/awaiting] + `accounts` [listFor, yalnız platform/handle/health/reference] + rich `awaiting` [JobRepo::awaitingApproval reuse]; salt-okuma tenant-scope; ölü `awaiting()` silindi). `web.php` binding +4 servis; `DashboardController` $now passthrough. `dashboard.php` baştan yazıldı: business KPI strip (count-up money `data-count`, gerçek delta) + 2-col grid (inline-player onay kartları | connected-accounts widget) + active-runs. Yeni `inline-player.js` (overlay→video.play, gerçek timeupdate→progress scaleX, `<video preload=none>` → media-free seed'de 404 YOK, **drawer AÇMAZ** [eski bug structurally imkânsız: data-drawer-open yok + type=button]). `motion.js` count-up money modu (data-count/prefix/decimals; reduced-motion bail korundu). `app.css` F17 bloğu (inline-player/appr-card/acct-row; transform/opacity, yeni backdrop YOK). `lang/en+tr` +16 `dash.*`/`player.*` parite (eski "scheduling arrives in Phase 10" jargon satırı KALDIRILDI). `AccountRepository::listFor` JOIN'e `asset.workspace_id` hardening (security LOW). `tests/run.php` +8 (balance/cost-null/granted-week/rich-awaiting/accounts-no-fabricate/**tenant-isolation sibling-empty**/parite). **746 PASS/0 FAIL**; visual **69 PNG / 0 err / 0 overflow / exit 0**; CC gözüyle 1280-EN (KPI $43.90 +grant delta / inline-player placeholder / accounts health) + 375-TR (stacked, tam Türkçe, truthful onay notu "kayıtlar hiçbir şekilde sahte tutulmaz"). **4 gate PASS/0 blocker**: ux (jargon node-id → P20), qa (746, scope/parite/build-free/JS-off), security (0 HIGH; 2 LOW: listFor JOIN [UYGULANDI] + JobRepo SELECT* [P20]), **compliance GATE: truthful records + AI label + 0 fabricated metric EN&TR**. Branch `feat/phase-17-signature-dashboard` (F16'dan stack). LOOP DEVAM → Faz 18.
- 2026-06-13 — **`/go` LOOP — FAZ 16 KABUL (Motion & Interaction Core).** Salt client-side enhancement; PHP/DB/route/screen DOKUNULMADI. Yeni: `motion.js` (PL namespace: `durOf` token okur, kayan-pill sidebar [mouseenter/focus → aktife döner], integer-only KPI count-up rAF), `palette.js` (⌘K palette: aç/filtrele/ok-Enter-Esc/focus-trap+restore, pure nav window.location), `drawer.js` (genel sağ panel `PL.drawer.open/openTemplate`, innerHTML yalnız escaped `<template>`'ten), `command-palette.php` + `drawer.php` partial'ları. Değişen: `base.css` (teal `#2dd4bf`→`#2ff0d2` global + `--glow`/`--accent-line` + statik ambient gradient [teal+violet radial, animasyonsuz, attach fixed] + overflow-x hidden), `app.css` (Faz 16 bloğu +~140: `.main>*` CSS giriş [reduced-motion sıfır, flash-free], pill, kpi hover-lift, ⌘K trigger, cmdk + drawer CSS [backdrop-filter YALNIZ bunların scrim'inde]), `app.php` (head'de `html.js` sync script + topbar ⌘K trigger + 2 partial require + 3 script defer), `lang/en+tr` (+9 `cmd.*`/`help.*` parite), `tests/run.php` (+6 p16 testi). Doğrulama: **738 PASS/0 FAIL**; visual gate **69 PNG / 0 console-error / 0 overflow / exit 0**; CC gözüyle dashboard 1280-EN (pill+brighter-teal+ambient+count-up) ve 375-TR (hamburger, icon-only ⌘K, tam Türkçe, stacked, no-overflow) teyit. §1.2 motion temiz: yalnız transform/opacity keyframe (pl-rise/fade/pop), backdrop-filter sadece `.cmdk`+`.drawer__scrim`, spinner/animasyonlu-blur/kalıcı-backdrop YOK, her animasyon state'e bağlı, reduced-motion tüm token sıfır, no-JS güvenli (html.js gate, content JS'e bağımlı gizlenmiyor). 3 gate **PASS/0 blocker**: ux (pill height-transition should_fix UYGULANDI → yalnız transform/opacity), qa (738 PASS, scope temiz, parite, build-free, JS-off fallback), security (0 HIGH, 2 LOW: drawer innerHTML invariant F17/18 + data-label F17). Branch `feat/phase-16-motion-core` (main'den). LOOP DEVAM → Faz 17.
- 2026-06-13 — START PHASE 15.9: Loop & Visual-Test Infra İNŞA EDİLDİ (Experience Layer altyapı fazı; ürün kodu DOKUNULMADI). `go.md`'in dayandığı GERÇEK görsel-gate altyapısı kuruldu. Karar: **sıfır-bağımlılık Node CDP harness** (sistem Chrome `/Applications/...` + Node v26 built-in `WebSocket`/`fetch` → npm/package.json/Playwright YOK; app build-free kaldı) + **izole visual DB** (`DB_PATH=storage/database/kuyash-visual.sqlite`, `APP_ENV=dev` → cookie non-Secure, http login çalışır). Yeni: `tools/visual/shot.mjs` (CDP driver: login→form-submit, locale-switch CSRF-form-submit, route×{375/768/1280}×{en/tr}, console-error[favicon hariç]+overflow yakala, full-page PNG, exit 1/2/0), `tools/visual/gate.sh` (seed→`php -S` 8099→/health→shot→teardown), `tools/visual/routes.json` (11 nav+login), `bin/visual-seed.php` (idempotent, MEDIA-FREE seed: awaiting job result_json'da draft_render_id YOK → 0 broken-media 404; `Nodes::defaultNodes()` ile doğru nodes_json), `tools/visual/README.md`, `.claude/docs/loop-gates.md` (3-gate görev şablonları). `.gitignore`(+storage/visual/) + `Caddyfile`(+/tools/* blok) ±2. Doğrulama: self-test `--only /dashboard`→6 PNG; tam baseline→**69 PNG, 0 console-error/0 overflow/exit 0**; fail-path (3000px overflow + console.error)→**exit 1**; **732 PASS**; ürün dosyası 0; package.json/node_modules YOK. Görsel teyit (CC gözüyle): dashboard EN/TR gerçek render (KPI 2/1/1/2/0, awaiting strip card--primary accent band, AI rozet), TR/375 tam Türkçe+responsive (hamburger, stacked, "ÖNBELLEK İSABETİ"). 3 reviewer **GO/0 blocker**: qa (scope/idempotent/exit-logic), security (0 blocker, 2 LOW), ux (baseline dürüstçe yeşil, gerçek i18n/responsive/empty-state/dürüst-rozet). Hardening UYGULANDI: visual-seed DB_PATH 'visual' guard (bare-run→exit 2) + Caddy /tools/* parity. Commit YAPILMADI — kabul bekliyor.
- 2026-06-13 — FAZ 15.5 KABUL: kullanıcı kabul + commit + push onayı verdi. Güvenlik kapısı temiz (secret grep: yalnız doc/fixture eşleşmeleri, gerçek anahtar yok), 732 PASS korundu → Faz 15.5 feat `840d1bb` (app.css+base.css+dashboard.php+queue/index.php+phase-15-followups A11Y-2/UX-1) + chore(state) (checkpoint+phase-plan+experience-layer-plan+go.md+design/) + `git push origin main` (auto-push). Aynı push'ta önceki oturumun Experience Layer replan (15.9→20) + `/go` loop altyapısı da gitti (daha önce uncommitted'di). Sıra: `START PHASE 15.9` (Loop & Visual-Test Infra).
- 2026-06-13 — EXPERIENCE LAYER YENİDEN PLANLANDI + OTONOM LOOP (plan/altyapı; ürün kodu YAZILMADI). Kullanıcı 3 iterasyonla v3 tasarım prototipini onayladı (v1→v2 GPU %100 yakıyordu→v3 GPU-light: animasyonlu blur→statik gradient, kalıcı backdrop kaldırıldı, dönen ring→heartbeat glow, sadece transform/opacity/dashoffset; pipeline workflow fill-flow + durum simgeleri + kutuya-tıkla yan panel + onay kartı inline player). Muğlak Faz 16-18 → detaylı **15.9→20** ile değiştirildi (phase-plan.md). Yeni dosyalar: `.claude/docs/experience-layer-plan.md` (tam spec: v3 token tablosu + §1.2 GPU-light kuralları + bileşen envanteri + loop/3-gate spec + faz detayları), `.claude/docs/design/prototype-v3.html` (onaylı görsel kaynak), `.claude/commands/go.md` (`/go` loop: plan→kur→3 paralel gate [ux-reviewer görsel / qa-reviewer kod / security-auditor güvenlik]→verdict→`feat/phase-<N>` branch-commit [no auto-push]→/clear; fail-cap 2→stop-and-report; insan kapısı tablosu). 3 KARAR ONAYLANDI: teal accent #2ff0d2 global, 15.9 ayrı altyapı fazı, plan repoya entegre. Görsel gate ŞART: gerçek Caddy+PHP render + headless screenshot (yoksa sahte). Sonra: `START PHASE 15.9`.
