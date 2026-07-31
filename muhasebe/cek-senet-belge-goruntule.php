<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();

$id = (int)($_GET['id'] ?? 0);
$source = (string)($_GET['source'] ?? '');
$row = null;

if ($source === 'check') {
    $stmt = db()->prepare("SELECT document_path, document_name, document_mime FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: null;
} elseif ($source === 'extra') {
    $stmt = db()->prepare("SELECT document_path, document_name, document_mime, description FROM standalone_documents WHERE id=? AND description LIKE 'Çek #% | %'");
    $stmt->execute([$id]);
    $row = $stmt->fetch() ?: null;
    if ($row && preg_match('/^Çek #(\d+) \| /u', (string)$row['description'], $match) === 1) {
        $checkStmt = db()->prepare("SELECT COUNT(*) FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $checkStmt->execute([(int)$match[1]]);
        if ((int)$checkStmt->fetchColumn() === 0) $row = null;
    } else {
        $row = null;
    }
}

if (!$row || empty($row['document_path'])) { http_response_code(404); exit('Belge bulunamadı.'); }
$base = realpath(UPLOAD_DIR);
$path = realpath(UPLOAD_DIR . '/' . $row['document_path']);
if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) { http_response_code(404); exit('Dosya bulunamadı.'); }
$mime = (string)($row['document_mime'] ?: 'application/octet-stream');
if (strpos($mime, 'image/') !== 0) { http_response_code(415); exit('Bu dosya görsel önizlemeye uygun değil.'); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string)($row['document_name'] ?: 'belge')));
header('X-Content-Type-Options: nosniff');
readfile($path);
