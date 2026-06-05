<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$type = $_GET['type'] ?? 'movements';
$filenamePrefix = 'bitke-' . $type . '-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filenamePrefix . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output','w');

if ($type === 'movements') {
    $where=[]; $params=[];
    if (empty($_GET['include_cancelled'])) $where[]='COALESCE(m.is_cancelled,0)=0';
    if (!empty($_GET['start'])) { $where[]='m.movement_date>=?'; $params[]=$_GET['start']; }
    if (!empty($_GET['end'])) { $where[]='m.movement_date<=?'; $params[]=$_GET['end']; }
    if (!empty($_GET['cari_id'])) { $where[]='m.cari_id=?'; $params[]=(int)$_GET['cari_id']; }
    if (!empty($_GET['movement_type'])) { $where[]='m.movement_type=?'; $params[]=$_GET['movement_type']; }
    if (!empty($_GET['account_id'])) { $where[]='m.account_id=?'; $params[]=(int)$_GET['account_id']; }
    if (!empty($_GET['document_type'])) { $where[]='m.document_type=?'; $params[]=$_GET['document_type']; }
    $sql="SELECT m.*, c.name AS cari_name, cat.name AS category_name, a.name AS account_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN accounts a ON a.id=m.account_id";
    if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY m.movement_date DESC, m.id DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    fputcsv($out,['ID','Tarih','Vade','Tip','Cari','Kategori','Kasa/Banka','Ödeme Yöntemi','Belge Türü','Açıklama','Tutar','Durum','Belge'], ';');
    foreach($rows as $r) fputcsv($out,[$r['id'],$r['movement_date'],$r['due_date'],movement_label($r['movement_type']),$r['cari_name'],$r['category_name'],$r['account_name'],$r['payment_method'],document_type_label($r['document_type']),$r['description'],number_format((float)$r['amount'],2,',','.'),((int)($r['is_cancelled'] ?? 0)===1?'İptal':'Aktif'),$r['document_name']], ';');
    log_action('Hareket CSV indirildi', $filenamePrefix); fclose($out); exit;
}

if ($type === 'checks') {
    $where=[]; $params=[];
    if (!empty($_GET['start'])) { $where[]='ch.due_date>=?'; $params[]=$_GET['start']; }
    if (!empty($_GET['end'])) { $where[]='ch.due_date<=?'; $params[]=$_GET['end']; }
    if (!empty($_GET['cari_id'])) { $where[]='ch.cari_id=?'; $params[]=(int)$_GET['cari_id']; }
    if (!empty($_GET['direction'])) { $where[]='ch.direction=?'; $params[]=$_GET['direction']; }
    if (!empty($_GET['status'])) { $where[]='ch.status=?'; $params[]=$_GET['status']; }
    if (!empty($_GET['account_id'])) { $where[]='ch.account_id=?'; $params[]=(int)$_GET['account_id']; }
    $sql="SELECT ch.*, c.name AS cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN accounts a ON a.id=ch.account_id";
    if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY ch.due_date ASC, ch.id DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    fputcsv($out,['ID','Yön','Durum','Çek Tarihi','Vade','Cari','Kasa/Banka','Banka','Şube','Çek No','Keşideci','Açıklama','Tutar','Belge'], ';');
    foreach($rows as $r) fputcsv($out,[$r['id'],check_direction_label($r['direction']),check_status_label($r['status']),$r['issue_date'],$r['due_date'],$r['cari_name'],$r['account_name'],$r['bank_name'],$r['branch_name'],$r['check_no'],$r['drawer'],$r['description'],number_format((float)$r['amount'],2,',','.'),$r['document_name']], ';');
    log_action('Çek CSV indirildi', $filenamePrefix); fclose($out); exit;
}

if ($type === 'cariler') {
    $rows = db()->query('SELECT * FROM cariler ORDER BY name ASC')->fetchAll();
    fputcsv($out,['ID','Tip','Ad/Ünvan','Yetkili','Şehir','Vergi No','Vergi Dairesi','Telefon','E-posta','Adres','IBAN','Not','Net Bakiye'], ';');
    foreach($rows as $r) { $b=cari_balance((int)$r['id']); fputcsv($out,[$r['id'],$r['cari_type'],$r['name'],$r['authorized_person'],$r['city'],$r['tax_no'],$r['tax_office'],$r['phone'],$r['email'],$r['address'],$r['iban'],$r['notes'],number_format((float)$b['net'],2,',','.')], ';'); }
    log_action('Cari CSV indirildi', $filenamePrefix); fclose($out); exit;
}

if ($type === 'account_transactions') {
    $where=[]; $params=[];
    if (!empty($_GET['start'])) { $where[]='at.transaction_date>=?'; $params[]=$_GET['start']; }
    if (!empty($_GET['end'])) { $where[]='at.transaction_date<=?'; $params[]=$_GET['end']; }
    if (!empty($_GET['account_id'])) { $where[]='at.account_id=?'; $params[]=(int)$_GET['account_id']; }
    $sql="SELECT at.*, a.name AS account_name, a.account_type, u.display_name AS user_name FROM account_transactions at JOIN accounts a ON a.id=at.account_id LEFT JOIN users u ON u.id=at.created_by";
    if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY at.transaction_date DESC, at.id DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    fputcsv($out,['ID','Tarih','Hesap','Hesap Tipi','Yön','Kaynak','Açıklama','Tutar','Kullanıcı'], ';');
    foreach($rows as $r) fputcsv($out,[$r['id'],$r['transaction_date'],$r['account_name'],account_type_label($r['account_type']),$r['direction']==='in'?'Giriş':'Çıkış',$r['source_type'],$r['description'],number_format((float)$r['amount'],2,',','.'),$r['user_name']], ';');
    log_action('Kasa/Banka CSV indirildi', $filenamePrefix); fclose($out); exit;
}

http_response_code(400); exit('Geçersiz dışa aktarma.');
