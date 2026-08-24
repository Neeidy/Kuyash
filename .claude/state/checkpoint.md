# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-08-23
- Güncelleyen: Claude (**FAZ 24 — HAFTALIK PLAN ARTIK TAKVİM + İKİ MOD tamamlandı. 994 PASS/0 FAIL (+53),
  görsel gate 75 PNG/0 hata/0 taşma, 12 nav route canlı 200.** Faz 23 haftalık **şablon** teslim etmişti ama
  şablon hiçbir şey TUTAMIYORDU — içerik ancak onay anında, teker teker bir saate bağlanıyordu. Faz 24 eksik ismi
  ekliyor: **tarihli hücre (occurrence)** + her saate bir **mod** (videoyu ben eklerim / Kuyash hazırlasın).
  **RİSK-ÖNCE:** ürün kodu yazmadan Görev-0 spike'ı mevcut kodla seam'i uçtan uca kanıtladı — DST'yi aşan
  `America/New_York` Çar 09:00 → `2026-03-11T13:00:00Z`, `runs.publish_after` → `run_after` gate → adapter'a
  birebir aynı `scheduledFor`. **Migration 0017 (tamamı ADDITIVE):** `publish_slots.mode`, `workspaces.auto_lead_minutes`
  (30–1440, vars. 180) + `plan_paused`, ve **`slot_occurrences`** — kimlik `UNIQUE(slot_id, local_date)` yani YEREL
  gün, böylece DST anı oynatır ama bir Pazartesi'yi asla ikizlemez; `UNIQUE(run_id)` çift-run kilidi.
  **`status` bilinçli olarak dar** (`open|assigned|skipped`) — "hazırlanıyor/onay bekliyor/zamanlandı/yayınlandı"
  `PlanBoard` tarafından gerçek run+job'dan TÜRETİLİR, ikinci bir state machine yok.
  **TAŞIYICI KARAR:** `runs.publish_after` **run DOĞARKEN** yazılıyor, onayda değil — `Engine::approve` saati yalnız
  YAZAR (hiç silmez), dolayısıyla önceden set edilmiş an, hiçbir şey seçilmeden yapılan onayda korunur VE
  `approve()`'dan hiç geçmeyen otomatik-onay yoluna (`finalizeAutoApproved`) da ulaşır. Onayda yazsaydık
  otomatik onaylanan planlı içerik slotunu yok sayıp HEMEN yayınlardı. Yeni motor metotları: `setPublishAfter`
  (null'lanabilir), `cancelRun` (korumalı; publish `processing`/`published` ise reddeder, `approvals` satırı YAZMAZ —
  iptal insan reddi değildir), `startRun` → `startRunFor(int $wsId, …)` delegasyonu (worker sessionless kalıyor).
  **`PlanRunner`** worker yarısı: chore kadansında VE worker başlangıcında **ilk claim'den ÖNCE** (sıra taşıyıcı —
  3 gün kapalı kalmış worker eski yayınları KAPATMALI, hepsini birden ateşlememeli). Üretim her guardrail'i
  **tek satır oluşmadan önce** kontrol eder; engel **KAPATILMAZ, NOT EDİLİR** (cap sıfırlanır, switch geri açılır —
  erken "kaçtı" demek yalan olurdu).
  **ÜÇ KAPANIŞ GATE'İ DE NO-GO DÖNDÜ; TÜM BLOCKER'LAR AYNI TURDA KAPATILDI**, her biri `p24/gatefix` testiyle
  çivilendi (12 test). En kötüsü (üçünün de bulduğu): saat silmede `committedForSlot` `publish_at > now` filtreliyordu →
  grace penceresindeki gün onaysız siliniyor, run'ı iptal edilmiyor → geride **geçmiş `publish_after`** taşıyan run
  kalıyor, kuyruk onu "hemen yayınla" okuyor → operatörün SİLDİĞİ bir saatten, plan kaydı olmadan anında yayın.
  Faz 23'ün KRİTİK dediği sınıfın aynısı. İki taraftan da kapatıldı. Ayrıca: **yayınlanmış** gün `missed` diye
  süpürülüp denetim kaydına sahte hata yazıyordu; board `now`'dan pencereliyordu → açıklama gereken TEK gün
  kayboluyordu ve dashboard "kaçtı" sayacı asla sıfırdan çıkamıyordu; her `skipped` kırmızı "Kaçtı" idi (operatörün
  kendi temizlediği gün ve görevini yapan guardrail dahil); yakalanmayan `PlanRunner::tick()` worker'ı claim'den
  ÖNCE öldürüp **tüm yayını sessizce durdurabiliyordu**; sıradan eski bir kütüphane videosunu silmek FK'ye çarpıp
  500 veriyordu; onay bildirimi run'ın gerçek anını değil PLANIN anını söylüyordu ve tekrar-POST edilen
  `publish_now` motorun reddettiği bir kararda state değiştirebiliyordu; takvim zaten SELECT ettiği gerçek kuyruk
  gate'ini yok sayıyordu; `plan.reason_compliance_block` format bloklarını da slop diye adlandırıyordu.
  **Kendim bulduğum 2 kusur (gate'lerden önce):** saat silmek FK ihlaliyle 500 veriyordu (occurrence'lar), ve
  `.sr-only` markup'ta kullanılıyor ama CSS'te **hiç tanımlı değildi** — Faz 23'ten beri "gizli" etiketler tam
  görünüyordu. Ayrıca `input[type="number"]` iki stil listesinde de yoktu (Faz 15 drift'inin aynısı).
  **UI:** `/plan` takvim-merkezli — 375px gün-listesi, 768px+ 7-sütun hafta ızgarası (CSS grid, yeni bağımlılık yok).
  Sıfır jargon; hücre "Kaçtı" ise gerçek nedeni söyler. Kuyrukta planlı kart gününü **söyler** (sormaz), "bunun
  yerine hemen yayınla" ayrı ve sonucu yazılı bir seçim. **Faz 23 borçları kapandı:** plan mutasyonları
  `guardrail.*` denetleniyor, duplicate ≠ invalid, plan yazmaları + `/accounts/sync` IP-başına throttle'lı.
  **ONAY ZAYIFLATILMADI:** `script_draft` otomatik run'da da insan kapısı; **ADR-015'in kilitli auto-onay kapsamı
  GENİŞLETİLMEDİ**; `approval_mode` varsayılanı `manual` (N1). Tam-gözetimsiz yol = **Görev 8, bu fazda YOK**.
  Dev DB 0017'ye migrate edildi (WAL-safe yedek `kuyash.pre-0017.20260823T185852Z.bak.sqlite`; 0 FK ihlali,
  mevcut saatler dürüstçe `manual`). Detay → **ADR-022**, `.claude/docs/phase-24-plan.md`,
  `.claude/docs/phase-24-followups.md`.)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 24 (Haftalık Plan = Takvim + İki Mod) TAMAM — kabul/commit bekliyor. 994 PASS / 0 FAIL;
  3 gate NO-GO → tüm blocker'lar kapatıldı, 12 `p24/gatefix` testiyle çivilendi.** Yeni dosyalar:
  `database/migrations/0017_plan_occurrences.sql`, `src/Publish/OccurrenceRepository.php`,
  `OccurrenceMaterializer.php`, `PlanBoard.php`, `PlanRunner.php`. Yeni route'lar: POST /plan/day/{id}/assign,
  /plan/day/{id}/clear, /plan/settings, /plan/pause, /plan/slots/{id}/mode. `SlotResolver::occurrencesBetween`
  (saf, DST-doğru), `Engine::startRunFor` / `cancelRun` / `setPublishAfter`, `SlotRepository::listForWorkspace` /
  `setMode` / `lastAddFailure`, `WorkspaceSettings::plan/setAutoLeadMinutes/setPlanPaused`,
  `WorkflowRepository::findByTemplateFor`. `bin/worker.php` plan tick'i claim'den ÖNCE + try/catch'li.
  **KAPSAM DIŞI (gerekçeli):** Görev 8 tam-gözetimsiz (`script_draft` oto-onayı) — ADR-015'in kilitli kapsamını
  genişletir, ayrı compliance kapısı ister; AI-video plandan tetiklenmez (stok+TTS); toplu yükleme yok;
  per-account FARKLI saat hâlâ yok (`account_id` okunmuyor); caption elle düzenleme ayrı bilet.
  **Önceki aşama:** FAZ 22 + DÜZELTME TURU TAMAM. 892 PASS / 0 FAIL. Düzeltme turu 2 bug: (1) **nav pill rebound GERÇEK
  kök-neden** — MPA'da her tıklama = sayfa yükleme; pill `translateY(0)`'da doğup aktif item'a **animasyonla**
  gidiyordu (kanıt: /settings offsetTop 351, pill 0, `getAnimations()` 250ms transform "running"). İlk fix (easing
  swap) bu yüzden işe yaramamıştı. FIX: base state'te transform transition YOK → `moveTo` + `void offsetHeight`
  (layout flush) → `.is-ready` **senkron** (rAF gizli sekmede askıya alınıyordu → pill opacity 0 kalıyordu).
  (2) **gerçek hesapta uydurma engagement (compliance)** — @ai.neeidy `9.5K/298/1.9K` crc32 gösteriyordu; artık
  `$providerBacked` (followers_count!==null) tüm kartı yönetiyor: gerçek hesap → snapshot'tan gerçek değer ya da
  `—` + "veri yok" rozeti, uydurma YOK; demo hesap → `[örnek]` çipli stand-in KORUNDU. `listFor` en yeni
  `account_metrics` snapshot'ını LEFT JOIN ediyor (ws-scoped).
  **Önceki tur:** FAZ 22 (Panel + Gerçek Veri) commit `9b7d7a2` + `d46e597`, 873 PASS. Yeni: `accountMetrics()`
  seam (follower+engagement), `src/Analytics/DailySnapshot.php` zero-cost günlük snapshot, migration **0014**
  (`account_metrics` + `accounts.followers_count`) ve **0015** (dedup repair + UNIQUE index), `connect()` revive-existing,
  `setFollowers()`, kartta gerçek-vs-örnek ayrımı, pill easing fix, `Messages::since()`. Dev DB 0015'e migrate edildi
  (yedek `kuyash.pre-p22-dedup.20260823T130328Z.bak.sqlite`); ws#2 hesapları artık: #1 @smoke_tt (mock demo, KORUNDU),
  #3 @ai.neeidy (gerçek, followers_count=7). Süreçler: dev server 8082 (PID 13685), worker (PID 51991, healthy-idle).
  **Önceki aşama:** ZERNIO accountId FIX push'lu (`2a2de5c`) — gerçek publish hazır.
  Publish 400 "Invalid accountId format": connect external_ref'i uyduruyordu (`zacct_`+random) → gerçek Zernio
  SocialAccount `_id` (24-hex, GET /accounts) saklanmalı. FIX: `accounts()` PublishProvider arayüzüne taşındı
  (Mock+Spy impl); `AccountRepository::setExternalRef`; connect gerçek _id'yi çözüyor; yeni `sync()` + `POST /accounts/sync`
  + "Hesapları eşitle" butonu reconcile (platform+@/case-insensitive username). UI vendor-neutral (Zernio sızıntısı yok).
  **CANLI KANIT:** GET /accounts `_id=6a2f250a5f7d1751abb4803a` → ws#2 #3 external_ref reconcile → gerçek postPayload
  `accountId`=o _id (MATCH). ws#2 #3 data-fix UYGULANDI → panelden retry hazır.
  **Önceki:** ASSEMBLY R2-staging fix (`62c76fe`) + ws#2 ölü-asset temizliği (asset #3 silindi, avatar=NULL → yeni
  run'lar canlı Pexels'e düşer). **`.env`:** OPENAI/TTS/STOCK_MOCK=false, STORAGE_DRIVER=r2, **ZERNIO_MOCK=false (gerçek
  yayın ON)**, VIDEO_MOCK=true; bütçe cap PreflightGate. **AÇIK:** çalışan worker PID 34294 fix'lerden önce başlamış
  olabilir → `php bin/worker.php` RESTART önerilir. Faz 10 `6891f8b`; EXPERIENCE LAYER (16–21) `806fdf8`.
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

0. **FAZ 24 TAMAM — kabul + commit + push bekliyor** (working tree'de, commit YOK). 994 PASS / 0 FAIL;
   görsel gate 75 PNG / 0 hata; 12 nav route canlı 200; secret taraması temiz. Dev DB 0017'de.
   **Sıradaki iş seçenekleri:** (a) **Görev 8 — tam gözetimsiz yayın**: plan-kaynaklı run'larda `script_draft`
   oto-onayı. ADR-015'in KİLİTLİ auto-onay kapsamını genişletir (`script_draft` öncesinde compliance verdict'i
   YOK) → **kullanıcı kararı + compliance-reviewer GO şart**. Alternatif: "her AI içerik bir insan onayından
   geçer"i kalıcı ürün vaadi yapıp bu yolu hiç açmamak. (b) **Caption/hashtag elle düzenleme** — distribution-only
   operatör için en değerli tek ekleme (kendi videonu yükle, sistem yalnız planlasın/yayınlasın senaryosu);
   bugün AI yazar, sen yalnız onaylar/reddedersin. (c) `.claude/docs/phase-24-followups.md`'den seçim.

1. **FAZ 10 önceki kararı (hâlâ açık):** `ZERNIO_MOCK=false` şu an ON; ilk kontrollü gerçek yayın kullanıcı
   inisiyatifinde (render_review Manual onay kapısı publish öncesi durdurur).
2. **Operatör enable-time (production-readiness.md):** R2 → `bin/r2-smoke.php` PASS + PRIVATE teyidi sonra
   `STORAGE_DRIVER=r2`; backup cron (`bin/backup.php`); `caddy validate` + canlı tunnel; prod `.env`
   APP_DEBUG=false + gerçek key'ler. Not: gerçek dev DB **0013'e** migrate edildi.

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

- 2026-08-23 — **FAZ 24: HAFTALIK PLAN = TAKVİM + İKİ MOD — 994 PASS/0 FAIL (+53), görsel gate 75 PNG/0 hata, 12 route canlı 200.** Faz 23'ün haftalık ŞABLONU hiçbir şey tutamıyordu; Faz 24 tarihli hücreyi (`slot_occurrences`, kimlik = saat × YEREL gün) ve saat-başına modu ekliyor. **Görev 0 RİSK SPIKE önce, ürün kodu yazmadan:** DST'yi aşan NY Çar 09:00 → `13:00Z` → `publish_after` → `run_after` gate → adapter'a birebir aynı `scheduledFor` (mevcut kodla, uçtan uca kanıt). **Taşıyıcı karar:** `publish_after` run DOĞARKEN yazılır — `approve()` saati yalnız yazar, asla silmez, ve otomatik-onay yolu `approve()`'dan hiç geçmez; onayda yazsaydık otomatik onaylanan planlı içerik slotunu yok sayıp hemen yayınlardı. `startRun` → `startRunFor(int $wsId,…)` delegasyonu (worker sessionless KALDI). `PlanRunner` chore claim'den ÖNCE koşar (3 gün kapalı worker eski yayınları kapatmalı, ateşlememeli); engel KAPATILMAZ, NOT EDİLİR. **3 GATE DE NO-GO; hepsi aynı turda kapatıldı (12 `p24/gatefix` testi).** Üçünün de bulduğu kritik: saat silmede `committedForSlot` `publish_at > now` filtreliyordu → grace penceresindeki gün onaysız silinip run'ı iptal edilmiyordu → geride geçmiş `publish_after` taşıyan run kalıyor, kuyruk "hemen yayınla" okuyor → SİLİNMİŞ bir saatten, plan kaydı olmadan anında yayın (Faz 23'ün KRİTİK sınıfı). Ayrıca: yayınlanmış gün `missed` süpürülüp denetime SAHTE hata yazıyordu; board `now`'dan pencereliyordu → açıklama gereken tek gün kayboluyor, dashboard "kaçtı" sayacı asla sıfırdan çıkamıyordu; her `skipped` kırmızı "Kaçtı" idi (operatörün temizlediği gün + görevini yapan guardrail dahil); yakalanmayan `PlanRunner::tick()` worker'ı claim'den ÖNCE öldürüp TÜM yayını sessizce durdurabiliyordu; sıradan eski kütüphane videosunu silmek FK'ye çarpıp 500 veriyordu; onay bildirimi PLANIN anını söylüyordu (run'ınkini değil) ve tekrar-POST `publish_now` reddedilen kararda state değiştirebiliyordu; takvim zaten SELECT ettiği gerçek kuyruk gate'ini yok sayıyordu; compliance blok gerekçesi format bloklarını slop diye adlandırıyordu. **Kendim bulduğum 2 kusur:** saat silmek FK ihlaliyle 500 (occurrence'lar) ve **`.sr-only` CSS'te hiç tanımlı değildi** — Faz 23'ten beri "gizli" etiketler tam görünüyordu; ayrıca `input[type=number]` stil listelerinde yoktu (Faz 15 drift'i). **ONAY ZAYIFLATILMADI:** `script_draft` insan kapısı kaldı, ADR-015 kapsamı genişletilmedi, `approval_mode` varsayılanı `manual`. Dev DB 0017 (WAL-safe yedek, 0 FK ihlali).

- 2026-08-23 — **FAZ 23: PLANLI PAYLAŞIM (haftalık slot) — 924 PASS/0 FAIL (+28), görsel gate 69 PNG/0 hata/0 taşma, route 12/12 200.** Premis doğrulandı: tek-anlık zamanlama ZATEN uçtan uca çalışıyordu (onay → `runs.publish_after` → kuyruğun `run_after` gate'i → adapter `scheduledFor`), eksik olan tekrarlı plandı → bu faz onun ÜSTÜNE kuruldu, **ENGINE'E DOKUNULMADI**. **Yeni:** migration **0016** `publish_slots` (workspace_id, ops. account_id, weekday 1-7 ISO, time_hhmm 'HH:MM', enabled; UNIQUE `COALESCE(account_id,0)` çünkü SQLite NULL'ları ayrı sayar) + `workspaces.timezone`; **`SlotResolver`** (SAF: saat okumaz, DB'ye bakmaz — "Pzt 09:00 <dilim>" → sonraki UTC anı; **DST-doğru**: gün kaydırmasından sonra duvar-saati YENİDEN uygulanır, canlı kanıt NY kış `14:00Z` / yaz `13:00Z` / DST'yi AŞAN hafta `13:00Z` yani yerel 09:00 korunuyor); **`SlotRepository`** (tenant-scoped CRUD, başka workspace'in hesabına daraltma REDDEDİLİR); `WorkspaceSettings::timezone/setTimezone` (tzdata doğrulamalı). **UI:** /settings "Haftalık yayın planı" kartı (dilim seçici + slot listesi "sıradaki 15 sa içinde" + Duraklat/Kaldır + ekleme satırı), /queue onay formunda slot seçici (varsayılan "Onaylanır onaylanmaz yayınla"), dashboard "Sıradaki yayın" bandı + canlı geri sayım (**Faz-10 ertelemesi kapandı**; geri sayım data-* attribute'larından okur → i18n tek kaynak, JS kapalıyken sunucu ifadesi kalır). **BULUP DÜZELTTİĞİM TUTARSIZLIK:** zaman-dilimsiz `datetime-local` sessizce UTC sanılıyordu — workspace UTC+3 iken 09:00 yazan operatör 12:00 yerel saatte yayınlardı; artık slot da manuel giriş de workspace dilimini kullanıyor, etiket gerçek dilimi söylüyor ("saatler Europe/Istanbul"). **Görsel gate 375px'te 10px taşma YAKALADI** → tahmin etmek yerine tarayıcıda DOM zinciri ölçüldü → kök-neden `.approve-card__actions` `flex:none` (küçülemiyor, parent 317px < içerik 343px) → `max-width:100%` → ölçümle temiz (scrollWidth 375 = viewport). **KAPSAM DIŞI (gerekçeli):** adapter `timezone:'UTC'` hardcode'u KALDIRILMADI — `publish_after` zaten UTC instant, UTC+UTC tutarlı ve doğrulanmış; workspace dilimini adaptöre taşımak Zernio'nun doğrulanmamış scheduledFor+timezone semantiğine girip yanlış saatte yayın riski yaratırdı (integrations "never hallucinate"). Per-account farklı saat de kapsam dışı (engine fan-out) — şema `account_id` ile hazır. `bin/visual-seed.php`'ye 3 slot + dilim eklendi (slot = operatör yapılandırması, uydurma metrik değil).

- 2026-08-23 — **FAZ 22 DÜZELTME TURU (yeni faz değil): 2 bug kapatıldı — 892 PASS/0 FAIL (+19).** **BUG1 nav pill rebound — İLK FIX YANLIŞ HEDEFLENMİŞTİ.** Gerçek kök-neden tarayıcıda ÖLÇÜLDÜ: Kuyash MPA → her nav tıklaması = tam sayfa yükleme → pill JS ile `translateY(0)`'da (en üst) doğuyor, sonra aktif item'a taşınıyor; base CSS'te transform transition ARMED olduğu için bu **başlangıç yerleşimi animasyona dönüşüyordu**. Kanıt (/settings, fix öncesi): aktif `offsetTop=351`, pill `translateY(0)`, `getAnimations()` → transform transition `playState:"running"`, `duration:250ms`. Yani gösterge her tıklamada yukarıdan aşağı uçuyordu = kullanıcının "başa atıp tekrar geliyor" şikayeti. Easing swap (`--spring`→`--ease-out`) bunu ASLA çözemezdi çünkü sorun eğri değil, **ilk yerleşimin animasyonlu olması**. FIX: `.nav-item__pill` base state'inde transform transition YOK → `moveTo(activeItem())` + `void pill.offsetHeight` (layout flush, konumu taban değer olarak commit et) → `.is-ready` transition'ı ARM eder (hover hâlâ akıcı). **Ek keşif:** `.is-ready` rAF içindeydi; rAF gizli sekmede askıya alınır → arka planda açılan sayfada pill hiç `.is-ready` almıyor, `opacity:0` kalıyordu (gösterge yok) → **senkron** yapıldı; opacity de transition'dan çıkarıldı (aynı nedenle takılıyordu). **GERÇEK TIKLAMA KANITI:** /accounts (aktif 211px) → Trends'e gerçek `click()` → /trends yüklendi → pill `translateY=70` = aktif `offsetTop=70`, `runningTransform=0`, `opacity=1`. Hover ölçümü: mouseenter → transform transition `running=1` (akıcılık korundu). **BUG2 gerçek hesapta uydurma engagement (COMPLIANCE).** Teşhis: @ai.neeidy (gerçek, connected, followers_count=7) kartı `9.5K/298/1.9K` **crc32 uydurma** engagement gösteriyordu ("sample" çipli olsa bile gerçek kanalda temsili sayı = yanlış beyan). FIX: tek sinyal `$providerBacked` (`followers_count !== null` = sync/chore bu hesabı canlı sağlayıcıdan okudu) TÜM kartı yönetiyor — gerçek hesap: engagement snapshot'tan gerçek değer, raporlanmayan `—` + nötr "veri yok" rozeti (stand-in HİÇ hesaplanmıyor); demo/seed hesap: deterministik stand-in + `[örnek]` çipi KORUNDU (ekranlar dolu kalır). `AccountRepository::listFor` en yeni `account_metrics` snapshot'ını LEFT JOIN ediyor (ws-scoped subquery), `shape()` NULL'ı NULL bırakıyor; yeni `acct.no_metrics` (en+tr) + `.acc-card__sample--empty` nötr stil (dürüst boşluk, stand-in rozetinin sesini ödünç almaz). **CANLI KANIT:** @ai.neeidy `— — — [no data yet]` + `7 followers`; @smoke_tt `7K 406 509 [sample]` + `61.2K [sample] +69 today`. Demo verisi SİLİNMEDİ (accounts 2 satır, posts 5), `.env` flag flip YOK, engine/şema-çekirdeği/node-graph dokunulmadı. Görsel gate 69 PNG/0 console-error/0 overflow; route 12/12 200.

- 2026-08-23 — **FAZ 22: PANEL + GERÇEK VERİ — 6 görev tamam (873 PASS/0 FAIL, +34 test).** (1) **Analytics seam (K1):** `PublishProvider::accountMetrics()` (follower + per-post engagement BİRLİKTE — dar follower-only adapter yasaklıydı); gerçek `ZernioPublishProvider` impl GET /accounts (followersCount) + GET /analytics; **per-post alan adları canlıda BOŞ geldiği için UYDURULMADI** → defansif çok-anahtarlı map (views/viewCount/impressions…) + `raw_json`'da ham payload saklama (integrations "never hallucinate" kuralına dürüst yanıt); deterministik Mock impl. (2) **Snapshot chore:** yeni `src/Analytics/DailySnapshot.php` (worker sessionless → ws açıkça iterate, her write'ta workspace_id), migration **0014** `account_metrics` (UNIQUE ws+account+gün → INSERT OR IGNORE) + `accounts.followers_count/followers_synced_at`; **zero-cost** (usage/credit YAZMAZ); worker start + 300s chore'a bağlandı. (3) **Follower wiring:** `setFollowers()` + `sync()` tek turda ref reconcile + gerçek follower; raporlanmayan follower stored değeri EZMEZ. (4) **Dedup (K2):** `connect()` blind INSERT → revive-existing (case/@-insensitive); migration **0015** re-point posts → dup sil → UNIQUE index; dev DB'ye WAL-safe yedekle uygulandı (`kuyash.pre-p22-dedup.20260823T130328Z.bak.sqlite`) → **id2 silindi, 5 post hâlâ id3'te, 0 FK ihlali, id1 mock demo + etiketli demo verisi KORUNDU**. (5) **UI:** pill `--spring`(overshoot 1.56)→`--ease-out` = "geri sekme" bitti. (6) **Jargon:** `Messages::since()` → /trends "fresh · 3 min ago" (ham ISO yalnız title'da); 11 ekranda görünür ham ISO = 0. **CANLI KANIT:** account_metrics id1 `followers=7 GERÇEK`, `post_count=0 + views NULL` (dürüst boş), 0 usage satırı; dashboard `@ai.neeidy · 7 followers` (çipsiz) vs `@smoke_tt · 61.2K [örnek]`. **Kendi yakaladığım regresyon:** sample çipi `.acc-card__who` ellipsis'i içinde YUTULUYORDU (görsel gate PASS demişti) → çip dışarı alındı + `.acc-card__sample--foot` + regresyon testi. **K3:** phase-plan.md → Faz 22 + Faz 23 eklendi (14–21 KORUNDU; token `START PHASE 14` idi ama o numara i18n'e ait → kullanıcı onayıyla 22). 16 dosya + 3 yeni; secret yok.

- 2026-08-22 — **SALT-OKUMA SAĞLIK KONTROLÜ + İNCELEME + 2-FAZLIK PLAN (ONAYLI, kod YOK, FAZ TOKEN'I BEKLİYOR).** Sistem sağlıklı ayağa kalktı: 839 PASS/0 FAIL, 12 route 200 (0×500), migration güncel (0013 doğrulandı), worker healthy-idle (PID 14205), dev server 8082 (PID 13685); tek mutasyon = 2 process başlatma (repo/DB dokunulmadı, git temiz). **CANLI Zernio read-only probe (yayın/para YOK):** GET /accounts gerçek `followersCount=7` + `hasAnalyticsAccess=true`; GET /analytics HTTP 200 doğru şekilli (overview/posts/pagination) AMA per-post BOŞ (`posts:[]`, `total:0`, `externalPostCount:0`) → per-post metrik Zernio sync populate edene dek yok, follower bugün gerçek. GET /posts/{id} zengin metadata ama metrik alanı YOK. **Bulgular:** (1) accounts dedup BUG — `connect()` körlemesine INSERT, `(ws,platform,handle)` UNIQUE yok → id2 stale-disconnected dup (@ai.neeidy ×2, 06-13→id2 + 06-14→id3, sync ikisinin ref'ini çekti). (2) UI BUG — kayan-pill `transform … var(--spring)` (cubic-bezier 0.34,**1.56**,…) overshoot = "sekmelerde geri sekme"; fix app.css:873 --spring→--ease-out. (3) posts 3/4/5 GERÇEK IG reel (24-hex ext_id + instagram.com/reel URL; post5 bugün). Dangling/orphan YOK, demo verisi dürüstçe etiketli (crc32 account-card = salt sunum, "örnek" çipi). **Plan onaylandı** (`~/.claude/plans/daha-detayl...hopcroft.md`): Faz1 (analytics adapter+snapshot / follower wiring / dedup+temizlik / UI fix / demo seed / jargon) + Faz2 (haftalık slot scheduling). Kullanıcı kararları: dedup=fix+id2 temizle, Faz2=tam slot, metrik=gerçek follower+etiketli örnek engagement. **phase-discipline: `START PHASE N` token'ı gelene dek kod YAZILMAYACAK.** Bug: gerçek publish `400 Invalid accountId format [invalid_field_value]`. POST /posts `platforms[].accountId = accounts.external_ref`, ama connect bunu UYDURUYORDU (`AccountsController.connectCallback`: `zacct_`+random) — gerçek Zernio SocialAccount `_id`'si (24-hex, GET /accounts) yerine. openapi: accountId="The Zernio SocialAccount ID"; canlı `_id=6a2f250a5f7d1751abb4803a`. FIX (adapter/controller/data; engine/şema/node-graph DEĞİŞMEDİ): `accounts()` PublishProvider arayüzüne taşındı (Mock + test Spy impl); `AccountRepository::setExternalRef()`; `connectCallback` artık gerçek _id'yi `accounts()` ile çözüyor (platform+@/case-insensitive username; eşleşmezse fallback); yeni `AccountsController::sync()` + `POST /accounts/sync` + /accounts "Hesapları eşitle" butonu tüm hesapların external_ref'ini canlı _id'ye reconcile ediyor. UI vendor-neutral (jargon-gate gereği "Zernio" kelimesi UI metninden çıkarıldı). **839 PASS/0 FAIL (+7):** payload accountId=external_ref verbatim, 400 invalid_field_value→REJECTED, sync reconcile (match/no-match/normalize), setExternalRef, connectCallback gerçek _id, mock accounts() 24-hex. secret yok; 10 dosya. **CANLI UÇTAN UCA KANIT (yayın YOK):** gerçek provider GET /accounts `@ai.neeidy _id=6a2f250a5f7d1751abb4803a` → ws#2 #3 external_ref reconcile (WAL-safe yedek) → gerçek `postPayload` `platforms[0].accountId`=o _id (MATCH, 24-hex). ws#2 #3 data-fix UYGULANDI → panelden gerçek publish retry hazır.

- 2026-06-15 — **ASSEMBLY R2-STAGING FIX (commit `62c76fe`, push'lu) + ws#2 ölü-asset temizliği (salt veri) + GERÇEK YAYIN AÇILDI.** Bug: STORAGE_DRIVER=r2'de ffmpeg girdisi (R2'ye taşınmış/evicted) yerelde yok → "No such file (exit 254)" tüm run'ları blokluyordu. FIX (asset-resolution katmanı; engine/şema/node-graph DEĞİŞMEDİ): `AssemblyEngine::localInput()` visual+audio için yerel-yoksa default durable disk'ten (R2) canonical'a stage, hiçbirinde yoksa dürüst `FfmpegException` (kriptik çökme yerine); `AssetCache::remember()` HIT artık yerel dosyayı doğruluyor → R2'den restore / kurtarılamazsa yerinde re-produce (opsiyonel `StorageManager`, nullable). **832 PASS/0 FAIL (+7)**: cache restore/re-produce/regresyon + R2-sim assembly E2E; secret yok. **Canlı retry #13/#18:** stale-kod worker (PID 3027) önce eski kodla tüketti → durdurdum, taze worker fix'li → hata ham ffmpeg'den "assembly input ... unrecoverable"a döndü → **ASIL neden VERİ KAYBI**: referans asset #3 "Smoke clip" `storage_disk=local` + yerel YOK + R2'de YOK (baytlar kayıp, canlı probe). **Temizlik (WAL-safe yedek `kuyash.pre-deadasset-cleanup.*`):** #13/#18 dead-lettered (terminal); ws#2 `avatar_asset_id`(=3)→NULL; asset #3 hard-delete → ws#2 0 ready asset. **#4 CANLI KANIT:** gerçek `AssetFetchExecutor` ws#2 faceless+face → `source=stock provider=pexels`, 5.3MB klip İNDİ (eski 'face'→ölü-avatar yolu elendi). **AÇIK:** worker PID 34294 (13:56 başladı, fix 13:59 commit) fix'i yüklememiş olabilir → kullanıcı `php bin/worker.php` RESTART etmeli; yoksa yeni R2-migration run'ları hâlâ patlar.

- 2026-06-15 — **GENERATION STACK GERÇEĞE AÇILDI + TTS streaming-WAV BUG FIX (commit `6b0c56f`, push'lu).** `.env`: `OPENAI_MOCK=false` + `TTS_MOCK=false` + `STOCK_MOCK=false` (STORAGE_DRIVER=r2; ZERNIO_MOCK=true + VIDEO_MOCK=true KALDI). Her sağlayıcı küçük canlı çağrıyla doğrulandı: OpenAI text 200+usage (`OpenAiTextProvider`, gpt-4o-mini, gerçek fikir; tek minik çağrı sub-cent→cost 0); Pexels 720×1280 dikey klip indirdi+ffmpeg (`PexelsStockProvider`); R2 **6/6 PASS** bucket PRIVATE. **TTS bug bulundu+fix:** OpenAI WAV'ı *streaming* döndürüyor (data chunk size = `0xFFFFFFFF` placeholder, header hexdump kanıtı) → `WavWriter::durationOf` **89478s** ölçtü (gerçek ffprobe 2.35s). FIX: sentinel ise gerçek payload = `fstat(filesize)−payload_offset`; normal/trailing-chunk WAV DOKUNULMADI (declared size). +3 test → **825 PASS/0 FAIL** (regresyon yok). Gerçek TTS yeniden doğrulandı: adapter **4.45s = ffprobe 4.45s** → `TTS_MOCK=false` KALDI. İlk turda TTS bug yüzünden geçici mock'a alınmıştı; fix sonrası gerçek. `.env` yedeği `.env.bak.pre-gen-20260615T012753Z`. **DİKKAT: generation artık GERÇEK PARA harcar; bütçe cap'leri PreflightGate ile etkin.** Pipeline ÇALIŞTIRILMADI (kullanıcı panelden yapacak). Değişen kod sadece `src/Media/WavWriter.php`+`tests/run.php`; secret yok.

- 2026-06-14 — **PHASE 10: ZERNIO GERÇEK PUBLISH ADAPTER + per-platform AI-disclosure — KABUL + COMMIT `6891f8b` + PUSH (ZERNIO_MOCK=true KALDI, gerçek yayın YOK).** Ham `openapi.yaml` (1.4MB) curl+parse ile şema BİREBİR (uydurma yok). Gerçek `ZernioPublishProvider`: presign+PUT upload, POST /posts, status, salt-okunur accounts(), 429 backoff, {error,code,reason} → PublishOutcome. **AI-LABEL:** YouTube `containsSyntheticMedia` + TikTok `videoMadeWithAi` native bayrak VAR, IG YOK → **hibrit+per-platform toggle**: Ayarlar→AI ifşası (migration **0013**, 3 boolean default 1), IG caption "Made with AI"/"AI ile üretildi" (owner locale), kapatınca `compliance.ai_disclosure_suppressed` truthful audit. Webhook event-id `payload.id`/`X-Zernio-Event-Id`. **CANLI salt-okunur GET /accounts → IG `@ai.neeidy` (kanıt; yayın yok).** Docs: zernio-notes + spec + **ADR-021**. **822 PASS/0 FAIL**; secret-scan temiz. **4 GATE GO:** qa, security (0 HIGH-MED; header-format bug'ı canlı 401 yakaladı+düzeltildi), compliance (truthful effective-flag + audit), integration (ilk NO-GO 4 uydurma alan platformResults/contentType:reels/per-platform error → B1-B4 `post.platforms[]`/shareToFeed/errorCategory → yeniden GO). 17 dosya commit (feat/publish) + checkpoint (chore/state). **+ DEV-DB FIX:** /settings 500 (no such column ai_disclose_instagram) = 0013 canlı dev DB'ye uygulanmamıştı (KOD/migration uyumsuzluğu DEĞİL) → WAL-safe yedek `kuyash.pre-0013.bak.sqlite` + `bin/migrate.php` (dev DB migration=13, 3 ws default ON) → /settings 200 + 3 toggle, 10 nav ekranı 200/0-SQL-hata.

- 2026-06-14 — **GO-LIVE PREP + R2 GATE + DASHBOARD BÜTÇE GERÇEĞE BAĞLANDI (hepsi main'e push'lu).** (a) Go-live planı sunuldu (servis-bazı credential/.env + Zernio 12-madde doc-gate [0/12 elde] + IG Business ön-koşulu); MOCK smoke izole DB'de uçtan uca **PASS** (trend→onay→pipeline→48 event→completed). (b) **R2 gate düzeltildi** (`bin/r2-smoke.php` `35bb7ac`): imzasız GET'te HTTP 400'ü gövde-temelli redde çevirdi (sızıntı=obje baytları döndü mü; PRIVATE=400/401/403 VE gövde gizli) → canlı bucket **6/6 PASS** (gövde R2 hata-XML'i, sızıntı yok). `.env`'de `STORAGE_DRIVER=r2` (kullanıcı bıraktı). (c) **Dashboard BYO-key bütçe** (`8716803`): "remaining balance"→**"remaining budget"** (cap−MTD spend / "no monthly limit"); `Cockpit::business` budget_cap+remaining + cost-per-content gerçek usage & 0-harcamada "no data"; sahte dev-DB finansı silindi (ws#2: $50 grant+$1.50 adjust+$0.95 usage → temiz $0; WAL-safe yedek `kuyash.pre-finance-reset.*.bak`; ws#1/#3 dokunulmadı). Bütçe enforcement zaten wire'lı (PreflightGate) — kanıtlandı: **$1 cap, $7.02 quick_create AI-video run → BudgetExceededException; $0.10 stok run → başlar.** **801 PASS/0 FAIL** (+5 test). origin/main `8716803`.

- 2026-06-14 — **FAZ 21 — 3. TUR REDDİ → 4 MADDELİK HEDEFLİ DÜZELTME BİTTİ (UNCOMMITTED; COMMIT/PUSH/MERGE YOK).** Kullanıcı F21'i hâlâ kabul etmedi, SADECE 4 iş istedi (başka ekran/refactor YOK; engine/queue/worker/migration DOKUNULMADI). (1) Workspace adı düzenlenebilir: `WorkspaceSettings::setName`+`SettingsController::saveName`+`POST /settings/name` ($protected/CSRF/tenant-scoped/≤60, ADDITIVE `workspaces.name`, migration yok) + /settings kartı + topbar `.mode-chip__name` TEAL GRADIENT (DB adı okur). (2) Metin rampası GÖRÜNÜR teal: `--text #d7ece5/--text-2 #8fbeb3/--text-3 #84b2a9` (G−R +21/+47/+46 ≫ eski +6; AA ≥7.06; luminance düşmedi). (3) Live dot `@keyframes live-beat` nabız+glow (accent+--glow, reduced-motion sabit). (4) Pipeline drawer GERÇEK per-aşama çıktı: `Cockpit::pipeline` node'lara `results` (read-only SELECT) + pipeline.php 12 node tipini render (hepsi `View::e` escape, wait→"başlamadı"/veri-yok→"çıktı yok"); visual-seed Run A TREND/IDEA/SCRIPT gerçekçi result_json (DEV-only). ~50 lang anahtarı (en+tr parite). **796 PASS/0 FAIL (+20 test); visual 69 PNG/0 err/0 overflow/exit 0; canlı-app curl: rename persist+CSRF 403+tenant-izole, dashboard'da gerçek node çıktısı; topbar yakın-çekim teal teyit.** 4 GATE GO/0 blocker (qa/security 0 HIGH-MED+2 LOW/ux piksel-teal/compliance truthful). 13 dosya working tree'de; İNSAN KAPISI bekliyor.
