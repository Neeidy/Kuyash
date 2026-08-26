# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-08-25
- Güncelleyen: Claude (**FAZ 25 KAPANIŞA GELDİ — 1048 PASS / 0 FAIL, COMMIT YOK, KABUL BEKLİYOR.**
  Faz 25 = **onay adımında caption + hashtag'i insanın elle düzenleyebilmesi** — distribution-only
  senaryonun en değerli eksiği. Token `START PHASE 25` VERİLDİ; Görev 0–6 TAMAM.
  **Tasarım otoritesi: `.claude/docs/phase-25-plan.md`**, kararlar **ADR-023**, ertelenenler
  `phase-25-followups.md`. Faz 24 `c62d640`'a kadar PUSH'LU — o iş KAPALI.
  **Çekirdek fikir:** düzenleme `jobs.result_json`'da `captions`/`hashtags` anahtarlarının ÜZERİNE
  yazılır (publish zaten orayı okuyor → "üretileni yayınlama" hatası yapısal olarak imkânsız), AI
  orijinali `captions_ai`'de korunur, `compliance_check` sonucu HİÇ ellenmez, **migration YOK**.
  AI ifşası publish anında kompoze edilir (`Publish\Disclosure`) → hiçbir edit onu sıyıramaz.
  Kapı iki noktalı: kaydetmede `ContentGate` (aynı SlopScorer + aynı eşikler), publish'te içerik-hash
  (uymazsa `failedPermanent`). Limitler **warn-only ve DOĞRULANMAMIŞ**; tek blok = bağlı platformda
  boş caption. Düzenleme penceresi: `render_review awaiting_approval` **VEYA `final_render`
  queued/processing** VEYA `publish queued`.
  **Kapanış turunda eklenen:** uyumluluk/benzerlik rozeti, düzenleme varsa
  `edit.verdict`'ten türetiliyor (kuyruk + panel + run ekranı AYNI değeri okur) —
  yayın butonunun yanındaki sayı artık yayınlanacak metne ait; `compliance_check`
  kaydı ELLENMİYOR. "Uyarıldı" ile "fazla benziyor" ayrıldı (etiket sayısı uyarısı
  "benzerlik 0.00" diye çıkıyordu = yanlış kontrolü adlandırıp anlamsız sayı basmak).
  Rozeti düzeltmek TEK BAŞINA yetmedi: kartta 4 satır aşağıda taslağın verdict'inden
  gelen ikinci bir "Uyumluluk: geçti" cümlesi vardı → düzenleme varsa bastırılıyor
  (AI etiketi satırı KALIYOR, o medyaya ait). Rozet artık HER run'da var — düzenleme
  hangi verdict'in geçerli olduğunu değiştirir, kontrol edilip edilmediğini değil.
  **KALAN: kullanıcı kabulü → commit + push.** Reviewer durumu ve kapanış kanıtı "Sıradaki adım"da.)

## Mevcut durum (kaldığımız yer)

- Aşama: **FAZ 25 TAMAMLANDI, KABUL BEKLİYOR — COMMIT YOK, 34 dosya working tree'de. 1048 PASS / 0 FAIL.**
  Görsel gate **93 PNG / 0 console error / 0 yatay taşma**; 12 nav route + `/runs/22` + `/runs/11` canlı
  200; secret scan temiz; lang paritesi **822 = 822**.
  **Yeni dosyalar:** `config/platforms.php`, `src/Content/{PlatformLimits,ContentRevision,TextEditorView,DraftStash}.php`,
  `src/Compliance/ContentGate.php`, `src/Publish/Disclosure.php`, `src/Controllers/ContentController.php`,
  `templates/partials/text-editor.php`, `.claude/docs/phase-25-{plan,followups,open-work}.md`.
  **Değişen:** `ZernioPublishExecutor` (hash guard + boş-caption koruması + `withDisclosure` delegasyonu),
  `Core/Database` (**`immediateTransaction`**), `Content/Sanitizer` (bozuk UTF-8 satırı artık boşaltmıyor),
  `Queue/WorkflowController`, `routes.php` (`POST /runs/{id}/text`, `/text/restore`), `bindings/{core,web}.php`,
  `templates/{queue/index,runs/show}.php`, `public/assets/{css/app.css,js/app.js}`, `bin/visual-seed.php`,
  `tools/visual/routes.json` (`/runs/2` + `/runs/3` + `/runs/4`), `Controllers/DashboardController`, `templates/dashboard.php`, `lang/{en,tr}.php`, `tests/run.php`.
- **Üç kapanış gate'i ilk turda NO-GO döndü; hepsinin bulguları kapatıldı** (ayrıntı → `phase-25-open-work.md`).
  En ağır üçü: (1) **reddedilen kayıt yazılanı çöpe atıyordu** — bir platformun boş olması yüzünden ÜÇ gövde
  ve etiketler birden siliniyordu → yeni `DraftStash` (tek sayfalık, **workspace + run** anahtarlı; run id'leri
  workspace başına yeniden başladığı için yalnız run yetmiyordu); (2) **salt-okunur editör yayınlanmış bir
  paylaşımı BUGÜNKÜ Ayarlar'la anlatıyordu** — toggle çevrilince geçmiş yeniden yazılıyordu → iş bittiyse
  editör ifşa hakkında HİÇBİR ŞEY iddia etmiyor; (3) **Kaydet-vs-Onayla tuzağı** + kaydettikten sonra bile
  "Kuyash'ın yazdığı çıkar" diyen yanlış cümle → `$text['edited']`'e göre dallanıyor.
- **Güvenlik kritik:** eski deferred `BEGIN` WAL'de worker ile çakışınca `BUSY_SNAPSHOT` → **500 + yazılan metin
  kayıp** (reviewer bunu makinede kanıtladı) → `Database::immediateTransaction` + 2 denemelik retry + dürüst
  "yeniden yükle" mesajı. Ayrıca edit hash'i artık **yalnız gerçekten yazılanı** kapsıyor ve ikinci CAS'ın
  `rowCount()`'u kontrol ediliyor (aksi halde publish, operatörü yapmadığı bir kurcalamayla suçluyordu).

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

0. **FAZ 25 — KULLANICI KABULÜ BEKLİYOR. Kabul gelirse: commit (feat + chore ayrımı) → `git push origin main`.**
   Kabul beklemeden commit YOK (token talimatı). Commit sonrası phase-plan/checkpoint zaten güncel.
   Kapanış kanıtı: **1048 PASS / 0 FAIL**, görsel gate 93 PNG / 0 hata / 0 taşma, 14 canlı route 200,
   secret temiz, lang 822=822. Testlerde gerçek yayın YOK, `.env` flip YOK, migration YOK.
   **Reviewer'lar: üçü de GO** (security-auditor, ux-reviewer, compliance-reviewer). İlk tur
   security GO+2 MEDIUM, ux NO-GO, compliance NO-GO idi; hepsi kapatıldı ve üçü yeniden koştu.
   Ne bulundu / nasıl kapandı → `.claude/docs/phase-25-open-work.md` (kapanış kaydı). Ertelenenler → `.claude/docs/phase-25-followups.md`
   (en önemlisi: **JS kapalıyken Onayla hâlâ kaydedilmemiş metni sessizce atar** — sunucu tarafı kemer
   ayrı bir iş; ve **hash uyuşmazlığı kalıcı**, operatöre düzeltme yolu yok).
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

- 2026-08-25 — **FAZ 25 (onay adımında caption+hashtag düzenleme) TAMAM — 1048 PASS/0 FAIL, görsel gate 93 PNG/0 hata, 14 route canlı 200. COMMIT YOK, kabul bekliyor.** Ara verilen turdan devam: önce kırık tek testin ortaya çıkardığı **gerçek ürün boşluğu** kapatıldı — düzenleme penceresi `final_render` queued/processing'i de kapsıyor (o adım videoyu render eder, metne HİÇ dokunmaz, publish job'ı henüz doğmamıştır; dışarıda bırakmak "onayladın, şimdi birkaç dakika yazım hatası düzeltemezsin" demekti ve bunu ekranda açıklayan hiçbir şey yoktu). Sonra üç gate'in kalan bulguları. **En ağır üçü:** (1) **reddedilen kayıt yazılanı yok ediyordu** — POST→redirect→GET, GET saklanan metni yeniden basıyor; bir platformun boş olması yüzünden ÜÇ gövde ve etiketler birden gidiyordu, geri alma yok → yeni `Content\DraftStash` (tek sayfalık, **workspace + run** anahtarlı; yalnız run ile anahtarlamak workspace başına yeniden başlayan id'ler yüzünden başka workspace'in taslağını gösterebiliyordu — testte yakalandı). Yalnız GÖRÜNEN değerler değişir; `hash`/`edited`/`edit` hâlâ veritabanını anlatır, yani kaydedilmemiş metin asla kaydedilmiş gibi sunulmaz. (2) **salt-okunur editör yayınlanmış paylaşımı BUGÜNKÜ Ayarlar'la anlatıyordu** — Instagram ifşa toggle'ı sonradan kapatılınca ifşayla çıkmış bir post "eklenmeyecek" diyordu, açılınca hiç eklenmemiş olan "eklendi" diyordu → iş bittiyse editör ifşa hakkında hiçbir şey iddia etmiyor, geçmişi `posts.ai_label_applied` taşıyor. (3) **Kaydet-vs-Onayla tuzağı**: onay formuna dirty-guard + JS'siz de görünen statik satır; ayrıca kaydettikten SONRA bile "Kuyash'ın yazdığı çıkar" diyen cümle `$text['edited']`'e göre dallandı. **Güvenlik (reviewer makinede kanıtladı):** eski deferred `BEGIN` WAL'de worker commit'iyle çakışınca `BUSY_SNAPSHOT` → **500 + yazılan metin kayıp**, ve `busy_timeout` bunu kapsamıyor → `Database::immediateTransaction` + 2 denemelik retry + dürüst "yeniden yükle". Edit hash'i artık yalnız GERÇEKTEN yazılanı kapsıyor ve ikinci CAS'ın `rowCount()`'u kontrol ediliyor (aksi halde publish operatörü yapmadığı kurcalamayla suçluyordu). **Compliance:** geçen düzenleme de artık denetime yazılıyor (`content.edit_checked`, skor + politika sürümü); geri alma `content.restored` olarak ayrı kaydediliyor (eskiden "sen düzenledin" diyordu); seed'de yayınlanmış run'ın COMPLIANCE'ı "pending" görünüyordu (olamayacak bir sonuç) ve `render_review` yanlış node'daydı ('PREVIEW' → engine'in kullandığı 'PUBLISH'). **Kendim bulduğum:** "TikTok ve YouTube'da not native bayrakla verilir" satırı toggle'ı KAPALI platformları da sayıyordu = yanlış güvence → yalnız etkin olanları adlandırıyor. Seed artık düzenlenmiş + limite yakın bir run da içeriyor (chip, geri-al butonu, uyarı callout'u, 14/15 etiket sayacı ilk kez fotoğraflandı). Ertelenenler `phase-25-followups.md`'de, en önemlisi: **JS kapalıyken Onayla hâlâ kaydedilmemiş metni sessizce atar** (sunucu kemeri ayrı iş) ve **hash uyuşmazlığı kalıcı** (operatöre düzeltme yolu yok).


- 2026-08-23 — **FAZ 24: HAFTALIK PLAN = TAKVİM + İKİ MOD — 994 PASS/0 FAIL (+53), görsel gate 75 PNG/0 hata, 12 route canlı 200.** Faz 23'ün haftalık ŞABLONU hiçbir şey tutamıyordu; Faz 24 tarihli hücreyi (`slot_occurrences`, kimlik = saat × YEREL gün) ve saat-başına modu ekliyor. **Görev 0 RİSK SPIKE önce, ürün kodu yazmadan:** DST'yi aşan NY Çar 09:00 → `13:00Z` → `publish_after` → `run_after` gate → adapter'a birebir aynı `scheduledFor` (mevcut kodla, uçtan uca kanıt). **Taşıyıcı karar:** `publish_after` run DOĞARKEN yazılır — `approve()` saati yalnız yazar, asla silmez, ve otomatik-onay yolu `approve()`'dan hiç geçmez; onayda yazsaydık otomatik onaylanan planlı içerik slotunu yok sayıp hemen yayınlardı. `startRun` → `startRunFor(int $wsId,…)` delegasyonu (worker sessionless KALDI). `PlanRunner` chore claim'den ÖNCE koşar (3 gün kapalı worker eski yayınları kapatmalı, ateşlememeli); engel KAPATILMAZ, NOT EDİLİR. **3 GATE DE NO-GO; hepsi aynı turda kapatıldı (12 `p24/gatefix` testi).** Üçünün de bulduğu kritik: saat silmede `committedForSlot` `publish_at > now` filtreliyordu → grace penceresindeki gün onaysız silinip run'ı iptal edilmiyordu → geride geçmiş `publish_after` taşıyan run kalıyor, kuyruk "hemen yayınla" okuyor → SİLİNMİŞ bir saatten, plan kaydı olmadan anında yayın (Faz 23'ün KRİTİK sınıfı). Ayrıca: yayınlanmış gün `missed` süpürülüp denetime SAHTE hata yazıyordu; board `now`'dan pencereliyordu → açıklama gereken tek gün kayboluyor, dashboard "kaçtı" sayacı asla sıfırdan çıkamıyordu; her `skipped` kırmızı "Kaçtı" idi (operatörün temizlediği gün + görevini yapan guardrail dahil); yakalanmayan `PlanRunner::tick()` worker'ı claim'den ÖNCE öldürüp TÜM yayını sessizce durdurabiliyordu; sıradan eski kütüphane videosunu silmek FK'ye çarpıp 500 veriyordu; onay bildirimi PLANIN anını söylüyordu (run'ınkini değil) ve tekrar-POST `publish_now` reddedilen kararda state değiştirebiliyordu; takvim zaten SELECT ettiği gerçek kuyruk gate'ini yok sayıyordu; compliance blok gerekçesi format bloklarını slop diye adlandırıyordu. **Kendim bulduğum 2 kusur:** saat silmek FK ihlaliyle 500 (occurrence'lar) ve **`.sr-only` CSS'te hiç tanımlı değildi** — Faz 23'ten beri "gizli" etiketler tam görünüyordu; ayrıca `input[type=number]` stil listelerinde yoktu (Faz 15 drift'i). **ONAY ZAYIFLATILMADI:** `script_draft` insan kapısı kaldı, ADR-015 kapsamı genişletilmedi, `approval_mode` varsayılanı `manual`. Dev DB 0017 (WAL-safe yedek, 0 FK ihlali).

- 2026-08-23 — **FAZ 23: PLANLI PAYLAŞIM (haftalık slot) — 924 PASS/0 FAIL (+28), görsel gate 69 PNG/0 hata/0 taşma, route 12/12 200.** Premis doğrulandı: tek-anlık zamanlama ZATEN uçtan uca çalışıyordu (onay → `runs.publish_after` → kuyruğun `run_after` gate'i → adapter `scheduledFor`), eksik olan tekrarlı plandı → bu faz onun ÜSTÜNE kuruldu, **ENGINE'E DOKUNULMADI**. **Yeni:** migration **0016** `publish_slots` (workspace_id, ops. account_id, weekday 1-7 ISO, time_hhmm 'HH:MM', enabled; UNIQUE `COALESCE(account_id,0)` çünkü SQLite NULL'ları ayrı sayar) + `workspaces.timezone`; **`SlotResolver`** (SAF: saat okumaz, DB'ye bakmaz — "Pzt 09:00 <dilim>" → sonraki UTC anı; **DST-doğru**: gün kaydırmasından sonra duvar-saati YENİDEN uygulanır, canlı kanıt NY kış `14:00Z` / yaz `13:00Z` / DST'yi AŞAN hafta `13:00Z` yani yerel 09:00 korunuyor); **`SlotRepository`** (tenant-scoped CRUD, başka workspace'in hesabına daraltma REDDEDİLİR); `WorkspaceSettings::timezone/setTimezone` (tzdata doğrulamalı). **UI:** /settings "Haftalık yayın planı" kartı (dilim seçici + slot listesi "sıradaki 15 sa içinde" + Duraklat/Kaldır + ekleme satırı), /queue onay formunda slot seçici (varsayılan "Onaylanır onaylanmaz yayınla"), dashboard "Sıradaki yayın" bandı + canlı geri sayım (**Faz-10 ertelemesi kapandı**; geri sayım data-* attribute'larından okur → i18n tek kaynak, JS kapalıyken sunucu ifadesi kalır). **BULUP DÜZELTTİĞİM TUTARSIZLIK:** zaman-dilimsiz `datetime-local` sessizce UTC sanılıyordu — workspace UTC+3 iken 09:00 yazan operatör 12:00 yerel saatte yayınlardı; artık slot da manuel giriş de workspace dilimini kullanıyor, etiket gerçek dilimi söylüyor ("saatler Europe/Istanbul"). **Görsel gate 375px'te 10px taşma YAKALADI** → tahmin etmek yerine tarayıcıda DOM zinciri ölçüldü → kök-neden `.approve-card__actions` `flex:none` (küçülemiyor, parent 317px < içerik 343px) → `max-width:100%` → ölçümle temiz (scrollWidth 375 = viewport). **KAPSAM DIŞI (gerekçeli):** adapter `timezone:'UTC'` hardcode'u KALDIRILMADI — `publish_after` zaten UTC instant, UTC+UTC tutarlı ve doğrulanmış; workspace dilimini adaptöre taşımak Zernio'nun doğrulanmamış scheduledFor+timezone semantiğine girip yanlış saatte yayın riski yaratırdı (integrations "never hallucinate"). Per-account farklı saat de kapsam dışı (engine fan-out) — şema `account_id` ile hazır. `bin/visual-seed.php`'ye 3 slot + dilim eklendi (slot = operatör yapılandırması, uydurma metrik değil).

- 2026-08-23 — **FAZ 22 DÜZELTME TURU (yeni faz değil): 2 bug kapatıldı — 892 PASS/0 FAIL (+19).** **BUG1 nav pill rebound — İLK FIX YANLIŞ HEDEFLENMİŞTİ.** Gerçek kök-neden tarayıcıda ÖLÇÜLDÜ: Kuyash MPA → her nav tıklaması = tam sayfa yükleme → pill JS ile `translateY(0)`'da (en üst) doğuyor, sonra aktif item'a taşınıyor; base CSS'te transform transition ARMED olduğu için bu **başlangıç yerleşimi animasyona dönüşüyordu**. Kanıt (/settings, fix öncesi): aktif `offsetTop=351`, pill `translateY(0)`, `getAnimations()` → transform transition `playState:"running"`, `duration:250ms`. Yani gösterge her tıklamada yukarıdan aşağı uçuyordu = kullanıcının "başa atıp tekrar geliyor" şikayeti. Easing swap (`--spring`→`--ease-out`) bunu ASLA çözemezdi çünkü sorun eğri değil, **ilk yerleşimin animasyonlu olması**. FIX: `.nav-item__pill` base state'inde transform transition YOK → `moveTo(activeItem())` + `void pill.offsetHeight` (layout flush, konumu taban değer olarak commit et) → `.is-ready` transition'ı ARM eder (hover hâlâ akıcı). **Ek keşif:** `.is-ready` rAF içindeydi; rAF gizli sekmede askıya alınır → arka planda açılan sayfada pill hiç `.is-ready` almıyor, `opacity:0` kalıyordu (gösterge yok) → **senkron** yapıldı; opacity de transition'dan çıkarıldı (aynı nedenle takılıyordu). **GERÇEK TIKLAMA KANITI:** /accounts (aktif 211px) → Trends'e gerçek `click()` → /trends yüklendi → pill `translateY=70` = aktif `offsetTop=70`, `runningTransform=0`, `opacity=1`. Hover ölçümü: mouseenter → transform transition `running=1` (akıcılık korundu). **BUG2 gerçek hesapta uydurma engagement (COMPLIANCE).** Teşhis: @ai.neeidy (gerçek, connected, followers_count=7) kartı `9.5K/298/1.9K` **crc32 uydurma** engagement gösteriyordu ("sample" çipli olsa bile gerçek kanalda temsili sayı = yanlış beyan). FIX: tek sinyal `$providerBacked` (`followers_count !== null` = sync/chore bu hesabı canlı sağlayıcıdan okudu) TÜM kartı yönetiyor — gerçek hesap: engagement snapshot'tan gerçek değer, raporlanmayan `—` + nötr "veri yok" rozeti (stand-in HİÇ hesaplanmıyor); demo/seed hesap: deterministik stand-in + `[örnek]` çipi KORUNDU (ekranlar dolu kalır). `AccountRepository::listFor` en yeni `account_metrics` snapshot'ını LEFT JOIN ediyor (ws-scoped subquery), `shape()` NULL'ı NULL bırakıyor; yeni `acct.no_metrics` (en+tr) + `.acc-card__sample--empty` nötr stil (dürüst boşluk, stand-in rozetinin sesini ödünç almaz). **CANLI KANIT:** @ai.neeidy `— — — [no data yet]` + `7 followers`; @smoke_tt `7K 406 509 [sample]` + `61.2K [sample] +69 today`. Demo verisi SİLİNMEDİ (accounts 2 satır, posts 5), `.env` flag flip YOK, engine/şema-çekirdeği/node-graph dokunulmadı. Görsel gate 69 PNG/0 console-error/0 overflow; route 12/12 200.

- 2026-08-23 — **FAZ 22: PANEL + GERÇEK VERİ — 6 görev tamam (873 PASS/0 FAIL, +34 test).** (1) **Analytics seam (K1):** `PublishProvider::accountMetrics()` (follower + per-post engagement BİRLİKTE — dar follower-only adapter yasaklıydı); gerçek `ZernioPublishProvider` impl GET /accounts (followersCount) + GET /analytics; **per-post alan adları canlıda BOŞ geldiği için UYDURULMADI** → defansif çok-anahtarlı map (views/viewCount/impressions…) + `raw_json`'da ham payload saklama (integrations "never hallucinate" kuralına dürüst yanıt); deterministik Mock impl. (2) **Snapshot chore:** yeni `src/Analytics/DailySnapshot.php` (worker sessionless → ws açıkça iterate, her write'ta workspace_id), migration **0014** `account_metrics` (UNIQUE ws+account+gün → INSERT OR IGNORE) + `accounts.followers_count/followers_synced_at`; **zero-cost** (usage/credit YAZMAZ); worker start + 300s chore'a bağlandı. (3) **Follower wiring:** `setFollowers()` + `sync()` tek turda ref reconcile + gerçek follower; raporlanmayan follower stored değeri EZMEZ. (4) **Dedup (K2):** `connect()` blind INSERT → revive-existing (case/@-insensitive); migration **0015** re-point posts → dup sil → UNIQUE index; dev DB'ye WAL-safe yedekle uygulandı (`kuyash.pre-p22-dedup.20260823T130328Z.bak.sqlite`) → **id2 silindi, 5 post hâlâ id3'te, 0 FK ihlali, id1 mock demo + etiketli demo verisi KORUNDU**. (5) **UI:** pill `--spring`(overshoot 1.56)→`--ease-out` = "geri sekme" bitti. (6) **Jargon:** `Messages::since()` → /trends "fresh · 3 min ago" (ham ISO yalnız title'da); 11 ekranda görünür ham ISO = 0. **CANLI KANIT:** account_metrics id1 `followers=7 GERÇEK`, `post_count=0 + views NULL` (dürüst boş), 0 usage satırı; dashboard `@ai.neeidy · 7 followers` (çipsiz) vs `@smoke_tt · 61.2K [örnek]`. **Kendi yakaladığım regresyon:** sample çipi `.acc-card__who` ellipsis'i içinde YUTULUYORDU (görsel gate PASS demişti) → çip dışarı alındı + `.acc-card__sample--foot` + regresyon testi. **K3:** phase-plan.md → Faz 22 + Faz 23 eklendi (14–21 KORUNDU; token `START PHASE 14` idi ama o numara i18n'e ait → kullanıcı onayıyla 22). 16 dosya + 3 yeni; secret yok.

- 2026-08-22 — **SALT-OKUMA SAĞLIK KONTROLÜ + İNCELEME + 2-FAZLIK PLAN (ONAYLI, kod YOK, FAZ TOKEN'I BEKLİYOR).** Sistem sağlıklı ayağa kalktı: 839 PASS/0 FAIL, 12 route 200 (0×500), migration güncel (0013 doğrulandı), worker healthy-idle (PID 14205), dev server 8082 (PID 13685); tek mutasyon = 2 process başlatma (repo/DB dokunulmadı, git temiz). **CANLI Zernio read-only probe (yayın/para YOK):** GET /accounts gerçek `followersCount=7` + `hasAnalyticsAccess=true`; GET /analytics HTTP 200 doğru şekilli (overview/posts/pagination) AMA per-post BOŞ (`posts:[]`, `total:0`, `externalPostCount:0`) → per-post metrik Zernio sync populate edene dek yok, follower bugün gerçek. GET /posts/{id} zengin metadata ama metrik alanı YOK. **Bulgular:** (1) accounts dedup BUG — `connect()` körlemesine INSERT, `(ws,platform,handle)` UNIQUE yok → id2 stale-disconnected dup (@ai.neeidy ×2, 06-13→id2 + 06-14→id3, sync ikisinin ref'ini çekti). (2) UI BUG — kayan-pill `transform … var(--spring)` (cubic-bezier 0.34,**1.56**,…) overshoot = "sekmelerde geri sekme"; fix app.css:873 --spring→--ease-out. (3) posts 3/4/5 GERÇEK IG reel (24-hex ext_id + instagram.com/reel URL; post5 bugün). Dangling/orphan YOK, demo verisi dürüstçe etiketli (crc32 account-card = salt sunum, "örnek" çipi). **Plan onaylandı** (`~/.claude/plans/daha-detayl...hopcroft.md`): Faz1 (analytics adapter+snapshot / follower wiring / dedup+temizlik / UI fix / demo seed / jargon) + Faz2 (haftalık slot scheduling). Kullanıcı kararları: dedup=fix+id2 temizle, Faz2=tam slot, metrik=gerçek follower+etiketli örnek engagement. **phase-discipline: `START PHASE N` token'ı gelene dek kod YAZILMAYACAK.** Bug: gerçek publish `400 Invalid accountId format [invalid_field_value]`. POST /posts `platforms[].accountId = accounts.external_ref`, ama connect bunu UYDURUYORDU (`AccountsController.connectCallback`: `zacct_`+random) — gerçek Zernio SocialAccount `_id`'si (24-hex, GET /accounts) yerine. openapi: accountId="The Zernio SocialAccount ID"; canlı `_id=6a2f250a5f7d1751abb4803a`. FIX (adapter/controller/data; engine/şema/node-graph DEĞİŞMEDİ): `accounts()` PublishProvider arayüzüne taşındı (Mock + test Spy impl); `AccountRepository::setExternalRef()`; `connectCallback` artık gerçek _id'yi `accounts()` ile çözüyor (platform+@/case-insensitive username; eşleşmezse fallback); yeni `AccountsController::sync()` + `POST /accounts/sync` + /accounts "Hesapları eşitle" butonu tüm hesapların external_ref'ini canlı _id'ye reconcile ediyor. UI vendor-neutral (jargon-gate gereği "Zernio" kelimesi UI metninden çıkarıldı). **839 PASS/0 FAIL (+7):** payload accountId=external_ref verbatim, 400 invalid_field_value→REJECTED, sync reconcile (match/no-match/normalize), setExternalRef, connectCallback gerçek _id, mock accounts() 24-hex. secret yok; 10 dosya. **CANLI UÇTAN UCA KANIT (yayın YOK):** gerçek provider GET /accounts `@ai.neeidy _id=6a2f250a5f7d1751abb4803a` → ws#2 #3 external_ref reconcile (WAL-safe yedek) → gerçek `postPayload` `platforms[0].accountId`=o _id (MATCH, 24-hex). ws#2 #3 data-fix UYGULANDI → panelden gerçek publish retry hazır.

- 2026-06-15 — **ASSEMBLY R2-STAGING FIX (commit `62c76fe`, push'lu) + ws#2 ölü-asset temizliği (salt veri) + GERÇEK YAYIN AÇILDI.** Bug: STORAGE_DRIVER=r2'de ffmpeg girdisi (R2'ye taşınmış/evicted) yerelde yok → "No such file (exit 254)" tüm run'ları blokluyordu. FIX (asset-resolution katmanı; engine/şema/node-graph DEĞİŞMEDİ): `AssemblyEngine::localInput()` visual+audio için yerel-yoksa default durable disk'ten (R2) canonical'a stage, hiçbirinde yoksa dürüst `FfmpegException` (kriptik çökme yerine); `AssetCache::remember()` HIT artık yerel dosyayı doğruluyor → R2'den restore / kurtarılamazsa yerinde re-produce (opsiyonel `StorageManager`, nullable). **832 PASS/0 FAIL (+7)**: cache restore/re-produce/regresyon + R2-sim assembly E2E; secret yok. **Canlı retry #13/#18:** stale-kod worker (PID 3027) önce eski kodla tüketti → durdurdum, taze worker fix'li → hata ham ffmpeg'den "assembly input ... unrecoverable"a döndü → **ASIL neden VERİ KAYBI**: referans asset #3 "Smoke clip" `storage_disk=local` + yerel YOK + R2'de YOK (baytlar kayıp, canlı probe). **Temizlik (WAL-safe yedek `kuyash.pre-deadasset-cleanup.*`):** #13/#18 dead-lettered (terminal); ws#2 `avatar_asset_id`(=3)→NULL; asset #3 hard-delete → ws#2 0 ready asset. **#4 CANLI KANIT:** gerçek `AssetFetchExecutor` ws#2 faceless+face → `source=stock provider=pexels`, 5.3MB klip İNDİ (eski 'face'→ölü-avatar yolu elendi). **AÇIK:** worker PID 34294 (13:56 başladı, fix 13:59 commit) fix'i yüklememiş olabilir → kullanıcı `php bin/worker.php` RESTART etmeli; yoksa yeni R2-migration run'ları hâlâ patlar.

- 2026-06-15 — **GENERATION STACK GERÇEĞE AÇILDI + TTS streaming-WAV BUG FIX (commit `6b0c56f`, push'lu).** `.env`: `OPENAI_MOCK=false` + `TTS_MOCK=false` + `STOCK_MOCK=false` (STORAGE_DRIVER=r2; ZERNIO_MOCK=true + VIDEO_MOCK=true KALDI). Her sağlayıcı küçük canlı çağrıyla doğrulandı: OpenAI text 200+usage (`OpenAiTextProvider`, gpt-4o-mini, gerçek fikir; tek minik çağrı sub-cent→cost 0); Pexels 720×1280 dikey klip indirdi+ffmpeg (`PexelsStockProvider`); R2 **6/6 PASS** bucket PRIVATE. **TTS bug bulundu+fix:** OpenAI WAV'ı *streaming* döndürüyor (data chunk size = `0xFFFFFFFF` placeholder, header hexdump kanıtı) → `WavWriter::durationOf` **89478s** ölçtü (gerçek ffprobe 2.35s). FIX: sentinel ise gerçek payload = `fstat(filesize)−payload_offset`; normal/trailing-chunk WAV DOKUNULMADI (declared size). +3 test → **825 PASS/0 FAIL** (regresyon yok). Gerçek TTS yeniden doğrulandı: adapter **4.45s = ffprobe 4.45s** → `TTS_MOCK=false` KALDI. İlk turda TTS bug yüzünden geçici mock'a alınmıştı; fix sonrası gerçek. `.env` yedeği `.env.bak.pre-gen-20260615T012753Z`. **DİKKAT: generation artık GERÇEK PARA harcar; bütçe cap'leri PreflightGate ile etkin.** Pipeline ÇALIŞTIRILMADI (kullanıcı panelden yapacak). Değişen kod sadece `src/Media/WavWriter.php`+`tests/run.php`; secret yok.

- 2026-06-14 — **PHASE 10: ZERNIO GERÇEK PUBLISH ADAPTER + per-platform AI-disclosure — KABUL + COMMIT `6891f8b` + PUSH (ZERNIO_MOCK=true KALDI, gerçek yayın YOK).** Ham `openapi.yaml` (1.4MB) curl+parse ile şema BİREBİR (uydurma yok). Gerçek `ZernioPublishProvider`: presign+PUT upload, POST /posts, status, salt-okunur accounts(), 429 backoff, {error,code,reason} → PublishOutcome. **AI-LABEL:** YouTube `containsSyntheticMedia` + TikTok `videoMadeWithAi` native bayrak VAR, IG YOK → **hibrit+per-platform toggle**: Ayarlar→AI ifşası (migration **0013**, 3 boolean default 1), IG caption "Made with AI"/"AI ile üretildi" (owner locale), kapatınca `compliance.ai_disclosure_suppressed` truthful audit. Webhook event-id `payload.id`/`X-Zernio-Event-Id`. **CANLI salt-okunur GET /accounts → IG `@ai.neeidy` (kanıt; yayın yok).** Docs: zernio-notes + spec + **ADR-021**. **822 PASS/0 FAIL**; secret-scan temiz. **4 GATE GO:** qa, security (0 HIGH-MED; header-format bug'ı canlı 401 yakaladı+düzeltildi), compliance (truthful effective-flag + audit), integration (ilk NO-GO 4 uydurma alan platformResults/contentType:reels/per-platform error → B1-B4 `post.platforms[]`/shareToFeed/errorCategory → yeniden GO). 17 dosya commit (feat/publish) + checkpoint (chore/state). **+ DEV-DB FIX:** /settings 500 (no such column ai_disclose_instagram) = 0013 canlı dev DB'ye uygulanmamıştı (KOD/migration uyumsuzluğu DEĞİL) → WAL-safe yedek `kuyash.pre-0013.bak.sqlite` + `bin/migrate.php` (dev DB migration=13, 3 ws default ON) → /settings 200 + 3 toggle, 10 nav ekranı 200/0-SQL-hata.

