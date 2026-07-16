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

function is_mustafa_duman_user(?array $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user) return false;

    $usernameKey = magaza_kullanici_anahtari($user['username'] ?? '');
    $displayKey = magaza_kullanici_anahtari($user['display_name'] ?? '');

    return $displayKey === 'mustafaduman' || $usernameKey === 'mustafaduman';
}

function is_fatih_user(?array $user = null): bool
{
    $user = $user ?: current_user();
    if (!$user) return false;

    $usernameKey = magaza_kullanici_anahtari($user['username'] ?? '');
    $displayKey = magaza_kullanici_anahtari($user['display_name'] ?? '');

    return in_array($usernameKey, ['fatih', 'fatihduman'], true)
        || in_array($displayKey, ['fatih', 'fatihduman'], true);
}

function yonetici_yetkilerini_senkronla(): void
{
    try {
        $rows = db()->query("SELECT id, username, display_name, role, is_active FROM users ORDER BY id ASC")->fetchAll() ?: [];
        $fatihIds = [];
        $mustafaIds = [];

        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            if ($userId <= 0) continue;

            if (is_fatih_user($row)) {
                $fatihIds[] = $userId;
                if (($row['role'] ?? '') !== 'admin' || (int)($row['is_active'] ?? 0) !== 1) {
                    db()->prepare("UPDATE users SET role='admin', is_active=1, updated_at=? WHERE id=?")
                        ->execute([now(), $userId]);
                }
            }

            if (is_mustafa_duman_user($row)) {
                $mustafaIds[] = $userId;
                if (($row['role'] ?? '') !== 'admin' || (int)($row['is_active'] ?? 0) !== 1) {
                    db()->prepare("UPDATE users SET role='admin', is_active=1, updated_at=? WHERE id=?")
                        ->execute([now(), $userId]);
                }
            }
        }

        $raw = setting_get('super_admin_user_ids', '[]') ?: '[]';
        $existingIds = json_decode($raw, true);
        if (!is_array($existingIds)) $existingIds = [];
        $existingIds = array_values(array_unique(array_filter(array_map('intval', $existingIds), fn($id) => $id > 0)));

        // Süper yönetici yalnızca Fatih'tir. Fatih hesabı henüz bulunamazsa
        // mevcut listeyi tamamen silmek yerine Mustafa'yı listeden çıkarmakla yetin.
        if ($fatihIds) {
            $superAdminIds = array_values(array_unique($fatihIds));
        } else {
            $superAdminIds = array_values(array_filter($existingIds, fn($id) => !in_array((int)$id, $mustafaIds, true)));
        }

        setting_set('super_admin_user_ids', json_encode($superAdminIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        // Yetki eşleştirmesi uygulamanın açılmasını engellememeli.
    }
}

yonetici_yetkilerini_senkronla();

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
    if ($currentStoreScript === 'faturalar.php') {
        redirect('magaza.php');
    }

    $allowedStoreScripts = [
        'magaza.php',
        'magaza-gunluk-satis.php',
        'magaza-odeme-dagilimi.php',
        'logout.php',
        'index.php',
    ];
    if (!in_array($currentStoreScript, $allowedStoreScripts, true)) {
        redirect('magaza.php');
    }
}
