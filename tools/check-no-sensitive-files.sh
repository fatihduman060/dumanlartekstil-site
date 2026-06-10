#!/usr/bin/env bash
set -euo pipefail

echo "Bitke güvenlik kontrolü: storage/sqlite/backup repoda var mı bakılıyor..."

if git ls-files | grep -E '(^storage/|\.sqlite($|-)|\.db($|-)|\.zip$|\.tar$|\.tar\.gz$|\.rar$|\.7z$)' ; then
  echo "HATA: Repoda canlı veri veya paket dosyası görünüyor. Bunları commit etme." >&2
  exit 1
fi

echo "Tamam: hassas dosya görünmüyor."
