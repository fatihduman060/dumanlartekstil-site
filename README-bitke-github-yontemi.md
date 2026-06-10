# Bitke Muhasebe Çalışma Yöntemi

Repo: `Ahmet1991/bitke-muhasebe`
Branch: `main`

## Normal yöntem

1. GitHub connector ile ilgili dosya okunur.
2. Dosya güncellenir veya oluşturulur.
3. `main` branch'e commit atılır.
4. `Bitke Deploy` GitHub Action otomatik çalışır.
5. FTP ile hostingteki muhasebe klasörüne kod dosyaları gönderilir.

## Korunan canlı veri

Şunlara dokunulmaz:

```text
storage/
storage/bitke_muhasebe.sqlite
storage/uploads/
storage/backups/
```

## Köprü yöntemi

Normal GitHub güncellemesi takılırsa `Bitke Apply Bridge` kullanılır.

Issue: `#1`
Marker: `BITKE_APPLY_V1`

Payload:

```json
{
  "message": "Commit mesajı",
  "files": [
    {
      "path": "ornek.php",
      "content": "Dosyanın tam yeni içeriği"
    }
  ]
}
```

Silme:

```json
{
  "message": "Dosya kaldır",
  "files": [
    {
      "path": "eski.php",
      "delete": true
    }
  ]
}
```

## Deploy zorla tetikleme

Gerekirse `deploy-version.txt` dosyası güncellenir.
