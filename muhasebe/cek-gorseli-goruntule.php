<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT document_path, document_name, document_mime FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || empty($row['document_path'])) { http_response_code(404); exit('Belge bulunamadı.'); }

$base = realpath(UPLOAD_DIR);
$path = realpath(UPLOAD_DIR . '/' . $row['document_path']);
if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) { http_response_code(404); exit('Dosya bulunamadı.'); }

$mime = (string)($row['document_mime'] ?: 'application/octet-stream');
$allowed = ['image/jpeg','image/png','image/webp','image/heic','image/heif','application/pdf'];
if (!in_array($mime, $allowed, true)) { http_response_code(415); exit('Bu dosya önizlemeye uygun değil.'); }

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string)($row['document_name'] ?: 'cek-gorseli')));
header('X-Content-Type-Options: nosniff');
readfile($path);
