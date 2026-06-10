<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$id=(int)($_GET['id']??0);
$stmt=db()->prepare('SELECT document_path, document_name, document_mime FROM private_receivables WHERE id=?');
$stmt->execute([$id]);
$row=$stmt->fetch();
if(!$row || !$row['document_path']) { http_response_code(404); exit('Belge bulunamadı.'); }
$path=realpath(UPLOAD_DIR . '/' . $row['document_path']);
$base=realpath(UPLOAD_DIR);
if(!$path || !$base || strpos($path, $base)!==0 || !is_file($path)) { http_response_code(404); exit('Belge bulunamadı.'); }
$mime=$row['document_mime'] ?: 'application/octet-stream';
$name=$row['document_name'] ?: basename($path);
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: inline; filename="'.str_replace('"','',$name).'"');
readfile($path);
exit;
