# Kuyash — Oturum Checkpoint (Session Continuity)

> Bu dosya her Claude Code oturumunun başında otomatik yüklenir (CLAUDE.md import eder).
> Amaç: her yeni oturumun, önceki sohbetin kaldığı yerden devam etmesi.
> KURAL: Bu dosya KISA kalmalı (~1 sayfa). Eski log satırları 10 kaydı aşınca en eskiden silinir.

## Son güncelleme

- Tarih: 2026-06-11
- Güncelleyen: Claude (git init onaylandı + ilk commit alındı)

## Mevcut durum (kaldığımız yer)

- Aşama: **Faz 0 ITERATION 2 TAMAMLANDI — kullanıcı kabulü bekleniyor.**
- Eklenenler (phase-0-iteration-2.md'ye uygun): mission-control Dashboard (KPI şeridi
  count-up'lı, aktif koşular + stage-segmentleri, 9:16 onay şeridi, hesap sağlığı,
  harcama); global Detail Drawer (timeline + skorlu compliance barları + audit +
  Approve/Reject; Queue/Trends/Accounts/Library/Dashboard/Logs'tan açılır); Create
  composer #/create (medya → Claude/ChatGPT-mock/manuel prompt → ayarlar → maliyet
  ön-kontrol + kredi kapısı + zorunlu AI-label → kuyruğa launch); ⌘K palette (topbar
  chip'i ile); J/K+A/R klavye onayı; platform-skin önizleme (IG/TikTok/YT); Why?
  açılımları; density toggle; öğreten empty-state'ler; motion genlik turu + approve
  check-flash→FLIP-exit koreografisi. Quick Create sekmesi composer'a yönlendirir.
- Doğrulama: 14 ekran × 3 genişlik PASS + jitter 0; davranış suiti 29/29 PASS
  (/tmp/kuyash-qa/behav2.js) + 13 edge repro temiz (edge3.js); EN+TR smoke 0 hata/
  0 eksik key; i18n parite 668/668 (38 ölü key temizlendi); reduced-motion her şeyi
  sıfırlıyor; renk/transition/dış-istek grep'leri temiz.
- Review: ux+qa reviewer → 2 blocker (masaüstü ölü hamburger; Why? tıklaması drawer
  açıyordu) + QA 6 should-fix (ticker placeholder, görünmez klavye onayı, tenant
  composer sızıntısı, sıfır-platform launch, aspect etiketi) + UX 9 should-fix
  (slop bar adı→Originality/Özgünlük, kredi KPI karışıklığı, density glifi, ⌘K chip,
  J/K odak sözleşmesi, workflow ilk kaydırma, ulaşılabilir kredi kapısı ws_calm=40,
  TikTok/YouTube marka yazımı, thumb parlaklığı) — TÜMÜ uygulandı, yeniden doğrulandı.
  Ayrıca suite gerçek bir hata yakaladı: dashboard delegasyon dinleyicileri kalıcı
  #screen-root'a bağlıydı → .dash-root sarmalayıcısına taşındı.
- Parkta (bilinçli): job drawer yoğunluğu + kredi/$ birimi, log gün işaretleri,
  composer medya-grid kaydırma ipucu, onay drawer'ına gömülü skin, Compliance/Workspace
  terim sözlüğü kararı, Safari/Firefox fallback manuel turu.
- Test etme: `phase-0-demo/index.html` tarayıcıda aç; EN|TR sağ üstte; ⌘K paleti dene.

## Verilmiş kararlar (özet)

- Stack sabit: Pure PHP 8.3 (framework yasak), SQLite WAL, Caddy + Cloudflare Tunnel, R2,
  OpenAI text/TTS, Pexels, Zernio (doc-gated), ffmpeg, Vanilla JS + custom CSS.
- Faz disiplini: implementasyon yalnızca `START PHASE N` token'ı ile başlar.
- Tüm entegrasyonlar mock-first, adapter arkasında. Compliance çekirdek mimari.
- Faz 0 teknik: file:// + Chrome CORS nedeniyle ES module YOK — klasik script'ler +
  global `Kuyash` namespace; tüm mock veri data/mock-data.js'te; HTML'de veri yok.

## Sıradaki adım

1. Kullanıcı Iteration 2'yi gözden geçirir: 14 ekran (13 + #/create), EN|TR, ⌘K palette,
   J/K+A/R (Queue'da), drawer'lar, composer yolculuğu (CalmClips'te kredi kapısını görmek
   için workspace değiştir), platform-skin sekmeleri, density toggle; manuel responsive
   turu (375/768/1280). Sorun → Faz 0 kapsamında düzeltilir.
2. Kabul sonrası: Faz 1 planı (`/next-phase`). (git init + ilk commit yapıldı.)
3. Faz 1 inşası yalnızca `START PHASE 1` token'ı ile başlar (PHP skeleton).

## Açık konular / bekleyenler

- Faz 0 kullanıcı kabulü bekleniyor (demo hazır, gözden geçirilmedi).

## Oturum logu (en yeni üstte, en fazla 10 satır)

- 2026-06-11 — Kullanıcı onayıyla git init + ilk commit (mevcut durum: Faz 0 Iteration 2 demosu + tüm .claude talimat seti). Secret taraması temiz; .gitignore mevcut haliyle yeterli.
- 2026-06-11 — ITERATION 2: mission-control dash, global drawer, Create composer (#/create), ⌘K, J/K+A/R, platform-skin, Why?, density, motion genlik turu. 14×3 + 29/29 + EN/TR PASS; ux+qa reviewer 2 blocker + 15 düzeltme kapatıldı.
- 2026-06-11 — FULL VISUAL REDESIGN: yeni kimlik (Ops-Teal/Inter/JBMono), 10 motion deseni, canlı-ops ticker, TR/EN i18n (586 key). 13×3 QA + 17 davranış testi PASS; ux+qa reviewer should-fix'leri kapatıldı.
- 2026-06-11 — Kullanıcı ilk görünümü reddetti ("jenerik AI tasarımı"); ui-style-guide.md (BINDING) + followups eklendi; redesign planı onaylandı (Ops Teal + Inter Display/JBMono + font indirme onayı).
- 2026-06-11 — START PHASE 0: 13 ekranlık demo inşa edildi, 3 reviewer çalıştı, should-fix'ler uygulandı, tüm testler PASS. Kullanıcı kabulü bekleniyor.
- 2026-06-11 — /phase-0-plan çalıştırıldı; plan onaylandı (İngilizce UI, koyu tema). Kod yazılmadı; START PHASE 0 bekleniyor.
- 2026-06-10 — Checkpoint sistemi kuruldu; proje hâlâ "instruction setup" aşamasında, faz başlamadı.
