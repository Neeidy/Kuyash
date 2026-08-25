# Faz 25 — kapanış kaydı

> Bu dosya faz YARIM kaldığında "devam noktası" olarak yazılmıştı. İş bitti;
> içeriği artık **ne bulunduğu ve nasıl kapatıldığı** kaydı. Kalan hiçbir
> blocker yok. Ertelenenler → `.claude/docs/phase-25-followups.md`.
> Tasarım otoritesi: `.claude/docs/phase-25-plan.md`. Kararlar: **ADR-023**.

## Durum

- **1041 PASS / 0 FAIL** · görsel gate **87 PNG / 0 console error / 0 yatay taşma**
  · 14 canlı route 200 · lang paritesi **818 = 818** · secret taraması temiz.
- **Üç reviewer da GO:** security-auditor GO, ux-reviewer GO, compliance-reviewer GO.
  Üçü de ilk turda NO-GO/MEDIUM döndürmüştü; hepsi kapatıldı ve yeniden koşturuldu.
- Migration YOK · `approvals` ve `events.kind` el değmedi · `.env` flip YOK ·
  testlerde gerçek yayın YOK (Mock/Spy provider).

## Kapatılan blocker'lar (ne yanlıştı, neden önemliydi)

1. **Onay ile publish arasındaki pencerede düzenleme kapanıyordu.** Onaydan sonra
   `render_review` artık beklemiyor, `publish` job'ı ise henüz doğmamış oluyordu →
   `final_render` sürerken editör kilitliydi. Pencere `final_render`
   queued/processing'i de kapsayacak şekilde genişletildi: o adım videoyu render
   eder, metne dokunmaz, ve adaptöre hiçbir şey gitmemiştir.
2. **Reddedilen kayıt yazılanı yok ediyordu.** POST → redirect → GET; GET saklanan
   metni basar. Tek bir platformun boş olması ÜÇ gövdeyi ve etiketleri birden
   siliyordu. → `Content\DraftStash` (tek sayfalık, **workspace + run** anahtarlı).
3. **Aynı yazma yolu 500 verebiliyordu.** WAL'de deferred `BEGIN` + read-then-write,
   worker commit'iyle çakışınca `BUSY_SNAPSHOT`; `busy_timeout` bunu kapsamıyor.
   → `Database::immediateTransaction` + 2 denemelik retry + dürüst "yeniden yükle".
4. **Salt-okunur editör yayınlanmış paylaşımı BUGÜNKÜ Ayarlar'la anlatıyordu.**
   Toggle çevirmek geçmişi yeniden yazıyordu. → iş bittiyse editör ifşa hakkında
   hiçbir şey iddia etmiyor; geçmişi `posts.ai_label_applied` taşıyor.
5. **Kaydet-vs-Onayla tuzağı** + kaydettikten sonra bile "Kuyash'ın yazdığı çıkar"
   diyen yanlış cümle. → dirty-guard + JS'siz de görünen statik satır + `edited`'e
   göre dallanan ifade.
6. **Sayaçlar yanlış kartı güncelliyordu** (doküman-geneli `querySelector`) —
   /queue onay bekleyen her paylaşım için bir editör basıyor.
7. **İptal edilmiş run'a "Already published" deniyordu** — kayıt ekranında yanlış
   yayın iddiası. → ayrı `run_stopped` ifadesi, çelişen ikinci mesaj silindi.
8. **Edit hash'i yazılmamış etiketleri kapsayabiliyordu** ve ikinci CAS'ın
   `rowCount()`'u kontrol edilmiyordu → publish, operatörü yapmadığı bir
   kurcalamayla suçlayıp run'ı kalıcı öldürüyordu.

## Kendim bulduklarım (reviewer listesinde yoktu)

- "TikTok ve YouTube'da not native bayrakla verilir" satırı, toggle'ı **kapalı**
  platformları da sayıyordu → yanlış güvence. Artık yalnız etkin olanları adlandırır.
- `DraftStash`'i yalnız run id ile anahtarlamak, workspace başına yeniden başlayan
  id'ler yüzünden başka workspace'in taslağını gösterebiliyordu (testte yakalandı).
- Seed'de yayınlanmış run'ın COMPLIANCE düğümü "pending" görünüyordu ve
  `render_review` yanlış node'daydı (`PREVIEW` → motorun kullandığı `PUBLISH`).

## Kalıcı olarak doğrulanmış davranış (regresyon kilitleri)

Düzenlenmemiş run bit-bazında Faz 25 öncesiyle aynı · ifşa hiçbir edit'le
sıyrılamaz (publish anında kompoze) · hash uyuşmazlığı `failedPermanent` ·
`approvals` ne yeniden yazılır ne yeniden açılır · limitler **warn-only** ·
tek blok = bağlı platformda boş caption · her okuma/yazma `workspace_id` filtreli.
