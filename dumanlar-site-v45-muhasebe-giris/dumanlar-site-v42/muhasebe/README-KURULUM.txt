BITKE MUHASEBE PANELİ - SADECE GİRİŞ SİSTEMİ

Adres:
https://www.bitke.com.tr/muhasebe

Kurulum:
1) Zip içindeki site dosyalarını hostingde public_html içine yükleyin.
2) public_html/muhasebe klasörü olduğu gibi kalmalı.
3) Hostingde PHP desteği aktif olmalı. MySQL bu ilk giriş sürümü için gerekli değildir.
4) SSL/HTTPS açık olmalı.

Şifre değiştirme:
1) Hosting panelinde veya kendi bilgisayarınızda PHP ile yeni hash üretin:
   php -r "echo password_hash('YENI_SIFRE', PASSWORD_DEFAULT), PHP_EOL;"
2) muhasebe/config.php dosyasındaki password_hash değerini yeni hash ile değiştirin.

Güvenlik notları:
- Bu sürüm sadece giriş altyapısını kurar.
- Muhasebe kayıtları, cari kartlar ve raporlar henüz eklenmedi.
- config.php ve auth.php dosyaları .htaccess ile dış erişime kapatıldı.
- /muhasebe alanı robots.txt ve meta noindex ile arama motorlarından gizlenir.
TXT dosyalarının dışarıdan okunması .htaccess ile engellenmiştir.
