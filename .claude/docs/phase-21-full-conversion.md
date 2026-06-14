# Kuyash — Faz 21: Tam Deneyim Dönüşümü (TEK FAZ, TÜM EKRANLAR) · token: `START PHASE 21`

> **Amaç:** Uygulamanın **TAMAMINI** onaylı v3 prototipine (`prototype-v3.html`) dönüştürmek. Tek faz, tek koşu,
> tek inceleme. Önceden planlanan her şey (16–20) + denetimde bulunan tüm açıklar bu faza sığar. Bitince sen
> baştan sona gezersin; "her ekran v3 gibi mi, jargon var mı, hesap kartları canlı mı" → evet olacak.
>
> **Tek hedef (acceptance'ın özü):** 12 ekranın HER BİRİ v3 görsel diline uyacak, HİÇBİR yerde teknik terim
> olmayacak, hesap kartları video+etkileşim+takipçi gösterecek, defektler kapanacak.
>
> **Sınır:** salt sunum + i18n + mock veri. Workflow engine, route, DB şeması, gerçek API → DOKUNULMAZ.
> Mantık aynı; yalnız görünüm, metin, motion, ve mock-veri widget'ları değişir. 732+ test yeşil kalır.

---

## 0. GLOBAL — her ekrana uygulanır

**Tasarım dili (v3 tokenları zaten inmiş):** teal accent, statik ambient gradient zemin, kart gradient'leri,
gradient başlık tipografisi, glow/heartbeat (sadece on-demand).

**Motion (GPU-light — sadece `transform`/`opacity`/`stroke-dashoffset`):** sayfa geçişleri, scroll-reveal
(stagger), hover-lift kartlar, count-up sayılar, heartbeat (dönen öğe YOK, kalıcı backdrop YOK, animasyonlu
blur YOK). `prefers-reduced-motion` hepsini sıfırlar.

**Shell (her ekranda):**
- **Sidebar:** kayan-pill aktif nav, hover'da hafif kayma, ikonlar.
- **Topbar:** workspace switcher, **canlı heartbeat noktası**, ⌘K arama, EN/TR, avatar.
- **⌘K komut paleti** + **global yan panel (drawer)** her ekranda erişilebilir.

**SIFIR JARGON (her ekran, tam eşleme tablosu):**

| Şu an (jargon) | Olacak (kullanıcı dili) |
|---|---|
| `Arka plan işçisi çalışmıyor… php bin/worker.php` | Normal kullanıcıya gösterme (yalnız `APP_ENV=dev`); görünürse "İşlemler şu an duraklatıldı." |
| `yayın: mock` / `kaynak: mock` / `mock` etiketleri | Kaldır (mod sızdırma yok) |
| `render_review` | "Önizleme onayı" |
| `script_draft` | "Senaryo taslağı" |
| `script.v1` | Kaldır |
| `Render review (mock): compliance pass (policy mock-v0)` | "Uyumluluk: geçti" |
| `COMPLIANCE kilitli` | "Uyumluluk zorunlu" + kilit ikonu |
| `Faz 4'te salt-okunur` | "Şimdilik salt görüntüleme" |
| `12 düğüm` / `0 düğüm` | "12 adım" |
| `bugün yayınlanan: 0/2` | "Bugün: 0 / 2 yayın" |
| `çalışma #6` / `içerik #11` | "İçerik #11" (net etiket) |
| `~$7.02 tahmini` | "Tahmini maliyet: $7.02" |

**Dürüst rozetler:** "Senin onayınla" vs "Uyumluluk ajanı otomatik onayladı" — asla karıştırılmaz. AI-etiket dili korunur.

**Responsive:** 375 / 768 / 1280 her ekranda; node-graph mobilde dikey stacked.

---

## 1. HESAP WIDGET'I — (senin asıl istediğin bileşen, her yerde kullanılır)

Yeni `templates/partials/account-card.php` — v3 `.acc` bileşeni. Her bağlı hesap için:
- Üstte hesabın **son paylaşılan videosu** önizlemesi (yavaş ken-burns, transform-only; medya yoksa zarif poster).
- Profil avatarı + **@kullanıcı** + platform rozeti (Instagram/TikTok/YT).
- Caption satırı.
- Alt şerit: **♥ like · 💬 comment · ↗ share** simge + sayıları.
- En altta: **takipçi sayısı + büyüme delta** (▲ +N bugün) + sağlık durumu.
- Kullanıldığı yer: **Dashboard "Bağlı Hesaplar"** + **/accounts** sayfası.
- Veri: metrikler `account_metrics` mock-seed'den (gerçek API go-live'da); yoksa "veri yok".

---

## 2. EKRAN EKRAN — ne değişecek

### 2.1 Panel / Dashboard (`/dashboard`)
- KPI'lar + pipeline node-graph + drawer **zaten var, korunur.**
- **EKLE:** Bağlı Hesaplar bölümü → düz liste yerine **canlı-akış hesap kartları** (§1).
- **EKLE:** Onay kartlarına **inline oynatıcı** (mock medya → play kart-içinde oynar, ilerleme çubuğu, "Oynuyor" rozeti).
- **EKLE:** görünür **canlılık** — KPI tık / aktivite akışı / topbar **"SIRADAKİ — mm:ss"** geri sayım gerçekten tıksın.
- **KALDIR:** worker jargon banner'ı (dev-only).

### 2.2 Hızlı Oluştur (`/quick`)
- v3 kart düzeni, hover/reveal, gradient başlık.
- Yükleme alanı v3 dropzone; "AI etiketi her zaman açık" dürüst rozet korunur.
- Maliyet tahmini kullanıcı diline; empty/loading/error halleri.

### 2.3 Trend Radarı (`/trends`)
- v3 trend kartları: **büyük gradient skor sayıları**, hover-lift, stagger-reveal.
- "Trendden oluştur" birincil buton (glow).
- **KALDIR:** `kaynak: mock`, `mock` etiketleri.
- Opsiyonel: üstte hafif, duraklatılabilir trend ticker.

### 2.4 Kütüphane (`/library`)
- v3 asset grid: hover-lift kartlar, video asset'lerde play affordance, 9:16 rozet.
- Filtre çipleri (Tümü/Kendi/Yüz/Stok/AI) v3 stilinde aktif-pill.
- v3 dropzone + boş durum ("henüz içerik yok") tasarımı.

### 2.5 İş Akışları (`/workflows`)
- Workflow kartları v3'e; **"12 düğüm" yerine mini node-graph önizlemesi** (node-graph bileşeni, salt-okunur).
- **KALDIR:** "Faz 4", "düğüm"; "COMPLIANCE kilitli" → "Uyumluluk zorunlu" + kilit.

### 2.6 Kuyruk (`/queue`)  *(en yoğun ekran)*
- Onay kartları v3'e: **inline oynatıcı** (mock medya), durum rozetleri, dürüst onay etiketleri.
- **DÜZELT:** kırık kırmızı blok → düzgün v3 eksik-medya/önizleme placeholder'ı.
- **KALDIR:** worker banner + `mock` + `policy mock-v0` + `render_review` + `script_draft` + `script.v1` jargonu.
- Tarih input'u (zaten dark-uyumlu) v3 ile hizalı.

### 2.7 Hesaplar (`/accounts`)
- Hesap listesi → **canlı-akış hesap kartları** (§1).
- Bağla akışı (Instagram/TikTok/YouTube) v3 buton/kart stiliyle.
- **KALDIR:** `yayın: mock`, `0/2` jargonu → "Bugün: 0 / 2 yayın".

### 2.8 Kayıtlar (`/logs`)
- Aktivite akışı → v3 zaman-çizgisi/feed: durum noktaları, kullanıcı-dili olay metni (iç kod yok).
- SSE varsa canlı akış (yeni olaylar üstte belirir).

### 2.9 Özet (`/digest`)
- Günlük özet → v3 özet kartları, gradient başlık, net metrik blokları.

### 2.10 Kullanım (`/usage`)
- Kredi/maliyet → v3 KPI + sade grafik stili, kullanıcı-dili maliyet metni.

### 2.11 Ayarlar (`/settings`)
- Formlar → v3 bölümlü kartlar, stillenmiş toggle/switch, net etiketler.

### 2.12 Giriş (`/login`)
- v3 markalı giriş: gradient zemin, teal, premium ilk-izlenim. (İlk gördüğün ekran.)

---

## 3. Defektler (kapanacak)
- Kuyruk kırık kırmızı blok → v3 placeholder.
- Inline oynatıcı görünür (mock medya seed'i).
- SSE canlılık → en az bir görünür tıkan sinyal; çalışmıyorsa onar.

---

## 4. Acceptance (bitti sayılma kriteri — sen gezerken doğrulanacak)
1. 12 ekranın HER BİRİ v3 görsel diline tutarlı (statik bakışta bile premium).
2. HİÇBİR ekranda teknik terim yok (worker/mock/policy/render_review/script_draft/Faz/düğüm/komut).
3. Hesap kartları **video + like/comment/share + takipçi büyüme** gösteriyor (mock).
4. Inline oynatıcı çalışıyor; kırık kırmızı blok gitti; en az bir canlı sinyal tıkıyor.
5. ⌘K + drawer + motion her ekranda; 375/768/1280 OK; reduced-motion hepsini sıfırlıyor.
6. Dürüst rozetler korunmuş; 732+ test yeşil; engine/route/DB/gerçek-API dokunulmamış.

## 5. Gate'ler + çalışma modu
- **ux-reviewer:** 12 ekranı 375/768/1280 × EN/TR screenshot → v3 uyumu + **jargon taraması (sıfır)** + defekt yok + canlılık.
- **qa-reviewer:** regresyon (732+), scope-creep yok, build-free/vanilla-JS korundu.
- **security-auditor:** SSE/medya yüzeyi, escaping, secret yok.
- **compliance-reviewer:** dürüst rozet + AI-etiket dili.
- **İnsan kapısı: EVET (tek, sonda).** Bu faz `/go` sürekli-otonom ile DEĞİL, `START PHASE 21` ile koşulur;
  CC bitirince tüm ekranların screenshot'ını + verdict verir, sen baştan sona gezip onaylarsın.

## 6. Bu tek fazda topladıklarım (eski 16–20 + 21–23 + denetim açıkları)
Motion/⌘K/drawer/count-up (eski 16) · KPI + **hesap widget'ları** + inline player (eski 17, eksikti) · pipeline
viz + drawer (eski 18) · SSE canlılık (eski 19) · perf/a11y/reduced-motion (eski 20) · **jargon-temizliği** +
**iç ekranların TAMAMI** + defekt düzeltme (denetim açıkları). Hepsi tek faz, tek inceleme.
