# Faz 25 — Onay adımında caption + hashtag düzenleme

> Tasarım otoritesi. Plan Mode'da üretildi, `START PHASE 25` ile kilitlendi.
> Uygulama bunu BİREBİR izler.

---

## FAZ 25 — KİLİTLİ KARARLAR (kullanıcı token'ı, 2026-08-24)

Planın J bölümündeki açık kararlar kapatıldı:

| # | Karar |
|---|---|
| J1 | **LİMİTLER = WARN-ONLY-UNTIL-VERIFIED.** `config/platforms.php` değerleri DOĞRULANMAMIŞ sayılır; bağlı platformda bile **BLOKLAMA YOK, yalnız UYARI**. Config'e "unverified — gerçek limit doğrulanmadan blok açma" notu düşülür. **İstisna:** boş-caption invaryantı (E6/E12) bağlı platformda yine **bloklar** — o bir limit değil, eksik içerik. |
| J2 | Slop yalnız **KAYDETME** anında yeniden skorlanır, publish'te DEĞİL (korpus-drift onaylanmış içeriği mahsur bırakırdı). |
| J3 | `captions` **AYNI anahtara üzerine yazılır** (tek okuma yolu = yapısal güvenlik); `captions_ai` orijinali korur; `compliance_check` job'ının `result_json`'ı **ELLENMEZ**. |
| J4 | Onaydan-sonra-edit onayı **YENİDEN AÇMAZ**: `approvals` kaydı değişmez + `content.edited_after_approval` event + UI rozeti. B1 zaten düzenleneni yeniden kapıya soktuğu için dürüst ve güvenli. |
| J5 | Yeniden-kapı **`warn` kaydı GEÇİRİR** (uyarı çipi + `compliance.warned`). |
| J6 | Limitler `CompliancePolicy`'de **DEĞİL**, `config/platforms.php`'te (VERSION bump'tan kaçınmak; geçmiş auto-onay kayıtlarının policy sürümüyle karışmasın). |

### Atlanamaz çekirdek

- **G0 — GÖREV 0 RİSK SPIKE, ürün kodundan ÖNCE.** `jobs.result_json`'daki `captions` elle ifşasız bir
  metne çevrilir → `Worker::tick` sürülür → spy provider'ın aldığı `PublishRequest` düzenleneni taşır **VE**
  IG'de ifşa satırı hâlâ **SONDA** **VE** `aiLabelApplied === true`. Geçmezse **DUR ve raporla**; Görev 1'e geçilmez.
- **B — İki noktalı kapı.** B1 kaydetmede (`SlopScorer` + `CompliancePolicy` eşikleri + `PlatformLimits` +
  ifşa rezervi), B2 publish'te hash eşleşmesi → içerik kapıdan geçmeden değiştiyse
  `failedPermanent('content changed without passing the compliance check')`.
- **C — İfşa garantisi.** Publish anında kompoze (`withDisclosure`); gövde editi sıyıramaz; dedupe;
  sayaç ve limit ölçümü **ifşa dahil birleşik string** üzerinden; UI'da kilitli/soluk ifşa satırı (input DEĞİL).
- **REG — Regresyon kilidi.** Düzenlenmemiş run'ın davranışı **bit-bazında AYNI**. TikTok/YouTube native
  bayrakları editten etkilenmez; toggle KAPALI iken `compliance.ai_disclosure_suppressed` yazılır.
- **REC — Dürüst kayıt.** `approvals` değişmez; edit ayrı event; `mode='auto'` onaylı + insan-düzenlemeli
  içerik hiçbir şablonda "sen onayladın" render etmez (iki gerçek ayrı gösterilir).

### Kısıtlar
Motor / node-graph / şema-çekirdeği rewrite YOK · **migration YOK** (`result_json`'a additive alan) ·
`events.kind`'a yeni değer EKLEME (mevcuda eşle) · `ContentRevision`'ın her sorgusu `workspace_id` filtreli,
cross-tenant edit reddedilir · testlerde **GERÇEK yayın YOK** (Mock/Spy) · `.env` flip YOK · secret basma ·
UI jargon yasağı · i18n en+tr parite · responsive 375/768/1280, JS-siz çalışır.

### Kapsam
Görev 0–6. Kapsam dışı: AI-video, toplu düzenleme, caption şablonu, per-platform hashtag,
"AI ile yeniden üret", yayınlanan-metin arşivi.

---

## A. Saklama & akış

**KARAR: yeni tablo YOK, migration YOK.** Düzenlenen değer, üretilenin *yerine* aynı anahtara yazılır:

```
jobs(type='caption_generation').result_json:
  captions      ← ÜZERİNE YAZILIR (düzenlenmiş hali)
  captions_ai   ← YENİ: AI'ın yazdığı orijinal (ilk düzenlemede bir kez yazılır)
  edit          ← YENİ: {by, by_email, at, hash, verdict{...}, platforms_checked}
jobs(type='hashtag_generation').result_json:
  hashtags / hashtags_ai / edit   (aynı desen)
```

**Neden üzerine yazma (ayrı tablo değil):** publish `$prior['caption_generation']['captions']`'ı okuyor
(`ZernioPublishExecutor.php:60`). Aynı anahtara yazınca **publish yolu hiç değişmeden** düzenleneni okur —
"üretileni yayınlama" hatası *yapısal olarak imkânsız*. Ayrı tablo olsaydı publish + `SlopScorer::historyTexts`
+ `runs/show` + pipeline drawer olmak üzere **4 okuma noktası** override'ı ayrı ayrı hatırlamak zorunda
kalırdı; biri unutulursa sessizce eski metin yayınlanır. Tek-yazma/tek-okuma bir güvenlik özelliğidir.

**Denetim izi kaybolmaz:** `captions_ai` AI'ın ne yazdığını saklar; `compliance_check` job'ının kendi
`result_json`'ı hiç ellenmez, yani "hangi metin skorlandı" kaydı dürüst kalır.

**Yan fayda:** `SlopScorer::historyTexts()` geçmişi `jobs.result_json` type `caption_generation` →
`captions`'tan okur. Üzerine yazınca gelecekteki slop karşılaştırması **gerçekten yayınlanan** metne bakar.

**Yeni servis:** `src/Content/ContentRevision.php` — tek yazma noktası. Guarded UPDATE, transaction,
tenant-scoped + eşzamanlılık için içerik hash'i.

**Yeni config:** `config/platforms.php` — platform başına caption karakter ve hashtag sayısı limitleri
(J1 gereği **warn-only**). `CompliancePolicy`'ye konmaz (J6).

---

## B. Compliance yeniden-kapı

### B1 — Kaydetme anında (senkron, web): asıl kapı
Yeni `src/Compliance/ContentGate.php`, **mevcut parçaları yeniden kullanır**:
- `SlopScorer::score($wsId, $runId, $candidate)` — düz servis, web'den çağrılabilir. Aday metin
  `SlopScorer::candidateText()`'in aynı şekliyle, **düzenlenmiş** hâliyle kurulur.
- `CompliancePolicy::SLOP_WARN/SLOP_BLOCK` — aynı eşikler, aynı sürüm.
- `PlatformLimits` — **birleşik** string üzerinde (gövde + ifşa + hashtag), **warn-only** (J1).
- İfşa-varlığı/rezervi: AI etiketi gerekiyorsa IG için ifşa satırının sığacağı hesaba katılır.
- Boş caption: bağlı platformda **blok** (J1 istisnası).

`block` → kayıt REDDEDİLİR, dürüst gerekçe, eski metin durur. `warn` → kaydedilir + uyarı çipi +
`compliance.warned` (J5).

### B2 — Publish anında (worker, otorite): sapma-koruması
`ZernioPublishExecutor::execute()` başında:
- `edit` bloğu varsa → içeriğin hash'i `edit.hash` ile eşleşmeli. Eşleşmiyorsa →
  `JobResult::failedPermanent('content changed without passing the compliance check')`.
- `edit` bloğu yoksa → **bugünkü davranış aynen** (REG).

**Slop publish anında YENİDEN skorlanmaz (J2).**

---

## C. AI-ifşa garantisi

İfşa caption metninde SAKLANMIYOR. `withDisclosure()` publish anında, hesap döngüsü içinde,
`PublishRequest` kurulmadan önce ekliyor. TikTok/YouTube tarafında ifşa metin değil **native bayrak**,
`aiLabelApplied`'dan türüyor — caption'dan değil.

Bu fazda eklenenler:
1. **Uzunluk rezervi** — limit ölçümü ifşa satırını dahil eder; sayaç da ifşa dahil kalanı gösterir.
2. **Tekrar-önleme (dedupe)** — kullanıcı gövdeye ifşa metnini yazarsa uyarılır ve publish'te iki kez eklenmez.
3. **UI'da kilitli gösterim** — soluk, salt-metin (input DEĞİL) + "AI içerik için otomatik eklenir".
4. **Değişmeyen kural** — ifşa gereksinimi **medyadan** gelir; insanın caption yazması onu ne doğurur ne kaldırır.

---

## D. UI

`/queue` onay kartı (`render_review`) + `/runs/{id}`.
Platform başına textarea + canlı sayaç + limit uyarısı · hashtag alanı + sayaç · kilitli ifşa satırı ·
birleşik önizleme · Kaydet / "Kuyash'ın yazdığına dön" · salt-okunur mod · "Onaydan sonra düzenlendi" rozeti ·
"Kuyash yazdı, sen düzenledin" çipi · `warn` bandı gerçek gerekçeyle.
Jargon yasağı: "Paylaşım metni", "Etiketler", "son paylaşımlarına benzerlik". i18n en+tr.
375 dikey tam genişlik; 768+ üst üste kartlar; JS-siz çalışır.

---

## E. Kenar durumlar

| # | Durum | Davranış |
|---|---|---|
| E1 | Zamanlanmış, ateşlenmemiş edit | İzinli. Pencere: `render_review` `awaiting_approval` **veya** publish job `queued`. |
| E2 | Yayınlanmış / uçuşta | Salt-okunur; publish `processing`/`published` veya `posts` `publishing`/`published` → reddedilir. |
| E3 | Kapıya takılan edit | Kaydedilmez, eski metin durur, gerekçe gösterilir, run İPTAL EDİLMEZ. |
| E4 | İfşayı silme girişimi | İmkânsız (ifşa gövdede değil). Gövdeye yazılırsa uyarı + publish'te tekrarlanmaz. |
| E5 | Limit aşımı | **Yalnız uyarı** (J1), bağlı platformda bile blok yok. |
| E6 | Boş caption | Bağlı platformda **blok** (YouTube başlığı caption'ın ilk satırından türüyor). |
| E7 | Eşzamanlı edit | Form yüklenen içeriğin hash'ini taşır; saklı hash farklıysa reddedilir. Kolon gerekmez. |
| E8 | AI-oto vs manuel | Fark yok. Edit metne, AI etiketi medyaya dair. |
| E9 | Bayat verdict | Düzenleme sonrası kart **yeni** verdict'i (`edit.verdict`) render eder, hangi metne ait olduğu yazılır. |
| E10 | Onaydan sonra edit | İzinli; onay kaydı DEĞİŞMEZ + `content.edited_after_approval` + UI rozeti (J4). |
| E11 | Run iptal/blok | Düzenleme kapalı. |
| E12 | `captions` eksik/bozuk | Kaydetmede eksik platform doldurulmaya zorlanır; B2'de boş-caption invaryantı bağlı platformda publish'i durdurur. |

---

## F. Kayıt / audit

`events.kind`'a **yeni değer eklenmez**. Eşleme:

| Olay | kind | level | key |
|---|---|---|---|
| İnsan metni düzenledi | `transition` | info | `content.edited` |
| Onaydan sonra düzenledi | `transition` | warn | `content.edited_after_approval` |
| Yeniden-kapı bloklandı | `compliance` | warn | `content.edit_blocked` |
| Yeniden-kapı uyardı | `compliance` | warn | `compliance.warned` (mevcut) |
| Publish'te hash uyuşmazlığı | `compliance` | error | `content.edit_unverified` |

`approvals` tablosu ve CHECK'i **değişmez**. Düzenleme ayrı bir event; onay kaydına karıştırılmaz.
Otomatik onaylanmış + insan-düzenlemeli içerik UI'da **iki ayrı gerçek** olarak gösterilir.

---

## G. Test & kabul

**Vitrin testi:** düzenlenmiş IG caption'ı Zernio'ya ulaşırken hâlâ ifşayı taşıyor (spy provider).
Mutlu yol · slop block · limit uyarısı · boş caption blok · hash uyuşmazlığı → `failedPermanent`, hiçbir
post `published` olmuyor · eşzamanlı edit reddi · yayınlanmış salt-okunur · **REG: düzenlenmemiş run
bit-bazında aynı** · native bayraklar etkilenmiyor · toggle KAPALI iken suppressed yazılıyor ·
tenant izolasyonu · dürüstlük (`mode='auto'` + edit) · i18n paritesi.
Testlerde gerçek yayın YOK.

Kapanış: `php tests/run.php` 0 FAIL · `tools/visual/gate.sh` 0 hata/0 taşma · 13 route 200 · secret temiz ·
**security-auditor + ux-reviewer + compliance-reviewer (ZORUNLU)**.

---

## H. Faz sıralaması (risk-önce)

0. **RİSK SPIKE** (ürün kodu YOK) → `php tests/run.php`
1. **Limitler + kapı** (`config/platforms.php`, `PlatformLimits`, `ContentGate`) → `php tests/run.php`
2. **`ContentRevision` yazma katmanı** (guarded UPDATE, hash, `captions_ai`, pencere guard'ı) → `php tests/run.php`
3. **Publish sapma-koruması** (B2; `edit` yoksa davranış değişmez) → `php tests/run.php`
4. **Route + controller** (`POST /runs/{id}/text` + revert, CSRF, rate-limit) → curl + testler
5. **UI** (kuyruk kartı + run detayı, sayaç, kilitli ifşa, rozetler, en+tr) → `tools/visual/gate.sh`
6. **Audit + doküman** (event'ler, ADR-023, phase-plan Faz 25, followups, checkpoint) → tam test + gate + 3 reviewer

---

## I. Kapsam dışı

AI-video · toplu düzenleme · caption şablon/kütüphanesi · caption'a zengin medya ·
"AI ile yeniden üret" · per-platform ayrı hashtag listeleri · YouTube başlığını ayrı düzenleme ·
zamanlama değişikliği (Faz 24'ün işi) · yayınlanan-metin arşivi.

---

## J. Açık kararlar — KAPANDI

Yukarıdaki "KİLİTLİ KARARLAR" tablosuna bakınız (J1–J6). Kalan tek gerçek belirsizlik:
**platform limit değerleri doğrulanmamıştır** — bu yüzden warn-only, ve config'te bu not yazılıdır.
