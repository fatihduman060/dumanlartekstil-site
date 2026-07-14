<?php

function magaza_kullanici_anahtari($value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'c','ğ'=>'g','ı'=>'i','i'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
    ];
    $value = strtolower(strtr(trim((string)$value), $map));
    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function is_store_sales_user(?array $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user) return false;

    $username = magaza_kullanici_anahtari($user['username'] ?? '');
    $displayName = magaza_kullanici_anahtari($user['display_name'] ?? '');

    return in_array($username, ['magaza', 'magza'], true)
        || $displayName === 'fabrikasatismagazasi';
}

function can_manage_store_sales(): bool
{
    return can_write() || is_store_sales_user();
}

function require_store_sales_write(): void
{
    require_login();
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Bu işlem yalnızca mağaza satış yetkisi olan kullanıcılar içindir.');
    }
}

$currentStoreScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (is_logged_in() && is_store_sales_user()) {
    $allowedStoreScripts = [
        'faturalar.php',
        'magaza-gunluk-satis.php',
        'logout.php',
        'index.php',
    ];
    if (!in_array($currentStoreScript, $allowedStoreScripts, true)) {
        redirect('faturalar.php');
    }
}
