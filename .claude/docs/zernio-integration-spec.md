# Zernio Entegrasyon Dokümanı — 12 Madde (Kuyash doc-gate)

> docs.zernio.com'dan derlendi (2026-06-14). Bu dosya, Kuyash'ın `ZernioPublishProvider` adapter'ını yazmak
> için gereken 12 maddenin tamamını içerir. CC bunu okuyup gerçek adapter'ı yazar; sonra `ZERNIO_MOCK=false`.
> Kuyash kapsamı: Instagram Reels + TikTok + YouTube Shorts (15–45 sn 9:16 video).

---

## 1. Resmi dokümantasyon linki ✅
- Doküman: https://docs.zernio.com/
- **Base URL:** `https://zernio.com/api/v1`
- OpenAPI spec (adapter'ı schema'dan üretmek için): https://docs.zernio.com/api/openapi
- LLM-dostu tam doküman: https://docs.zernio.com/llms-full.txt
- PHP SDK (opsiyonel — Kuyash pure-PHP, curl ile de gidebilir): `composer require zernio-dev/zernio-php`

## 2. Kimlik doğrulama (auth) ✅
- Her istek API key ister: HTTP header `Authorization: Bearer sk_...`
- Key formatı: `sk_` + 64 hex karakter (toplam 67). SHA-256 hash'lenir, **yalnız oluşturulurken bir kez gösterilir**.
- Alma: zernio.com → **Settings → API Keys → Create API Key** → hemen kopyala.
- `.env`: `ZERNIO_API_KEY=sk_...` (SDK bu env değişkenini varsayılan okur).

## 3. Yayınlama endpoint listesi ✅
| Amaç | Method + Path |
|---|---|
| Profil oluştur (marka/proje konteyneri) | `POST /api/v1/profiles` |
| OAuth bağlama URL'i al | `GET /api/v1/connect/{platform}?profileId=...` |
| Bağlı hesapları listele | `GET /api/v1/accounts` |
| Medya için imzalı yükleme URL'i | `POST /api/v1/media/presign` |
| **Post oluştur / zamanla / yayınla / taslak** | `POST /api/v1/posts` ← ana endpoint |
| Analitik | `GET /api/v1/analytics?platform=...&fromDate=...&toDate=...` |

Tam referans: docs.zernio.com/posts/create-post, /profiles/list-profiles vb.

## 4. Medya yükleme akışı ✅
İmzalı-URL (presigned) akışı:
1. `POST /api/v1/media/presign` body `{ "filename":"reel.mp4", "contentType":"video/mp4" }`
2. Yanıt: `{ uploadUrl, publicUrl, key, expiresIn:3600 }`
3. Dosyayı **PUT** ile `uploadUrl`'e yükle (auth header GEREKMEZ).
4. `publicUrl`'ü post'un `mediaItems`'ına koy: `mediaItems:[{ "url":publicUrl, "type":"video" }]`
- Geçici depo **7 gün** sonra silinir; post yayınlanınca kalıcıya kopyalanır → upload'tan sonra 7 gün içinde yayınla.
- Formatlar: görsel JPG/PNG/GIF/WebP, video MP4/MOV/AVI/WebM, **max 5 GB**.
- **KRİTİK:** medya URL'i **herkese açık, ham bayt** dönmeli (auth arkasında değil, HTML değil). Google Drive/Dropbox/iCloud çalışmaz.
  → Kuyash için en temiz yol: medyayı **Zernio'nun presign endpoint'ine yükle** (R2 signed URL yerine), Zernio kendi CDN'ine alsın.

## 5. Publish payload — platform başına ✅
```
POST /api/v1/posts
{
  "content": "caption metni",
  "scheduledFor": "2026-06-20T12:00:00",   // opsiyonel (zamanla)
  "timezone": "Europe/Istanbul",            // scheduledFor ile
  "publishNow": true,                        // opsiyonel (hemen)
  "mediaItems": [{ "url": "<publicUrl>", "type": "video" }],
  "platforms": [
    { "platform": "instagram", "accountId": "acc_...",
      "platformSpecificData": { "shareToFeed": true } },   // IG contentType enum = [story] ONLY; Reels auto-detected
    { "platform": "tiktok",  "accountId": "acc_..." },
    { "platform": "youtube", "accountId": "acc_..." }
  ]
}
```
**Instagram `platformSpecificData` alanları (ham openapi):** `contentType` **enum yalnız `["story"]`** (Reels alanı YOK — 9:16 <90sn videodan OTOMATİK Reel algılanır), `shareToFeed` (Reel’i ana feed’e de koy), `collaborators[]` (≤3), `userTags[]`, `thumbOffset`/`instagramThumbnail`, `audioName`, `firstComment`, `trialParams`. **AI alanı YOK.**
**IG Reels şartı:** dikey 9:16, ≤90 sn → otomatik Reel. `contentType` GÖNDERME (story değilse); yalnız `shareToFeed:true`. Kuyash 15–45sn 9:16 buna uyar.
**TikTok / YouTube:** kendi `platformSpecificData` alanları var — CC bu ikisini bağlarken docs.zernio.com/platforms/tiktok ve /platforms/youtube'u okusun.

## 6. Schedule (zamanlama) payload ✅
- `scheduledFor` (ISO datetime) + `timezone` → ileri tarihli yayın.
- `publishNow:true` → hemen yayınla.
- İkisini de atla → **taslak (draft)**.
- Alternatif: profil **queue** (tekrarlayan zaman slotları) — docs.zernio.com/guides/queue-scheduling.

## 7. Hesap bağlama (OAuth) akışı ✅
Kavramlar: **Profile** (marka konteyneri) → **Account** (bağlı sosyal hesap) → **Post**.
1. `POST /api/v1/profiles` → `profile._id` al.
2. `GET /api/v1/connect/instagram?profileId=prof_...` → `authUrl` döner.
3. Kullanıcıyı `authUrl`'e yönlendir → platformda yetki verir → geri yönlenir, hesap bağlanır.
4. `GET /api/v1/accounts` → `account._id` al (post'larda `accountId` olarak kullanılır).
- IG, **Facebook Business OAuth** ile bağlanır. **Sosyal OAuth token'ları Zernio'da durur, Kuyash'ta değil** (Kuyash sadece account referansı + sağlık tutar — mevcut tasarımla uyumlu).

## 8. Webhook formatı + imza (HMAC) ✅
- Webhook ayarı: `POST /webhooks` (Create webhook settings) → event'leri seç → Zernio senin URL'ine `POST` atar → `2xx` (5 sn içinde) dön.
- **İmza doğrulama:** secret tanımlıysa her istekte `X-Zernio-Signature` header'ı gelir = **`HMAC-SHA256(rawBody, ZERNIO_WEBHOOK_SECRET)` → lowercase hex**. Ham gövdeyi oku, HMAC'i hesapla, header ile karşılaştır; **eşleşmezse 400/401 dön, işleme**. (Kuyash tasarımı zaten ham-gövde HMAC bekliyor — uyumlu.)
- **Idempotency:** `payload.id` (UUID) = `X-Zernio-Event-Id` header'ı. At-least-once teslim → event-id ile dedupe et.
- **Retry:** 7 denemeye kadar, üstel backoff (24s cap); başarı = 5sn içinde 2xx; sonra dead-letter.
- **Kuyash için ilgili event'ler:** `post.published`, `post.failed`, `post.partial`, `post.cancelled`, `post.scheduled`, `account.connected`, `account.disconnected`.
- Post webhook gövdesi: `{ id, event, post:object, timestamp }`.
- `.env`: `ZERNIO_WEBHOOK_SECRET=...` (boşsa Kuyash webhook'u fail-closed yapmalı).

## 9. Rate limit'ler ✅ (platform-düzeyi)
- **Instagram:** 100 post / 24 saat (kayan pencere, tüm içerik türleri dahil). IG genel başarısızlık oranı ~%10.2 (genelde medya-URL/otomasyon-tespit kaynaklı).
- Global Zernio API rate limit'i okuduğum sayfalarda açık yazılı değil → CC adapter'da yanıt header'larını (varsa `X-RateLimit-*`) ve OpenAPI spec'i kontrol etsin; platform caps yukarıda.

## 10. Hata yanıtı örnekleri ✅
- IG yaygın hatalar (docs.zernio.com/platforms/instagram → "Common Errors"): "Cannot process video from this URL" (cloud-storage linki → CDN/direct URL kullan), "maximum of 100 posts per day", "Instagram blocked your request" (otomasyon-tespit → sıklığı düşür), "Duplicate content detected" (caption/medya değiştir), "Instagram access token expired" (**hesabı yeniden bağla**, `account.disconnected` webhook'unu dinle).
- Yayın sonucu: `post.failed` (hepsi başarısız) / `post.partial` (kısmi) webhook'ları + post nesnesi. Yapısal hata şeması: OpenAPI spec.
- → Kuyash'ın mock'taki 8 hata modu (success/reject/rate-limit/auth-fail/webhook/partial/lost-webhook/duplicate) bu gerçek hatalarla eşleştirilecek.

## 11. Fiyat / tier ✅
- **Kullanım-bazlı, bağlı-hesap başına.** İlk **2 hesap ücretsiz** ($12/ay kredi). Sonra kademeli: **3–10 = $6**, 11–100 = $3, 101–2000 = $1 / hesap / ay.
- Her şey dahil: analytics, inbox, ads, sınırsız post, tam API, 14+ platform. Günlük prorate (account-days ÷ 30 → kademeli tarife).
- Ayrı kalemler (Kuyash'ı ilgilendirmez): X/Twitter API passthrough, WhatsApp numaraları.
- → Kuyash kişisel kullanım: 1 IG + 1 TikTok + 1 YouTube = 3 hesap → ilk 2 ücretsiz, 3.'sü $6/ay civarı.

## 12. Platform AI-label / flag alanları ✅ DÜZELTİLDİ (Faz 10, ham openapi.yaml ile doğrulandı)
> ⚠️ **Önceki not (“hiçbir platformda alan yok”) YANLIŞTI** — özet, 1.4 MB’lık openapi.yaml’ı truncate eden
> bir araçtan derlenmişti. Ham `https://zernio.com/openapi.yaml` grep’i gerçeği gösterdi:

| Platform | Yerel AI-ifşa alanı (openapi.yaml) | Kuyash davranışı |
|---|---|---|
| **YouTube** | ✅ `platformSpecificData.containsSyntheticMedia: boolean` (“AI-generated content disclosure… YouTube may add a label”) | native bayrak set edilir |
| **TikTok** | ✅ `platformSpecificData.videoMadeWithAi: boolean` (“Set true to disclose AI-generated content”) | native bayrak set edilir |
| **Instagram** | ❌ **YOK** (yalnız contentType/shareToFeed/collaborators/userTags/thumbnails/audioName/firstComment/trialParams) | caption’a “Made with AI” / “AI ile üretildi” satırı eklenir |
| *(Twitter/X)* | `madeWithAi` — Kuyash kapsamı dışı | — |

- **Kuyash kararı (ADR-021):** **Hibrit + per-platform toggle** (Ayarlar → AI ifşası). `aiLabelApplied=true` (gerçekçi AI medyası)
  olduğunda: YouTube/TikTok native bayrak, Instagram caption satırı. Varsayılan: 3 platformda da AÇIK. Operatör herhangi
  birini kapatabilir → publish’te `compliance.ai_disclosure_suppressed` truthful audit event’i yazılır (asla sessiz değil).
- IG’nin caption ifşası, owner locale’ine göre yerelleştirilir (EN/TR).

---

## CC için adapter yazma notları (özet)
- Tek kritik endpoint `POST /api/v1/posts`; medya önce `POST /api/v1/media/presign` + PUT.
- Auth Bearer `sk_`; webhook HMAC-SHA256 raw-body / `X-Zernio-Signature`; dedupe `payload.id`.
- Kuyash 9:16 15–45sn → IG için `contentType` YOK (otomatik Reel), `shareToFeed:true`. TikTok/YouTube native AI alanları (videoMadeWithAi / containsSyntheticMedia).
- **AI-label:** YouTube `containsSyntheticMedia` + TikTok `videoMadeWithAi` native bayrak; IG caption-ifşa (alan yok). Hibrit + per-platform toggle (ADR-021).
- Adapter'ı mock'un 8 hata moduna karşı test et, sonra `ZERNIO_MOCK=false`. **(Faz 10: adapter YAZILDI + test edildi; ZERNIO_MOCK hâlâ true — gerçek yayın yok.)**

## Kaynaklar
- https://docs.zernio.com/ · /webhooks · /guides/media-uploads · /pricing · /platforms/instagram · /api/openapi
