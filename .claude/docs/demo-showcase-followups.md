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
- **Video seçici `<select>` `Talking-he` diye kesiliyor ve caret son harfin
  ÜSTÜNE biniyor** — 768 ve 1280'de (`plan__768__en.png`, `plan__1280__en.png`).
  Her boş takvim hücresinde bozuk kontrol gibi duruyor.
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
