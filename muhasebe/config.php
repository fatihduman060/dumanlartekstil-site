<?php
// Bitke Muhasebe / Cari Takip Paneli
// İlk kurulum kullanıcı bilgisi README-KURULUM.txt içindedir.

const APP_NAME = 'Bitke Muhasebe Paneli';
const APP_TIMEZONE = 'Europe/Istanbul';
const APP_BASE_PATH = '/muhasebe';

const DB_PATH = __DIR__ . '/storage/bitke_muhasebe.sqlite';
const UPLOAD_DIR = __DIR__ . '/storage/uploads';
const MAX_UPLOAD_BYTES = 5242880; // 5 MB

// İlk açılışta users tablosu boşsa bu kullanıcı oluşturulur.
const DEFAULT_ADMIN_USERNAME = 'admin';
const DEFAULT_ADMIN_DISPLAY = 'Yönetici';
const DEFAULT_ADMIN_PASSWORD_HASH = '$2y$12$kle3D6KUT1w9.OK/398oTOsOMPOYtAv.OQJVMs/qEy3MP.M9bzh66';
