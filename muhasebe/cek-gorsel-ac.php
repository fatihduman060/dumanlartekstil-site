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

// Önce çek kaydının ana görselini aç.
$mime = (string)($check['document_mime'] ?? '');
if (!empty($check['document_path']) && strpos($mime, 'image/') === 0) {
    redirect('cek-senet-belge-goruntule.php?source=check&id=' . $id);
}

// Ana belge boşsa Çek / Senet Arşivi'ne eklenen ön veya arka görseli bul.
$archiveStmt = db()->prepare("SELECT id
    FROM standalone_documents
    WHERE description LIKE ?
      AND COALESCE(document_path,'')<>''
      AND document_mime LIKE 'image/%'
    ORDER BY
      CASE
        WHEN description LIKE '%| Ön görsel' THEN 1
        WHEN description LIKE '%| Arka görsel' THEN 2
        ELSE 3
      END,
      id ASC
    LIMIT 1");
$archiveStmt->execute(['Çek #' . $id . ' | %']);
$archiveDocumentId = (int)($archiveStmt->fetchColumn() ?: 0);

if ($archiveDocumentId > 0) {
    redirect('cek-senet-belge-goruntule.php?source=extra&id=' . $archiveDocumentId);
}

// Hiç görsel yoksa çek kaydını aç; kullanıcı belge ekleyebilsin.
$direction = (string)($check['direction'] ?? 'alinacak');
if (!isset(check_directions()[$direction])) $direction = 'alinacak';
redirect('cekler.php?direction=' . urlencode($direction) . '&edit=' . $id . '#cek-form');
