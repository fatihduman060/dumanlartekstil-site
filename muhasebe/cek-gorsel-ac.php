<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Çek bulunamadı.');
}

$stmt = db()->prepare("SELECT id, direction, document_path, document_mime
    FROM checks
    WHERE id=? AND COALESCE(is_cancelled,0)=0
    LIMIT 1");
$stmt->execute([$id]);
$check = $stmt->fetch();

if (!$check) {
    http_response_code(404);
    exit('Çek bulunamadı veya iptal edilmiş.');
}

$mime = (string)($check['document_mime'] ?? '');
if (!empty($check['document_path']) && strpos($mime, 'image/') === 0) {
    redirect('cek-senet-belge-goruntule.php?source=check&id=' . $id);
}

$direction = (string)($check['direction'] ?? 'alinacak');
if (!isset(check_directions()[$direction])) $direction = 'alinacak';
redirect('cekler.php?direction=' . urlencode($direction) . '&edit=' . $id . '#cek-form');
