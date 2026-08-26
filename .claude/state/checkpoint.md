# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-08-26
- Güncelleyen: Claude (**AÇIK İŞ DENETİMİ — `726f3ed` push'lu, 1104 PASS/0 FAIL,
  99 PNG temiz. Takvim `<select>` caret örtüşmesi KAPANDI (pixel kanıtlı).
  İki KARAR kullanıcıda: /workflows çerçevesi + "seni bekleyen"in TEK tanımı —
  panelde aynı anda 7 / 4 / 4 yazıyor. Vitrin hâlâ ws2'de KURULU,
  ön koşullar hâlâ GEÇİCİ.**)
- Önceki: Claude (**CASE-STUDY VİTRİNİ TAMAM — `2f1537f`'ye kadar push'lu,
  1104 PASS / 0 FAIL, working tree temiz.** Poster'lar GERÇEK stok karesi,
  hesap kartlarında mock görüntü, görsel gate artık boyanmamış görseli
  YAKALIYOR. Vitrin seed **ws2'ye kurulu**.
  **ÖN KOŞULLAR GEÇİCİ — geri alma SIRASI önemli, aşağıya bak.**)

## Mevcut durum (kaldığımız yer)
- **VİTRİN ws2'YE KURULU** (134 manifest kaydı). Geri alma: `php bin/demo-teardown.php --yes`.
- **⚠ GEÇİCİ AYARLAR — SIRAYLA GERİ AL:** yakalama bitince **ÖNCE**
  `php bin/demo-teardown.php --yes`, **SONRA** `.env`'de `ZERNIO_MOCK=false`
  (yedek: `.env.bak.pre-casestudy.20260826T144421Z`) ve ws2 `approval_mode`
  → `auto`. Ters sırada kuyrukta 5 GERÇEK onay kapısı canlı yayın yoluna bakar
  kalır (insan onaylı run günlük cap'i de kill switch'i de ATLAR).
- **Demo medya artık GERÇEK stok** (`StockMediaFactory`, Pexels, öğe başına kendi
  arama terimi). Sentetik gradient'ti; doğru çıkarılan kare de gradient oluyordu,
  yani poster işi ekranda görünmüyordu. Sessiz fallback YOK — sağlayıcı veremezse
  seed hangi öğeyi kuramadığını adıyla söyler.
- **Poster mimarisi (ADR-025):** `assets.sha256`'dan içerik-adresli dosya,
  `cache` store'da, **migration/kolon YOK**. Üretim: ingest +
  `bin/backfill-posters.php` + seed; **sayfa sunan istekte ASLA**. Kendi
  `Ffmpeg`'i 15s ile (`POSTER_TIMEOUT`). Poster `<video poster="">` üzerinde —
  ayrı `<img>` mutlak konumlu video'nun ALTINDA kalıp siyah boyanıyordu.
- **ADR-026:** "Approved by you" yalnız karar verene; kayıt karar veren hesabın
  ADINI da basar. Demo onayları `sample.operator@kuyash.invalid` adına.
- **Hesap kartı:** örnek kartlar mock kare gösterir; **provider-backed kart
  GÖSTERMEZ** (gerçek kanala uydurma kare = o kanal hakkında iddia).
- **Görsel gate artık kör değil:** lazy görselleri zorla yükleyip
  `naturalWidth`'e bakar, capture'dan ÖNCE. Fixture'lar gerçek stok
  (`tools/visual/fixtures/stock/01..10.mp4`). `DEMO_MEDIA=fixture` ile deterministik.
- **Yeni script'ler:** `bin/demo-seed.php`, `bin/demo-teardown.php`,
  `bin/backfill-posters.php`, `bin/refresh-legacy-demo-media.php` (sonuncusu
  manifest'in sahip OLMADIĞI eski `[SAMPLE]` asset'lerin baytlarını yeniler —
  seed'den ayrı, çünkü mutasyon yapar; süreyi ASLA değiştirmez).
- **VİTRİN SEED ws2'YE KURULU** (134 manifest kaydı: 10 poster'lı klip, 2 mock
  kanal, 8 run, 4 saat + 8 takvim hücresi, 6 masraf, 1 demo kullanıcı).
  Geri alma: `php bin/demo-teardown.php --yes`.
- **ÖN KOŞULLAR SAĞLANDI, FERAGAT EDİLMEDİ — ve GEÇİCİ:** ws2 `approval_mode`
  `auto`→`manual` (SQL), `.env` `ZERNIO_MOCK` `false`→`true` (yedek:
  `.env.bak.pre-casestudy.20260826T144421Z`), worker yeniden başlatıldı.
  Sağlayıcı `mock` olarak çözülüyor (teyitli). **SIRA ÖNEMLİ:** yakalama bitince
  ÖNCE teardown, SONRA flag'ler geri. Ters sırada kuyrukta 5 GERÇEK onay kapısı
  canlı yayın yoluna bakar kalır (insan onaylı run günlük cap'i de kill
  switch'i de ATLAR).
- **Poster mimarisi (ADR-025):** `assets.sha256`'dan türetilen içerik-adresli
  dosya, `cache` store'da — **migration YOK, kolon YOK**. Çıkarım ingest'te,
  `bin/backfill-posters.php`'de ve seed'de; **sayfa sunan istekte ASLA**.
  Kendi `Ffmpeg`'i **15s** timeout ile (paylaşılan 900s assembly watchdog'u
  yükleme isteğinde bir DoS bütçesiydi). R2 nesnesi work-dir'e stage edilip
  temizleniyor (kanonik yola değil — yoksa backfill tüm kütüphaneyi yerele
  geri indirirdi).
- **ADR-026 — "Approved by you" yalnız KARAR VERENE söyleniyor.** Etiket sabit
  kodluydu; "you" deiktik olduğu için okuyan HERKESE "sen onayladın" diyordu,
  yanındaki e-posta ise başkasını gösteriyordu. Aynı workspace'teki iki gerçek
  operatör de bunu yaşardı; demo hesabı sadece yolu ilk tetikleyen veriydi.
  Kayıt artık karar veren hesabın ADINI da basıyor (işaret o ekrana böyle
  ulaşıyor).

- **VİTRİN SEED HAZIR, KURULU DEĞİL.** `php bin/demo-seed.php --yes` 10 klip
  (ölçülmüş süre), 2 mock kanal (asla `connected`), 8 run (3 bitmiş+yayınlanmış,
  5 onay bekleyen), 4 yayın saati + 8 takvim hücresi, 6 masraf yazar — hepsi
  `demo_seed_manifest`'te. `php bin/demo-teardown.php --yes` **tam olarak o
  satırları** siler (önce `--dry-run`, sonra WAL-safe yedek). Canlı kanıt: seed
  → teardown sonrası ws2'nin HER sayımı seed öncesi değerine döndü, 0 FK ihlali.
- **SEED İKİ ÖN KOŞULLA REDDEDER** (uyarı değil, `exit(1)`):
  `approval_mode=auto` ise `--auto-mode-ok` ister — demo run'lar QualityScore'un
  son-20 ve SlopScorer'ın son-10 penceresine KANIT olarak giriyor ve operatörün
  gerçek geçmişini pencereden atıyor (slop maksimum alır → gerçek bir
  benzerin skoru TEMİZLENİR). `ZERNIO_MOCK=false` ise `--live-publish-ok` ister —
  kuyruktaki 5 kart GERÇEK onay kapısı, insan onaylı run günlük cap'i de kill
  switch'i de atlar.
- **ws2 İÇİN OPERATÖR KARARI:** ws2 hem `auto` hem `ZERNIO_MOCK=false`. İki
  seçenek: (a) yakalama için /settings'ten Manuel'e al + `ZERNIO_MOCK=true`,
  sonra tek komut; (b) iki bayrakla riski kabul et. Ben kurmadım.
- **SEED'İN UYDURMADIKLARI (gate'lerin öğrettiği):** onay kaydı YOK (ne `auto`
  ne `manual` — run sayfası "Approved by you · <gerçek e-posta>" basıyor, digest
  ise yalnız id+zaman; işaret o yüzeylere ULAŞAMAZ, o hâlde doldurulmaz),
  kredi defteri satırı YOK (bakiye bir toplam, işaret taşıyamaz), `events` YOK
  (append-only, geri alınamaz). Süreler MediaProbe, slop skorları SlopScorer ile
  ÖLÇÜLÜR.
- **Aşama: Faz 25 KAPALI. Kapanış turu da bitti. Working tree temiz, origin/main senkron.**
  **1068 PASS / 0 FAIL** · görsel gate 93 PNG / 0 console error / 0 taşma ·
  14 route **gövde-temiz** 200 · lang paritesi 828=828 · secret taraması temiz.
- **Dev DB artık 0017.** Faz 24 oturum logu "0017" diyordu ama gerçek DB 0016'daydı —
  `slot_occurrences` yoktu, yani haftalık takvim o veritabanında çalışmıyordu ve
  /dashboard SLOT'U OLAN workspace'te 500 veriyordu. Yedek:
  `storage/database/kuyash.pre-0017-apply.20260825T222409Z.bak.sqlite`.
- **ADR-024 — yan kart düşebilir, panel düşemez.** `Cockpit::snapshot()` plan satırı ve
  hesap kartı okumalarını koruyor; her biri KENDİ üçüncü durumunu döndürüyor:
  plan `['unavailable'=>true]` (null "planı yok" demek, sıfır ise ölçülmemiş sayı),
  hesaplar `null` (boş liste "hiç hesabın yok" demek). Kalan okumalar (kpis, activeRuns,
  awaiting, nextPublish, business) PANELİN KENDİSİ — onlar sesli patlamaya devam etmeli.
- **Kapanış gate'lerinin bulduğu ve kapatılanlar:** (1) `demo-seed` 3 saniyelik bir dosyaya
  **22.0s uydurmuştu** — o sayı caption değil, compliance'ın 15-45sn bandını ONA göre
  kontrol ettiği değer; artık ffmpeg ile klip ÜRETİLİYOR ve `MediaProbe` ile ÖLÇÜLÜYOR.
  (2) `health.php` argv'deki HERHANGİ bir host'a şifre POST edebiliyordu → sadece
  http/https, loopback dışında https zorunlu. (3) `/accounts` her workspace'e
  "onaylananlar hemen yayınlanır" diyordu — planı OLANA da; üçüncü durum eklendi.
  (4) `demo-seed` "idempotent" değildi: worker duruksa her çağrı yeni run başlatıyordu
  (gerçek üretim sağlayıcılarına para harcayarak). (5) Hata durumları boş durumlardan
  görsel olarak ayrıldı. (6) **Compliance'ın iptal ettiği bir run'ın günü ASLA
  temizlenemiyordu** (`cancelRun` "already decided" → "çok geç") — o tarih kalıcı
  kayıptı; artık bitmiş run'ın günü boşaltılabiliyor, uçuştaki yayın hâlâ reddediliyor.
- **ÜÇ GATE DE GO** (security, ux, compliance) — hepsi ikinci turda. Compliance ilk turda
  NO-GO'ydu ve haklıydı: "bitmiş run'ın günü boşaltılabilir" kuralım `completed`'ı da
  kapsıyordu, ki o **YAYINLANMIŞ** bir run olabilir — occurrence yayınlanınca `assigned`
  kalır (takvim "yayınlandı"yı run'dan TÜRETİR, kopyalamaz), yani o günü boşaltmak
  operatörün o tarihte bir paylaşım çıktığını gördüğü TEK yeri siliyordu. Post satırı,
  run ve denetim kaydı duruyordu; yalan söyleyen tek şey takvimdi. Artık controller
  gerçekten çıkmış bir şey varsa reddediyor (`PostRepository::runHasPublished`).
  **İkinci yarısı daha sinsiydi:** düzeltme EKRANA ULAŞMIYORDU — şablon, tam da yazıldığı
  `STOPPED` durumu için butonu gizliyordu, yani commit mesajımdaki iddia controller için
  doğru, ÜRÜN için yanlıştı. Bu turun kapatmaya çalıştığı abartı türünün ta kendisi, ve
  benimdi. Şablon artık karar vermiyor; butonu sunuyor, controller hükmediyor.
- **Smoke artıklarım temizlendi:** 31 Ağustos'a sabitlenmiş GERÇEK yayın iptal edildi;
  mock (`zp_`) 3 post silindi (gerçek günlük cap'i tüketiyorlardı → bugün 0).
- **Canlı smoke (yayın MOCK):** compliance 3sn klibi 15-45sn bandına karşı bloklardı
  (dürüst gerekçe + 2 denetim satırı); slop 0.6452 → warn → otomatik onay REDDETTİ,
  insana bıraktı; onay kayıtları `auto/decided_by=NULL/policy` ve `manual/decided_by=2/policy=NULL`;
  düzenlenen caption yayınlandı (`captions_ai` korunmuş); 1 kuruşluk bütçe cap'i run
  satırı bile yaratmadı. **Ürün defect'i bulunmadı.**

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

**KARAR BEKLEYEN İKİ ÜRÜN SORUSU (denetimde kanıtlandı, 2026-08-26):**

- **K1 — "seni bekleyen" için TEK tanım.** `dashboard__1280__en.png` tek karede
  üç farklı sayı gösteriyor: KPI **7** (run), onay kartı rozeti **4**
  (`Cockpit.php:76` `array_slice(...,0,4)` — rozet dilimi sayıyor,
  `dashboard.php:151`), plan bandı **4** (haftanın hücreleri); ACTIVE RUNS
  listesi aynı sayfada **7** "awaiting" satırı basıyor. Rozet düpedüz eksik
  söylüyor ve "N tane daha" yok. Ama düzeltmek tanım seçmeyi gerektiriyor
  (job mu run mu) — KPI'ı değiştirmek `business()` üzerinden bütçe/maliyet
  yüzeylerine dokunur, o yüzden tek başıma seçmedim.
- **K2 — /workflows.** `workflows__1280__en.png`: iki küçük kart + ~570px boşluk,
  CTA yok, node graph yok, selection state yok, sağ ayar paneli yok.
  `frontend.md` node graph için ikisini de şart koşuyor. Case study "görsel
  workflow builder" diyecekse bu ekran onu yalanlıyor: ya panel yapılacak ya
  sayfa yeniden çerçevelenecek.

0. **Case study'yi yakala.** Ekranlar dolu, poster'lar gerçek kare.
   Yakalama sırasında **bir demo run'ı gerçekten onayla** — teardown onu korur
   (event pinler), böylece gerçek bir onay kaydı da fotoğraflanır.
1. **Bitince SIRAYLA:** teardown → sonra `ZERNIO_MOCK=false` + ws2 `auto`.
2. **Karar bekleyen tek ürün sorusu: /workflows.** `frontend.md` node graph için
   "selection state + sağ ayar paneli" diyor; hiçbir ekran görüntüsünde yok.
   Case study "görsel builder" iddia edecekse önce bu çözülmeli.
3. Ertelenenler: `.claude/docs/demo-showcase-followups.md`. Öne çıkanlar:
   onay önizlemesi 9:16 klibin ~%31'ini gösteriyor (16:9 kutu, Approve butonunun
   üstünde); `/library/upload`'da rate limit yok (çok kiracılı UI'dan ÖNCE
   zorunlu); "seni bekleyen" için hâlâ 3 farklı sayı.

## Açık konular / bekleyenler

- **ÇÖZÜLDÜ — dev DB 0017 UYGULANDI** (2026-08-25, kullanıcı talimatıyla).
  Yedek: `storage/database/kuyash.pre-0017-apply.20260825T222409Z.bak.sqlite`
  (WAL checkpoint TRUNCATE sonrası kopyalandı, `integrity_check ok`).
  `php bin/migrate.php` → yalnız bekleyen `0017_plan_occurrences.sql`. Sonrası:
  `slot_occurrences` + 3 index var, `publish_slots.mode`/`workspaces.auto_lead_minutes`/
  `plan_paused` default'larıyla geldi, `integrity_check ok`, **0 FK ihlali**,
  veri korundu (22 run · 205 job · 5 post · 2 hesap · 2 slot).
  Worker temiz: "plan: 4 slot(s) added", `no such table` YOK. 14 route 200 + gövdede
  0 exception izi. **Neden fark edilmemişti:** Faz 24 `PlanRunner::tick()` hatasını
  bilerek yutuyor (yayın durmasın diye), ve /dashboard yalnız SLOT'U OLAN workspace'te
  patlıyordu — ws2'de 2 slot var, ws1/ws3'te 0.
  **Kalan (ürün değil veri):** ws2'de hazır kütüphane videosu YOK (Faz 8 ölü-asset
  temizliğinden kalma), o yüzden /plan takvimi dürüst boş-durum gösteriyor ve gerçek
  bir atama CANLI DB'de denenemedi. Kod yolu testte kanıtlı
  (`p24/ui: putting a video on a day starts the work and pins it to that time`).
  Bir video yüklenince akış uçtan uca denenebilir.

- **BUG FIX (aynı gün, ayrı commit):** /dashboard, SLOT'U OLAN workspace'te 500 veriyordu.
  Kök-neden yukarıdaki eksik migration'dı (giderildi), ama ASIL kod kusuru ayrı:
  `Cockpit::snapshot()` plan özetini KORUMASIZ okuyordu — tek satırlık bir bant,
  patlayınca KPI'ları/onayları/hesapları da birlikte götürüyordu. Worker aynı okumayı
  `PlanRunner::tick()` içinde bilerek koruyor; panelde karşılığı yoktu. Artık try/catch +
  `error_log` → **üçüncü durum** `['unavailable' => true]`.
  **Neden null DEĞİL:** null = "bu workspace'in planı yok" ve panel bunu
  "onaylanan videolar hemen yayınlanır" diye yazıyor — planı OLAN bir workspace'e
  bunu söylemek yalan olurdu. Sıfır da değil: okunamayan sayı 0 değil, EKSİK.
  Ekranda: "Bu haftanın planı şu an okunamadı — sayı sıfır değil, eksik."
  **Yeni: `bin/health.php`** — status + GÖVDE taraması, login'li, hangi workspace'e
  düştüğünü söyler (workspace switch route'u yok → bir koşu TEK tenant kanıtlar).
  Kimlik bilgisi env'den (`HEALTH_EMAIL`/`HEALTH_PASSWORD`), dosyada varsayılan YOK.
  **Ertelenen (gerekçeli):** panelin hesap kartı da aynı şekilde sayfayı düşürebilir —
  `phase-25-followups.md`'de, neden bu commit'te yapılmadığıyla birlikte.

- `.env` lokal dev/debug=true; `.env.example` prod/false (bilinçli ayrım).
- Port 8080'de eski bir `php -S` süreci dinliyor — dev için 8082 kullan.
- Followups: phase-5-followups.md (Faz 6/9/11 tetikleyicileri: 401 non-retryable, semantik
  prompt-injection [gerçek trend], OpenAI quota counter, variation skorlama rendered output'ta,
  Claude 2. sağlayıcı, Studio UI, awaiting_recording/shooting-brief). phase-1..4 followup'ları:
  finalize-throw fallback, EventLog clock, autoload extraction hâlâ açık (düşük öncelik).

## Oturum logu (en yeni üstte, en fazla 10 satır)


- 2026-08-26 — **AÇIK İŞ DENETİMİ + 1 KAPANIŞ — `726f3ed` push'lu, 1104 PASS/0 FAIL, 99 PNG (0 console error / 0 taşma / 0 kırık görsel).** Doc okumak yerine gerçek render'dan denetim: izole görsel gate (`VISUAL_DEMO=1`) ile /dashboard, /plan, /workflows yakalandı. **Kapanan:** takvim video seçicisinin caret'i son harfin ÜSTÜNE biniyordu (`.cell__assign select` `padding-inline: 4px`; native caret kontrolün sağ iç kenarına boyanır) → `4px 15px`. Kanıt aynı koordinatlardan önce/sonra pixel crop; 1280'de `Talking-head intr`+ok-üstünde-"o" → ayrık caret. `text-overflow: ellipsis` denendi, **GERİ ALINDI** (görünen etiketi ~9→~5 karaktere düşürüyordu). **Dürüst uyarı:** görsel gate bu kusur ekrandayken 99 PNG boyunca yeşildi — glyph örtüşmesini ölçen kontrol YOK; guard yazmadan önce Chrome'da `select`'in kırpılmayı `scrollWidth`'le bildirip bildirmediği doğrulanmalı, yoksa assert kör-yeşil olur. **Kapanmayan, KARARA bağlı:** (K1) panelde "seni bekleyen" için tek karede 7/4/4 — rozet `array_slice` dilimini sayıyor, "N tane daha" yok, ama tek tanım seçmek KPI'ı ve `business()` üzerinden bütçe yüzeylerini etkiliyor; (K2) /workflows 1280'de iki kart + ~570px boşluk, node graph/selection/ayar paneli hiçbiri yok. **Değişmeyen:** vitrin ws2'de KURULU (134 kayıt), `ZERNIO_MOCK=true`, ws2 `manual` — geri alma SIRASI hâlâ geçerli (önce teardown, sonra flag'ler).

- 2026-08-26 — **POSTER TURU: "görsel çalışmıyor" şikâyeti HAKLIYDI — `2f1537f`'ye kadar push'lu, 1104 PASS/0 FAIL.** Kullanıcı bağımsız olarak /library ve dashboard'un hâlâ düz gradient olduğunu bildirdi. **Kök-neden ikiliydi ve ikisi de benimdi:** (1) demo klipleri sentetik gradient test footage'ıydı → **doğru çıkarılmış kare de gradient**; önceki turda bunu yarım söyleyip hue kaydırmasıyla geçiştirmiştim (on aynı wash'ı birbirinden ayırdı, hiçbirini videoya benzetmedi). (2) Görsel gate aynı gradient fixture'ını kullandığı için **render edilen poster ile eksik poster'ı ayırt edemiyordu** — bozuk `<img>` console error da üretmediğinden beş tur yeşil geçti. **Düzeltmeler:** `StockMediaFactory` gerçek Pexels dikey klipleri indiriyor (öğe başına kendi arama terimi, sessiz fallback YOK); fixture'lar gerçek stok oldu (`stock/01..10.mp4`, kütüphane sırasıyla); harness lazy görselleri zorla yükleyip `naturalWidth`'e bakıyor. **UX gate ikinci bir körlük buldu:** ilk düzeltmem `i.complete && i.naturalWidth === 0` filtreliyordu — hiç yüklenmeye başlamamış lazy `<img>`'de `complete === false`, yani tam da önemli olan durum dışlanıyordu; 375px'te 8 poster lazy eşiğinin altında kalıp hiç boyanmıyordu ve gate boş döşemelere yeşil diyordu (sabotaj testim sadece 404 yolunu denemişti). Artık sadece zorlamayı kapatarak kırmızıya düştüğünü kanıtladım. **Ayrıca:** /plan hücrelerinde hiç poster yoktu → eklendi; "önizleme yok" döşemesi kesikli çerçeve oldu (gradient placeholder washy footage poster'ından ayırt edilemiyordu); hesap kartlarında örnek kartlara mock kare, **provider-backed karta ASLA**; `bin/refresh-legacy-demo-media.php` manifest'in sahip olmadığı eski `[SAMPLE]` asset baytlarını yeniliyor (#27'nin mor wash'ı) — **süreyi değiştirmiyor**, çünkü mevcut compliance kaydı ölçtüğü süreyi beyan ediyor (teyit: kayıt 22s, satır 22.0s). Fixture'a ilk kez provider-backed hesap eklendi: "gerçek kanala uydurma değer yazılmaz" kuralı kodda doğruydu ama 99 ekran görüntüsünün hiçbirinde gösterilmiyordu.


- 2026-08-26 — **CASE-STUDY CİLA + DOLDURMA (A→C) — `64a9286`'ya kadar push'lu, 1103 PASS/0 FAIL, vitrin ws2'ye KURULU.** (A) Poster mimarisi: içerik-adresli dosya (`sha256`), migration YOK; ingest+backfill+seed'de üretilir, sayfa sunan istekte ASLA; kendi `Ffmpeg`'i 15s ile. Live nokta artık **yalnız opacity** (box-shadow animasyonu her karede repaint'ti). `[SAMPLE]` kendi çipi → 768px takvim hücresinde artık hem çip hem başlık okunuyor (önce yalnız `[SAMPLE]…` kalıyordu). (B) Ön koşullar **sağlandı**: ws2 `manual`, `ZERNIO_MOCK=true`, worker restart. (C) **Üç gate, üçü de gerçek kusur buldu ve üçü de BENİMDİ.** **ux P0:** onay önizlemelerinin 5/6'sı **saf siyah** çıkıyordu — poster `<img>` ile `<video>` ikisi de `absolute inset:0`, video sonra geliyor, `preload=metadata` SİYAH boyuyor; şimdiye kadar doğru görünmesinin tek sebebi fixture'ın render dosyalarının 404 vermesiydi → poster artık `<video poster="">` üzerinde (422.065 siyah piksel → **0**). **security 2×HIGH:** `ensure()` "asla throw etmez" diyordu ama koruma metodun ortasını kapsıyordu ve çağrı `AssetIngest`'in **catch'inin İÇİNDEYDİ** — yazılamayan bir `storage/cache` yüklenen dosyayı SİLİP satırı bırakırdı, yani o catch'in önlemek için var olduğu yetim; ve thumbnail alma **900s assembly watchdog**'unu `POST /library/upload` içinde miras alıyordu (tek 200MB dosya bir worker'ı çeyrek saat tutar). **compliance HIGH:** "Approved by you" sabit kodluydu → okuyan herkese "sen onayladın" diyordu (ADR-026); demo onay kaydının kendisini truthful buldu, ekranı bulmadı. **2. turda gate iki iddiamı çürüttü:** commit mesajımda "TTL 86400→3600" yazıyordu ama edit **hiç uygulanmamıştı** (yanlış bloğa eşleşmiş), ve yeni `json_extract` alt sorgum **onay kuyruğunu sessizce kesebiliyordu** (SQLite malformed JSON'da raise eder, PDO `fetchAll()` önceki satırları throw ETMEDEN döner → bir bozuk satır kendisini ve sonrasındaki her işi gizler) → `json_valid()`. Ayrıca yükseklik tavanım 768'de **genişliği** daralttı (tarayıcı oranı korumak için width'i yeniden hesaplıyor) → `width:100%`. Ertelenenler followups'ta; en önemlisi onay önizlemesinin 9:16 klibin yalnız **%31.6**'sını göstermesi ve `/library/upload`'da rate limit olmaması.


- 2026-08-26 — **VİTRİN SEED (case study) — commit `ef638ae` push'lu, 1093 PASS/0 FAIL, HİÇBİR WORKSPACE'E KURULU DEĞİL.** Manifest'li (`0018`), tek komutla geri alınabilir demo seed + `bin/demo-teardown.php`. **Üç gate turu; ilk ikisi NO-GO ve ikisi de haklıydı.** (1) `postTarget()` sağlayıcı kökenine bakıyordu ama `status`'a BAKMIYORDU — `connectedFor()` tam da ona bakar, yani mock ama CONNECTED olan `@smoke_tt` kazandı ve bugün tarihli demo post gerçek günlük cap'i yedi; testim kaçırdı çünkü fixture'da "mock ama connected" satırı yoktu (artık var). (2) Digest'i doldurmak için bugüne tarihlediğim `auto` onay kaydı canlı auto-approval cap'ini 2/2'den 3/2'ye çıkardı — o sayaç tetiklenince ürün `guardrail.daily_cap_reached`'i UYDURMA sayıyla append-only log'a yazar; seed'in kendi yazmadığı satırı ürüne yazdırmak aynı ihlal. (3) O düzeltmede YANLIŞ boynuzu tuttum: `auto` yerine `manual` yazdım, yani politika damgalı AJAN kaydını **uydurma KİŞİ kaydına** çevirdim — run sayfası "Approved by you · <gerçek e-posta>" basıyor. Reviewer kendi C3 argümanımı bana geri çevirdi: yalnız karar+kimlik+zaman basan yüzey işaret taşıyamaz, o hâlde DOLDURULMAZ → **hiç onay kaydı yazılmıyor**. Testim baştan beri geçiyordu çünkü ŞEMA şeklini doğruluyordu, ki uydurma kayıt onu kusursuz sağlar; artık YOKLUĞU doğruluyor. (4) Kredi defteri satırları ws2'de gösterilen ömür boyu bakiyenin **%72'siydi** — bakiye bir toplam, işaret taşıyamaz → hiç yazılmıyor. (5) Slop skorları literal'di (gerçek ölçümden 0.06'ya kadar sapıyordu) → artık ürünün kendi `SlopScorer`'ı ölçüyor; test `history_runs` dizisini (0..7) doğruluyor, çünkü skor sonradan yeniden ölçülemez ama geçmiş BOYUTU o anın parmak izi. **Compliance NO-GO'su ws2 için haklıydı:** slop penceresi %80 demo olmuş, operatörün gerçek 19–25 run'ları pencereden atılmıştı (slop maksimum alır → gerçek bir benzer TEMİZ skorlanır), üstelik `ZERNIO_MOCK=false`. **Seti ws2'den tamamen kaldırdım**; her sayım seed öncesine döndü, 0 FK ihlali. Seed artık auto-mode'u ve canlı yayın yolunu **ÖN KOŞUL olarak** reddediyor (`--auto-mode-ok` / `--live-publish-ok`). **Kendi harness'imde iki kusur:** dashboard KPI'ı 7 yerine 6 fotoğraflıyordu — `motion.js` 1000ms sayıyor, `shot.mjs` 450ms'de yakalıyor, `toFixed(0)` aşağı yuvarlıyor (ux gate kök-nedeni buldu; artık `prefers-reduced-motion`); ve visual gate her sağlayıcıyı mock'larken `ZERNIO_MOCK`'u `.env`'den MİRAS ALIYORDU — yeni ön koşul yakaladı. Teardown: rowid yeniden kullanımına karşı kimlik kontrolü, ve audit log bir run'ı pinlediğinde gerçekten KISMİ (pinli run bütün kalır, gerisi çıkar). Ertelenenler: `.claude/docs/demo-showcase-followups.md`.

- 2026-08-26 — **KAPANIS TURU (A-E) — 1059 PASS/0 FAIL, 4 commit push'lu, urun defect'i YOK.** (A) Panelin **hesap karti** da plan satiri gibi korumasizdi -> ayni ucuncu-durum deseni: `null`, bos liste DEGIL (bos liste "hic hesabin yok" ifadesinin kaynagi; basarisiz okuma onu odunc alirsa canli kanali olan operatore hic kanali yokmus denir). Guard'i kaldirip testin gercek `PDOException`'i urettigini kanitladim. **Nerede DURDUGUMUZ da karar:** kpis/activeRuns/awaiting/nextPublish/business PANELIN KENDISI — `runs`/`jobs` okunamiyorsa durustce gosterilecek bir sey kalmaz, onlar sesli patlamali (ADR-024). (B) **Canli uctan uca smoke, yayin MOCK** (`ZERNIO_MOCK=true`, `PublishProvider::name()==='mock'` ile teyitli): 25 HTTP kontrolu pass; compliance 3sn klibi 15-45sn bandina karsi **blokladi** (durust gerekce + 2 denetim satiri); slop 0.6452 -> warn -> **otomatik onay reddetti**, insana birakti; onay kayitlari invaryanti tuttu (`auto/NULL/policy` ve `manual/2/NULL`); duzenlenen caption yayinlandi, `captions_ai` korundu; 1 kurusluk butce cap'i **run satiri bile yaratmadi**; yabanci workspace run'i 404, cross-tenant metin yazimi hicbir sey yazmadi. **Iki "hata" benim smoke'umun hatasiydi** (run 1'i baska workspace sandim — kendi workspace'imizdi; kill switch'e `state` gondermedim — o blind toggle degil), kayda gecirdim cunku bunlari gizleyen yesil matris daha az degerli. (C) B defect bulmadi -> **uydurma is yapmadim**. (D) `bin/demo-seed.php`: korumali (`--yes` + CLI), etiketli vitrin seed'i — gercek hesaba **asla** sayi yazmaz (`followers_count`'a dokunmaz -> kart "sample" isaretlemeye devam eder), job/post/approval **INSERT etmez** (run'lari BASLATIR, kart/verdict/onay kaydi gercek pipeline ciktisi olur). Ilk surumu her cagrida yeni run doguruyordu — iki kez calistirinca yakaladim: otomatik-onayli workspace'te kuyrugun bos olmasi DOGRU, o yuzden artik hicbir sey baslatmiyor. (E) 3 gate + ADR-024 + phase-plan + checkpoint. **ZERNIO_MOCK false'a geri alindi, teyit edildi.**

- 2026-08-25 — **FAZ 25 (onay adımında caption+hashtag düzenleme) TAMAM — 1048 PASS/0 FAIL, görsel gate 93 PNG/0 hata, 14 route canlı 200. COMMIT YOK, kabul bekliyor.** Ara verilen turdan devam: önce kırık tek testin ortaya çıkardığı **gerçek ürün boşluğu** kapatıldı — düzenleme penceresi `final_render` queued/processing'i de kapsıyor (o adım videoyu render eder, metne HİÇ dokunmaz, publish job'ı henüz doğmamıştır; dışarıda bırakmak "onayladın, şimdi birkaç dakika yazım hatası düzeltemezsin" demekti ve bunu ekranda açıklayan hiçbir şey yoktu). Sonra üç gate'in kalan bulguları. **En ağır üçü:** (1) **reddedilen kayıt yazılanı yok ediyordu** — POST→redirect→GET, GET saklanan metni yeniden basıyor; bir platformun boş olması yüzünden ÜÇ gövde ve etiketler birden gidiyordu, geri alma yok → yeni `Content\DraftStash` (tek sayfalık, **workspace + run** anahtarlı; yalnız run ile anahtarlamak workspace başına yeniden başlayan id'ler yüzünden başka workspace'in taslağını gösterebiliyordu — testte yakalandı). Yalnız GÖRÜNEN değerler değişir; `hash`/`edited`/`edit` hâlâ veritabanını anlatır, yani kaydedilmemiş metin asla kaydedilmiş gibi sunulmaz. (2) **salt-okunur editör yayınlanmış paylaşımı BUGÜNKÜ Ayarlar'la anlatıyordu** — Instagram ifşa toggle'ı sonradan kapatılınca ifşayla çıkmış bir post "eklenmeyecek" diyordu, açılınca hiç eklenmemiş olan "eklendi" diyordu → iş bittiyse editör ifşa hakkında hiçbir şey iddia etmiyor, geçmişi `posts.ai_label_applied` taşıyor. (3) **Kaydet-vs-Onayla tuzağı**: onay formuna dirty-guard + JS'siz de görünen statik satır; ayrıca kaydettikten SONRA bile "Kuyash'ın yazdığı çıkar" diyen cümle `$text['edited']`'e göre dallandı. **Güvenlik (reviewer makinede kanıtladı):** eski deferred `BEGIN` WAL'de worker commit'iyle çakışınca `BUSY_SNAPSHOT` → **500 + yazılan metin kayıp**, ve `busy_timeout` bunu kapsamıyor → `Database::immediateTransaction` + 2 denemelik retry + dürüst "yeniden yükle". Edit hash'i artık yalnız GERÇEKTEN yazılanı kapsıyor ve ikinci CAS'ın `rowCount()`'u kontrol ediliyor (aksi halde publish operatörü yapmadığı kurcalamayla suçluyordu). **Compliance:** geçen düzenleme de artık denetime yazılıyor (`content.edit_checked`, skor + politika sürümü); geri alma `content.restored` olarak ayrı kaydediliyor (eskiden "sen düzenledin" diyordu); seed'de yayınlanmış run'ın COMPLIANCE'ı "pending" görünüyordu (olamayacak bir sonuç) ve `render_review` yanlış node'daydı ('PREVIEW' → engine'in kullandığı 'PUBLISH'). **Kendim bulduğum:** "TikTok ve YouTube'da not native bayrakla verilir" satırı toggle'ı KAPALI platformları da sayıyordu = yanlış güvence → yalnız etkin olanları adlandırıyor. Seed artık düzenlenmiş + limite yakın bir run da içeriyor (chip, geri-al butonu, uyarı callout'u, 14/15 etiket sayacı ilk kez fotoğraflandı). Ertelenenler `phase-25-followups.md`'de, en önemlisi: **JS kapalıyken Onayla hâlâ kaydedilmemiş metni sessizce atar** (sunucu kemeri ayrı iş) ve **hash uyuşmazlığı kalıcı** (operatöre düzeltme yolu yok).


- 2026-08-23 — **FAZ 24: HAFTALIK PLAN = TAKVİM + İKİ MOD — 994 PASS/0 FAIL (+53), görsel gate 75 PNG/0 hata, 12 route canlı 200.** Faz 23'ün haftalık ŞABLONU hiçbir şey tutamıyordu; Faz 24 tarihli hücreyi (`slot_occurrences`, kimlik = saat × YEREL gün) ve saat-başına modu ekliyor. **Görev 0 RİSK SPIKE önce, ürün kodu yazmadan:** DST'yi aşan NY Çar 09:00 → `13:00Z` → `publish_after` → `run_after` gate → adapter'a birebir aynı `scheduledFor` (mevcut kodla, uçtan uca kanıt). **Taşıyıcı karar:** `publish_after` run DOĞARKEN yazılır — `approve()` saati yalnız yazar, asla silmez, ve otomatik-onay yolu `approve()`'dan hiç geçmez; onayda yazsaydık otomatik onaylanan planlı içerik slotunu yok sayıp hemen yayınlardı. `startRun` → `startRunFor(int $wsId,…)` delegasyonu (worker sessionless KALDI). `PlanRunner` chore claim'den ÖNCE koşar (3 gün kapalı worker eski yayınları kapatmalı, ateşlememeli); engel KAPATILMAZ, NOT EDİLİR. **3 GATE DE NO-GO; hepsi aynı turda kapatıldı (12 `p24/gatefix` testi).** Üçünün de bulduğu kritik: saat silmede `committedForSlot` `publish_at > now` filtreliyordu → grace penceresindeki gün onaysız silinip run'ı iptal edilmiyordu → geride geçmiş `publish_after` taşıyan run kalıyor, kuyruk "hemen yayınla" okuyor → SİLİNMİŞ bir saatten, plan kaydı olmadan anında yayın (Faz 23'ün KRİTİK sınıfı). Ayrıca: yayınlanmış gün `missed` süpürülüp denetime SAHTE hata yazıyordu; board `now`'dan pencereliyordu → açıklama gereken tek gün kayboluyor, dashboard "kaçtı" sayacı asla sıfırdan çıkamıyordu; her `skipped` kırmızı "Kaçtı" idi (operatörün temizlediği gün + görevini yapan guardrail dahil); yakalanmayan `PlanRunner::tick()` worker'ı claim'den ÖNCE öldürüp TÜM yayını sessizce durdurabiliyordu; sıradan eski kütüphane videosunu silmek FK'ye çarpıp 500 veriyordu; onay bildirimi PLANIN anını söylüyordu (run'ınkini değil) ve tekrar-POST `publish_now` reddedilen kararda state değiştirebiliyordu; takvim zaten SELECT ettiği gerçek kuyruk gate'ini yok sayıyordu; compliance blok gerekçesi format bloklarını slop diye adlandırıyordu. **Kendim bulduğum 2 kusur:** saat silmek FK ihlaliyle 500 (occurrence'lar) ve **`.sr-only` CSS'te hiç tanımlı değildi** — Faz 23'ten beri "gizli" etiketler tam görünüyordu; ayrıca `input[type=number]` stil listelerinde yoktu (Faz 15 drift'i). **ONAY ZAYIFLATILMADI:** `script_draft` insan kapısı kaldı, ADR-015 kapsamı genişletilmedi, `approval_mode` varsayılanı `manual`. Dev DB 0017 (WAL-safe yedek, 0 FK ihlali).

- 2026-08-23 — **FAZ 23: PLANLI PAYLAŞIM (haftalık slot) — 924 PASS/0 FAIL (+28), görsel gate 69 PNG/0 hata/0 taşma, route 12/12 200.** Premis doğrulandı: tek-anlık zamanlama ZATEN uçtan uca çalışıyordu (onay → `runs.publish_after` → kuyruğun `run_after` gate'i → adapter `scheduledFor`), eksik olan tekrarlı plandı → bu faz onun ÜSTÜNE kuruldu, **ENGINE'E DOKUNULMADI**. **Yeni:** migration **0016** `publish_slots` (workspace_id, ops. account_id, weekday 1-7 ISO, time_hhmm 'HH:MM', enabled; UNIQUE `COALESCE(account_id,0)` çünkü SQLite NULL'ları ayrı sayar) + `workspaces.timezone`; **`SlotResolver`** (SAF: saat okumaz, DB'ye bakmaz — "Pzt 09:00 <dilim>" → sonraki UTC anı; **DST-doğru**: gün kaydırmasından sonra duvar-saati YENİDEN uygulanır, canlı kanıt NY kış `14:00Z` / yaz `13:00Z` / DST'yi AŞAN hafta `13:00Z` yani yerel 09:00 korunuyor); **`SlotRepository`** (tenant-scoped CRUD, başka workspace'in hesabına daraltma REDDEDİLİR); `WorkspaceSettings::timezone/setTimezone` (tzdata doğrulamalı). **UI:** /settings "Haftalık yayın planı" kartı (dilim seçici + slot listesi "sıradaki 15 sa içinde" + Duraklat/Kaldır + ekleme satırı), /queue onay formunda slot seçici (varsayılan "Onaylanır onaylanmaz yayınla"), dashboard "Sıradaki yayın" bandı + canlı geri sayım (**Faz-10 ertelemesi kapandı**; geri sayım data-* attribute'larından okur → i18n tek kaynak, JS kapalıyken sunucu ifadesi kalır). **BULUP DÜZELTTİĞİM TUTARSIZLIK:** zaman-dilimsiz `datetime-local` sessizce UTC sanılıyordu — workspace UTC+3 iken 09:00 yazan operatör 12:00 yerel saatte yayınlardı; artık slot da manuel giriş de workspace dilimini kullanıyor, etiket gerçek dilimi söylüyor ("saatler Europe/Istanbul"). **Görsel gate 375px'te 10px taşma YAKALADI** → tahmin etmek yerine tarayıcıda DOM zinciri ölçüldü → kök-neden `.approve-card__actions` `flex:none` (küçülemiyor, parent 317px < içerik 343px) → `max-width:100%` → ölçümle temiz (scrollWidth 375 = viewport). **KAPSAM DIŞI (gerekçeli):** adapter `timezone:'UTC'` hardcode'u KALDIRILMADI — `publish_after` zaten UTC instant, UTC+UTC tutarlı ve doğrulanmış; workspace dilimini adaptöre taşımak Zernio'nun doğrulanmamış scheduledFor+timezone semantiğine girip yanlış saatte yayın riski yaratırdı (integrations "never hallucinate"). Per-account farklı saat de kapsam dışı (engine fan-out) — şema `account_id` ile hazır. `bin/visual-seed.php`'ye 3 slot + dilim eklendi (slot = operatör yapılandırması, uydurma metrik değil).

- 2026-08-23 — **FAZ 22 DÜZELTME TURU (yeni faz değil): 2 bug kapatıldı — 892 PASS/0 FAIL (+19).** **BUG1 nav pill rebound — İLK FIX YANLIŞ HEDEFLENMİŞTİ.** Gerçek kök-neden tarayıcıda ÖLÇÜLDÜ: Kuyash MPA → her nav tıklaması = tam sayfa yükleme → pill JS ile `translateY(0)`'da (en üst) doğuyor, sonra aktif item'a taşınıyor; base CSS'te transform transition ARMED olduğu için bu **başlangıç yerleşimi animasyona dönüşüyordu**. Kanıt (/settings, fix öncesi): aktif `offsetTop=351`, pill `translateY(0)`, `getAnimations()` → transform transition `playState:"running"`, `duration:250ms`. Yani gösterge her tıklamada yukarıdan aşağı uçuyordu = kullanıcının "başa atıp tekrar geliyor" şikayeti. Easing swap (`--spring`→`--ease-out`) bunu ASLA çözemezdi çünkü sorun eğri değil, **ilk yerleşimin animasyonlu olması**. FIX: `.nav-item__pill` base state'inde transform transition YOK → `moveTo(activeItem())` + `void pill.offsetHeight` (layout flush, konumu taban değer olarak commit et) → `.is-ready` transition'ı ARM eder (hover hâlâ akıcı). **Ek keşif:** `.is-ready` rAF içindeydi; rAF gizli sekmede askıya alınır → arka planda açılan sayfada pill hiç `.is-ready` almıyor, `opacity:0` kalıyordu (gösterge yok) → **senkron** yapıldı; opacity de transition'dan çıkarıldı (aynı nedenle takılıyordu). **GERÇEK TIKLAMA KANITI:** /accounts (aktif 211px) → Trends'e gerçek `click()` → /trends yüklendi → pill `translateY=70` = aktif `offsetTop=70`, `runningTransform=0`, `opacity=1`. Hover ölçümü: mouseenter → transform transition `running=1` (akıcılık korundu). **BUG2 gerçek hesapta uydurma engagement (COMPLIANCE).** Teşhis: @ai.neeidy (gerçek, connected, followers_count=7) kartı `9.5K/298/1.9K` **crc32 uydurma** engagement gösteriyordu ("sample" çipli olsa bile gerçek kanalda temsili sayı = yanlış beyan). FIX: tek sinyal `$providerBacked` (`followers_count !== null` = sync/chore bu hesabı canlı sağlayıcıdan okudu) TÜM kartı yönetiyor — gerçek hesap: engagement snapshot'tan gerçek değer, raporlanmayan `—` + nötr "veri yok" rozeti (stand-in HİÇ hesaplanmıyor); demo/seed hesap: deterministik stand-in + `[örnek]` çipi KORUNDU (ekranlar dolu kalır). `AccountRepository::listFor` en yeni `account_metrics` snapshot'ını LEFT JOIN ediyor (ws-scoped subquery), `shape()` NULL'ı NULL bırakıyor; yeni `acct.no_metrics` (en+tr) + `.acc-card__sample--empty` nötr stil (dürüst boşluk, stand-in rozetinin sesini ödünç almaz). **CANLI KANIT:** @ai.neeidy `— — — [no data yet]` + `7 followers`; @smoke_tt `7K 406 509 [sample]` + `61.2K [sample] +69 today`. Demo verisi SİLİNMEDİ (accounts 2 satır, posts 5), `.env` flag flip YOK, engine/şema-çekirdeği/node-graph dokunulmadı. Görsel gate 69 PNG/0 console-error/0 overflow; route 12/12 200.

- 2026-08-23 — **FAZ 22: PANEL + GERÇEK VERİ — 6 görev tamam (873 PASS/0 FAIL, +34 test).** (1) **Analytics seam (K1):** `PublishProvider::accountMetrics()` (follower + per-post engagement BİRLİKTE — dar follower-only adapter yasaklıydı); gerçek `ZernioPublishProvider` impl GET /accounts (followersCount) + GET /analytics; **per-post alan adları canlıda BOŞ geldiği için UYDURULMADI** → defansif çok-anahtarlı map (views/viewCount/impressions…) + `raw_json`'da ham payload saklama (integrations "never hallucinate" kuralına dürüst yanıt); deterministik Mock impl. (2) **Snapshot chore:** yeni `src/Analytics/DailySnapshot.php` (worker sessionless → ws açıkça iterate, her write'ta workspace_id), migration **0014** `account_metrics` (UNIQUE ws+account+gün → INSERT OR IGNORE) + `accounts.followers_count/followers_synced_at`; **zero-cost** (usage/credit YAZMAZ); worker start + 300s chore'a bağlandı. (3) **Follower wiring:** `setFollowers()` + `sync()` tek turda ref reconcile + gerçek follower; raporlanmayan follower stored değeri EZMEZ. (4) **Dedup (K2):** `connect()` blind INSERT → revive-existing (case/@-insensitive); migration **0015** re-point posts → dup sil → UNIQUE index; dev DB'ye WAL-safe yedekle uygulandı (`kuyash.pre-p22-dedup.20260823T130328Z.bak.sqlite`) → **id2 silindi, 5 post hâlâ id3'te, 0 FK ihlali, id1 mock demo + etiketli demo verisi KORUNDU**. (5) **UI:** pill `--spring`(overshoot 1.56)→`--ease-out` = "geri sekme" bitti. (6) **Jargon:** `Messages::since()` → /trends "fresh · 3 min ago" (ham ISO yalnız title'da); 11 ekranda görünür ham ISO = 0. **CANLI KANIT:** account_metrics id1 `followers=7 GERÇEK`, `post_count=0 + views NULL` (dürüst boş), 0 usage satırı; dashboard `@ai.neeidy · 7 followers` (çipsiz) vs `@smoke_tt · 61.2K [örnek]`. **Kendi yakaladığım regresyon:** sample çipi `.acc-card__who` ellipsis'i içinde YUTULUYORDU (görsel gate PASS demişti) → çip dışarı alındı + `.acc-card__sample--foot` + regresyon testi. **K3:** phase-plan.md → Faz 22 + Faz 23 eklendi (14–21 KORUNDU; token `START PHASE 14` idi ama o numara i18n'e ait → kullanıcı onayıyla 22). 16 dosya + 3 yeni; secret yok.
