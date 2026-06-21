# Bitke / Dumanlar Web Sitesi

Bu repo `www.bitke.com.tr` üzerinde yayınlanan kurumsal web sitesi kodlarını içerir.

## Ana yapı

- Statik web sitesi: kök dizindeki HTML sayfaları
- Ortak stiller: `assets/css/style.css`
- Ana sayfa yenileme stilleri: `assets/css/bitke-refresh.css`
- Ortak site scriptleri: `assets/js/main.js`
- Ana sayfa marka vitrini: `assets/js/home-brand-clean-v1.js`
- Muhasebe paneli kodları: `muhasebe/`

## Yayın / Deploy

`main` branch'e yapılan push sonrası `.github/workflows/deploy.yml` çalışır ve web sitesi dosyalarını hostingteki `/bitke.com.tr/` dizinine gönderir.

Muhasebe canlı verileri deploy paketine dahil edilmez. `muhasebe/storage/`, SQLite veritabanı, yüklenen belgeler ve yedek dosyaları repoya eklenmemelidir.

## Önemli güvenlik kuralı

Aşağıdaki dosya ve klasörler repoya eklenmez:

```text
muhasebe/storage/
storage/
*.sqlite
*.sqlite-*
*.db
*.db-*
*.zip
*.tar
*.tar.gz
*.rar
*.7z
.env
.env.*
.ftp-deploy-sync-state*.json
```

## Sayfalar

- `index.html`
- `kurumsal.html`
- `markalar.html`
- `urunler.html`
- `erkek-corap.html`
- `bambu-corap.html`
- `modal-corap.html`
- `uretim.html`
- `ozel-marka-uretimi.html`
- `katalog.html`
- `sss.html`
- `iletisim.html`
- `kvkk-aydinlatma-metni.html`
- `gizlilik-politikasi.html`
- `cerez-politikasi.html`
- `404.html`

## İletişim

- Telefon: 0 (356) 715-8283
- WhatsApp: 0532 179 87 07
- E-posta: info@dumanlartekstil.com.tr
