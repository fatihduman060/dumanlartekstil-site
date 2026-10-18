<?php
require_once __DIR__.'/teklif-db.php';
require_once __DIR__.'/magaza-kullanici.php';
require_login();
if (!can_write() && !can_access_warehouse_dispatch()) redirect('dashboard.php');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
require_csrf();
try {
    teklif_db_ensure();
    $product = urun_define((string)($_POST['name'] ?? ''), (string)($_POST['product_type'] ?? ''), (string)($_POST['barcode'] ?? ''));
    echo json_encode(['ok'=>true, 'product'=>$product], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$e instanceof PDOException ? 'Ürün kaydedilemedi.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
