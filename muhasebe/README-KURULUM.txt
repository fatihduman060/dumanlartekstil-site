Bitke Muhasebe Paneli v50.8 — Veri Korumalı Güncelleme

YÜKLEME
1) Bu paketin içindeki "muhasebe" klasörünü FTP ile /public_html içine yükleyin.
2) FileZilla sorarsa "Üzerine yaz / overwrite" seçin.
3) Mevcut canlı sistemdeki /public_html/muhasebe/storage klasörünü SİLMEYİN.

BU PAKET VERİTABANI İÇERMEZ
- bitke_muhasebe.sqlite yoktur.
- Cari, hareket, kullanıcı, çek ve belge verilerini ezmez.
- storage içinde yalnızca güvenlik amaçlı .htaccess dosyaları vardır.

EKLENENLER
- Günlük otomatik yedekleme: Panel ilk kullanıldığında günde 1 yedek alır.
- Son 5 otomatik yedek saklanır.
- Özel Alacak toplu raporu: ozel-alacaklar.php
- Özel Alacak CSV ve PDF/Yazdır çıktısı.
- Dashboard özel alacak, bu ay ödeme takibi, en yüksek alacak/borç görünümü.
- Cari detayda sekmeli/pratik bölüm geçişleri.
- Audit/değişiklik izi: kim ne ekledi, değiştirdi, sildi takip edilir.
- storage / uploads / backups dış erişime kapatma için .htaccess güçlendirmesi.

KRİTİK KURAL
/public_html/muhasebe/storage klasörünü silmeyin.
Uzak dosyaları temizleme yapmayın.
Sadece üzerine yazın.

TEKNİK GEREKSİNİM
Hostingde PHP PDO SQLite aktif olmalıdır.
ZIP yedek için ZipArchive aktifse belgelerle birlikte ZIP alınır; kapalıysa SQLite kopyası alınır.
