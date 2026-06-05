<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT document_path, document_name, document_mime FROM checks WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || empty($row['document_path'])) { http_response_code(404); exit('Belge bulunamadı.'); }
$path = UPLOAD_DIR . '/' . $row['document_path'];
$name = $row['document_name'] ?: ('cek-belge-' . $id);
$mime = $row['document_mime'] ?: 'application/octet-stream';
download_file($path, $name, $mime);
