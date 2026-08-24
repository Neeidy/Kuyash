# Weekly Plan revizyonu — takvim, içerik bağlama, iki mod

---

## FAZ 24 — KİLİTLİ KARARLAR (kullanıcı token'ı `START PHASE 24`, 2026-08-23)

Aşağıdaki maddeler bu planın K bölümünü **kapatır**. Uygulama bunlarla ilerler; yeniden tartışılmaz.

| # | Karar |
|---|---|
| K1 | Mod = **SLOT başına** (`publish_slots.mode`); otonomi politikası **WORKSPACE başına**. |
| K2 | **Tam-gözetimsiz (script_draft oto-onayı) ERTELENDİ → Görev 8, BU FAZDA YOK.** `script_draft` DAİMA insan onayı bekler. *"Her AI içerik bir insan onayından geçer"* bu fazın ürün vaadidir. **ADR-015'in auto-onay kapsamı GENİŞLETİLMEZ.** |
| K3 | Grace = **60 dk**; grace dışında geç-yayın YOK → atla. |
| K4 | Ufuk **14 gün**, retention **30 gün**. |
| K5 | `plan_paused` insan-onaylı **zamanlanmış yayınları DONDURMAZ**; yalnız otomatik üretimi durdurur. UI etiketi net: **"Otomatik üretimi duraklat"**. |
| K6 | Saat dilimi değişiminde **zaten zamanlanmış** (publish job `queued`) yayınlar oynatılmaz; operatöre liste gösterilir. |
| K7 | AI-oto konu kaynağı = workspace'in `full` workflow'u + mevcut `trend_config`. **Slot-başına niche YOK.** |
| K8 | Atanmış occurrence'ı olan slotu kaldırma = **onaylı cascade-iptal**. |
| K9 | Faz numarası **24**; `phase-plan.md`'ye 14–21 kayıtları KORUNARAK Faz 24 satırı eklenir. |
| K10 | Migration 0017 gerçek dev DB'ye ancak **WAL-safe yedekten sonra** (`kuyash.pre-0017.*.bak.sqlite`). |

### Zorunlu ek notlar (atlanamaz)

- **N1 — `approval_mode` VARSAYILAN `'manual'` KALIR.** İnsan final videoyu görmeden AI-oto içerik
  yayınlanmaz. `'auto'` bilinçli bir opt-in'dir; bu fazda default DEĞİŞTİRİLMEZ.
- **N2 — REGRESYON KİLİDİ.** `publish_after`'ı run doğuşta yazma değişikliği YALNIZ **planlı**
  run'ları etkiler. Planlı-OLMAYAN normal bir run'ın (biri Distribution çalıştırıp onayda
  "hemen yayınla" seçer) davranışının **DEĞİŞMEDİĞİ** ayrı bir teste bağlanır.
  Mevcut 941 testin hiçbiri kırılmaz.
- **N3 — Görev 0 (RİSK SPIKE) HER ŞEYDEN ÖNCE**, ürün kodu yazmadan: DST'yi aşan bir
  `America/New_York` slotu materyalize et → `Worker::tick` sür → spy provider'ın aldığı
  `PublishRequest.scheduledFor` == beklenen UTC an. **Spike GEÇMEDEN Görev 1'e geçilmez**;
  geçmezse DUR ve raporla.

### Kapsam kilidi
Yalnız **Görev 0–7**. Görev 8 (tam-gözetimsiz) bu fazda yok. Faz 23'ün açık borçları
(slot mutasyon event'leri, `/accounts/sync` rate-limit) Görev 7'de kapanır.

---

## Context

**Neden bu iş?** Faz 23 "Planlı Paylaşım"ı teslim etti ama ürün olarak yarım:
`/plan` ekranı yalnızca **haftalık saat şablonu** (Pzt 09:00, Prş 18:30) + saat dilimi
gösteriyor. Takvim yok, içerik bağlama yok, mod kavramı yok. Slot hiçbir şey tutmuyor —
içerik ancak **onay anında** kuyrukta tek tek bir slota bağlanıyor. Yani operatör
"Salı'ya şu videoyu koyayım" diyemiyor, sadece "bu videoyu bir sonraki Salı'ya at"
diyebiliyor. Slot bir *takvim* değil, bir *seçenek listesi*.

**Hedef sonuç:** `/plan` takvim-merkezli bir ekrana dönüşsün; her hücre gerçek bir
**tarih+saat örneği (occurrence)** olsun ve o hücreye içerik bağlanabilsin. İki mod:
kendi videonu takvime yerleştirdiğin **MANUEL** mod ve slot vakti geldiğinde sistemin
içerik üretip kuyruğa düşürdüğü **AI-OTOMATİK** mod.

**Bu plan sıfırdan değil, DELTA.** Yeniden kullanılan çekirdek (hiçbiri yeniden
yazılmıyor):

| Mevcut parça | Nerede | Nasıl kullanılacak |
|---|---|---|
| `SlotResolver` (saf, DST-doğru) | `src/Publish/SlotResolver.php` | **1 yeni saf metot** eklenir (`occurrencesBetween`); mevcut `nextOccurrence` algoritması aynen tekrar kullanılır |
| `publish_slots` + tz | `database/migrations/0016_publish_slots.sql` | Şablon olarak KALIR; üstüne `mode` kolonu ve occurrence tablosu gelir |
| `runs.publish_after` → `jobs.run_after` gate | `0008_accounts.sql:97`, `Engine.php:726-735` | **Tek "vakti gelince ateşle" primitifi** — değişmez, sadece daha erken yazılır |
| `Engine::approve(..., $scheduledFor)` | `src/Workflow/Engine.php:237` | Değişmez. `publish_after` yalnız non-null iken yazıldığı için önceden set edilmiş değer onayda **korunur** |
| `PreflightGate` (bütçe, run'dan ÖNCE) | `src/Usage/PreflightGate.php:35` | AI-oto üretimin bütçe kapısı — aynen |
| `AutoApprovalGate` + `approval_mode`/`kill_switch`/`daily_post_cap` | `0007_compliance.sql`, `src/Compliance/AutoApprovalGate.php` | **"Tam gözetimsiz" toggle'ı ZATEN bu** — yeni toggle EKLENMEZ |
| `PublishCounter::publishedToday(int,string,?int)` | `src/Publish/PublishCounter.php:25` | Günlük cap kontrolü — worker-dostu imza, aynen |
| `PublishGateExecutor` (kill switch + cap defer) | `src/Compliance/PublishGateExecutor.php:50` | Değişmez |
| `ZernioPublishExecutor` + `PublishRequest.scheduledFor` | `src/Publish/ZernioPublishExecutor.php:248` | Değişmez — `runs.publish_after`'ı okumaya devam eder |
| `DailySnapshot` chore deseni | `src/Analytics/DailySnapshot.php` | Yeni `PlanRunner` chore'unun **birebir şablonu** (sessionless, ws-iterate, idempotent) |
| `AssetRepository::readyVideosFor` | `src/Library/AssetRepository.php:98` | Atanabilir havuz |
| `Nodes::DISTRIBUTION` / `FULL` | `src/Workflow/Nodes.php:28-37` | Manuel mod = distribution, AI-oto = full. **Yeni template YOK** |
| `Cockpit::nextPublish()` | `src/Workflow/Cockpit.php:76` | Dashboard bandı; plan özetiyle genişletilir |
| Görsel gate (`/plan` zaten kayıtlı) | `tools/visual/routes.json` | UI doğrulaması |

**Faz numarası:** `phase-plan.md` 0–13 dolu, 14–21 i18n+Experience, 22 Panel, 23 Planlı
Paylaşım. Bu iş **Faz 24**; `START PHASE 24` token'ı olmadan tek satır kod yazılmaz.

---

## A. Mod tasarımı — plan-başına mı slot-başına mı?

### KARAR: **Mod SLOT başına; politika WORKSPACE başına.**

`publish_slots.mode TEXT NOT NULL DEFAULT 'manual' CHECK (mode IN ('manual','auto'))`.
Saat eklerken sorulur; workspace'te hiç slot yokken ekran önce **mod sorusuyla** açılır
("Kendi videolarını mı yüklüyorsun, yoksa Kuyash mı üretsin?") — kullanıcının istediği
"plan oluştururken sorulan anlamlı seçim" bu boş-durum ekranıdır.

**Gerekçe:**
1. Slot-başına mod, plan-başına modu **kapsar** (hepsini aynı seçersen plan-başına
   olur), tersi doğru değil. Maliyeti tek kolon + tek radio.
2. Gerçek kullanım karma: "Pzt/Çar kendi videom, Cuma Kuyash doldursun". Plan-başına
   mod bunu imkânsız kılar ve kullanıcıyı iki ayrı workspace açmaya iter.
3. Kritik ayrım: **mod bir yönlendirmedir, politika değil.** Otonomi politikası
   (bütçe cap, günlük cap, kill switch, onay modu) zaten `workspaces` kolonlarında ve
   worker tarafından okunuyor. Bunları slota indirmek Faz 9'un tek-kaynak
   guardrail modelini bozardı. Bu yüzden **mod slotta, guardrail workspace'te**.
4. Mevcut satırlar `mode='manual'` default'una düşer ve bu **dürüst**: bugünkü slotlar
   kendiliğinden hiçbir şey yapmıyor.

**Trade-off (dürüstçe):** karma plan takvimi okumayı zorlaştırır ve "bu hafta ne kadar
harcayacağım" sorusunun cevabı slot sayımına bağlanır. Karşı önlem: her hücre modunu
açıkça etiketler ("Senin videon" / "Kuyash üretecek") ve plan başlığı özet verir
("3 saati sen dolduruyorsun · 2 saati Kuyash · haftalık ~$0.40 tahmini").

**Reddedilen alternatif:** workspace-başına tek `plan_mode` kolonu. Daha basit ama
yukarıdaki (2) yüzünden ürünü daraltıyor; kazandırdığı tek şey bir kolon.

---

## B. Veri modeli (migration `0017_plan_occurrences.sql`, tamamı ADDITIVE)

Kural: forward-only, `BEGIN/COMMIT` yok (Migrator sarar), yalnız `ADD COLUMN` +
`CREATE TABLE`. `tests/run.php:312`'deki migration listesi assertion'ı güncellenir.

### B1. `publish_slots` genişletmesi
```sql
ALTER TABLE publish_slots ADD COLUMN mode TEXT NOT NULL DEFAULT 'manual'
    CHECK (mode IN ('manual', 'auto'));
```
`account_id` kolonu **hâlâ okunmuyor** ve bu planda da okunmuyor (Bölüm J).

### B2. `slot_occurrences` — planın çekirdek yeni tablosu

Bir occurrence = **(slot, yerel tarih)** somut örneği. İçerik ancak somut bir güne
bağlanabilir; ayrıca 5 dakikada bir koşan chore'un iki kez run açmasını engelleyen
idempotency çıpası budur.

```sql
CREATE TABLE slot_occurrences (
    id           INTEGER PRIMARY KEY,
    workspace_id INTEGER NOT NULL REFERENCES workspaces (id),
    slot_id      INTEGER NOT NULL REFERENCES publish_slots (id),
    -- Kimlik YEREL takvim günüdür. DST kayması anı oynatır ama günü ASLA
    -- ikizlemez — 'YYYY-MM-DD', workspace saat diliminde.
    local_date   TEXT NOT NULL CHECK (length(local_date) = 10),
    -- Çözümlenmiş UTC anı. status='open' iken yeniden hesaplanabilir.
    publish_at   TEXT NOT NULL,
    -- Slot'tan materyalizasyon anında KOPYALANIR (runs.nodes_json snapshot
    -- deseni): slot sonradan mod değiştirse bile geçmiş yalan söylemez.
    mode         TEXT NOT NULL CHECK (mode IN ('manual', 'auto')),
    -- Planın SAHİP OLDUĞU durumlar sadece bunlar. "hazırlanıyor / onay bekliyor
    -- / zamanlandı / yayınlandı" run+job'dan TÜRETİLİR, kopyalanmaz.
    status       TEXT NOT NULL DEFAULT 'open'
                 CHECK (status IN ('open', 'assigned', 'skipped')),
    asset_id     INTEGER REFERENCES assets (id),   -- manuel modda seçilen video
    run_id       INTEGER REFERENCES runs (id),
    skip_reason  TEXT,   -- 'no_content'|'not_approved'|'missed'|'daily_cap'|
                         -- 'budget_cap'|'kill_switch'|'plan_paused'|
                         -- 'compliance_block'|'no_owner'|'no_workflow'
    created_at   TEXT NOT NULL,
    updated_at   TEXT NOT NULL
);

-- bir slot × bir yerel gün = bir occurrence (materializer idempotency)
CREATE UNIQUE INDEX uq_slot_occurrences ON slot_occurrences (slot_id, local_date);
-- bir run yalnız bir occurrence'a ait olabilir (çift-run kilidi)
CREATE UNIQUE INDEX uq_slot_occurrences_run ON slot_occurrences (run_id)
    WHERE run_id IS NOT NULL;
-- takvim okuması + due taraması
CREATE INDEX idx_slot_occurrences_due ON slot_occurrences (workspace_id, publish_at);
```

**`status` neden bu kadar dar?** Faz 22/23'ün kanıtlanmış kuralı: *"gerçek job
gate'inden oku, plandan değil"* (`Cockpit.php:62`). Occurrence'ın görünen durumu
`slot_occurrences → runs → jobs` join'iyle **türetilir** (`PlanBoard` read-model).
Böylece senkronize tutulacak ikinci bir state machine doğmaz.

### B3. `workspaces` — iki kolon, fazlası yok
```sql
-- AI-oto üretimin slot saatinden ne kadar ÖNCE başlayacağı. Varsayılan 3 saat:
-- tam pipeline (TTS+stok+ffmpeg) dakikalar sürer, kalan süre insanın onaylaması
-- içindir (kilitli karar 3: varsayılan onay bekler).
ALTER TABLE workspaces ADD COLUMN auto_lead_minutes INTEGER NOT NULL DEFAULT 180
    CHECK (auto_lead_minutes BETWEEN 30 AND 1440);
-- Planın kendi duraklatması. Compliance kill_switch'ten AYRI ve daha dar:
-- yalnız OTOMATİK üretimi durdurur, insanın onayladığı yayınlara dokunmaz.
ALTER TABLE workspaces ADD COLUMN plan_paused INTEGER NOT NULL DEFAULT 0
    CHECK (plan_paused IN (0, 1));
```

### B4. EKLENMEYENLER (bilinçli)
- **"Tam gözetimsiz" için yeni toggle YOK.** `workspaces.approval_mode` ('manual'|'auto',
  0007) zaten tam olarak bu ve `AutoApprovalGate` onu kill switch + günlük cap +
  bütçe cap + kalite-düşüş→manual-fallback ile birlikte uyguluyor. İkinci bir toggle
  eklemek iki otonomi kaynağı yaratır — reddedildi.
- **`auto_workflow_id` YOK.** AI-oto run'ı workspace'in `full` workflow'unu kullanır
  (`WorkflowRepository::findByTemplate($ctx,'full')`); worker tarafı için ws-id alan
  eşdeğer sorgu. Konfigürasyon değil, çözümleme.
- **`runs` tablosuna kolon YOK.** Geri-işaretçi `slot_occurrences.run_id` üzerinden
  join edilir; motorun merkez tablosuna dokunulmaz.
- Cron ifadesi, aralık, tekrar-istisnası, tatil takvimi yok (0016'nın kendi
  "DELIBERATELY NOT A CRON ENGINE" sınırı korunuyor).

### B5. Tenant izolasyonu
`slot_occurrences` bir tenant tablosudur: `workspace_id` NOT NULL, **her okuma ve her
yazma** `workspace_id = ?` ile filtrelenir. `OccurrenceRepository`'nin web-yüzü
`WorkspaceContext` alır, worker-yüzü `int $workspaceId` alır (repo'nun kendi kuralı —
`TrendRepository.php:12`). Atama sırasında `asset_id` **ve** `slot_id` ayrı ayrı
tenant-doğrulanır (`SlotRepository::add`'in mevcut deseni). Cross-tenant asset ataması
reddedilir, sessizce yok sayılmaz.

---

## C. İçerik → slot bağlama

### C1. Atanabilir havuz nasıl doluyor
- **Manuel mod:** `AssetRepository::readyVideosFor($ctx)` — `kind='video' AND
  status='ready'`. Kütüphaneye yüklenen her video anında `status='ready'`
  (`AssetRepository.php:39`), yani ek bir hazırlık adımı yok.
- Havuz boşsa atama modalı `/library`'ye link veren dürüst bir boş-durum gösterir.
- **AI-oto mod:** havuz yok — içerik slot vaktinde üretilir; atama yapılmaz.

### C2. Atama mekaniği (manuel)

`POST /plan/occurrence/{id}/assign` (`asset_id`) — **web isteği, oturum var**, bu
yüzden `Engine::startRun(WorkspaceContext …)` doğrudan çağrılabilir. Sıra:

1. Occurrence tenant-scoped bulunur; `mode='manual'`, `status='open'`, `run_id IS NULL`,
   `publish_at > now` değilse **reddedilir** (dürüst mesaj, sessiz düzeltme yok).
2. Asset tenant-scoped + `ready` + `kind='video'` doğrulanır.
3. `Engine::startRun($ctx, $distributionWorkflowId, $assetId, $userId)` —
   `PreflightGate` bütçeyi run oluşmadan ÖNCE kontrol eder (~2 cent distribution
   tahmini); `BudgetExceededException` yakalanır → flash, occurrence `open` kalır.
4. `RunRepository::setPublishAfter($wsId, $runId, $occurrence['publish_at'])` —
   **`runs.publish_after` run doğarken yazılır**, onayda değil.
5. `slot_occurrences`: `asset_id`, `run_id`, `status='assigned'`; `transition` event.

**(4) neden kritik:** `Engine::approve` `publish_after`'ı yalnızca gelen değer
non-null iken yazar (`Engine.php:257-265`). Yani önceden set edilmiş değer, operatör
onay formunda hiçbir şey seçmese bile **korunur**. Aynı değer `advance()` →
`insertJob()` yolunda publish job'ının `run_after`'ı olur (`Engine.php:726-735`) ve
`ZernioPublishExecutor::scheduledFor()` tarafından adapter'a taşınır. Ayrıca bu,
otomatik onaylanan (`finalizeAutoApproved`) run'ların da slot saatini korumasını
sağlar — o yol `approve()`'dan geçmediği için onay-anında yazsaydık AI-oto içerik
slot saatini **yok sayıp hemen yayınlardı**. (Bu, denetimde bulunan gerçek bir
tuzaktır; tasarım onu doğuşta kapatıyor.)

### C3. Değiştirme / kaldırma

| Occurrence'ın türetilmiş durumu | Sunulan eylem | Davranış |
|---|---|---|
| `open` | Ata | C2 |
| `assigned` + run hazırlanıyor | Değiştir / Kaldır | `Engine::cancelRun` → run `cancelled`, kuyruktaki job'ları `cancelled`; occurrence `open` |
| onay bekliyor (`render_review`) | Değiştir / Kaldır | aynı; alternatif olarak `/queue`'dan Reddet (gerçek `approvals` reddi kaydı) |
| zamanlandı (publish job `queued`, gelecek) | İptal et | publish job **hâlâ `queued`** olduğu için adaptöre HİÇBİR ŞEY gönderilmemiştir → yerel iptal gerçekten güvenlidir; `posts` satırları `cancelled` |
| publish job `processing`/`published` | eylem YOK | "Yayınlanıyor — artık iptal edilemez" (Zernio'da geri alma iddia edilmez) |

**Tek motor eklemesi:** `Engine::cancelRun(int $wsId, int $runId, ?int $userId,
string $reason)` — guarded UPDATE'lerle run `cancelled` + non-terminal job'lar
`cancelled` + `transition` event. Neden gerekli: `Engine::reject` yalnızca
`awaiting_approval` bir job'dan çalışır; run pipeline'ın ortasındayken (caption
üretilirken) böyle bir job yoktur. Mevcut hiçbir state machine yolu değişmez; sadece
yeni bir terminal geçiş eklenir. `approvals` tablosuna **yazmaz** — bu bir insan
onay/red kararı değil, bir iptal (dürüst kayıt kuralı).

### C4. Çift-yayın önleme — dört bağımsız katman
1. `uq_slot_occurrences (slot_id, local_date)` — bir slot bir günde bir kez.
2. `uq_slot_occurrences_run (run_id) WHERE run_id IS NOT NULL` — bir run bir occurrence.
3. `uq_jobs_idempotency` → `run:{id}:publish` (mevcut) — bir run bir publish job.
4. `uq_posts_idempotency` → `run:{r}:acct:{a}:publish` (mevcut) — bir (run,hesap) bir post.

Ayrıca `PlanRunner` her turda `run_id IS NULL AND status='open'` filtresiyle çalışır;
iki tur üst üste koşsa bile ikinci turda satır zaten `assigned`'dır.

### C5. Materyalizasyon (occurrence satırlarını kim yaratır)

`OccurrenceMaterializer::materialize(int $wsId, string $timezone, array $slots,
string $nowIso, int $horizonDays = 14): int` — saf hesaplama + `INSERT OR IGNORE`.
- Yeni saf metot: `SlotResolver::occurrencesBetween(string $tz, int $weekday,
  string $hhmm, string $fromIso, string $toIso): list<array{local_date,at}>` —
  `nextOccurrence`'ın aynı "her tarih kaydırmasından sonra duvar-saatini YENİDEN
  uygula" kuralıyla, DST-doğru.
- Çağrı yerleri: (a) `/plan` GET'inde (ucuz, idempotent — takvim hep dolu görünür),
  (b) `PlanRunner` chore'unda (kimse UI açmasa da AI-oto çalışsın).
- `publish_at <= now` olan occurrence **hiç yaratılmaz**.
- Tavan: 50 slot × 14 gün = 700 satır/workspace. Retention: `publish_at` 30 günden
  eski satırlar `Maintenance`'ın mevcut chore'unda silinir.

---

## D. Akışlar (adım adım)

### D1. MANUEL slot akışı
1. Operatör `/plan` açar → önümüzdeki 14 gün; Pzt 09:00 hücresi **"Boş"**.
2. "Ata" → hazır kütüphane videoları listesi (başlık, süre, önizleme karesi).
3. `POST assign` → C2 (1–5). Hücre: **"Hazırlanıyor"**.
4. Worker: `LIBRARY → CAPTION → HASHTAGS → MUSIC NOTE/STYLE → PREVIEW → COMPLIANCE →
   render_review`. Sadece caption+hashtag OpenAI harcar (cent-altı).
5. `render_review` → job `awaiting_approval`. Hücre: **"Senin onayını bekliyor"** +
   `/queue` linki.
6. Kuyrukta kart **"Pzt 09:00 için planlandı (Europe/Istanbul)"** rozetiyle görünür.
   Slot seçici yerine **salt-okunur planlanan saat** + ayrı, açık bir "bunun yerine
   hemen yayınla" seçeneği (sessiz "hemen yayınla"ya düşme YOK).
7. Onay → `Engine::approve` → `publish_after` korunur (C2/4) → `advance()` →
   `final_render` → publish job `run_after = publish_at`. Hücre: **"Zamanlandı"** +
   canlı geri sayım (mevcut `data-countdown` mekanizması).
8. Vakti gelince worker job'ı claim eder → `PublishGateExecutor` (insan onayı →
   düz geçiş) → `ZernioPublishExecutor` hesaplara fan-out. Hücre: **"Yayınlandı"**
   + hedef başına durum.

### D2. AI-OTOMATİK slot akışı
1. Slot `mode='auto'`. Occurrence `open` olarak materyalize edilir. Hücre:
   **"Kuyash üretecek"**.
2. `PlanRunner::tick($nowIso)` — worker'ın 300 sn chore kadansında **ve** worker
   başlangıcında koşar (`DailySnapshot` deseni; sessionless, ws-iterate).
3. Due tanımı: `mode='auto' AND status='open' AND run_id IS NULL AND
   publish_at - auto_lead_minutes <= now < publish_at`.
4. **Hiçbir satır yaratılmadan önce guardrail sırası** (hepsi mevcut kod):
   1. `plan_paused=1` → dokunma (occurrence açık kalır; süre dolunca E8).
   2. `kill_switch=1` → dokunma (süre dolunca `skip_reason='kill_switch'`).
   3. Bağlı hesapların **hepsi** günlük cap'te (`PublishCounter::publishedToday`) →
      `skipped`/`daily_cap`, harcama YOK.
   4. Workspace sahibi (`workspace_users.role='owner'`) yoksa →
      `skipped`/`no_owner` (`runs.created_by` NOT NULL).
   5. `full` workflow yoksa → `skipped`/`no_workflow`.
   6. `PreflightGate::check` → `BudgetExceededException` → `skipped`/`budget_cap`
      (gate zaten `guardrail.preflight_block` yazar). **Tekrar denenmez** — bütçe
      lead penceresinde açılmaz.
5. `Engine::startRunFor($wsId, $fullWorkflowId, null, $ownerUserId)` →
   `setPublishAfter($occurrence['publish_at'])` → occurrence `assigned`.
6. Pipeline: `TREND → IDEA → SCRIPT`. **`script_draft` bir onay düğümüdür**
   (`Nodes::APPROVAL_TYPES`). Varsayılan (`approval_mode='manual'`) davranış:
   run burada durur, kuyrukta **"Kuyash bir metin yazdı — onayla"** olarak görünür.
   Bu, kilitli karar 3'ün ta kendisidir (üretilen içerik slot öncesi onay bekler) ve
   TTS/stok harcaması **onaydan sonra** başlar — para açısından da doğru sıra.
7. Onaydan sonra `VOICE → VISUALS → ASSEMBLE → CAPTION → HASHTAGS → MUSIC → PREVIEW →
   COMPLIANCE → render_review`. İkinci onay kapısı:
   - `approval_mode='manual'` → insan onaylar (gerçek kullanıcı + timestamp kaydı).
   - `approval_mode='auto'` → `AutoApprovalGate` değerlendirir; `pass` /
     `pass_with_ai_label` ise otomatik onaylar ve kaydı **"compliance ajanı onayladı
     (policy kuyash-v1)"** olarak yazar (`mode='auto'`, `decided_by NULL`,
     0007'deki CHECK bunu veritabanı seviyesinde garanti eder).
8. Her iki durumda `publish_after` doğuşta yazıldığı için publish job slot anında
   ateşlenir → D1/8 ile aynı.

**"Tam gözetimsiz" (tek insan dokunuşu bile olmayan) yol:** `approval_mode='auto'`
bugün yalnızca `render_review`'ı otomatik onaylıyor; `script_draft` durmaya devam
eder. Onu da otomatikleştirmek ADR-015'in **kilitli** auto-onay kapsamını genişletir
→ **ayrı, compliance-reviewer kapısı olan bir görev** (Bölüm I, Görev 8) ve Bölüm
K'da açık karar olarak listelenmiştir. Bu plan onsuz da eksiksiz çalışır.

---

## E. Kenar durumlar (davranış tanımlı)

| # | Durum | DAVRANIŞ |
|---|---|---|
| E1 | **Boş slot** (manuel, kimse atamadı) | Grace sonrası `skipped`/`no_content`. Yayın yok, hata yok. Takvimde "kaçırıldı — içerik atanmamıştı", digest'te sayılır. |
| E2 | **Onaysız içerik slot vaktinde** | Publish job hiç oluşmamıştır (run onay kapısında park). Grace sonrası occurrence `skipped`/`not_approved` **ve `runs.publish_after` NULL'lanır** — böylece operatör 3 gün sonra onayladığında sessizce "hemen yayınla"ya düşmez. **Run İPTAL EDİLMEZ** (emek yok olmaz); kuyrukta "bu saat geçti, yeni saat seç" yazar. |
| E3 | **Kaçan slot / worker down** | `PLAN_GRACE_MINUTES = 60` sabiti. Grace **içinde**: geç ama aynı niyetle yayınlanır. Grace **dışında**: `skipped`/`missed` + kuyruktaki publish job `cancelled`. Kritik sıra: `PlanRunner` süpürmesi worker'ın **başlangıç bloğunda** ve chore bloğunda, `$worker->tick()` claim'inden **ÖNCE** koşar (`bin/worker.php:75-83` + `:103`) → 3 gün kapalı kalmış bir worker açılınca eski job'ları ateşlemeden temizler. |
| E4 | **DST sınırı** | Kimlik `(slot, local_date)`; `publish_at` her materyalizasyonda duvar-saatinden yeniden çözülür → operatör için 09:00 hep 09:00. **İleri atlama boşluğu** (02:30 o gün yok): PHP normalizasyonu kabul edilir (03:30 olur) ama UI çözümlenen gerçek saati gösterir ("o hafta 03:30'da — saatler değişiyor"). **Geri alma tekrarı** (01:30 iki kez): PHP'nin seçtiği ilk oluşum. İkisi de teste bağlanır. |
| E5 | **Günlük cap aşımı** | (a) Üretim anında: tüm hesaplar cap'teyse üretim hiç başlamaz (`skipped`/`daily_cap`, sıfır harcama). (b) Yayın anında: mevcut `PublishGateExecutor` otomatik-onaylı job'ı sonraki UTC gece yarısına erteler — bu slot vaadini bozardı, bu yüzden `PlanRunner` süpürmesi grace sonrası o ertelenmiş job'ı iptal eder ve `skipped`/`daily_cap` yazar. **Mevcut executor'a dokunulmaz.** |
| E6 | **Bütçe cap (AI-oto)** | `PreflightGate` run doğmadan atar → `skipped`/`budget_cap` + mevcut `guardrail.preflight_block` event + plan seviyesinde `guardrail.plan_skipped`. Yarım-başlamış run YOK. |
| E7 | **Çok-hesap kısmi yayın** | Mevcut ADR-016 davranışı korunur: job tamamlanır, hedef başına gerçek `posts` satırı. Occurrence "3 hesabın 2'sine yayınlandı" gösterir, başarısız hedefi **adıyla** söyler. Gizlenmez, "başarılı" diye yuvarlanmaz. |
| E8 | **Plan duraklat / kill switch** | `plan_paused=1`: yalnız OTOMATİK üretim durur; insanın onayladığı zamanlanmış yayınlar zamanında çıkar (UI bunu aynen söyler). Süresi dolan auto occurrence `skipped`/`plan_paused`. `kill_switch=1` (compliance): otomatik onayları durdurur + otomatik-onaylı publish'leri erteler (mevcut) + AI-oto üretimi durdurur. Manuel atama her iki durumda da serbesttir — *guardrail otonomiyi kısıtlar, insanı değil.* |
| E9 | **Slot düzenleme (gün/saat değişimi)** | Yalnız o slotun **gelecekteki `open`** occurrence'ları yeniden materyalize edilir. `assigned` olanlar sessizce OYNATILMAZ; UI "bu içerik hâlâ eski saatte gidiyor — yeniden zamanla veya iptal et" der. |
| E10 | **Slot kaldırma** | Atanmış gelecek occurrence'ı varsa tek tıkla silinmez: "N zamanlanmış paylaşımı da iptal et" onayı istenir (`data-confirm`, mevcut desen). Onaysız kaldırma reddedilir. |
| E11 | **Slot duraklatma** | Yeni occurrence üretilmez; mevcut `assigned` olanlar devam eder (dürüst), hücrede uyarı bandı. |
| E12 | **Saat dilimi değişimi** | `open` + `assigned` (henüz zamanlanmamış) occurrence'ların `publish_at`'i yeni dilimde yeniden çözülür (niyet "09:00 yerel"). **Zaten zamanlanmış** (publish job `queued`) olanlar sessizce OYNATILMAZ — kuyruğa girmiş bir an taahhüttür; operatöre liste gösterilir ve tek tek yeniden zamanlar. |
| E13 | **Bir occurrence'a iki run** | C4'teki 4 katman + atama sırasında `status='open' AND run_id IS NULL` guard'ı. |
| E14 | **Geçmişe occurrence** | Materializer `publish_at <= now` olanı hiç yaratmaz. |
| E15 | **Atanmış asset silinmesi** | `LibraryController::delete` terminal-olmayan bir occurrence/run'a bağlı asset'i silmeyi **reddeder** (dürüst mesaj + hangi tarihe bağlı olduğu). |
| E16 | **Compliance BLOCK (slop)** | Mevcut davranış: run `cancelled`. Occurrence `skipped`/`compliance_block`, digest'te görünür. **Sessiz yeniden deneme YOK** — AI-oto haftada 5 post = asıl slop riski, blok görünür kalmalı. |
| E17 | **Bağlı hesap yok** | `ZernioPublishExecutor` zaten "yayınlanacak bir şey yok" ile tamamlanır (hata değil). Occurrence `skipped`/`no_account`; plan ekranı `/accounts`'a yönlendirir. |

---

## F. UI

### F1. `/plan` — takvim-merkezli ekran
- **Yapı:** üstte mevcut "Sıradaki yayın" bandı (gerçek job gate'inden, korunuyor) →
  **plan özeti kartı** (mod dağılımı, saat dilimi, lead süresi, plan duraklat) →
  **takvim**.
- **Responsive (375/768/1280):**
  - **375px** → gün-listesi: önümüzdeki 14 gün dikey akış, her günün altında o günün
    hücreleri. (7 sütunlu ızgara telefonda okunmaz; `frontend.md`'nin "mobil
    node-graph → yığılmış kart" kuralının aynısı.)
  - **768/1280px** → 7 sütunlu **hafta ızgarası** + ikinci hafta sekmesi. CSS Grid,
    yeni bağımlılık yok. Yatay taşma yasak (görsel gate ölçer).
- **Hücre durumları** (her biri ayrı çip, sıfır teknik jargon):

| Görünen | Anlamı (arkada) |
|---|---|
| **Boş** + "Ata" | `open`, manuel |
| **Kuyash üretecek** | `open`, auto |
| **Hazırlanıyor** | run çalışıyor |
| **Senin onayını bekliyor** → `/queue` | job `awaiting_approval` |
| **Zamanlandı** + geri sayım | publish job `queued`, gelecek |
| **Yayınlandı** + hedef durumu | `posts` terminal |
| **Kaçırıldı** + gerçek neden | `skipped` + `skip_reason` |
| **Duraklatıldı** | slot/plan pasif |

- **Jargon yasağı:** "slot", "occurrence", "run", "job", "render_review", "pipeline"
  kelimeleri UI metninde geçmez. Kullanılan: "saat", "paylaşım", "senin videon",
  "Kuyash üretecek". (Faz 21 jargon-gate kuralı.)
- **Dürüst rozetler:** onaylanan içerik "Sen onayladın" ya da "Uyumluluk ajanı
  onayladı (kuyash-v1)" — stored `approvals.mode`'dan dallanır, asla ters çevrilmez.
- **Boş/loading/hata:** hiç slot yoksa → **mod seçici karşılama** (A bölümündeki
  "anlamlı seçim"); hazır video yoksa → `/library` linki; atama hatası → flash;
  hiç bağlı hesap yoksa → `/accounts` linki.

### F2. Plan ayarları (aynı ekranda kart)
Saat dilimi (mevcut), saat ekle/kaldır/duraklat (mevcut) **+ mod radio'su**, lead
süresi, plan duraklat toggle'ı, ve AI-oto slot varsa **dürüst maliyet cümlesi**:
`CostEstimator::estimateRun('full', …)` × haftalık auto slot sayısı → "video başına
yaklaşık $0.10 · haftada ~$0.40 tahmini" (tahmin olduğu açıkça yazılır, ücret değil).

### F3. Kuyruk (`/queue`)
Planlı bir kart: **"Pzt 09:00 için planlandı"** rozeti + salt-okunur planlanan saat;
slot seçici bu kartta gösterilmez (occurrence "ne zaman"ı zaten cevaplıyor). "Bunun
yerine hemen yayınla" ayrı ve açık bir seçim. Saati geçmiş occurrence → "bu saat
geçti, yeni saat seç" + normal seçici geri gelir.

### F4. Dashboard / cockpit
Mevcut "Sıradaki yayın" bandının altına tek satır plan özeti: **"Bu hafta: 3 planlı ·
1 onayını bekliyor · 1 kaçırıldı"** → `/plan` linki. `Cockpit::snapshot()`'a tek bir
salt-okuma ekler; hiçbir sayı uydurulmaz (veri yoksa satır gizlenir).

### F5. Komut paleti
`/plan` komut paletine eklenir (bugün eksik — `layout/partials/command-palette.php`).

---

## G. Compliance & dürüstlük

- **Audit log:** her plan mutasyonu `events` satırı yazar. `events.kind` CHECK'i
  `('transition','compliance','guardrail')` ile sınırlı ve genişletmek tablo rebuild'i
  gerektirir → **yeni kind EKLENMEZ**. Eşleme: atama/iptal/zamanlama/yayın =
  `transition`; duraklat/kill/cap/bütçe-atlama = `guardrail`. Aynı turda Faz 23'ün
  açık borcu da kapanır (slot ekle/kaldır/duraklat/tz değişimi bugün hiç event
  yazmıyor — security L5).
- **AI etiketi:** değişmez. `ComplianceCheckExecutor` herhangi bir TTS veya AI görsel
  varsa etiketi zorunlu kılar. AI-oto run'da **her zaman** TTS vardır → daima
  `pass_with_ai_label` → `posts.ai_label_applied=1` + platform-başına ifşa (0013).
  Manuel modda yüklenen kendi videon TTS içermez → etiket istenmez. İkisi de dürüst,
  kod değişikliği gerekmez — ama **teste bağlanır**.
- **Onay kayıtları:** manuel = gerçek kullanıcı + timestamp, policy NULL; auto = ajan
  + policy sürümü, kullanıcı NULL. 0007'deki CHECK bunu veritabanı invariant'ı yapar;
  plan ekranı stored `mode`'u render eder, asla "sen onayladın" diye yeniden
  etiketlemez.
- **Slop:** haftalık otomatik akış asıl slop riskidir. `SlopScorer` mevcut şekilde
  son 10 run'a karşı puanlar, ≥0.80 bloklar (E16). Plan bunu **zayıflatmaz**; blok
  görünür bir "kaçırıldı" olarak kalır.
- **[SAMPLE] / demo ayrımı:** `bin/visual-seed.php` yeni occurrence'ları yalnız
  `open`/`assigned` durumunda üretir; **uydurma "yayınlandı" hücresi ASLA
  üretilmez**. Ekran görüntüsü için dolu bir hücre gerekiyorsa Faz 22'nin `[örnek]`
  çip deseni kullanılır. Kural aynısı: bir mock'un ürettiği değer, UI'da işaretsiz
  render edilemez.

---

## H. Test & kabul kriterleri

`tests/run.php` konvansiyonu: `check('p24/konu: düz cümle iddia', …)`. Migration liste
assertion'ı (`tests/run.php:312`) 0017 ile güncellenir. **Hiçbir test gerçek yayın
yapmaz** — `MockPublishProvider` (deterministik, ağsız) kullanılır.

**Mutlu yol**
1. `occurrencesBetween` 14 günlük ufukta haftalık slot için tam 2 occurrence döner,
   hepsi `> now`.
2. Materializer iki kez koşunca satır sayısı değişmez (UNIQUE idempotency).
3. Manuel atama: run doğar, `runs.publish_after == occurrence.publish_at`.
4. **Regresyon kilidi:** planlı run onay formunda hiçbir zaman seçilmeden onaylanır →
   `publish_after` KORUNUR (sessiz "hemen yayınla" yok).
5. Publish job'ının `run_after`'ı = occurrence anı; spy provider'a giden
   `PublishRequest.scheduledFor` = aynı an.
6. AI-oto: lead penceresinde tam **bir** run doğar (iki tick → bir run).

**DST**
7. `America/New_York` Çar 09:00 → kış `14:00Z`, yaz `13:00Z`, DST'yi aşan hafta
   `13:00Z` (yerel 09:00 korunur) — mevcut Faz 23 testlerinin occurrence'a taşınması.
8. İleri-atlama boşluğu (02:30) → çözümlenen an kaydedilir ve UI'ya taşınır.
9. Geri-alma tekrarı (01:30 ×2) → tek occurrence, ilk oluşum.

**Hata yolları**
10. Bütçe cap → occurrence `skipped`/`budget_cap`, **`runs` sayısı DEĞİŞMEZ**,
    `guardrail.preflight_block` event var.
11. Kill switch / plan_paused → hiçbir satır yaratılmaz.
12. Tüm hesaplar günlük cap'te → `skipped`/`daily_cap`, sıfır harcama.
13. Owner yok / `full` workflow yok → dürüst `skip_reason`.
14. Grace: 61 dk geçmiş occurrence → publish job `cancelled`, `skipped`/`missed`,
    `publish_after` NULL'lanmış. 59 dk → dokunulmaz.
15. Onaysız içerik slot vaktinde → run YAŞAR, occurrence `skipped`/`not_approved`.
16. Compliance block → `skipped`/`compliance_block`.
17. Kısmi yayın (bir hesap reject) → occurrence "2/3", başarısız hedef adıyla.

**Tenant izolasyonu**
18. Başka workspace'in occurrence'ına atama → reddedilir.
19. Başka workspace'in asset'i ile atama → reddedilir.
20. `PlanRunner` iki workspace'i ayrı ayrı işler; birinin cap'i diğerini etkilemez.

**Dürüstlük**
21. `mode='auto'` onaylı bir post hiçbir şablonda "sen onayladın" render etmez.
22. AI-oto run daima `ai_label_applied=1`; manuel kendi-video run'ı 0 (TTS yoksa).
23. i18n paritesi: her yeni anahtar hem `lang/en.php` hem `lang/tr.php`'de.

**Kabul kriterleri (kapanış)**
- `php tests/run.php` → 0 FAIL, ~+70 test.
- `tools/visual/gate.sh` → 0 console error, 0 yatay taşma, `/plan` 375/768/1280 × en/tr.
- 13 nav route 200 (0×500).
- `php bin/migrate.php` temiz dev DB'de 0017'ye kadar çalışır; **WAL-safe yedek
  alınmadan gerçek dev DB'ye uygulanmaz**.
- Secret grep temiz.
- Reviewer kapıları: **security-auditor** + **ux-reviewer** (her faz sonu) +
  **compliance-reviewer** (otonomi/onay kaydı dokunulduğu için ZORUNLU).

---

## I. Faz sıralaması — risk-önce

Her görevin **çalıştırılabilir kontrolü** var. Sıra, en riskli seam'i öne alır.

**Görev 0 — RİSK SPIKE (ürün kodu YOK, sadece test)**
En riskli iddiayı önce kanıtla: *worker doğru yerel anda ateşliyor ve o an
Zernio'ya `scheduledFor` olarak birebir gidiyor.* Sahte saatle DST'yi aşan bir
`America/New_York` slotu materyalize et, `Worker::tick()`'i sür, spy provider'ın
aldığı `PublishRequest.scheduledFor`'u beklenen UTC anıyla karşılaştır.
→ `cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php tests/run.php`

**Görev 1 — Saf katman + şema**
`SlotResolver::occurrencesBetween`, `OccurrenceMaterializer`, migration 0017,
`OccurrenceRepository` (web `WorkspaceContext` / worker `int $wsId` çift yüzü).
→ scratch DB'de `php bin/migrate.php` + `php tests/run.php`

**Görev 2 — Worker-tarafı run seam'i**
`Engine::startRunFor(int $wsId, …)`; mevcut `startRun(WorkspaceContext …)` ona
delege eder (imza ve tüm çağıranlar **değişmez**). `RunRepository::setPublishAfter`.
Worker sessionless kalır — `WorkspaceContext` bağlanmaz.
→ `php tests/run.php` (mevcut 941 testin hiçbiri kırılmamalı)

**Görev 3 — `PlanRunner` chore (üret + süpür)**
Guardrail sırası (D2/4), grace süpürmesi (E3), `bin/worker.php` başlangıç **ve**
300 sn bloğuna bağlama — claim'den ÖNCE.
→ tohumlanmış scratch DB'de `php bin/worker.php --once` + `php tests/run.php`

**Görev 4 — Manuel atama (web)**
`POST /plan/occurrence/{id}/assign` · `/unassign`, `Engine::cancelRun`,
asset-silme guard'ı (E15). Tümü mevcut global CSRF kapısının arkasında.
→ dev sunucuda (8082) curl ile route'lar + `php tests/run.php`

**Görev 5 — Kuyruk entegrasyonu**
Planlı rozet, salt-okunur planlanan saat, kaçmış-slot mesajı, `publish_after`
koruma regresyonu (H/4).
→ mock provider ile canlı onay akışı + `php tests/run.php`

**Görev 6 — UI**
Takvim ekranı (375 gün-listesi / 768+ hafta ızgarası), hücre durumları, mod seçici
boş-durumu, plan ayarları kartı, dashboard özeti, komut paleti, en+tr paritesi.
→ `tools/visual/gate.sh --only /plan --out storage/visual/p24`

**Görev 7 — Audit, borç kapatma, doküman**
Plan mutasyon event'leri + Faz 23 borçları (`slots.invalid` ayrımı, rate limit),
digest sayıları, **ADR-022**, `phase-24-plan.md` + `phase-24-followups.md`,
checkpoint güncellemesi.
→ tam test + tam görsel gate + 3 reviewer subagent

**Görev 8 — AYRI KAPILI (ertelenebilir)**
"Tam gözetimsiz" yol: plan-kaynaklı run'larda `script_draft`'ın otomatik onayı.
ADR-015'in kilitli auto-onay kapsamını genişletir → **compliance-reviewer GO
olmadan yazılmaz**; kullanıcı onayı gerekir (Bölüm K/2).
→ compliance-reviewer raporu + `php tests/run.php`

---

## J. Kapsam dışı

- **AI-video (Quick Create / image-to-video):** AI-oto mod V1 kalitesinde
  **stok + TTS** üretir. `ai_video` (~$7/run) plan tarafından asla tetiklenmez.
  `/quick` elle kullanılmaya devam eder.
- **Toplu yükleme:** 1 video = 1 atama. Çoklu dosya kuyruğu yok.
- **Genel takvim / CRM / ekip planlama:** paylaşılan takvim, atama-kişiye, yorum,
  onay zinciri, tatil takvimi, sürükle-bırak yeniden sıralama — hiçbiri yok.
- **Cron ifadeleri, aralıklar, tekrar istisnaları.** 0016'nın sınırı korunur.
- **Per-account FARKLI saatte yayın:** `publish_slots.account_id` hâlâ okunmaz;
  engine fan-out değişikliği gerektirir, ertelenmiş kalır.
- **Adapter'ın `timezone: 'UTC'` sabiti:** kaldırılmaz (Faz 23 gerekçesi geçerli —
  `publish_after` zaten UTC anı; Zernio'nun doğrulanmamış `scheduledFor+timezone`
  semantiğine girmek yanlış saatte yayın riski).
- **NOT — AYRI İŞ:** *caption/hashtag'i onay ekranında elle düzenleme.* Distribution-only
  kullanıcı için en değerli tek ekleme budur (checkpoint + memory'de kayıtlı) ama
  **bu planda değil**; kendi bileti olmalı.

---

## K. Açık kararlar / trade-off'lar

1. **Mod granülerliği.** Slot-başına seçtim (Bölüm A). Karma takvim okumayı zorlaştırır;
   workspace-başına tek mod istenirse `publish_slots.mode` yerine tek `workspaces`
   kolonu — plan bu değişikliğe küçük.
2. **`script_draft` otomatik onayı (tam gözetimsiz yol).** ADR-015'in kilitli
   auto-onay kapsamı `pass|pass_with_ai_label` compliance verdict'iyle sınırlı;
   `script_draft` öncesinde compliance verdict'i **yoktur**. Genişletmek bilinçli bir
   politika kararı → **kullanıcı onayı + compliance-reviewer GO** gerekir.
   Alternatif: gözetimsiz yolu hiç açmamak ve "her AI içerik bir insan onayından
   geçer"i kalıcı ürün vaadi yapmak.
3. **Grace penceresi = 60 dk** ve "grace dışında geç yayınlama, atla" kararı. Alternatif:
   hiç grace vermemek (saat geçtiyse asla yayınlama) ya da aynı gün içinde yayınlamak.
4. **Ufuk 14 gün, retention 30 gün.** Daha uzun ufuk = daha fazla satır + daha erken
   taahhüt; daha kısa = takvimde ileriyi planlayamama.
5. **`plan_paused` zamanlanmış yayınları dondurmalı mı?** Hayır dedim (insan onayı
   taahhüttür). Karşı görüş: "duraklat" kelimesi kullanıcıya "her şey durdu" der.
6. **Saat dilimi değişiminde zamanlanmışları oynatmama** (E12) — güvenli ama
   operatöre elle iş yükler.
7. **AI-oto konu kaynağı.** `full` workflow + mevcut `trend_config` niche/region.
   Slot-başına konu/niche istenirse ek kolon gerekir (şu an kapsam dışı).
8. **Atanmış occurrence'ı olan slotu kaldırma:** onaylı cascade-iptal seçtim (E10);
   alternatif düpedüz reddetmek.
9. **Faz numarası ve token:** bu iş **Faz 24**, token `START PHASE 24`. `phase-plan.md`
   14–21 kayıtları korunarak Faz 24 satırı eklenir.
10. **Dev DB migration'ı:** 0017 gerçek dev DB'ye ancak WAL-safe yedekten sonra
    uygulanır (`kuyash.pre-0017.*.bak.sqlite`) — Faz 22/23'te kurulan kural.
