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
    if (empty($_GET['include_cancelled'])) $where[]='COALESCE(ch.is_cancelled,0)=0';
    if (!empty($_GET['start'])) { $where[]='ch.due_date>=?'; $params[]=$_GET['start']; }
    if (!empty($_GET['end'])) { $where[]='ch.due_date<=?'; $params[]=$_GET['end']; }
    if (!empty($_GET['cari_id'])) { $where[]='ch.cari_id=?'; $params[]=(int)$_GET['cari_id']; }
    if (!empty($_GET['direction'])) { $where[]='ch.direction=?'; $params[]=$_GET['direction']; }
    if (!empty($_GET['status'])) { $where[]='ch.status=?'; $params[]=$_GET['status']; }
    if (!empty($_GET['account_id'])) { $where[]='ch.account_id=?'; $params[]=(int)$_GET['account_id']; }
    $sql="SELECT ch.*, c.name AS cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN accounts a ON a.id=ch.account_id";
    if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY ch.due_date ASC, ch.id DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    fputcsv($out,['ID','Yön','Durum','İptal Durumu','Çek Tarihi','Vade','Cari','Kasa/Banka','Banka','Şube','Çek No','Keşideci','Açıklama','Tutar','Belge'], ';');
    foreach($rows as $r) fputcsv($out,[$r['id'],check_direction_label($r['direction']),check_status_label($r['status']),((int)($r['is_cancelled'] ?? 0)===1?'İptal':'Aktif'),$r['issue_date'],$r['due_date'],$r['cari_name'],$r['account_name'],$r['bank_name'],$r['branch_name'],$r['check_no'],$r['drawer'],$r['description'],number_format((float)$r['amount'],2,',','.'),$r['document_name']], ';');
    log_action('Çek CSV indirildi', $filenamePrefix); fclose($out); exit;
}


if ($type === 'payment_calendar') {
    $start = substr((string)($_GET['start'] ?? date('Y-m-01')), 0, 10);
    $end = substr((string)($_GET['end'] ?? date('Y-m-t')), 0, 10);
    if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }
    $rows = payment_calendar_rows($start, $end, true);
    $summary = payment_calendar_summary($rows);
    fputcsv($out, ['ÖDEME TAKVİMİ'], ';');
    fputcsv($out, ['Dönem', tr_date($start) . ' - ' . tr_date($end)], ';');
    fputcsv($out, ['Beklenen Alacak', number_format((float)$summary['in'], 2, ',', '.')], ';');
    fputcsv($out, ['Yapılacak Ödeme', number_format((float)$summary['out'], 2, ',', '.')], ';');
    fputcsv($out, ['Net', number_format((float)$summary['net'], 2, ',', '.')], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Vade', 'Tür', 'Kaynak', 'Cari', 'Açıklama', 'Durum', 'Tutar'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['date'],
            $r['kind'],
            $r['source'],
            $r['cari_name'],
            $r['description'],
            $r['status'],
            number_format((float)$r['amount'], 2, ',', '.')
        ], ';');
    }
    log_action('Ödeme Takvimi Excel indirildi', $filenamePrefix);
    fclose($out);
    exit;
}

if ($type === 'cariler') {
    $rows = db()->query('SELECT * FROM cariler ORDER BY name ASC')->fetchAll();
    fputcsv($out,['ID','Tip','Ad/Ünvan','Yetkili','Şehir','Vergi No','Vergi Dairesi','Telefon','E-posta','Adres','IBAN','Not','Net Bakiye'], ';');
    foreach($rows as $r) { $b=cari_balance((int)$r['id']); fputcsv($out,[$r['id'],$r['cari_type'],$r['name'],$r['authorized_person'],$r['city'],$r['tax_no'],$r['tax_office'],$r['phone'],$r['email'],$r['address'],$r['iban'],$r['notes'],number_format((float)$b['net'],2,',','.')], ';'); }
    log_action('Cari CSV indirildi', $filenamePrefix); fclose($out); exit;
}


if ($type === 'private_receivables') {
    $where=[]; $params=[];
    if (!empty($_GET['status']) && isset(private_receivable_statuses()[$_GET['status']])) { $where[]='pr.status=?'; $params[]=$_GET['status']; }
    if (!empty($_GET['start'])) { $where[]='pr.receivable_date>=?'; $params[]=$_GET['start']; }
    if (!empty($_GET['end'])) { $where[]='pr.receivable_date<=?'; $params[]=$_GET['end']; }
    if (!empty($_GET['cari_id'])) { $where[]='pr.cari_id=?'; $params[]=(int)$_GET['cari_id']; }
    if (!empty($_GET['q'])) { $where[]='(pr.description LIKE ? OR c.name LIKE ? OR pr.document_name LIKE ?)'; $q='%'.$_GET['q'].'%'; array_push($params,$q,$q,$q); }
    $sql="SELECT pr.*, c.name AS cari_name, u.display_name AS user_name FROM private_receivables pr JOIN cariler c ON c.id=pr.cari_id LEFT JOIN users u ON u.id=pr.created_by";
    if ($where) $sql.=' WHERE '.implode(' AND ',$where);
    $sql.=' ORDER BY pr.receivable_date DESC, pr.id DESC';
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
    fputcsv($out,['ID','Tarih','Cari','Durum','Açıklama','Belge Türü','Belge','Tutar','Ekleyen','Oluşturma'], ';');
    foreach($rows as $r) fputcsv($out,[$r['id'],$r['receivable_date'],$r['cari_name'],private_receivable_status_label($r['status']),$r['description'],document_type_label($r['document_type']),$r['document_name'],number_format((float)$r['amount'],2,',','.'),$r['user_name'],$r['created_at']], ';');
    log_action('Özel Alacak CSV indirildi', $filenamePrefix); fclose($out); exit;
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
