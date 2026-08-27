# Vitrin seed — ertelenenler (2026-08-26)

Üç gate turu sonunda kapatılmayanlar. Hepsi **ürün tarafı** (bu iş "ürün kodu
değişmez" kuralıyla yapıldı) ya da bilinçli bir karar. Seed'in kendi kusurları
commit `ef638ae` içinde kapatıldı.

## Ürün kodu — /plan, /queue, /accounts

- **H1 — takvim hücresinde işaret başlığı yiyor.** 768px'te 7 sütunlu ızgara
  hücreye ~68px bırakıyor; `[SAMPLE]` tek başına o kadar yer tutuyor, başlık
  tamamen elenıyor (`plan__768__en.png`). 375px'te aynı başlıklar TAM görünüyor
  (`plan__375__en.png`) — yani sorun başlık uzunluğu değil, hücrenin sarmaması.
  Çözüm ürün CSS'inde: işareti ayrı bir çip yap, ya da başlığı iki satıra clamp'le.
  Seed tarafından çözülemez: işaret ÖNDE olmak zorunda (ellipsis sonu yer).
- **F1 — panel rozeti kesilmiş listeyi sayıyor.** `Cockpit.php:69`
  `array_slice(…, 0, 4)`, `dashboard.php:151` o dilimi sayıyor → 8 bekleyen işte
  rozet sonsuza kadar "4" diyor, "N tane daha" yok.
- **F2 — "seni bekleyen" için dört farklı sayı.** /queue 8 (job), dashboard KPI 7
  (run), plan bandı 4 (bu haftanın hücreleri), panel rozeti 4 (dilim). Tanım
  çakışması; tek tanıma indirilmeli.
- **F5 — onay kuyruğu 8 öğede 16.102px (1280) / 18.698px (375).** Her öğe tam
  editörünü açıyor. Katlanabilir satır / sayfalama gerekiyor. Bu seed olmadan
  görünmüyordu.
- **N2 — bağlı olmayan hesap "+88 bugün" gösteriyor.** `account-card.php:167`
  koşulu `!$providerBacked`, bağlantı durumuna hiç bakmıyor. "Hiç kontrol
  edilmedi" çipiyle aynı kartta günlük artış iddiası duruyor. Seed tarafından
  çözülemez: provider-backed olmak `followers_count` yazmayı gerektirir, ki
  seed'in asla yazmayacağı tek şey o.
- **N1 — dashboard "BAĞLI HESAPLAR" başlığı altında disconnected hesap.**
- **N3 — bitmiş run'ın adımları "done" derken job listesi "ready" diyor.**
- **F6 / N5 — run detayda adım zinciri afordanssız kesiliyor; ham ISO + `entity:`.**

## Ürün kodu — koruma

- **M-2 (compliance) — auto-mode reddi yalnız kurulum ANINDA çalışıyor.**
  Operatör yakalamadan sonra workspace'i Auto'ya alırsa kurulu demo seti sessizce
  guardrail kanıtı hâline gelir. `SettingsController`'da: manifest doluyken Auto'ya
  geçişi uyar/reddet.

## Bilinçli kararlar (kapatılmadı, gerekçeli)

- **Yayınlanmış demo run'da onay kaydı YOK.** İki gate burada anlaşamadı:
  compliance "kaydı tekrar ekleme, absence dürüst" dedi; ux "sessizlik 'bu yayın
  onay kapısından geçmemiş' diye okunuyor" dedi. Compliance otoritedir ve açık
  konuştu → absence korundu. Operatörün yolu: **yakalama sırasında bir demo
  run'ı gerçekten onayla** — teardown o run'ı korur (event pinler, `blockers()`
  önden söyler), yani gerçek bir onay kaydı fotoğraflanabilir.
- **F3 — /usage başlığı $0.00.** Bugünkü yayınlanmış run'a masraf verilmedi;
  vermek cari aya masraf yazmak, yani gerçek bütçe cap'ini yemek olurdu (R2).
  Başlığın sıfır olması dürüst sonuç.
- **B5 — visual-seed'in run #2'si aynı anda iki onay kapısında.** Önceden var
  olan fixture kusuru, bu seed'in değil. Düzeltmek başka bir fixture'ı ve tüm
  mevcut ekran görüntülerini değiştirirdi. **Gate koşulu:** case study, visual
  fixture'ı OLMAYAN bir veritabanından yakalanmalı.
- **LOW-1 — 8 compliance kararı, sıfır denetim satırı.** `events` append-only;
  yazılan geri alınamaz. İki kural gerçekten çakışıyor. Yakalama talimatı:
  /logs ile bir demo run sayfasını yan yana fotoğraflama.
- **INFO-1 — ws2'de manifest'siz `[SAMPLE]` artığı** (assets 3, 4). Eski
  demo-seed'den kalma, dürüstçe etiketli, ama teardown onları kaldıramaz.

---

# Cila turu — ertelenenler (2026-08-26, HEAD 909a845)

Poster + Live-dot + etiket turu sonrası. Üç gate koştu; kapatılanlar commit'te,
bunlar açık. Hepsi **ürün tarafı CSS/kapsam** — bu turun sözü "motor/şema
rewrite yok"tu.

## P1 — case-study'yi etkiler

- **TR `Buraya koy` butonun sağ kenarına dayanıyor**, son harf köşe yarıçapına
  giriyor (`plan__768__tr.png`). EN `Put it here` aynı 75px butonda rahat.
  Buton padding'i ya da daha kısa dize.
- ~~**Video seçici `<select>` caret'i son harfin ÜSTÜNE biniyor**~~ —
  **KAPANDI (2026-08-26).** `.cell__assign select` `padding-inline: 4px` idi;
  native caret kontrolün sağ iç kenarına boyanıyor, o yüzden kesilen etiket
  caret'in ALTINDAN geçiyordu (1280: `Talking-head intr` + "o"nun üstünde ok).
  `padding-inline: 4px 15px` ile caret'e kendi yeri ayrıldı. Kanıt: aynı
  koordinatlardan önce/sonra pixel crop (768 ve 1280).
  **Etiket kesilmesi KAPANMADI** — o zaten bilinçli (hücre satırın 1/7'si);
  `text-overflow: ellipsis` denendi ve GERİ ALINDI: 1280'de görünen karakter
  sayısını ~9'dan ~5'e düşürüyordu ("Talki…"), yani hangi video olduğunu
  söyleme işini daha da kötüleştiriyordu.
  **HARNESS KÖRLÜĞÜ (açık):** görsel gate bu kusur dururken 99 ekran görüntüsü
  boyunca YEŞİLDİ — console error yok, taşma yok, kırık görsel yok; glyph
  üst üste binmesini ölçen hiçbir kontrol yok. Kanıt gate değil, pixel crop.
  Guard eklemeden ÖNCE doğrulanmalı: Chrome'da `select` kırpılmayı
  `scrollWidth > clientWidth` ile bildirmeyebilir — bildirmiyorsa yazılan
  assert hep-yeşil (kör) olur, yani mevcut durumdan kötü.
- **`Format::splitTag()` yalnız /plan'da.** `[SAMPLE]` hâlâ ham metin olarak:
  13 kütüphane başlığının 9'unda, Quick Create foto etiketlerinde (kesik),
  6 /usage masraf satırında, run-detail caption alanlarında. Aynı çip muamelesi
  oralara da genişletilmeli.

## P2 — cila

- **Kütüphane/dashboard yatay kırpıyor**, kart `9:16` çipi taşırken. Onay kartı
  artık dikey (poster attribute'ü sayesinde); ızgara henüz değil.
- **Quick Create'in iki demo fotoğrafı ayırt edilemiyor** — hue kaydırması
  klipslere gitti, fotoğraflara gitmedi; seçim yapılan yer orası.
- **PRODUCTION LINE düğüm adlarını kesiyor**: 1280'de `MUSIC NOTE ...`,
  768'de `COMPLI...`. Compliance-first bir üründe "COMPLIANCE"ı kesmek.
- **/workflows en zayıf ekran** — 1280'de iki küçük kart + ~600px boşluk, CTA
  yok. `frontend.md` node graph için "selection state + sağ ayar paneli" diyor;
  99 PNG'nin hiçbirinde ikisi de yok. Case study "görsel workflow builder"
  iddia edecekse bu ekran onu yalanlıyor. **Karar gerekiyor:** ya panel
  yapılacak ya sayfa yeniden çerçevelenecek.
- **/logs vitrin run'ına bağlanmıyor** — bütün kayıtlar run #3'e (fixture)
  gidiyor, vitrinin yayınlanmış run'ı #5.
- **/usage RECENT CHARGES'ta tarih aralığı etiketi yok** — başlık `$0.00`
  derken altında dolar tutarları var; satırlar dürüstçe eski aylardan ama
  ekran bunu söylemiyor.
- **Trend skorları (92/88/81) etiketsiz** — birim yok, ölçek yok.
- **Onay kuyruğu 8 öğede ~15.600px** (1280). Katlanabilir satır gerekiyor.

## Bilinçli kararlar

- **Yayınlanmış demo run'ın EVENT TIMELINE'ı boş.** `events` append-only;
  yazılan geri alınamaz, o yüzden seed hiç yazmıyor. Compliance gate bunu
  onayladı. Ama bu tur o sayfayı vitrinin amiral gemisi yaptı → maliyeti arttı.
  **Yakalama talimatı:** /logs ile bir demo run sayfasını yan yana fotoğraflama.
- **Demo yayın saatleri (`publish_slots`) işaretsiz** — tabloda başlık alanı
  yok, o yüzden operatörün kendi programı gibi görünüyorlar. Manifest'te ve
  geri alınabilir; teardown'a kadar takvim hücresi üretmeye devam ederler.
- **/usage başlığı $0.00** — bugünkü yayınlanmış run'a masraf verilmedi; vermek
  cari aya yazmak, yani gerçek bütçe cap'ini yemek olurdu.

## Operasyonel — KAPANMADAN ÖNCE

- **`ZERNIO_MOCK=true` ve ws2 `approval_mode=manual` GEÇİCİ.** Yakalama bitince:
  önce `php bin/demo-teardown.php --yes`, SONRA flag'leri geri al. Ters sırada
  yaparsan kuyrukta 5 gerçek onay kapısı canlı yayın yoluna bakar durumda kalır
  (insan onaylı run günlük cap'i de kill switch'i de atlar).
  `.env` yedeği: `.env.bak.pre-casestudy.20260826T144421Z`.

## Cila turu, 2. gate turu — ertelenenler (HEAD 64a9286)

- **N2 (ux, P1) — onay önizlemesi 16:9 kutuda 9:16 klibi KIRPIYOR.** Dashboard'da
  karenin yalnız **%31.6**'sı görünüyor (üstten ~%34, alttan ~%34 gidiyor) ve bu
  kutu doğrudan "Approve & publish" butonunun üstünde. Gerçek bir Reel'de hook
  (üst üçte bir) ve CTA/handle (alt üçte bir) önizlemede GÖRÜNMÜYOR. Kuyruk
  kartı `aspect-ratio: 9/16` beyan ediyor ama `max-height:320px` onu eziyor →
  gerçek oran 0.686, beyan 0.5625; ikisi çelişiyor. Fixture klipleri düz gradient
  olduğu için gate "0 taşma / 0 siyah piksel" derken bu görünmez kalıyor.
  **Doğru çözüm:** dashboard önizlemesine portre kutu (`max-width` ile), ve
  kuyruğun beyan ettiği oranla render ettiğini uzlaştırmak.
- **run-detail PRODUCTION STEPS şeridi 7. düğümü kesiyor**, kaydırma afordansı
  yok — "completed" rozetli bir run'da göremediğin düğüm PUBLISH.
- **TR `mod: manual`** — etiket çevrilmiş, değer çevrilmemiş (`manuel`).
  Compliance taşıyan bir alanda parite boşluğu.
- **Kütüphanede baytı olmayan 3 gerçek video ikon döşemesinde**, [SAMPLE]
  klipler poster'lı → ızgara yarı bozuk görünüyor (dürüst ama tekdüze değil).
- Kopya: `Approved by · [SAMPLE] Demo operator` — edattan sonraki orta nokta
  liste ayracı gibi okunuyor.

## Güvenlik — ertelenenler (gate onaylı, V1 için bloklamıyor)

- **`POST /library/upload`'da rate limit YOK.** Diğer dört controller'da var.
  15s poster tavanı bir tavan, hız kontrolü değil: tek oturum FPM worker'larını
  15'er saniyelik bloklarla süresiz tutabilir. **Çok kiracılı UI'dan ÖNCE
  zorunlu.**
- **Poster çıkarımı hâlâ istek içinde** (ingest), kuyruklu iş değil. 15s ile
  sınırlı. `MediaProbe` bilerek saf-PHP'ydi; bu, yükleme yoluna gerçek bir
  decoder sokan ilk şey — kalan risk bir decoder CVE'si, ki timeout onu kapsamaz.
- `POSTER_TIMEOUT` `.env.example`'da yok (artık bir DoS kontrolü, görünmeli).
- `RunRepository::approvalsForRun`'ın `LEFT JOIN users`'ında workspace yüklemi
  yok (önceden var olan; bu tur `u.name` de seçiliyor).
- `MediaPaths::pathFor()` GET yolunda `mkdir` yapıyor (okuma yolunda yazma yan
  etkisi; 0750 + NAME_RE ile zararsız).
- Eklenmeyen testler: silme poster'ı kaldırıyor mu + paylaşılan-sha koruması,
  ve R2 staging sonrası work-dir temizliği.

---

# Poster turu (gerçek medya) — ertelenenler (HEAD sonrası)

Kullanıcı bağımsız olarak "poster'lar görsel olarak çalışmıyor" dedi ve haklıydı.
Kök-neden ikiliydi: demo klipleri sentetik gradient'ti (doğru çıkarılan kare de
gradient oluyordu) ve görsel gate aynı gradient fixture'ı kullandığı için
render edilen poster ile eksik poster'ı ayırt edemiyordu. İkisi de düzeltildi.

## Ertelenenler (ürün tarafı)

- **Hesap kartı gradient'i dashboard'un en büyük görsel bloğu** (~920px, std 19).
  `account-card.php` bunu bilerek yapıyor ("gerçek oynatılabilir video iddiası
  değil") ve dürüst — ama artık ekrandaki TEK wash o, dolayısıyla "yüklenememiş"
  gibi okunuyor. Doğru çözüm: kartın arkasına gönderinin kendi karesini koymak
  ya da kartı küçültmek.
- **"Seni bekleyen" için hâlâ üç farklı sayı** (dashboard KPI 7 / bant 4 /
  rozet 4 / queue 8) ve **"Sıradaki yayın: kuyrukta bir şey yok"** dört bekleyen
  slot gösteren takvimin üstünde duruyor. Tanım/kapsam açıklaması gerekiyor.
- **/queue 8 onayda ~15.600px** — kartlar varsayılan katlanmalı.
- **/plan hücresinde 9:16 kaynak yatay kutuya cover-crop ediliyor**, özne başı
  kesiliyor; chip/başlık bazı hücrelerde satır içi, bazılarında alt alta.

## Bilinen sınır (kanıt değeri)

- Gate artık lazy görselleri zorla yükleyip `naturalWidth`'e bakıyor, ama
  `<video poster>` kontrolü hâlâ yan-kanal `Image()` probe'u: URL'in
  getirilebildiğini kanıtlar, video elementinin onu BOYADIĞINI değil. Poster'ın
  gerçekten boyandığını doğrulamak render-piksel örneklemesi gerektirir.


---

# Son cila turu — ertelenenler (2026-08-27)

Üç iş kapandı (gerçek stok fixture'lı gate, önizleme-tabanı kontrolü, K1 tek
tanım). ux gate'in aynı turda bulduğu ve **bilinçli olarak kapsam dışı
bırakılan** kusurlar — hepsi gerçek render'dan doğrulandı:

- **P0 — önizlemesi olmayan kapıda "Onayla ve yayınla" aktif.** `run #4`
  kartında tile "Preview pending" derken birincil buton yayınlıyor
  (`dashboard__1280__en.png` ~y1010, `queue__1280__en.png` ~y1780). Operatörden
  göremediği bir videoyu yayınlamaya yetki vermesi isteniyor ve kayda ADI
  yazılıyor. Panelde 4 karttan 2'si bu durumda.
- **P0 — run-detail ekranlarının HİÇBİRİ medya basmıyor** (`rendered: 0`,
  dördü de). Vitrinin amiral gemisi `run-detail-demo-published` dahil: "bunu
  yayınladık" diyen sayfada tek kare yok. `routes.json`'da bu rotalar için
  `minMediaDemo` YOK — yani yeni taban orada **eksiklikten dolayı kör**.
  (Taban koyulamaz: bugün 0 basıyorlar; önce önizleme eklenmeli.)
- **P1 — gerçek hesabın kartında sentetik gradient tile.** `@visual.real`
  (`accounts__1280__en.png`, `dashboard__1280__en.png`). Bu turda yalnız
  `aria-label`/`role` düzeltildi (artık o kanala ait olmayan bir videoyu
  DUYURMUYOR); tile'ın kendisi hâlâ gradient ve gerçek karelerin yanında
  "yüklenemedi" gibi okunuyor. Dürüst boş-durum gerekiyor.
- **P1 — plan bandı "seni bekliyor"u KPI'ın birebir sözcükleriyle tekrar
  ediyor** (`plan.summary*`). Sayı doğru (haftanın AWAITING hücreleri) ama
  KPI'ın 20px altında ve bu veri setinde 7/4 sayıları çakışıyor → okuyan
  bandı KPI'ın dökümü sanıyor. İsim verilerek çözülmeli ("4 gün onayını
  bekliyor"), sayı değiştirilerek değil.
- **P1 — canlı tick "ve N tane daha" satırını ve rozetin ton sınıfını
  güncellemiyor.** Sayı 6'ya düşünce rozet 6 der, satır hâlâ "3 tane daha"
  der; kuyruk boşalınca rozet turuncu "0" olur. Oturum ortasında yeniden
  tutarsızlaşabilir.
- **P1 — önizleme tabanı sayfa-toplamı, yüzey-başına değil.** Panelde taban 5;
  bugün onu geçiren 3 hesap karesi + 2 onay poster'ı. Hesap karesi sayısı
  artarsa dört onay önizlemesi birden kararabilir ve taban yine geçer.
  Ayrıca `naturalWidth > 0` NİCELİK ölçer, içerik değil: gradient bir poster da
  decode olur. Önizlemeleri fotoğrafik tutan şey bu assert değil, GERÇEK STOK
  FIXTURE. (Bu iki sınır `routes.json` yorumunda açıkça yazıyor.)
- **P1 — /plan'da başlıksız "seni bekliyor" hücresi** (Cuma 28.08, Salı 01.09):
  ne poster ne başlık; operatör o gün ne çıkacağını göremiyor.
- **P2** — TR'de sen/siz karışımı (`ve seni bekleyen …` ↔ `Sizin tarafınızdan
  onaylandı`); PRODUCTION STEPS'te PUBLISH düğümü kırpılıyor, kaydırma
  göstergesi yok; bitmiş run'da adımlar "done" derken job listesi "ready";
  "ve N tane daha" 375px'te ~17px dokunma hedefi; kütüphanenin önizlemesiz
  3 klibi operatörün KENDİ dosyaları ve ızgaranın ilk üç kutusu.


---

# Bitiş turu — ertelenenler (2026-08-27, iki gate raporundan)

Dört iş kapandı (fixture varsayılan seed, K1 tek sayı, önizlemesiz kapıda onay
yok, run-detail'de "THE VIDEO"). Gate'lerin bulduğu ve bu turda **kapatılan**
ekstralar: gerçek kanalda uydurma kitle (C1), final render'ın baytları yokken
kaynak klibe düşme (H1), `.run-player`'ın 9:16 olmaması. Aşağıdakiler AÇIK.

## Yüksek

- **M1 (compliance) — `previewMissing()` bayt DEĞİL id kontrol ediyor.**
  `draft_render_id` dolu ama dosyası olmayan bir kapı hâlâ "Onayla ve yayınla"
  sunuyor ve POST geçiyor — tam da guard'ın önlemek için var olduğu kayıt.
  Aynı turda doğru standart zaten yazıldı (`WorkflowController::onDisk()`);
  `QueueController`'a `RenderRepository` + `MediaPaths` bağlanıp yeniden
  kullanılmalı. Şablondaki `inline-player--pending` dalı da aynı şekilde
  yalnız id'ye bakıyor.
- **ux #3 — Trend Radar işaretsiz mock skor basıyor.** `TREND_MOCK=true` iken
  `98/96/95/88/82/75/75/71` skorları ve `fresh · 31 min ago` tazelik iddiası
  hiçbir `[SAMPLE]`/`mock` işareti taşımıyor (`trends__1280__en.png`).
  Yakalamadaki TEK tamamen işaretsiz uydurma yüzey. `ZERNIO_MOCK` ile aynı
  teardown kontrol listesine girmeli.
- **M2 — demo `compliance_check` satırları kalite/slop penceresine giriyor.**
  `QualityScore` son 20, `SlopScorer` son 10 okuyor; seed 8 tane yazıyor
  (`digest`/`settings` ekranlarında `quality score: 88 · 20 checks`).
  `manual` iken zararsız, **`auto`'da belirleyici** → ws2 `auto`'ya dönmeden
  ÖNCE teardown (mevcut sıra kuralı zaten bunu söylüyor, kaçırma).

## Orta

- **M3 — panel "COST PER CONTENT" `[SAMPLE]` harcamayı işaretsiz bir KPI'a
  topluyor** (`Cockpit.php` tüm zamanların `SUM(cost_cents)`'i). `/usage`
  satırları dürüstçe `[SAMPLE]` etiketli, ama ortalama değil. Ya manifest'li
  `usage_events` dışlanmalı ya o satırlar seed edilmemeli.
- **ux #5 — PRODUCTION STEPS 1280'de kırpılıyor, kaydırma göstergesi yok** ve
  kırpılan düğüm **PUBLISH**. 375/768'de dikey yığılıp tamamı okunuyor; yani
  en geniş breakpoint en kötüsü.
- **ux #7 — geri çekilen onay kartında `Reddet` birincil slotta.** Komşu dört
  kart eli o x-konumuna alıştırıyor; orada tıklamak yıkıcı olan. `Çalışmayı
  görüntüle` (ya da etkisiz bir Onayla) o slotu tutmalı. Ayrıca /queue'da
  gerekçe cümlesi tile'dan ~1200px aşağıda, editörün altında.
- **ux #6 — provider-backed hesap kartının tile'ı gradient.** Gerçek verisi
  olan tek kart, yüklenememiş gibi duran tek kart. Kütüphanenin kesikli
  çerçeveli placeholder muamelesi buraya da gelmeli.

## Düşük

- **L1 — `queue.approve_needs_preview` "hâlâ hazırlanıyor" diyor**, ki sayfa
  bunu bilmiyor; kalıcı olarak önizlemesiz bir kapı olabilir. "İzlenecek bir
  şey yok" daha doğru.
- **L2 — iptal/başarısız run için run-detail fixture'ı yok**, yani "THE VIDEO"
  o durumlarda ekran görüntüsüyle kanıtlanmadı (kod okumasıyla kabul edilebilir).
- **ux #8 — TR'de sen/siz karışımı** (`dash.awaiting_more` "seni" vs
  `queue.waiting_for_you` "sizi" vs `dash.needs_review` "incelemeni"), ayrıca
  `dash.budget_of` ("bu ay $5.00 limitten") bozuk ve `{missed} kaçtı` yanlış
  anlam veriyor.
- **ux #10** — TR digest'te ham `plan.slot_missed` anahtarı; "Approved by ·"
  sonrası boşta ayraç; takvim hücresindeki `<select>` `[SAMPLE] 22s d…` diye
  kesiliyor; kütüphane döşemeleri 9:16 klipleri yatay kırpıyor.
- **L4 / ux #10 — yayınlanmış demo run'da EVENT TIMELINE boş.** Doğru (seed
  append-only log'a yazmaz) ama compliance-first bir vitrinde boş denetim izi
  gibi fotoğraflanıyor. Gerçekten koşmuş bir run yakalamak daha iyi.

## Yakalama talimatı (kod değil)

- **`/runs/5` FIXTURE'A ÖZGÜ.** `tools/visual/routes.json` artık bunu yazıyor:
  gerçek bir workspace'e doğrultulduğunda o yol oradaki 5 numaralı run'a düşer —
  ws2'de bu, draft render'ı düz mock-stok rengi olan iptal edilmiş bir Haziran
  run'ı. Kare dürüst, ama vitrinde bozuk döşeme gibi okunuyor. Gerçek workspace
  yakalarken run id'sini veritabanından çöz.
