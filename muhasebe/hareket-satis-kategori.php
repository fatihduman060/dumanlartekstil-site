<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = db()->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
    $stmt->execute(['Satış']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id <= 0) {
        db()->prepare("INSERT OR IGNORE INTO categories (name, type, created_at) VALUES (?, 'gelir', ?)")
            ->execute(['Satış', now()]);
        $stmt->execute(['Satış']);
        $id = (int)($stmt->fetchColumn() ?: 0);
    }
    if ($id <= 0) throw new RuntimeException('Satış kategorisi hazırlanamadı.');
    echo json_encode(['ok'=>true,'id'=>$id,'label'=>'Satış'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
