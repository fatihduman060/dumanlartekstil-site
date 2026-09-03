<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function barkod_veresiye_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function barkod_veresiye_normalize_name($name): string
{
    $name = preg_replace('/\s+/u', ' ', trim((string)$name));
    return mb_strtolower($name, 'UTF-8');
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Barkodlu satış yetkisi gerekiyor.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.');
    }

    pos_store_credit_ensure();
    $name = preg_replace('/\s+/u', ' ', trim((string)($_POST['full_name'] ?? '')));
    if (mb_strlen($name, 'UTF-8') < 3) {
        throw new RuntimeException('İsim en az 3 karakter olmalı.');
    }
    if (mb_strlen($name, 'UTF-8') > 120) {
        throw new RuntimeException('İsim çok uzun.');
    }

    $searchName = barkod_veresiye_normalize_name($name);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM store_credit_people WHERE search_name=? LIMIT 1');
    $stmt->execute([$searchName]);
    $existing = $stmt->fetch() ?: null;
    $now = now();
    $userId = current_user()['id'] ?? null;

    if ($existing) {
        $personId = (int)$existing['id'];
        if ((int)($existing['is_active'] ?? 0) !== 1) {
            $pdo->prepare('UPDATE store_credit_people SET full_name=?,is_active=1,updated_at=? WHERE id=?')
                ->execute([$name, $now, $personId]);
            audit_action('magaza_personel_veresiye', $personId, 'personel_aktif_edildi', $existing, ['full_name'=>$name,'is_active'=>1], $name);
            $message = 'Kişi yeniden aktif edildi ve seçildi.';
        } else {
            $message = 'Bu isim zaten kayıtlıydı; mevcut kişi seçildi.';
        }
    } else {
        $pdo->prepare('INSERT INTO store_credit_people (full_name,search_name,notes,is_active,created_by,created_at,updated_at) VALUES (?,?,NULL,1,?,?,?)')
            ->execute([$name, $searchName, $userId, $now, $now]);
        $personId = (int)$pdo->lastInsertId();
        audit_action('magaza_personel_veresiye', $personId, 'personel_eklendi', null, ['full_name'=>$name,'source'=>'barkodlu_satis'], $name);
        log_action('Barkodlu satış veresiye kişisi eklendi', $name);
        $message = 'Yeni isim eklendi ve veresiye satış için seçildi.';
    }

    $personStmt = $pdo->prepare('SELECT id,full_name FROM store_credit_people WHERE id=? AND is_active=1 LIMIT 1');
    $personStmt->execute([$personId]);
    $person = $personStmt->fetch() ?: null;
    if (!$person) throw new RuntimeException('Kişi kaydı hazırlanamadı.');

    barkod_veresiye_json(['ok'=>true,'person'=>$person,'message'=>$message]);
} catch (Throwable $e) {
    barkod_veresiye_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
