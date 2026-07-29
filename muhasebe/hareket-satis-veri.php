<?php
require_once __DIR__ . '/hareket-satis-db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $response = ['ok' => true];

    if (!empty($_GET['products'])) {
        $response['products'] = hareket_satis_products();
    }

    $movementId = (int)($_GET['id'] ?? 0);
    if ($movementId > 0) {
        $response['sale'] = hareket_satis_load($movementId);
    }

    $rawIds = trim((string)($_GET['ids'] ?? ''));
    if ($rawIds !== '') {
        $ids = preg_split('/[^0-9]+/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $response['summaries'] = hareket_satis_summaries($ids);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
