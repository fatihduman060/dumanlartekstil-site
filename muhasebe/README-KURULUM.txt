BİTKE MUHASEBE / CARİ TAKİP PANELİ - KURULUM

Adres:
https://www.bitke.com.tr/muhasebe

İlk giriş bilgileri:
Kullanıcı adı: admin
Şifre: *E#M%t?pmhSzw#QxBv

ÖNEMLİ:
1) İlk girişten sonra Kullanıcılar sayfasından admin şifresini değiştirin.
2) Bu panel SQLite ile çalışır. Hostingde PHP PDO SQLite eklentisi aktif olmalıdır.
3) Veriler /muhasebe/storage/bitke_muhasebe.sqlite dosyasında tutulur.
4) Belgeler /muhasebe/storage/uploads klasörüne yüklenir.
5) /storage klasörü .htaccess ile dış erişime kapatıldı. Nginx kullanılıyorsa ayrıca klasör erişimi kapatılmalıdır.
6) Panel iç takip/cari takip amaçlıdır; resmi muhasebe, e-fatura, e-arşiv ve defter sistemi yerine geçmez.

ÖZELLİKLER:
- Firma / kişi cari kartları
- Alacak / verecek / tahsilat / ödeme takibi
- Gelir / gider hareketleri
- Kategori bazlı gider/gelir takibi
- Cari bakiye raporu
- Ay sonu özeti
- Belge/fatura görseli veya PDF yükleme
- Excel uyumlu CSV dışa aktarma
- Yazdır/PDF rapor ekranı
- Kullanıcı yetkileri: Yönetici, Düzenleyici, Görüntüleyici
- Log kaydı: giriş, ekleme, düzenleme, silme, CSV indirme

FTP YÜKLEME:
Zip içindeki dumanlar-site-v42 klasörünün içeriğini public_html içine yükleyin.
Mevcut site dosyalarının üzerine yazabilirsiniz.

YEDEK:
Düzenli olarak şu klasörü yedekleyin:
/muhasebe/storage
