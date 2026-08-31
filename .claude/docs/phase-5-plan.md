# Faz 5 Planı — Script & Caption Engine (ONAYLI — START PHASE 5 bekliyor)

> Plan-mode'da 2026-06-12'de kullanıcı tarafından onaylandı. İmplementasyon yalnızca
> `START PHASE 5` token'ı ile başlar. Bu dosya onaylanan planın tam kopyasıdır.

## Context (neden bu faz, neden bu kapsam)

Faz 0–4 kabul edilip commit'lendi (`f56d4ab` = Faz 4 HEAD); 285 test yeşil; auth/CSRF/
tenant-isolation + Content Library + Workflow Engine (SQLite job queue, worker, watchdog,
append-only event log, executor seam) canlı. **Hiçbir gerçek dış çağrı yok** — 13 job tipi de
tek `MockExecutor` ile koşuyor.

Faz 5, **executor seam'e ilk gerçek sağlayıcı adapter'ını** takıyor: 4 içerik job tipi
(`idea_generation`, `script_draft`, `caption_generation`, `hashtag_generation`) artık bir
**`TextProvider` soyutlamasının** arkasından üretilecek — varsayılan zengin deterministik mock,
opsiyonel gerçek OpenAI. Faz; versiyonlu prompt şablonları, **tohumlu varyasyon motoru** (slop
benzerliğini ölçülebilir biçimde düşüren hook/pacing varyasyonu — compliance çekirdeği) ve
job satırına maliyet kaydı getiriyor.

**Kullanıcı kararları (planlama oturumu, 2026-06-12):**
1. **UI = engine-only** — yeni sayfa YOK. Zengin script + platform-bazlı caption'lar + hashtag'ler
   mevcut `/queue` onay kartında ve `/runs/{id}`'de gösterilir; approve/reject aynı (Faz 4 minimal-UI
   çizgisi korunur).
2. **Gerçek OpenAI HTTP yolu KURULUR ama varsayılan KAPALI** — yalnız `OPENAI_MOCK=false` VE key
   varsa devreye girer; tüm testler mock'ta, CI'da sıfır ağ.
3. **İkinci sağlayıcı (Anthropic Claude) ERTELENDİ** — TextProvider sağlayıcı-agnostik kalır; Claude
   istendiğinde tek-sınıf opt-in olarak sonra eklenir.

**Migration YOK:** `result_json` (TEXT) tüm script/caption/hashtag/brief çıktısını tek JSON
nesnesinde tutuyor; maliyet `jobs.cost_cents` + `jobs.provider` sütunlarına yazılıyor (Faz 4 zaten
yazıyor). Kredi defteri/usage_events Faz 11'in işi — Faz 5 yalnız job satırına cent yazar.

## Kapsam (precise scope)

1. **TextProvider soyutlaması (`src/Content/`)**
   - `TextProvider` interface: `generate(string $kind, array $context, int $seed): TextResult`
     (`$kind` ∈ idea|script|caption|hashtag). Çekirdek vendor adı görmez (adapter kuralı).
   - `TextResult` VO: `{ data: array, provider: string, model: ?string, costCents: ?int }` — seam'i
     geçen tek şekil.
   - `PromptLibrary`: versiyonlu prompt şablonları (sabitler, `idea.v1`/`script.v1`/`caption.v1`/
     `hashtag.v1`). Versiyon string'i her job'un `result_json.prompt_version`'una + event'ine yazılır.
   - `VariationEngine`: tohumlu (run_id+step) varyasyon — hook havuzları, pacing/yapı kalıpları,
     kelime varyantları. Aynı tohum → aynı çıktı (deterministik); farklı tohum → farklı hook
     (ölçülebilir slop düşüşü). Hem mock hem gerçek sağlayıcı bunu kullanır (gerçekte seed/temperature
     + hook seçimi). **`asset shuffle` ERTELENDİ** (asset seçimi Faz 7).

2. **`MockTextProvider implements TextProvider`** — Faz 4 stub'larından zengin: idea trend'e atıf
   yapar, script (awaiting) word_count + tahmini süre taşır, caption 3 ayrı platform varyantı
   (instagram/tiktok/youtube), hashtag ≥N etiket. provider='mock', costCents=null (Faz 4 kuralı: mock
   maliyet gerçek harcama gibi sunulmaz). Faz 4'ün test ettiği SÖZLEŞMELERİ korur (idea↔trend, script
   awaiting, prior akışı).

3. **`OpenAiTextProvider implements TextProvider`** (gerçek, flag arkasında, varsayılan KAPALI)
   - **HTTP seam:** `HttpClient` interface (`post(url, headers, body, timeout): HttpResponse`) +
     `CurlHttpClient` (gerçek). OpenAiTextProvider HttpClient'a bağımlı → testler sahte transport
     enjekte eder, **ASLA ağa çıkmaz**.
   - POST `/v1/chat/completions` (stabil, belgelenmiş şekil): Bearer auth, messages dizisi,
     `choices[0].message.content`, `usage.{prompt,completion}_tokens`. Endpoint/model/fiyat **config'te**
     (varsayım kodda gömülü değil).
   - `CostCalculator`: `usage × config fiyat tablosu` → cost_cents. provider='openai', model config'ten.
   - **Hata durumları** (tümü kapsanır): timeout, non-200, 429 rate-limit, boş/bozuk yanıt, JSON decode
     hatası → tipli exception → ContentExecutor `JobResult::failed(temiz mesaj, 'openai')` → Worker
     backoff/retry. **Mesaj ASLA key/payload/header içermez** (Faz 4 followup).
   - **Input sanitization:** prior sonuçları (trend/idea metni) prompt'a gömülmeden önce uzunluk
     clamp + kontrol-karakter temizliği (prompt-injection yüzeyi; Faz 5'te trend mock olsa da kural
     bugünden uygulanır).

4. **`ContentExecutor implements JobExecutor`** — 4 içerik tipinin gerçek executor'ı. Bir TextProvider
   alır. job+prior → request kurar → provider çağırır → JobResult döndürür. `script_draft` →
   `awaitingApproval` (insan kapısı); diğer 3 → `ready`. cost_cents + provider sonuca yazılır. Tohum
   run_id+step'ten türetilir (MockExecutor ile tutarlı).

5. **Sağlayıcı seçimi + kayıt (`src/bindings/core.php` + yeni `config/openai.php`)**
   - `config/openai.php`: `mock` (bool, default true), `api_key`, `model`, `timeout`, `org_id`,
     `prices` (model→[in,out] cent/1M token). `.env.example`'a: `OPENAI_MOCK=true`,
     `OPENAI_API_KEY=`, `OPENAI_MODEL=...`, `OPENAI_TIMEOUT=30`, `OPENAI_ORG_ID=`.
   - Binding: `OPENAI_MOCK=false` VE key varsa → `OpenAiTextProvider`, aksi halde `MockTextProvider`.
     ContentExecutor seçilen provider'la 4 içerik tipine `register()` edilir; kalan 9 tip MockExecutor'da
     kalır. Swap = config-only (adapter kuralı).

6. **UI (yalnız mevcut sayfa zenginleştirme — yeni sayfa/route YOK)**
   - `/queue` onay kartı: script_draft için tam script (zaten var) + üretilen **per-platform caption'lar**
     + **hashtag'ler** + `prompt_version` rozeti (salt-okunur).
   - `/runs/{id}`: içerik job'larının result özeti (idea/script/caption/hashtag) timeline'la birlikte.
   - Job satırında provider rozeti zaten var ('mock'/'openai'); cost_cents varsa küçük "~$0.0x" notu
     (gerçek harcama dürüstlüğü). Yeni CSS minimum.

7. **Messages + olaylar:** yeni event key'leri (`job.generated` zaten `job.finished` ile karşılanır;
   gerekirse `content.prompt_version` info event'i). Tüm yeni flash/etiketler `Core\Messages` sözlüğünde.

8. **Testler (~55 yeni, hedef ≈340)** — gruplar:
   (1) MockTextProvider: 4 kind şekli; script awaiting; caption 3 ayrı platform; hashtag ≥N; deterministik.
   (2) VariationEngine: aynı seed→aynı hook; farklı seed→farklı hook (ölçülebilir benzerlik düşüşü);
   havuz kapsama.
   (3) PromptLibrary: versiyon key'leri var; versiyon result_json'a + event'e yazılır.
   (4) ContentExecutor: her tip doğru maplenir; cost_cents/provider; script awaiting; prior akışı
   (trend→idea→script: idea trend'e, script idea hook'una atıf).
   (5) OpenAiTextProvider (SAHTE transport, ağ YOK): 200 happy → TextResult; 429/timeout/non-200/bozuk
   JSON → tipli exception → JobResult::failed; **hata mesajında key YOK** assert'i; cost = usage×fiyat.
   (6) Sağlayıcı seçimi: OPENAI_MOCK=true/key-yok → Mock seçilir; false+key → OpenAi seçilir (çağırmadan).
   (7) Registry swap: ContentExecutor 4 tipte, MockExecutor 9 tipte; uçtan uca full run zengin script +
   caption + hashtag üretir, script onayında + render review'da durur, completed olur.
   (8) Faz 4 kırılgan assert güncellemeleri (eski "Mock script" exact-string'leri yeni deterministik
   şekle; sözleşmeler korunur).
   (9) Sıfır ağ grep'i hâlâ temiz; tüm testler mock'ta.

### Yeni dosyalar
`src/Content/{TextProvider, TextResult, PromptLibrary, VariationEngine, MockTextProvider,
OpenAiTextProvider, CostCalculator, ContentExecutor}.php` · `src/Http/{HttpClient, CurlHttpClient,
HttpResponse}.php` · `config/openai.php`

### Değişen dosyalar
`src/bindings/core.php` (provider seçimi + ContentExecutor kaydı) · `.env.example` (OpenAI key'leri) ·
`templates/queue/index.php` + `templates/runs/show.php` (caption/hashtag/prompt_version gösterimi) ·
`src/Core/Messages.php` (gerekirse) · `public/assets/css/app.css` (minik) · `tests/run.php` ·
`src/Workflow/MockExecutor.php` (4 içerik case'i ContentExecutor'a devredildiği için sadeleşir —
ya kaldırılır ya da fallback olarak bırakılır; karar implementasyonda, davranış değişmez).

## Non-goals (açıkça DIŞARIDA)

TTS / video / trend (plan) · **awaiting_recording + shooting-brief PAUSE akışı** (ERTELENDİ —
trend'ler "face" format önermeden tetikleyici yok; brief METNİ script result'ında görünebilir ama
pause-status kod yolu / "mark recorded" kapısı Faz 6/7) · `/studio` UI (engine-only kararı) ·
**Anthropic Claude adapter'ı** (ertelendi, tek-sınıf sonra) · usage_events ledger / kredi-gating
(Faz 11; yalnız cost_cents job satırına) · reject-to-revise döngüsü (hâlâ reject=cancel) ·
asset shuffle varyasyonu (Faz 7) · auto-approval / compliance skorlama (Faz 9) · genel amaçlı
"Create composer" çoklu-mod UI'sı (engine hazır; UI yüzeyi sonraki faz).

## Build sırası

1. TextProvider + TextResult + PromptLibrary + VariationEngine → unit testler
2. MockTextProvider → testler (+ Faz 4 kırılgan assert güncellemeleri)
3. HttpClient seam + OpenAiTextProvider + CostCalculator + tipli hatalar → sahte-transport offline testler
4. ContentExecutor → testler
5. config/openai.php + .env.example + bindings (provider seçimi + kayıt) → seçim testleri
6. /queue + /runs zenginleştirme + Messages + minik CSS
7. Uçtan uca testler (mock full run zengin çıktı) → manuel tur → reviewer'lar
   (security-auditor + integration-reviewer + php-architect; ux hafif) → followups + checkpoint + VERDICT

## Kabul kriterleri (ölçülebilir)

- [ ] `bin/worker.php` ile full run, 4 içerik node'u için gerçek-şekilli çıktı üretir: idea trend'e
      atıf yapar; script draft (awaiting) word_count + tahmini süre; caption 3 AYRI platform varyantı;
      hashtag ≥3. /queue + /runs'da görünür.
- [ ] Sağlayıcı soyutlaması: ContentExecutor bir TextProvider kullanır; mock↔openai geçişi **config-only**
      (`OPENAI_MOCK` + key); çekirdekte openai adapter + config dışında vendor referansı YOK (grep).
- [ ] Tohumlu varyasyon ölçülebilir: aynı trend'in iki run'ı farklı hook üretir (test); aynı tohum
      birebir tekrar üretir (deterministik).
- [ ] Gerçek OpenAI yolu flag arkasında, **varsayılan KAPALI**, tam hata yönetimiyle
      (timeout/429/non-200/bozuk → failed → backoff); sahte transport'la kanıtlı; **hata mesajı key
      içermez** (test).
- [ ] Versiyonlu prompt: her içerik job'u `prompt_version`'u result_json'a + event'e yazar.
- [ ] Maliyet: gerçek yol usage×config-fiyat → cost_cents; mock → provider 'mock', cost null;
      `jobs.cost_cents`'e yazılır.
- [ ] Sıfır ağ çağrısı (grep: tüm testler mock); ≈340 test PASS; lint temiz; secret yok;
      security-auditor + integration-reviewer + php-architect review tamam.

## Manuel test adımları

`php bin/migrate.php` → login → /workflows → full run başlat →
`php bin/worker.php --once` tekrarları → /queue: script onayında durur, kart artık per-platform
caption + hashtag + prompt_version gösterir → approve → worker → render review → approve →
published; /runs/{id}'de içerik özeti + timeline. **Opsiyonel gerçek yol** (kullanıcı isterse):
`.env`'de OPENAI_MOCK=false + OPENAI_API_KEY=... → tek run → cost_cents > 0 ve provider 'openai'
(varsayılan akış mock'tur).

## Riskler

1. **Gerçek API şekil/model/fiyat sürüklenmesi** → endpoint+model+fiyat config'te, varsayılan mock,
   sahte-transport testleri; tahmini değer mantıkta gömülü değil.
2. **Prompt injection (prior trend/idea metni)** → input sanitization (uzunluk clamp + kontrol-karakter);
   Faz 5'te düşük risk ama kural bugünden.
3. **Key/hata sızıntısı** → temiz JobResult::failed mesajları; ham payload/header asla loglanmaz;
   security-auditor kapısı.
4. **Faz 4 test kırılması (zengin mock)** → kırılgan exact-string assert'leri güncelle, SÖZLEŞMELERİ
   koru (idea↔trend, script awaiting, prior akışı).
5. **Varyasyon motoru gerçekten benzerliği düşürmüyor** → ölçülebilir test (farklı seed→farklı hook +
   benzerlik metriği).
6. **Studio/recording'e kapsam sızması** → açıkça ertelendi; reject=cancel değişmez; non-goal'lar
   raporda yinelenir.

## Açık sorular

Yok — üç fork (UI footprint, gerçek-çağrı yolu, ikinci sağlayıcı) kullanıcıyla netleştirildi
(engine-only · gerçek yol flag-kapalı · Claude ertelendi).

## Token

Bu faz yalnızca kullanıcı **`START PHASE 5`** yazınca başlar. Token gelene kadar hiçbir implementasyon
yapılmayacak; plan onayı dahil hiçbir genel onay ifadesi kodu açmaz.
