# Faz 4 Planı — Workflow Engine (ONAYLI — START PHASE 4 bekliyor)

> Plan-mode'da 2026-06-12'de kullanıcı tarafından onaylandı. İmplementasyon yalnızca
> `START PHASE 4` token'ı ile başlar. Bu dosya onaylanan planın tam kopyasıdır.

## Context (mevcut durum + neden bu faz)

Faz 0–3 kabul edilip commit'lendi (`ee042fa` → `b9728ed` → `f7121e0`); 180 test yeşil;
auth/CSRF/tenant-isolation + Content Library canlı; dış entegrasyon yok. Sıradaki tek faz
**Faz 4 — Workflow Engine**: canonical node'lu workflow JSON modeli, doğrulama,
deterministik yürütme, SQLite job queue + worker, retry/failure, **self-healing watchdog**
(stuck-job → requeue/fail + dead-letter görünümü), **append-only event log** (timeline/audit).
Bu motor; Faz 5 (script engine), 7 (medya üretimi), 9 (compliance) ve 10 (publish) işlerinin
üzerinde koşacağı omurga. Ortam doğrulandı: SQLite 3.53 (`RETURNING` var), pcntl yüklü.

**Kullanıcı kararı (planlama oturumunda):** Builder UI **read-only + run trigger** — node
track salt-okunur, ayar düzenleme UI'sı Faz 5+'a (mock'lar ayarları yok sayar); minimal
"run başlat" tetikleyicisi (distribution için library asset seçimi) dahil.

## Kapsam (precise scope)

1. **Şema (0003_workflow_engine.sql)** — 5 tablo, hepsi workspace_id'li:
   `workflows` (name, template CHECK(full|distribution), nodes_json),
   `runs` (workflow_id, entity_type CHECK(trend|library|quick_create), entity_id,
   **nodes_json snapshot** — geçmiş immutable, status CHECK(running|awaiting_approval|
   awaiting_recording|completed|failed|cancelled), current_node, created_by),
   `jobs` (sqlite-queue-notes alanlarının tamamı: run_id, node, step, type, status
   CHECK(queued|processing|awaiting_approval|awaiting_recording|ready|failed|published|
   cancelled), payload/result_json, retry_count/max_retries, error_message,
   idempotency_key UNIQUE partial idx, priority, run_after, worker_id, cost_cents,
   provider; claim idx (status,run_after,priority,id)),
   `events` (append-only: level CHECK(info|warn|error), kind CHECK(transition|compliance|
   guardrail), key+params_json — demo log şekli; **UPDATE/DELETE'i SQL trigger'ları
   ABORT eder**),
   `approvals` (run_id, job_id, node, decision CHECK(approved|rejected), mode
   CHECK(manual|auto — 'auto' Faz 9 için şemada, kod üretmez), decided_by REFERENCES
   users, decided_at).
   CHECK'ler bilinçli geniş: awaiting_recording, quick_create kod yolu olmadan şemada
   (SQLite CHECK değişimi tablo rebuild ister).

   Referans migration iskeleti (tasarım ajanından, implementasyonda rafine edilebilir):
   - workflows: id PK, workspace_id FK, name, template, nodes_json, created/updated_at;
     idx (workspace_id)
   - runs: id PK, workspace_id FK, workflow_id FK, entity_type, entity_id NULL,
     nodes_json, status DEFAULT 'running', current_node NULL, created_by FK users,
     created/updated_at; idx (workspace_id, created_at DESC)
   - jobs: id PK, workspace_id FK, run_id FK, node, step INT, type, user_id NULL,
     entity_type NULL, entity_id NULL, status DEFAULT 'queued', payload_json DEFAULT '{}',
     result_json NULL, retry_count DEFAULT 0, max_retries DEFAULT 3, error_message NULL,
     idempotency_key NULL, priority DEFAULT 100, run_after NOT NULL, worker_id NULL,
     cost_cents NULL, provider NULL, created_at, started_at NULL, finished_at NULL;
     idx'ler: (status,run_after,priority,id) claim, (workspace_id,created_at DESC),
     (run_id,step), UNIQUE partial (idempotency_key) WHERE NOT NULL
   - events: id PK, workspace_id FK, run_id NULL FK, job_id NULL FK, level, kind, key,
     params_json DEFAULT '{}', created_at; idx (workspace_id,id DESC), (run_id);
     trg_events_no_update + trg_events_no_delete → RAISE(ABORT,'events is append-only')
   - approvals: id PK, workspace_id FK, run_id FK, job_id FK, node, decision, mode
     DEFAULT 'manual', decided_by FK users, decided_at; idx (workspace_id,decided_at DESC)

2. **Canonical registry (`src/Workflow/Nodes.php`)** — node listesi, iki template,
   locked set (COMPLIANCE), node→job-type haritası, tip başına timeout + max_retries
   default'ları, terminal/geçiş status setleri. Tek doğruluk kaynağı; config DEĞİL
   (kullanıcı ayarlanabilirliği yok — canonical adlar kod invariant'ı).
   Node→job eşlemesi 1:1, tek istisna **PUBLISH → render_review + publish** (content-
   pipeline sırası: compliance_check → render_review → publish; render_review onay
   kapısıdır, PREVIEW değil). Job tipleri (13): trend_fetch, idea_generation,
   script_draft, tts, asset_fetch, assembly, caption_generation, hashtag_generation,
   music_note, preview, compliance_check, render_review, publish.
   Full zincir 14 job, distribution 8 job. script_draft ve render_review →
   awaiting_approval durağı; awaiting_recording yalnız şema-stub (gerçek brief'ler Faz 5).

3. **Doğrulama (`WorkflowValidator`)** — node dizisi iki template'ten birine TAM eşit
   (alt-küme mantığı yok — spekülatif genellik); COMPLIANCE mevcut+locked:true; VISUALS
   source ∈ {library,stock,ai}; settings schema-light (yalnız skaler değerler, sınırlı
   anahtar sayısı/string uzunluğu, iç içe yapı reddedilir). Kayıtta VE run başlangıcında
   koşar.

4. **Engine (`src/Workflow/Engine.php`)** — startRun (run + ilk job tek kısa tx; doğrulama
   + distribution'da ready video asset zorunluluğu), advance (**bir-seferde-bir job**:
   finalize tx'i sonucu yazar + SONRAKİ job'ı enqueue eder + runs.current_node/status'u
   AYNI tx'te günceller — saf pointer-advance, dependency/blocked durumu yok, DAG yok;
   "sonraki" = run'ın nodes_json snapshot'ından deterministik saf fonksiyonla step+1),
   approve/reject/retry. Tüm geçişler `WHERE status=expected` guard'lı tek UPDATE +
   `changes()` kontrolü (yarış kaybedenler sakin "already decided/claimed" yoluna düşer).

5. **Executor seam** — `JobExecutor` interface (`execute(job, priorResults): JobResult`) +
   `JobResult` VO (status ready|awaiting_approval|failed|published, result array,
   errorMessage, costCents, provider) + `ExecutorRegistry::for(type)` (Faz 5/7/10 gerçek
   executor'ları tek satırla kaydeder — adapter kuralı) + **tek `MockExecutor`** (13 tip,
   match ile; run_id/job_id tohumlu deterministik sahte çıktılar YALNIZ result_json'da —
   dosya üretimi yok; provider 'mock', mock maliyetler gerçek harcama gibi sunulmaz;
   compliance_check sonucu 'mock-v0' damgalı, hep pass — warn/block Faz 9).
   Tek gerçek dokunuş: distribution'da asset_fetch, run'ın GERÇEK ready library
   asset'ini (id, title, duration, ai bayrağı) result_json'a çözer; sahte stock/ai
   asset satırı ASLA yaratılmaz.

6. **Worker (`Worker::tick()` + ince `bin/worker.php`)** — tick: atomic claim
   (`UPDATE ... WHERE id = (SELECT id FROM jobs WHERE status='queued' AND
   run_after <= :now ORDER BY priority, id LIMIT 1) RETURNING *`), execute (tx DIŞINDA —
   transaction'da yavaş iş tutulmaz), finalize (kısa tx). Boşsa false döner.
   bin flag'leri: --once, --max-jobs=N, --sleep-ms=500; worker_id = host:pid:4hex;
   pcntl_async_signals + SIGTERM/SIGINT stop-flag (extension_loaded guard'lı; fallback:
   --max-jobs + watchdog zaten iyileştirir). Retry: başarısızlıkta retry_count+1 <
   max_retries → `run_after = now + 2^retry_count * 5s` ile requeue; tükenince failed +
   error_message + run failed. Test edilebilirlik: Clock closure enjeksiyonu (ISO now);
   testler tick()'i :memory: üzerinde process'siz sürer.

7. **Watchdog (`Watchdog::sweep(now)`)** — boş tick'te + her ~20 dolu tick'te:
   started_at, tip-bazlı timeout'u (Nodes'tan) aşmış processing job'lar → retry_count+1
   ile requeue (warn event `log.watchdog_requeued`) ya da tükenmişse dead-letter (error
   event) + run failed. Hiçbir run sonsuza dek takılı kalamaz.

8. **Maintenance (worker chore'ları)** — `Maintenance::pruneLoginAttempts()` (Faz 2
   followup) + `Maintenance::sweepOrphanAssets()` (Faz 3 followup; YALNIZ 1 saatten eski
   VE assets tablosunda olmayan dosyalar — in-flight upload yarışı imkânsız; tablo önce
   okunur, dosya işlemleri tx dışında). Worker döngüsünde ~5 dk'da bir + başlangıçta;
   testlerden doğrudan çağrılabilir.

9. **Event log (`EventLog::record(workspaceId, level, kind, key, params, runId?, jobId?)`)**
   — HER geçişle AYNI kısa tx içinde yazılır (geçişsiz event, event'siz geçiş yok).
   Yazım noktaları: job created/claimed/finished/awaiting/failed/requeued/cancelled/
   published, run started/completed/failed/cancelled, onay kararları, watchdog
   aksiyonları, manuel retry. kind demo'nun üçlüsü; onay/watchdog = ayırt edici key'li
   transition. Timeline `ORDER BY id` (saniye hassasiyetli created_at anlık mock'larda
   çakışır). Retention yok (V1). key+params_json şekli TR i18n geçişini mekanik tutar.

10. **Bootstrap split + CLI ErrorHandler** — `src/bindings/{core,web,worker}.php`;
    `src/bootstrap.php` mevcut return-Container sözleşmesini korur (core+web require
    eder — index.php/testler değişmez), yeni `src/bootstrap-worker.php` (core+worker).
    Worker binding'lerinde Session/Csrf/View/WorkspaceContext YOK. ErrorHandler'a
    plain-text CLI modu (stderr + storage/logs, HTML yok — Faz 1 followup).
    **Worker izolasyon düzlemi:** session yok → WorkspaceContext worker'da yok; worker,
    claim ettiği satırın taşıdığı workspace_id ile yazar (`WHERE id=? AND workspace_id=?`
    her sonraki yazımda); claim bilinçli global (tek kuyruk tüm workspace'lere hizmet eder).

11. **UI (4 sayfa + run detayı; app shell + BINDING stil rehberi)** —
    `/workflows`: liste; ilk ziyarette `WorkflowRepository::ensureDefaults(ctx)` ile
    "Full pipeline" + "Distribution" workflow'ları idempotent seed (migration workspace
    bilemez — kod tarafında).
    `/workflows/{id}`: read-only lineer node track (COMPLIANCE kilit rozeti, salt-okunur
    ayarlar, template etiketi) + **run trigger**: distribution → ready library video
    asset `<select>` ile `POST /workflows/{id}/run`; full → entity'siz POST
    (entity_type='trend', entity_id NULL, mock trend). Run + ilk job tek tx, /queue'ya
    redirect + flash.
    `/queue`: düz job listesi en-yeni-üstte (mono tip + entity etiketi + durum rozeti +
    failed'da retry butonu → `POST /queue/job/{id}/retry`: retry_count=0, error temiz,
    queued + event), onaylar kartı (script taslak metni / compliance özeti +
    approve/reject → `POST /queue/job/{id}/approve|reject`; guard'lı UPDATE, yarışta
    "already decided" flash'i), runs kartı (durum + current node + link).
    Progress bar YOK (mock'lar anlık — demo theater olur).
    `/runs/{id}`: node track + node başına job durumu + **event timeline** — append-only
    log hedefinin görünür kanıtı.
    `/logs`: event feed en-yeni-üstte, level/kind filter chip'leri (GET param), LIMIT 200,
    düz reload (SSE/auto-refresh yok — Faz 7'de işler gerçekten yavaşlayınca).
    Sidebar nav: + Workflows, Queue, Logs (demo sırası). Gerekli job-row/badge/
    node-track/timeline CSS'i demo'dan app.css'e port edilir.
    Onay kaydı UI'da dürüst: "Approved by you · {email} · {time}" (users join'i) —
    sahte aktör asla.

12. **Messages sözlüğü** — `src/Core/Messages.php`: paylaşılan message-key sözlüğü
    (Queue/Workflow controller'ları 2./3. flash tüketicisi — followup tetiklendi);
    LibraryController kendi MESSAGES map'inden buna geçer. TR i18n geçişinde
    değiştirilecek tek harita.

13. **Testler (~70 yeni, hedef ≈250)** — gruplar:
    (1) migration: tablolar, CHECK redleri, events trigger'ları UPDATE/DELETE'te fırlatır;
    (2) validator: iki template geçer; non-canonical node, yanlış sıra, eksik/kilitsiz
    COMPLIANCE, geçersiz VISUALS source, bozuk settings reddedilir;
    (3) tenant isolation: workflows/runs/jobs/events cross-workspace null/boş; approve/
    retry route'ları cross-tenant 404;
    (4) claim: priority,id sırası; gelecekteki run_after görünmez; ardışık iki claim aynı
    job'ı alamaz; boş tick false;
    (5) retry/backoff: fırlatan sahte executor → gelecek run_after + count ile requeue;
    tükenme → failed + error_message + run failed + dead-letter listede;
    (6) watchdog: bayat processing → warn event'le requeue; tükenmiş → failed;
    (7) uçtan uca full run (tick() döngüsüyle): script onayında durur → approve(user) →
    devam → render_review'da durur → approve → published + run completed; yürütme sırası
    template'le birebir; approvals satırları dürüst; fazladan tick no-op;
    (8) distribution run uçtan uca: seed'li GERÇEK asset asset_fetch.result_json'da;
    (9) reject → run cancelled, başka job yok;
    (10) manuel retry sıfırlar ve yeniden koşar;
    (11) events en-yeni-üstte + monotonik id;
    (12) maintenance: prune yalnız eski satırları siler; sweep eski-bilinmeyen dosyayı
    siler, taze VE bilinen dosyaları korur;
    (13) ErrorHandler CLI modu smoke.

### Yeni dosyalar
`database/migrations/0003_workflow_engine.sql` · `src/Workflow/{Nodes, WorkflowValidator,
WorkflowRepository, RunRepository, JobRepository, EventLog, Engine, JobResult, JobExecutor,
ExecutorRegistry, MockExecutor, Worker, Watchdog, Maintenance}.php` ·
`src/Core/Messages.php` · `src/bindings/{core,web,worker}.php` · `src/bootstrap-worker.php` ·
`bin/worker.php` · `src/Controllers/{Workflow,Queue,Logs}Controller.php` ·
`templates/workflows/{index,show}.php`, `templates/queue/index.php`,
`templates/runs/show.php`, `templates/logs/index.php`

### Değişen dosyalar
`src/bootstrap.php` (binding'ler split dosyalarına), `src/routes.php`,
`src/Core/ErrorHandler.php` (CLI modu), `templates/layout/app.php` (nav),
`public/assets/css/app.css` (job-row/node-track/timeline portu),
`src/Controllers/LibraryController.php` (Messages'a geçiş), `tests/run.php`.

## Non-goals (açık olarak DIŞARIDA)

Otonom ajan döngüleri · HERHANGİ gerçek dış çağrı (tüm executor'lar mock) ·
Auto onay modu / kill switch / cap'ler (Faz 9 — şemada 'auto' değeri hazır, kod yok) ·
compliance skorlama/warn/block (Faz 9; mock hep pass + 'mock-v0') · gerçek script/brief +
reject-to-revise döngüsü (Faz 5; reject = cancel) · render artifact'leri/preview
dosyaları/used_in (Faz 7) · publish scheduling/webhook (Faz 10) · maliyet tahmini/credit
gating (Faz 11) · canlı progress/SSE/auto-refresh (Faz 7) · workflow ayar DÜZENLEME UI'sı
(Faz 5+; karar: read-only) · awaiting_recording kod yolu (şema-stub) · genel amaçlı
DAG/branching (ASLA — lineer track ürünün kendisi) · library pagination + tags json_each
(ertelendi).

## Build sırası

1. 0003 migration (+trigger'lar) → şema/immutability testleri
2. Nodes registry + WorkflowValidator → testler
3. Repository'ler + EventLog → tenant isolation testleri
4. Engine + JobExecutor/JobResult/Registry/MockExecutor → zincir genişletme testleri
5. Worker.tick() + claim + backoff + Watchdog → Clock-enjekteli testler
6. Bootstrap split + ErrorHandler CLI + bin/worker.php + Maintenance → testler
7. Controller'lar + template'ler + route'lar + nav + Messages + CSS portu
8. Uçtan uca testler (full/distribution/reject) → manuel tur → reviewer'lar
   (php-architect + security-auditor + ux-reviewer) → followups + checkpoint + VERDICT

## Kabul kriterleri (ölçülebilir)

- [ ] Distribution run'ı gerçek bir library asset'iyle yalnız `bin/worker.php` ile
      `published`/`completed`'a ulaşır; render review'da bir kez durur; onay kaydı
      "Approved by you · {email} · {timestamp}" olarak dürüst. Full run ek olarak
      script onayında durur.
- [ ] Atomic claim testle kanıtlı; fırlatan executor → exponential backoff'la requeue,
      max_retries'te dead-letter + error_message; /queue'da görünür + çalışan retry butonu.
- [ ] Bayat processing job watchdog'la requeue/fail edilir (warn/error event'leriyle) —
      hiçbir run sonsuza dek takılı kalmaz.
- [ ] Her geçişin aynı-tx event satırı var; events SQL seviyesinde UPDATE/DELETE reddeder;
      /runs/{id} timeline'ı, /logs filtreleri çalışır.
- [ ] Tenant isolation: runs/jobs/events/approvals cross-tenant'ta 404/boş; testli.
- [ ] Sıfır ağ çağrısı (grep: curl/file_get_contents URL yok); tüm testler PASS (≈250);
      lint temiz; secret yok; php-architect + security-auditor + ux-reviewer review tamam.

## Manuel test adımları

`cd ~/Desktop/Kuyash && php bin/migrate.php` → login → /workflows (default'lar belirir) →
distribution run başlat (asset seç) → `php bin/worker.php --once` tekrarları → /queue
render review'da durur → approve → worker → published; /runs/{id} timeline + /logs
chip'leri → sqlite3 ile bir processing job'ı yaşlandır → worker → watchdog requeue →
zorla fail → retry butonu → full run ile script onayı → `--sleep-ms` worker'a SIGTERM →
temiz çıkış. (php = /opt/homebrew/opt/php@8.3/bin/php)

## Riskler

1. **Status enum çift görevde** (kuyruk mekaniği + domain 'published') → terminal/geçiş
   setleri Nodes'ta const; her geçiş guard'lı UPDATE + changes() — yasadışı geçiş imkânsız.
2. **SQLite CHECK'leri kalıcı** → 0002'deki gibi bilinçli geniş enum'lar bugünden.
3. **Web/worker yazma yarışı** (approve vs finalize vs watchdog) → tek-satır guard'lı
   UPDATE'ler; kaybeden changes()=0 görür; testli.
4. **Faz 5/9/10'a kapsam sızması** → mock'lar yalnız pass/'mock-v0'; reject=cancel;
   non-goal'lar faz raporunda yinelenir.
5. **Orphan sweep'in canlı upload'ı silmesi** → yalnız 1 saatten eski + assets'te
   olmayan dosyalar; taze-dosya koruma testi.

## Açık sorular

Yok — tek fork (builder UI seviyesi: read-only + run trigger) kullanıcıyla netleştirildi.

## Token

Bu faz yalnızca kullanıcı **`START PHASE 4`** yazınca başlar. Token gelene kadar hiçbir
implementasyon yapılmayacak; plan onayı dahil hiçbir genel onay ifadesi kodu açmaz.
