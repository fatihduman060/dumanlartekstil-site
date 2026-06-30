<?php
require_once __DIR__ . '/layout.php';
require_login();

if (!is_admin()) {
    flash('error', 'Kasa/Banka bölümü yalnızca yöneticilere açıktır.');
    redirect('dashboard.php');
}

$target = (string)($_GET['target'] ?? '');

if ($target === 'hesaplar') {
    require __DIR__ . '/hesaplar.php';
    exit;
}

if ($target === 'hesap-dokumleri') {
    require __DIR__ . '/hesap-dokumleri.php';
    exit;
}

redirect('dashboard.php');
