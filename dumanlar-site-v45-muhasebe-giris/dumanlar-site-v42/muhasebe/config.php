<?php
// Bitke muhasebe paneli temel ayarları.
// Kullanıcı ve şifre hash ayarları.
// Şifre değiştirmek için yeni bir password_hash üretip aşağıdaki password_hash değerini değiştirin.

$APP_NAME = 'Bitke Muhasebe Paneli';

$APP_USERS = [
    'admin' => [
        'display_name' => 'Yönetici',
        'password_hash' => '$2y$12$eZZRH4cnxxac9p75WrFIpetQ9INyNRDFELCGNPnqzsmnrRaaX4OA.',
    ],
];
