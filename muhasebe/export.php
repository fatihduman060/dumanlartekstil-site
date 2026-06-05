<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$type = $_GET['type'] ?? 'movements';
if ($type !== 'movements') { http_response_code(400); exit('Geçersiz dışa aktarma.'); }
$where=[]; $params=[];
if (!empty($_GET['start'])) { $where[]='m.movement_date>=?'; $params[]=$_GET['start']; }
if (!empty($_GET['end'])) { $where[]='m.movement_date<=?'; $params[]=$_GET['end']; }
if (!empty($_GET['cari_id'])) { $where[]='m.cari_id=?'; $params[]=(int)$_GET['cari_id']; }
if (!empty($_GET['movement_type'])) { $where[]='m.movement_type=?'; $params[]=$_GET['movement_type']; }
$sql="SELECT m.*, c.name AS cari_name, cat.name AS category_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id";
if ($where) $sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY m.movement_date DESC, m.id DESC';
$stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
$filename='bitke-hareketler-'.date('Ymd-His').'.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['ID','Tarih','Vade','Tip','Cari','Kategori','Ödeme Yöntemi','Açıklama','Tutar','Belge'], ';');
foreach($rows as $r){ fputcsv($out,[$r['id'],$r['movement_date'],$r['due_date'],movement_label($r['movement_type']),$r['cari_name'],$r['category_name'],$r['payment_method'],$r['description'],number_format((float)$r['amount'],2,',','.'),$r['document_name']], ';'); }
fclose($out);
log_action('CSV indirildi', $filename);
exit;
