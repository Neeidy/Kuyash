# Kuyash — Kullanım Rehberi

## 1. Sistem Ne Yapıyor? (tek paragraf)

Kuyash, kısa video (Reels/TikTok/Shorts) üretip yayınlayan bir **içerik fabrikası + operasyon merkezi**. Trend tarar → fikir/script üretir → seslendirir → görsel toplar → ffmpeg ile 9:16 video montajlar → platform kurallarına uygunluğunu denetler → onaylar → 3 platforma birden yayınlar. Bütün bunları kendi kendine, gözetimsiz çalışabilen bir döngü (loop) olarak yapar; sen sadece kuralları koyar ve istediğinde onaylarsın.

## 2. Çalışma Mantığı (loop)

```
TREND → FİKİR → SCRIPT → SES → GÖRSEL → MONTAJ → CAPTION → HASHTAG
→ MÜZİK NOTU → ÖNİZLEME → COMPLIANCE (kural denetimi) → YAYIN
```

İki giriş kapısı var:
- **Trend-driven:** sistem nişindeki trendi yakalar, baştan üretir.
- **Quick Create:** sen foto + prompt verirsin ("kedim mutfakta yemek yapsın"), AI videoya çevirir, gerisi aynı hat.

## 3. Ekranlar / Bölümler

| Bölüm | Ne işe yarar |
|---|---|
| **Dashboard** | Genel durum: aktif işler, onay bekleyenler, kredi/maliyet, hesap sağlığı |
| **Trend Radar** | Nişindeki yükselen trendler (Google/YouTube/TikTok kaynaklı) |
| **Content / Quick Create** | Script üretimi + foto-prompt'tan AI video |
| **Library** | Yüklediğin videolar, fotolar, avatar/referans görseller |
| **Workflows** | Yayın hattının görsel düzeni (node graph) |
| **Queue** | Üretim kuyruğu + **onay kartları** (burada Approve/Reject yaparsın) |
| **Accounts** | Bağlı IG/TikTok/YT hesapları + sağlık durumu |
| **Logs** | Canlı iş kayıtları, hata/retry takibi |
| **Usage / Credits** | Harcama, bütçe cap'i, maliyet dökümü |
| **Settings** | Onay modu (Manuel/Auto), guardrail'ler (günlük cap, kill switch) |

## 4. Nasıl Kullanılır? (günlük akış)

1. **Hesapları bağla** (Accounts → Connect) — şu an mock, gerçek Zernio dokümanlarıyla canlı olacak.
2. **Niş ayarla** (workspace bazında) — Trend Radar neyi tarayacağını bundan bilir.
3. **İçerik üret:** trendlerden "Create from trend" VEYA Quick Create'te foto+prompt.
4. **Worker işler:** kuyruktaki işler sırayla üretilir (montaj, render).
5. **Onayla:**
   - *Manuel mod (varsayılan):* Queue'da onay kartı çıkar, sen Approve edersin.
   - *Auto mod (Settings'ten açarsın):* compliance agent düşük riskli içeriği kendi onaylar; sadece riskli olanlar sana gelir.
6. **Yayınla/zamanla:** post anında veya ileri saate planlanır.
7. **İzle:** Logs (ne oldu), Usage (ne harcandı), Analytics (nasıl gitti).

**Emniyet rayları (Settings):** günlük post tavanı, aylık bütçe cap'i, kill switch (her şeyi anında durdur), kalite düşünce otomatik Manuel'e dönüş. Otonom modda seni koruyan bunlar.

## 5. Dashboard'ı Şu An Nasıl Açarım? (geliştirme modu)

Sistem iki süreçle çalışır: **web sunucusu** (vitrin) + **worker** (üretim bandı). İkisini ayrı Terminal penceresinde başlat.

**[Terminal 1 — web sunucusu]**
```
cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php -S 127.0.0.1:8082 -t public public/index.php
```

**[Terminal 2 — worker]** (yeni pencere aç)
```
cd ~/Desktop/Kuyash && /opt/homebrew/opt/php@8.3/bin/php bin/worker.php
```

**[Tarayıcı]**
```
http://127.0.0.1:8082/login
```
Giriş yap → sol menüden gez. Kapatmak için her iki terminalde `Ctrl+C`.

## 6. "Mac Sürekli Açık mı Kalmalı?" — EVET (şimdilik)

Şu an sistem senin Mac'inde çalışıyor. İki terminal süreci açık olduğu sürece çalışır; **Mac uyursa veya pencereleri kapatırsan loop durur.** Yani 7/24 otonom yayın için Mac'i hep açık + uyutmadan tutman gerekir — bu pratik değil.

**Gerçek 7/24 çözümü (go-live adımı):** Sistemi küçük bir sunucuya (örn. Hetzner VPS, ~€5/ay) taşırsın. Orada worker `systemd`/supervisor ile sürekli çalışır, Mac'inden bağımsız olur. Caddy + Cloudflare Tunnel zaten bunun için stack'te. Bu, V1 kodunu değiştirmez — sadece deployment.

## 7. Şu Anki Durum — Önemli

Sistem **mock modda**: gerçekten içerik üretiyor (ffmpeg render gerçek) ama dış servisler (OpenAI, trend, Zernio yayın) varsayılan kapalı. Yani **henüz gerçek post atmıyor.** Canlıya almak için (`production-readiness.md`):
- Gerçek API anahtarları + bayrakları çevir
- Zernio'nun 12 dokümanı (yayın için şart)
- Always-on worker (VPS)
- Gerçek hesapları bağla

**Özet:** Kod bitti, sistem mock'ta dönüyor; sıradaki gerçek iş go-live (kod değil, kurulum).
