Bitke Muhasebe Paneli - v50
============================

Bu paket sadece /muhasebe klasörünü içerir. Ana site dosyalarına dokunmaz.

Yükleme hedefi:
/public_html/muhasebe

Temiz kurulum ilk giriş:
Kullanıcı adı: admin
Şifre: B!tkeV48#9M7sQp2@

Not: V47/V48/V49'dan güncelleme yaparken storage klasörü korunursa mevcut kullanıcı/şifre aynen devam eder.

GÜNCELLEME KURALI
-----------------
Gerçek veri girildiyse:
- /public_html/muhasebe/storage klasörünü SİLME.
- Diğer dosyaları V50 ile üzerine yaz.
- storage içinde SQLite veritabanı, yüklenen belgeler ve yedekler durur.

Gerçek veri yoksa:
- Mevcut muhasebe klasörünü silip bu paketteki muhasebe klasörünü yükleyebilirsin.

V50 İLE GELENLER
----------------
1. Kasa/Banka modülü
- Kasa, banka, POS ve diğer hesaplar.
- Açılış bakiyesi.
- Manuel kasa/banka girişi ve çıkışı.
- Hesaplar arası virman.
- Güncel kasa/banka bakiyesi.

2. Tahsilat/ödeme sırasında kasa/banka seçimi
- Tahsilat ve gelir seçilen hesaba giriş yazar.
- Ödeme ve gider seçilen hesaptan çıkış yazar.
- Alacak/verecek kayıtları kasa/banka hareketi oluşturmaz.

3. Çek modülü geliştirmeleri
- Yeni durumlar: ciro edildi, protestolu.
- Tahsil edildi/ödendi durumunda seçilen kasa/banka hesabına hareket oluşturma.
- Vade renkleri ve hızlı durum güncelleme korunur.

4. Belge arşivi
- Hareket ve çek belgeleri tek sayfada listelenir.
- Belge türleri: fatura, dekont, makbuz, irsaliye, sözleşme, çek görseli, diğer.
- Cari, tarih, kaynak ve belge türüne göre filtreleme.

5. Cari detay geliştirmeleri
- Tarih, tip, açıklama/belge arama ve iptal kayıtları filtresi.
- Hızlı tahsilat/ödeme formunda kasa/banka ve belge yükleme.
- Cari bazlı çek durum filtresi.

6. Hareket iptal sistemi
- Hareketler doğrudan silinmez, iptal edildi olarak işaretlenir.
- İptal edilen hareketler cari bakiye ve raporlara dahil edilmez.
- İptal kaydı loglarda korunur.

7. Yedekten geri yükleme
- ZIP veya SQLite yedek yüklenebilir.
- Geri yükleme öncesi otomatik mevcut sistem yedeği alınır.
- Onay için GERI YUKLE yazılması gerekir.

8. Raporlar ve CSV çıktıları
- Kasa/banka raporu.
- Kasa/banka hareket CSV çıktısı.
- Geliştirilmiş cari, hareket, çek CSV çıktıları.
- Yazdır/PDF ekranları korunur.

TEKNİK NOTLAR
-------------
- PHP ile çalışır.
- SQLite kullanır.
- MySQL gerekmez.
- Hostingde PHP PDO SQLite aktif olmalıdır.
- ZIP yedek/geri yükleme için ZipArchive açık olursa belgeler de yedeğe dahil edilir.
- .htaccess dosyaları da FTP'ye yüklenmelidir.

CANLIYA ALMADAN ÖNCE
--------------------
1. Varsa mevcut /muhasebe klasörünün komple yedeğini al.
2. Gerçek veri varsa storage klasörünü silme.
3. V50 dosyalarını yükle.
4. Panele girip şu ekranları kontrol et:
   - Genel Bakış
   - Kasa/Banka
   - Hareketler
   - Çekler
   - Belgeler
   - Yedekleme
5. İlk iş güçlü bir admin şifresi belirle.
