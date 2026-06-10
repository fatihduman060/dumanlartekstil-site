# Bitke Muhasebe GitHub + Otomatik Deploy Kurulumu

Bu paket Bitke muhasebe uygulamasını AKENTEK'teki çalışma mantığına benzer hale getirmek için hazırlanmıştır.

Amaç:

- Bundan sonra zip indirip FTP'ye manuel yükleme yapmamak.
- Kod değişikliklerini GitHub üzerinden yapmak.
- `main` branch'e commit düşünce hosting'e otomatik deploy almak.
- Canlı veritabanı ve yüklenen belgeleri korumak.

---

## 1) En önemli kural

Aşağıdaki klasör/dosyalar kesinlikle GitHub'a yüklenmeyecek:

```text
storage/
storage/bitke_muhasebe.sqlite
storage/uploads/
storage/backups/
*.sqlite
*.db
*.zip
```

Bunlar canlı veridir. Repoda sadece kod olmalı.

---

## 2) GitHub repo hazırlığı

GitHub'da private repo aç:

```text
Ahmet1991/bitke-muhasebe
```

Mevcut muhasebe klasöründen şu dosyaları repoya yükle:

```text
*.php
assets/
includes/ varsa
.htaccess
README.md varsa
```

Şunları yükleme:

```text
storage/
*.sqlite
*.zip
```

Bu paketteki `.gitignore`, `.github/workflows/` ve `deploy-version.txt` dosyalarını da repo köküne ekle.

---

## 3) GitHub Secrets ayarları

Repo içinde:

```text
Settings → Secrets and variables → Actions → New repository secret
```

Şu secret'ları ekle:

```text
FTP_SERVER
FTP_USERNAME
FTP_PASSWORD
FTP_SERVER_DIR
```

Örnek:

```text
FTP_SERVER=ftp.bitke.com.tr
FTP_USERNAME=hosting FTP kullanıcı adın
FTP_PASSWORD=hosting FTP şifren
FTP_SERVER_DIR=/public_html/muhasebe/
```

Not: Bazı hostinglerde FTP girişinde zaten `public_html` içine düşer. O zaman `FTP_SERVER_DIR` şu olabilir:

```text
/muhasebe/
```

İlk denemede deploy hata verirse sadece bu yolu düzeltiriz.

---

## 4) İlk deploy testi

Repo'ya küçük bir commit at:

```text
deploy-version.txt
```

GitHub'da:

```text
Actions → Bitke Deploy
```

çalışıyor mu bak.

Başarılı olursa dosyalar hostingteki muhasebe klasörüne gider.

---

## 5) Bitke Apply Bridge kullanımı

Normal GitHub dosya güncellemesi takılırsa Issue üzerinden köprü çalışır.

Repo içinde bir Issue aç:

```text
Title: Bitke Apply Bridge
```

Bu dosyada workflow varsayılan olarak Issue #1'i dinler.

Eğer issue numarası #1 değilse `.github/workflows/bitke-apply.yml` içinde şu satırı değiştir:

```yaml
BRIDGE_ISSUE_NUMBER: "1"
```

ve aşağıdaki `if` satırındaki `github.event.issue.number == 1` değerini de aynı numaraya çek.

Köprü yorum formatı:

```text
BITKE_APPLY_V1

{
  "message": "Commit mesajı",
  "files": [
    {
      "path": "dashboard.php",
      "content": "Dosyanın komple yeni içeriği"
    }
  ]
}
```

Dosya silmek için:

```text
BITKE_APPLY_V1

{
  "message": "Gereksiz dosyayı kaldır",
  "files": [
    {
      "path": "eski-dosya.php",
      "delete": true
    }
  ]
}
```

Köprü güvenlik için şuralara yazabilir:

```text
Kök dizindeki .php dosyaları
assets/
includes/
public/
config/
migrations/
database/
tools/
.htaccess
README.md
composer.json
composer.lock
deploy-version.txt
```

Şuralara yazamaz:

```text
.github/
storage/
.git/
vendor/
node_modules/
*.sqlite
*.db
*.zip
```

---

## 6) Bundan sonraki çalışma şekli

Normal akış:

```text
1. ChatGPT ilgili dosyayı GitHub'dan okur.
2. Değişikliği yapar.
3. main branch'e commit atar.
4. GitHub Actions otomatik FTP deploy yapar.
5. Canlı storage/veritabanı korunur.
```

Takılırsa:

```text
1. ChatGPT Issue #1'e BITKE_APPLY_V1 yorumunu atar.
2. Bridge workflow dosyaları yazar.
3. Commit oluşur.
4. Deploy workflow otomatik çalışır.
```

---

## 7) Veritabanı migration kuralı

Veritabanı değişikliği gerekiyorsa canlı SQLite dosyası repoya alınmaz.

Migration PHP kodu güvenli ve tek seferlik çalışacak şekilde hazırlanır.

Örnek mantık:

```text
schema_version kontrol edilir
eskiyse ALTER TABLE / veri düzeltme yapılır
başarılı olursa schema_version artırılır
aynı migration ikinci kez çalışmaz
```

