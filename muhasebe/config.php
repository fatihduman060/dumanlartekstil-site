<?php
// Bitke Muhasebe / Cari Takip Paneli
// İlk kurulum kullanıcı bilgisi README-KURULUM.txt içindedir.

const APP_NAME = 'Bitke Muhasebe Paneli';
const APP_VERSION = 'v50';
const APP_TIMEZONE = 'Europe/Istanbul';
const APP_BASE_PATH = '/muhasebe';

const DB_PATH = __DIR__ . '/storage/bitke_muhasebe.sqlite';
const UPLOAD_DIR = __DIR__ . '/storage/uploads';
const BACKUP_DIR = __DIR__ . '/storage/backups';
const MAX_UPLOAD_BYTES = 10485760; // 10 MB

// Güvenlik ayarları
const SESSION_TIMEOUT_SECONDS = 1800; // 30 dakika işlem yoksa otomatik çıkış
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 600; // 10 dakika kilit

// İlk açılışta users tablosu boşsa bu kullanıcı oluşturulur.
const DEFAULT_ADMIN_USERNAME = 'admin';
const DEFAULT_ADMIN_DISPLAY = 'Yönetici';
const DEFAULT_ADMIN_PASSWORD_HASH = '$2y$12$FUFv1VJ4.7D6X3o.pnbat.z/AqTyPRXuDO75P/t5Pzdi/xTW0r40G';
