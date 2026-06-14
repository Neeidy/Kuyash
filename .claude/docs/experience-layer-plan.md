# Kuyash — Experience Layer v2 (Faz 15.9–20) + Otonom Loop Sistemi

> **Statü:** ONAYLANDI ve entegre edildi (2026-06-13). `phase-plan.md` Experience Layer bloğu 15.9–20 ile güncellendi; bu doküman tam spec'tir.
>
> **Görsel kaynak (single source of truth):** onaylı `kuyash-prototip-v3-hafif.html`. Entegrasyondan önce repoya `.claude/docs/design/prototype-v3.html` olarak kopyalanacak; CC her faz başında bu dosyayı **okuyup** birebir referans alacak.
>
> **Bağlayıcı doküman:** `.claude/docs/ui-style-guide.md` (mevcut) + bu doküman. Çelişki olursa: token **isimleri** ui-style-guide'dan, token **değerleri ve bileşen davranışı** v3 prototipinden gelir.

---

## 0. Bağlam ve neyi değiştirmiyoruz

Faz 0–14 (V1 + i18n), Faz 15 (Design Foundation) ve Faz 15.5 (Elevation) kabul edildi ve push'landı. Premium karanlık tasarım **zemini** (token, self-hosted Inter/JetBrains, kart/badge/buton/form bileşen katmanı) gerçek app'te **zaten mevcut** (`base.css` + `app.css`). 732 test PASS.

Bu yüzden Faz 16–20, zemini yeniden kurmaz. v3 prototipinin app'te **henüz olmayan** katmanını gömer:

1. **Motion sistemi** (yalnız `transform`/`opacity`/`stroke-dashoffset` — GPU-light), View Transitions, ⌘K komut paleti, global yan panel (drawer).
2. **İmza dashboard**: business KPI'ları (count-up), hesap **canlı-akış widget'ları** (video + like/comment/share + takipçi büyüme), onay kartlarında **inline player**.
3. **Pipeline/workflow görselleştirmesi**: node-graph, **aktif aşamaya akan fill-flow**, durum simgeleri, kutuya tıkla → **yan detay paneli** (teknik detaysız).
4. **Canlı katman (SSE)**: yukarıdaki widget'ları gerçek dev-DB state'iyle canlandırır.
5. **Cila + performans + erişilebilirlik** kapanışı.

**Değişmeyen mimari (sert kısıt — her fazda geçerli):**
- Pure PHP 8.3, server-rendered, **framework yok, build tool yok**.
- **Vanilla JS modülleri + custom CSS.** jQuery/Tailwind-build/GSAP/Three.js yok.
- **Progressive enhancement**: sunucu gerçek veriyi render eder; JS yalnız üstüne motion/canlılık ekler. JS kapalıyken sayfa çalışır (statik fallback).
- **Mobil fallback**: node-graph mobilde dikey **stacked kart**; 375/768/1280 kullanılabilir.
- **Dürüst rozet**: "Senin onayınla" vs "Uyumluluk ajanı otomatik onayladı" — asla karıştırılmaz.
- **UI'da sıfır teknik jargon** (ffmpeg/TTS/SSE/job/queue gibi terimler kullanıcıya görünmez).
- **prefers-reduced-motion**: tüm animasyonları sıfırlar.
- Faz 17 dışında **backend/DB/route değişikliği yok** (17 = tek gerçek backend yüzeyi: SSE).

---

## 1. v3 Tasarım Sistemi — CC'nin uyacağı sabitler

### 1.1 Renk token'ları (v3 değerleri)

Bu değerler app'in **mevcut token isimlerine** map'lenir (yeni paralel set AÇMA). Eşleme kararı Faz 16'nın ilk adımıdır; aşağıdaki değerler hedeftir:

| Rol | Değer | Kullanım |
|---|---|---|
| Zemin | `#050507` | sayfa arka planı (statik ambient gradient ile) |
| Zemin-2 | `#08080c` | kart gradient alt ucu |
| Yüzey-1 | `#0d0e14` | kart/panel taban |
| Yüzey-2 | `#13141c` | ikincil yüzey, ghost buton |
| Yüzey-3 | `#1c1e28` | bar/track, hover yüzey |
| Çizgi | `#1e2029` | 1px border |
| Çizgi-2 | `#2a2d3a` | belirgin border, hover border |
| Metin | `#f4f6fb` | birincil metin |
| Metin-soft | `#cfd5e2` | ikincil metin (**gri-aşırı kullanımı çözen ton**) |
| Meta | `#7b8395` | en sönük tier (WCAG AA için ≥ bu ton) |
| **Accent** | `#2ff0d2` (teal) | birincil vurgu, glow, aktif durum |
| Accent-2 | `#13c4a8` | gradient/buton alt ucu |
| Glow | `rgba(47,240,210,.4)` | box-shadow / drop-shadow glow |
| Violet | `#b794ff` | ikincil vurgu, AI etiketi |
| OK | `#3ee594` | tamamlandı/sağlıklı/pozitif delta |
| Warn | `#ffc24b` | bekliyor/uyarı |
| Danger | `#ff6b6b` | reddet/negatif/kalp |
| AI | `#b794ff` | AI-etiketli rozet |

Radius: kart `16px`, kontrol `11px`. Easing: standart `cubic-bezier(.22,1,.36,1)`, yaylı `cubic-bezier(.34,1.56,.64,1)`.

> **KARAR (ONAYLANDI 2026-06-13):** v3'ün teal accent'i (`#2ff0d2`) app'in mevcut marka accent'inin **YERİNE** geçer. Global renk değişimi; tersine çevrilebilir (sadece CSS değeri). Faz 16'da uygulanır.

### 1.2 Motion kuralları (GPU-light — ihlali FAIL sebebidir)

Bu kurallar Faz 15.9 görsel-gate'inin sert kriteridir:

1. **Sadece `transform`, `opacity`, `stroke-dashoffset` animasyonu.** `filter: blur()`, `box-shadow`, `background-position`, `width/height`, `top/left` animasyonu **YASAK**.
2. **`backdrop-filter` yalnız on-demand modallarda** (⌘K paleti, drawer). Kalıcı/çok sayıda öğede yasak.
3. **`mix-blend-mode` + animasyonlu büyük blur YASAK** (v2'nin GPU'yu yakan sebebi buydu).
4. **Sürekli dönen öğe yok** (spinner ring yok). Aktiflik = hafif glow + opacity heartbeat (~1.5–2.4s).
5. Ambient arka plan **statik gradient** (animasyonsuz).
6. Her animasyon **bir state değişimine** karşılık gelmeli (dekoratif sonsuz hareket değil).
7. `prefers-reduced-motion: reduce` → tüm animasyon/transition sıfır.
8. Hedef **60fps**; boştayken GPU ~0.

### 1.3 Bileşen envanteri (v3 → gerçek app)

Her bileşen tekrar kullanılır PHP partial + CSS/JS modülü olur:

- **Kayan-pill sidebar nav** — aktif sekme altında yaylı kayan pill.
- **Topbar**: workspace switcher, **canlı heartbeat noktası**, ⌘K arama, avatar.
- **KPI kartı**: count-up sayı (requestAnimationFrame, bir kez), sparkline (canvas), delta ▲▼, business metrikleri.
- **Hesap canlı-akış widget'ı**: oynayan video (yavaş ken-burns, transform-only), like/comment/share simgeleri + sayıları, takipçi + büyüme delta, sağlık durumu.
- **Onay kartı**: **inline player** (play → kart içinde oynat, ilerleme çubuğu scaleX, "Oynuyor" rozeti), uyumluluk/AI-etiket rozetleri, Onayla/Reddet.
- **Pipeline node-graph**: aralıklı kutular, bağlantı çizgileri (tamamlanan = dolu yeşil; aktif = soldan sağa **fill-flow** + öncü parlayan nokta; bekleyen = sönük kesik), durum simgeleri (✓ / ⚡ / kesik halka), aktif kutu heartbeat glow + alttan dolum, **kutuya tıkla → drawer**.
- **Yan panel (drawer)**: sağdan yaylı giriş, on-demand backdrop-filter, sade içerik (skor/timeline/akış), **teknik detay yok**.
- **Komut paleti ⌘K**: on-demand, fade+pop giriş, klavye ile.
- **Scroll-reveal**: IntersectionObserver, bir kez.

---

## 2. Otonom Loop Sistemi (`/goal`)

### 2.1 Döngü akışı

```
/clear
  → checkpoint.md + ilgili rule + ui-style-guide + prototype-v3.html OKU
  → PLANLA  (iç plan + dosyaya plan yaz: .claude/state/phase-<N>-plan.md)
  → KUR     (yalnız aktif fazın kapsamı; scope-creep yasak)
  → TEST    (3 subagent PARALEL → orchestrator toplar)
       ├─ ux-reviewer       (GÖRSEL)
       ├─ qa-reviewer       (KOD)
       └─ security-auditor  (GÜVENLİK)
  → orchestrator değerlendir:
       • 3'ü de PASS  → VERDICT → COMMIT (faz branch'i) → [insan kapısı?] → /clear → sonraki faz
       • herhangi FAIL → DÜZELT + ilgili agent'ı yeniden çalıştır
            – max 2 düzeltme denemesi
            – hâlâ FAIL → DUR, stop-and-report → kullanıcıya raporla (commit YOK)
```

### 2.2 Orchestrator (ana ajan) kuralları

- Üç gate sonucu **PASS/FAIL + gerekçe** döndürür. Orchestrator yalnız **üçü de PASS** ise commit eder.
- FAIL'de orchestrator hatayı düzeltir, **sadece düşen gate'i** yeniden koşar (3'ünü baştan değil — token tasarrufu).
- Düzeltme sayacı faz başına **2**. Aşılırsa hard-stop; CC kendi başına "yeterince iyi" deyip geçemez.
- Her faz **kendi branch'inde**: `feat/phase-<N>-<slug>`. **Otomatik push YOK** (kullanıcı revert edebilsin). main'e merge insan onayıyla.
- Commit mesajı conventional + faz no; **secret commit etmez** (security gate bunu da kontrol eder).
- `/clear`'dan sonra context sıfır; CC her şeyi checkpoint + plan dosyasından yeniden yükler. Bu yüzden **plan dosyası ve checkpoint güncellemesi zorunlu** (yoksa sonraki faz bağlamsız başlar).

### 2.3 Üç gate — geç/kal kriterleri

**GÖRSEL gate — `ux-reviewer`** (gerçek render zorunlu; bkz. 2.4)
- Caddy+PHP ayakta, headless Chrome ile 375 / 768 / 1280 + EN & TR screenshot.
- Kontrol: 0px yatay overflow; console error yok; empty/loading/error halleri var; bu fazın v3 bileşeni prototiple görsel uyumlu; **motion kuralları (§1.2) ihlali yok**; reduced-motion'da animasyon sıfır.
- FAIL örnekleri: kırık layout, off-palette gri, animasyonlu blur/backdrop kalıcı öğede, dönen spinner, jargon sızıntısı.

**KOD gate — `qa-reviewer`**
- Mevcut **732+ test PASS** (regresyon yok) + bu fazın yeni testleri.
- Acceptance kriteri self-check; scope-creep yok (yalnız faz dosyaları değişmiş); pure-PHP/no-dep/no-build ihlali yok; vanilla-JS (yeni bağımlılık eklenmemiş).
- JS-kapalı fallback çalışıyor.

**GÜVENLİK gate — `security-auditor`**
- Secret sızıntısı yok; output escaping; (state değiştiren yüzey varsa) CSRF; SSE fazında tenant-izolasyon + uzun-transaction yok; ffmpeg/komut enjeksiyonu yüzeyi yok.
- Çoğu Experience fazı düşük güvenlik yüzeyli (salt sunum) → bu gate kısa kalır; **Faz 17 (SSE)** ve **Faz 20** ağır.

**Koşullu 4. gate:** uyumluluk yüzeyine dokunan fazda (onay rozetleri, AI-etiket) `compliance-reviewer` eklenir — **dürüst rozet** gate'i (mevcut kural).

### 2.4 Ön koşul: görsel-test altyapısı (Faz 15.9)

Bir subagent "ekranı göremez", kod okur. Görsel gate'in **gerçek** olması için lokal render + screenshot altyapısı şart. Yoksa görsel test sahtedir — bu da seni koruyan tek otomatik kapıyı çürütür. Bu altyapı Faz 15.9'da kurulur (aşağıda).

### 2.5 İnsan kapısı politikası (kritik)

Loop senin manuel "tarayıcıda görürüm" kapını bir **AI görsel-gate'iyle** değiştiriyor. AI kapısı *kırık layout / regresyon / kural ihlali* yakalar; **zevk** ("premium mi, istediğim hava var mı") otomatikleşmez. Bu yüzden:

| Faz | İnsan kapısı | Gerekçe |
|---|---|---|
| 15.9 (altyapı) | **EVET** | loop'un temeli; bir kez doğru kurulmalı |
| 16 (motion) | **EVET** | his/zevk kritik |
| 17 (dashboard) | **EVET** | görünüm vitrini |
| 18 (pipeline) | **EVET** | imza bileşen, zevk kritik |
| 19 (SSE/canlı) | opsiyonel | çoğu mantık; istersen bak |
| 20 (cila/perf) | opsiyonel | düşük risk |

"İnsan kapısı EVET" = loop o fazı yapar, verdict + screenshot üretir, **DURUR**; sen tarayıcıda bakıp `START PHASE <N+1>` ile devam ettirirsin. İlk fazları izleyerek koş; agent kalitesine güvendikçe 19–20'yi tam otonom bırak.

---

## 3. Faz 15.9 — Loop & Görsel-Test Altyapısı · token: `START PHASE 15.9`

**Amaç:** Otonom loop'u ve gerçek görsel gate'i çalıştıracak altyapıyı kurmak. Ürün kodu değişmez.

**Kapsam:**
- Lokal çalıştırma scripti: Caddy+PHP'yi dev modda ayağa kaldır, dev-DB seed (mock veri), sağlık kontrolü.
- Headless screenshot aracı: Playwright **veya** sistemde mevcut Chrome ile script — verilen route listesini 375/768/1280 + EN/TR açıp PNG kaydeder, console error toplar. (Node bağımlılığı dev-only; ürün bağımlılığı DEĞİL — `package.json` yalnız dev-tooling, app hâlâ build-free.)
- `/goal` slash komutu (`.claude/commands/goal.md`): §2.1 akışını, fail-cap'i, branch-commit'i, `/clear` disiplinini, insan-kapısı tablosunu içerir.
- Orchestrator talimatı + 3 gate çağrısının nasıl yapılacağı (mevcut `ux-reviewer`/`qa-reviewer`/`security-auditor` agent'larına görev şablonları).
- `stop-and-report` ile entegrasyon (fail-cap aşımında).

**Kapsam dışı:** ürün UI/DB/route değişikliği; CI/CD; uzaktan deploy.

**Acceptance:** `/goal` boş bir deneme fazında uçtan uca koşuyor (plan→kur(noop)→3 gate→verdict→branch commit→/clear); görsel araç gerçekten 6 screenshot üretiyor; fail senaryosu 2 denemeden sonra duruyor.

**Test:** görsel = araç gerçek PNG üretiyor mu (kendini test); kod = script'ler hatasız + app hâlâ build-free + 732 PASS; güvenlik = script secret basmıyor, dev-only ayrımı net.

**İnsan kapısı: EVET.**

---

## 4. Faz 16 — Motion & Etkileşim Çekirdeği · token: `START PHASE 16`

**Amaç:** v3'ün hissini app'e gömmek: motion-token sistemi, geçişler, ⌘K paleti, global drawer, hover/reveal/count-up. Yalnız client-side enhancement.

**v3 eşlemesi:**
- Motion token'ları (süre/easing) → `base.css`'e; reduced-motion hepsini sıfırlar.
- Statik ambient gradient zemin → body (animasyonsuz, blur'suz).
- Kayan-pill sidebar, hover-lift kartlar, scroll-reveal (IntersectionObserver) → app shell.
- **⌘K komut paleti** → global partial + JS modülü (navigasyon + aksiyonlar).
- **Global drawer (yan panel)** → tekrar kullanılır partial; satır/kart tıklamasıyla açılır; on-demand backdrop-filter.
- KPI count-up (requestAnimationFrame, bir kez) → mevcut KPI'lara.
- Teal accent reconciliation (§1.1 kararı) → token değerleri.

**Kapsam dışı:** SSE/canlı veri, yeni backend, yeni ekran, pipeline node-graph (Faz 18), inline player (Faz 17).

**Dokunulan dosyalar:** `base.css`, `app.css`, yeni `assets/js/` modülleri (palette, drawer, motion, countup), shell partial'ları. **PHP/DB/route yok.**

**Acceptance:** motion algılanır ama premium; her animasyon bir state'e map'li; reduced-motion saygı; 60fps, boşta GPU ~0; ⌘K + drawer çalışıyor; §1.2 ihlali yok; 732 PASS; TR/EN bozulmadı.

**Test:** görsel = motion kuralları + ⌘K/drawer + reduced-motion screenshot; kod = regresyon + vanilla-JS + scope; güvenlik = (düşük) yeni JS XSS yüzeyi yok.

**İnsan kapısı: EVET.** Reviewer: `ux-reviewer`.

---

## 5. Faz 17 — İmza Dashboard (KPI + Hesap Widget + Inline Player) · token: `START PHASE 17`

**Amaç:** Dashboard'u v3 vitrinine dönüştürmek; gerçek dev-DB verisine bağlı.

**v3 eşlemesi:**
- **Business KPI şeridi**: kalan bakiye, bu ay harcanan, içerik başı maliyet, onay bekleyen — count-up + sparkline + delta. Gerçek veri (runs/jobs/renders/ledger). Veri yoksa "veri yok", asla uydurma.
- **Hesap canlı-akış widget'ları**: paylaşılan video önizleme (yavaş ken-burns, transform-only), like/comment/share simge+sayı, takipçi + büyüme delta, sağlık durumu. (Metrikler Faz 10 snapshot'ından; yoksa "veri yok".)
- **Onay bekleyenler**: **inline player** (play → kart içinde oynat, ilerleme scaleX, "Oynuyor" rozeti — yan pencere AÇMAZ), uyumluluk/AI-etiket rozetleri (**dürüst**), Onayla/Reddet.

**Kapsam dışı:** pipeline node-graph (Faz 18), SSE canlılık (Faz 19 — burada veriler sayfa yüklemesinde gelir), yeni metrik backend'i.

**Dokunulan dosyalar:** `dashboard.php` + ilgili partial'lar, hesap-widget partial, KPI partial, CSS, inline-player JS modülü. Veri okuma mevcut servislerden; **yeni DB yüzeyi yok.**

**Acceptance:** dashboard gerçek veriyle render; eksik veri zarifçe "veri yok"; inline player kart içinde oynatıyor (drawer açmıyor — eski bug çözülü); rozetler dürüst; empty/loading/error var; 60fps; 375/768/1280 OK; regresyon yok.

**Test:** görsel = KPI/widget/player tüm kırılımlarda + boş-veri hali; kod = veri-bağlama doğru, regresyon, scope; güvenlik = escaping (kullanıcı içeriği captiona girer), signed-URL ihlali yok. **Koşullu:** `compliance-reviewer` (dürüst rozet gate).

**İnsan kapısı: EVET.** Reviewer: `ux-reviewer` (+ `compliance-reviewer`).

---

## 6. Faz 18 — Pipeline / Workflow Görselleştirme · token: `START PHASE 18`

**Amaç:** Üretim hattını v3'ün yaşayan workflow'una çevirmek; **gerçek job durumlarına** bağlı, teknik jargon sızdırmadan.

**v3 eşlemesi:**
- Node-graph: aralıklı kutular + bağlantı çizgileri. Tamamlanan = dolu yeşil; **aktif = soldan sağa fill-flow + öncü parlayan nokta** (stroke-dashoffset); bekleyen = sönük kesik çizgi. Aktif indeks **gerçek job state**'ten gelir.
- Durum simgeleri (yazı yerine): ✓ (tamam) / ⚡ (işleniyor, heartbeat) / kesik halka (sırada) — glow'lu.
- Aktif kutu: heartbeat glow + alttan dolum (opacity).
- **Kutuya tıkla → yan detay paneli**: aşamanın **sade** açıklaması, durumu, "Gelen → İşlem → Çıktı" akışı. **ffmpeg/TTS/queue gibi terim YOK.**
- Mobil: dikey **stacked kart** fallback.

**Kapsam dışı:** workflow **engine** değişikliği (sadece VISUALIZE eder, motor linear kalır), SSE canlı ilerleme (Faz 19), yeni node tipi.

**Dokunulan dosyalar:** pipeline/queue template'leri, node-graph partial, drawer içerik üreticisi, CSS, node-graph JS modülü (SVG çizim + tıklama). Veri = mevcut pipeline event log / job status. **Engine'e dokunulmaz.**

**Acceptance:** bir içeriğin gerçek pipeline durumu doğru yansıyor (tamam/aktif/sırada); fill-flow yalnız aktif segmentte; kutu tıklaması doğru sade paneli açıyor; jargon yok; mobil stacked çalışıyor; §1.2 ihlali yok; engine değişmemiş; regresyon yok.

**Test:** görsel = farklı pipeline durumlarında node-graph + drawer + mobil + reduced-motion; kod = state→görsel eşlemesi doğru, engine dosyaları değişmemiş, scope; güvenlik = (düşük) drawer içeriğinde escaping, jargon/iç-detay sızıntısı yok.

**İnsan kapısı: EVET.** Reviewer: `ux-reviewer`.

---

## 7. Faz 19 — Canlı Katman / SSE · token: `START PHASE 19`

**Amaç:** Faz 17–18'de kurulan statik bileşenleri gerçek dev-DB state'iyle **canlandırmak**. Tek gerçek backend yüzeyi — izole tutulur.

**v3 eşlemesi:**
- Pure-PHP **SSE** streaming endpoint + tek event arayüzünü paylaşan JS canlı-client (mock-first: client ister ticker ister gerçek SSE'den beslensin çalışır).
- Canlı: tıkan KPI'lar, akan aktivite akışı, canlı render-kuyruğu ilerlemesi, **nabız atan job durumu** (pipeline aktif kutusu), **topbar "NEXT UP — mm:ss"** geri sayım, topbar heartbeat noktası.
- Tenant-scoped stream; **yalnız kısa SQLite okuması** (stream'de uzun transaction YOK); zarif reconnect; JS/SSE yoksa **statik render'a düşer**.

**Kapsam dışı:** gerçek dış API çağrısı, websocket, yeni queue sistemi.

**Dokunulan dosyalar:** yeni SSE endpoint (PHP), canlı-client JS modülü, ilgili partial'lara hook'lar. Yeni route + okuma; **yazma yok.**

**Acceptance:** dashboard refresh'siz canlı güncelleniyor; SSE tenant-izole + güvenli; JS/SSE yokken statik çalışıyor; event-arayüzü + tenant-scope testleri var; uzun-transaction yok; regresyon yok.

**Test:** görsel = canlı güncelleme görünür + reduced-motion + no-JS fallback; kod = event arayüzü + tenant-scope testleri, kısa-transaction; güvenlik = **AĞIR** — tenant izolasyon, stream yetkilendirme, kaynak tüketimi/timeout, secret yok.

**İnsan kapısı: opsiyonel.** Reviewer: `security-auditor` + `ux-reviewer` (mandatory).

---

## 8. Faz 20 — Cila, Performans & Erişilebilirlik Kapanışı · token: `START PHASE 20`

**Amaç:** Experience katmanını üretime hazırlamak: performans doğrulaması, erişilebilirlik, dürüst-rozet ve güvenlik son geçişi.

**Kapsam:**
- **GPU/performans doğrulaması**: tüm Experience ekranlarında boşta GPU ~0, 60fps; §1.2 ihlali taraması (kalıcı backdrop, animasyonlu blur, dönen öğe = sıfır).
- **Erişilebilirlik**: WCAG AA kontrast (faint-tier `phase-15-followups.md` A11Y borçları dahil), klavye navigasyonu (⌘K, drawer, focus trap), `aria-current`/SR etiketleri, focus-visible.
- **Dürüst rozet** son denetimi (UI + her yer).
- **Güvenlik** son geçişi (Experience yüzeyleri).
- prefers-reduced-motion tam kapsama.

**Kapsam dışı:** yeni özellik.

**Acceptance:** perf hedefleri ölçülü karşılanıyor; a11y AA; tüm rozetler dürüst; güvenlik temiz; reduced-motion %100; regresyon yok.

**Test:** görsel = a11y/kontrast/focus + reduced-motion; kod = perf bütçesi + regresyon; güvenlik = **AĞIR** son denetim. **Koşullu:** `compliance-reviewer` (dürüst rozet gate).

**İnsan kapısı: opsiyonel.** Reviewer: `ux-reviewer` + `security-auditor` (+ `compliance-reviewer`).

---

## 9. Özet

- **6 token'lı adım:** 15.9 (altyapı) → 16 (motion) → 17 (dashboard) → 18 (pipeline) → 19 (canlı) → 20 (cila).
- **Loop:** `/goal` her fazı plan→kur→test(3 paralel gate)→verdict→branch-commit→/clear ile yapar; fail-cap 2 → hard-stop; insan kapısı 15.9/16/17/18'de zorunlu, 19/20'de opsiyonel.
- **v3 prototipi** repoya kopyalanıp her fazın görsel referansı olur; çelişkide token-isim ui-style-guide'dan, değer+davranış v3'ten.
- **Mimari dokunulmaz:** Pure PHP, build-free, vanilla JS, progressive enhancement, mobil fallback, dürüst rozet, jargonsuz UI, reduced-motion.

**Onaylanan kararlar (2026-06-13):** (1) teal accent global değişim — EVET; (2) Faz 15.9 ayrı altyapı fazı — EVET; (3) plan repoya entegre edildi (phase-plan.md + bu doküman + prototype-v3 + /goal komutu).
