<?php
require_once __DIR__ . '/layout.php';
require_login();

$today = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));

function cek_banka_listesi(): array
{
    return ['Akbank','Aktif Bank','Albaraka Türk','Alternatif Bank','Anadolubank','Burgan Bank','Citibank','DenizBank','Emlak Katılım','Fibabanka','Garanti BBVA','Halkbank','HSBC Türkiye','ING Bank','İş Bankası','Kuveyt Türk','Odea Bank','QNB Finansbank','Şekerbank','TEB','Türkiye Finans','Vakıf Katılım','VakıfBank','Yapı Kredi','Ziraat Bankası','Ziraat Katılım','Diğer'];
}

function cek_status_meta2(string $status): array
{
    $map = [
        'bekliyor' => ['label'=>'Bekliyor','tone'=>'info'],
        'bankaya_verildi' => ['label'=>'Bankaya verildi','tone'=>'info'],
        'tahsil_edildi' => ['label'=>'Tahsil edildi','tone'=>'success'],
        'odendi' => ['label'=>'Ödendi','tone'=>'success'],
        'ciro_edildi' => ['label'=>'Ciro edildi','tone'=>'warning'],
        'iade' => ['label'=>'İade','tone'=>'neutral'],
        'karsiliksiz' => ['label'=>'Karşılıksız','tone'=>'danger'],
        'protestolu' => ['label'=>'Protestolu','tone'=>'danger'],
        'iptal' => ['label'=>'İptal','tone'=>'neutral'],
    ];
    return $map[$status] ?? ['label'=>$status ?: 'Bekliyor','tone'=>'neutral'];
}
function cek_status_label2(string $status): string { return cek_status_meta2($status)['label']; }
function cek_status_tone2(string $status): string { return cek_status_meta2($status)['tone']; }
function cek_status_options_for_direction(string $direction): array
{
    if ($direction === 'verilecek') {
        return [
            'bekliyor' => 'Bekliyor',
            'odendi' => 'Ödendi',
            'iade' => 'İade alındı',
            'karsiliksiz' => 'Karşılıksız',
            'protestolu' => 'Protestolu',
        ];
    }
    return [
        'bekliyor' => 'Bekliyor',
        'bankaya_verildi' => 'Bankaya verildi',
        'tahsil_edildi' => 'Tahsil edildi',
        'ciro_edildi' => 'Ciro edildi',
        'iade' => 'İade',
        'karsiliksiz' => 'Karşılıksız',
        'protestolu' => 'Protestolu',
    ];
}
function cek_is_open_status(string $status): bool { return in_array($status, ['bekliyor','bankaya_verildi'], true); }
function cek_due_text(array $ch, string $today): string
{
    if ((int)($ch['is_cancelled'] ?? 0) === 1) return 'İptal edildi';
    $status = (string)($ch['status'] ?? 'bekliyor');
    if (!cek_is_open_status($status)) return cek_status_label2($status);
    $due = (string)($ch['due_date'] ?? '');
    if ($due === '') return '-';
    $diff = (int)round((strtotime($due) - strtotime($today)) / 86400);
    if ($diff < 0) return abs($diff) . ' gün gecikti';
    if ($diff === 0) return 'Bugün vade';
    if ($diff === 1) return 'Yarın vade';
    return $diff . ' gün kaldı';
}
function cek_prefix(int $id): string { return 'Çek #' . $id . ' | '; }
function cek_extra_docs_for_ids(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = db()->query("SELECT * FROM standalone_documents WHERE description LIKE 'Çek #% | %' ORDER BY id ASC")->fetchAll();
    $out = [];
    $allowed = array_flip($ids);
    foreach ($rows as $row) {
        if (!preg_match('/^Çek #(\d+) \| /u', (string)($row['description'] ?? ''), $m)) continue;
        $id = (int)$m[1];
        if (!isset($allowed[$id])) continue;
        $out[$id][] = $row;
    }
    return $out;
}
function save_check_extra_upload(int $checkId, ?int $cariId, string $field, string $docType, string $label): void
{
    if (empty($_FILES[$field]['name'])) return;
    $doc = handle_upload($field);
    if (!$doc['path']) return;
    db()->prepare('INSERT INTO standalone_documents (cari_id, document_date, document_type, document_path, document_name, document_mime, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$cariId ?: null, date('Y-m-d'), $docType, $doc['path'], $doc['name'], $doc['mime'], cek_prefix($checkId) . $label, current_user()['id'] ?? null, now(), now()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? 'alinacak';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $due = $_POST['due_date'] ?? '';
        if (!isset(check_directions()[$direction]) || $amount <= 0 || $due === '') {
            flash('error', 'Çek yönü, tutar ve vade tarihi kontrol edilmeli.');
            redirect('cekler.php');
        }

        $oldDoc = null;
        $oldCheck = null;
        $status = 'bekliyor';
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
            $stmt->execute([$id]);
            $oldCheck = $stmt->fetch() ?: null;
            if (!$oldCheck || (int)($oldCheck['is_cancelled'] ?? 0) === 1) {
                flash('error', 'İptal edilmiş çek düzenlenemez.');
                redirect('cekler.php?include_cancelled=1');
            }
            $status = (string)($oldCheck['status'] ?? 'bekliyor');
            $oldDoc = ['path'=>$oldCheck['document_path'] ?? null, 'name'=>$oldCheck['document_name'] ?? null, 'mime'=>$oldCheck['document_mime'] ?? null];
        }

        try { $doc = handle_upload('document', $oldDoc); }
        catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cekler.php'); }

        $cariIdForExtra = ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null;
        $payload = [
            'cari_id' => $cariIdForExtra,
            'account_id' => ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null,
            'direction' => $direction,
            'status' => $status,
            'amount' => $amount,
            'issue_date' => $_POST['issue_date'] ?: null,
            'due_date' => $due,
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'branch_name' => trim($_POST['branch_name'] ?? ''),
            'check_no' => trim($_POST['check_no'] ?? ''),
            'drawer' => trim($_POST['drawer'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'document_path' => $doc['path'],
            'document_name' => $doc['name'],
            'document_mime' => $doc['mime'],
            'closed_at' => $oldCheck['closed_at'] ?? null,
            'is_opening_balance_check' => isset($_POST['is_opening_balance_check']) ? 1 : 0,
        ];

        if ($id > 0) {
            $stmt = db()->prepare('UPDATE checks SET cari_id=:cari_id, account_id=:account_id, direction=:direction, status=:status, amount=:amount, issue_date=:issue_date, due_date=:due_date, bank_name=:bank_name, branch_name=:branch_name, check_no=:check_no, drawer=:drawer, description=:description, document_path=:document_path, document_name=:document_name, document_mime=:document_mime, closed_at=:closed_at, is_opening_balance_check=:is_opening_balance_check, updated_at=:updated_at WHERE id=:id');
            $payload['updated_at'] = now();
            $payload['id'] = $id;
            $stmt->execute($payload);
            sync_check_to_movement($id);
            delete_replaced_upload($oldDoc, $doc);
            save_check_extra_upload($id, $cariIdForExtra, 'front_document', 'cek_on_gorseli', 'Ön görsel');
            save_check_extra_upload($id, $cariIdForExtra, 'back_document', 'cek_arka_gorseli', 'Arka görsel');
            log_action('Çek güncellendi', '#' . $id . ' ' . check_direction_label($direction) . ' ' . money($amount));
            audit_action('cek', $id, 'guncellendi', $oldCheck, $payload, check_direction_label($direction));
            flash('success', 'Çek güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO checks (cari_id, account_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, closed_at, is_opening_balance_check, created_by, created_at, updated_at) VALUES (:cari_id, :account_id, :direction, :status, :amount, :issue_date, :due_date, :bank_name, :branch_name, :check_no, :drawer, :description, :document_path, :document_name, :document_mime, :closed_at, :is_opening_balance_check, :created_by, :created_at, :updated_at)');
            $payload['created_by'] = current_user()['id'];
            $payload['created_at'] = now();
            $payload['updated_at'] = now();
            $stmt->execute($payload);
            $newId = (int)db()->lastInsertId();
            sync_check_to_movement($newId);
            save_check_extra_upload($newId, $cariIdForExtra, 'front_document', 'cek_on_gorseli', 'Ön görsel');
            save_check_extra_upload($newId, $cariIdForExtra, 'back_document', 'cek_arka_gorseli', 'Arka görsel');
            log_action('Çek eklendi', check_direction_label($direction) . ' ' . money($amount));
            audit_action('cek', $newId, 'eklendi', null, $payload, check_direction_label($direction));
            flash('success', 'Çek eklendi.');
        }
        redirect('cekler.php');
    }

    if ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'bekliyor';
        $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if ($old && (int)($old['is_cancelled'] ?? 0) === 0) {
            $allowed = cek_status_options_for_direction((string)$old['direction']);
            if (!isset($allowed[$newStatus])) {
                flash('error', check_direction_label((string)$old['direction']) . ' için bu durum seçilemez.');
                redirect('cekler.php');
            }
            $closedAt = cek_is_open_status($newStatus) ? null : date('Y-m-d');
            db()->prepare('UPDATE checks SET status=?, closed_at=?, updated_at=? WHERE id=?')->execute([$newStatus, $closedAt, now(), $id]);
            sync_check_to_movement($id);
            log_action('Çek durumu güncellendi', '#' . $id . ' ' . cek_status_label2($newStatus));
            audit_action('cek', $id, 'durum', $old, ['status'=>$newStatus,'closed_at'=>$closedAt], cek_status_label2($newStatus));
            flash('success', 'Çek durumu güncellendi: ' . cek_status_label2($newStatus));
        }
        redirect('cekler.php');
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
        $stmt->execute([$id]);
        $ch = $stmt->fetch();
        if ($ch && (int)($ch['is_cancelled'] ?? 0) === 0) {
            $reason = trim($_POST['cancel_reason'] ?? 'Liste üzerinden iptal');
            db()->prepare('UPDATE checks SET is_cancelled=1, status=?, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')->execute(['iptal', now(), current_user()['id'], $reason, now(), $id]);
            sync_check_to_movement($id, false);
            log_action('Çek iptal edildi', '#' . $id . ' ' . money($ch['amount']));
            audit_action('cek', $id, 'iptal', $ch, ['is_cancelled'=>1,'cancel_reason'=>$reason], money($ch['amount']));
            flash('success', 'Çek iptal edildi. Kayıt silinmedi; geçmişte korunuyor.');
        }
        redirect('cekler.php');
    }
}

$cariler = cariler_for_select();
$accounts = accounts_for_select(true);
$bankList = cek_banka_listesi();
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if ($edit && (int)($edit['is_cancelled'] ?? 0) === 1) {
        flash('error', 'İptal edilmiş çek düzenlenemez.');
        redirect('cekler.php?include_cancelled=1');
    }
}

$q = trim($_GET['q'] ?? '');
$cariId = trim($_GET['cari_id'] ?? '');
$direction = trim($_GET['direction'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dueFilter = trim($_GET['due_filter'] ?? '');
$accountId = trim($_GET['account_id'] ?? '');
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']) && $_GET['include_cancelled'] === '1';
$where = [];
$params = [];
if (!$includeCancelled) $where[] = 'COALESCE(ch.is_cancelled,0)=0';
if ($q !== '') {
    $where[] = '(ch.bank_name LIKE ? OR ch.branch_name LIKE ? OR ch.check_no LIKE ? OR ch.drawer LIKE ? OR ch.description LIKE ? OR c.name LIKE ? OR a.name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($cariId !== '') { $where[] = 'ch.cari_id=?'; $params[] = (int)$cariId; }
if ($direction !== '') { $where[] = 'ch.direction=?'; $params[] = $direction; }
if ($statusFilter !== '') { $where[] = 'ch.status=?'; $params[] = $statusFilter; }
if ($accountId !== '') { $where[] = 'ch.account_id=?'; $params[] = (int)$accountId; }
if ($start !== '') { $where[] = 'ch.due_date>=?'; $params[] = $start; }
if ($end !== '') { $where[] = 'ch.due_date<=?'; $params[] = $end; }
if ($dueFilter === 'overdue') { $where[] = "ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date < ?"; $params[] = $today; }
if ($dueFilter === 'today') { $where[] = "ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date = ?"; $params[] = $today; }
if ($dueFilter === 'week') { $where[] = "ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date >= ? AND ch.due_date <= ?"; array_push($params, $today, $weekAhead); }

$sql = "SELECT ch.*, c.name AS cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN accounts a ON a.id=ch.account_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ch.due_date ASC, ch.id DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$checks = $stmt->fetchAll();
$extraDocs = cek_extra_docs_for_ids(array_column($checks, 'id'));

$pendingReceived = $pendingGiven = $endorsedAmount = 0.0;
$overdueCount = $todayCount = $soonCount = 0;
foreach ($checks as $ch) {
    if ((int)($ch['is_cancelled'] ?? 0) === 1) continue;
    $amount = (float)$ch['amount'];
    $status = (string)($ch['status'] ?? 'bekliyor');
    if ($status === 'ciro_edildi') $endorsedAmount += $amount;
    if (cek_is_open_status($status)) {
        if ($ch['direction'] === 'alinacak') $pendingReceived += $amount;
        if ($ch['direction'] === 'verilecek') $pendingGiven += $amount;
        if ($ch['due_date'] < $today) $overdueCount++;
        elseif ($ch['due_date'] === $today) $todayCount++;
        elseif ($ch['due_date'] <= $weekAhead) $soonCount++;
    }
}

page_header('Çekler', 'cekler');
?>
<style>
.checks-v2{display:grid;gap:16px;max-width:1540px;margin:0 auto}.checks-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.checks-hero h2{margin:4px 0 6px;color:#fff;font-size:clamp(24px,3vw,38px);line-height:1}.checks-hero p{margin:0;color:#e9f5ed;max-width:760px}.checks-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.checks-actions{display:flex;gap:8px;flex-wrap:wrap}.checks-actions a,.checks-actions button{border:0;border-radius:999px;padding:10px 14px;background:#fff;color:#16482e;text-decoration:none;font-weight:900}.checks-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.checks-summary article{background:#fff;border:1px solid #e5dccf;border-radius:18px;padding:15px 16px;box-shadow:0 12px 30px rgba(7,27,63,.06)}.checks-summary span{font-size:11px;color:#8a6a26;font-weight:950;text-transform:uppercase;letter-spacing:.04em}.checks-summary strong{display:block;margin-top:7px;color:#102818;font-size:22px}.check-form-details{background:#fff;border:1px solid #e5dccf;border-radius:20px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.check-form-details>summary{cursor:pointer;list-style:none;display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:#fbf6ed;font-weight:950;color:#102818}.check-form-details>summary::-webkit-details-marker{display:none}.check-form-body{padding:16px 18px}.check-form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.check-form-grid label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.check-form-grid input,.check-form-grid select,.check-form-grid textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.check-form-grid .wide{grid-column:span 2}.check-form-grid .full{grid-column:1/-1}.check-note{margin:12px 0 0;padding:12px;border-radius:14px;background:#fbf6ed;color:#776b5c}.check-list-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.check-list-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.check-list-head h3{margin:0;color:#102818}.check-filter{display:grid;grid-template-columns:minmax(180px,1.5fr) 130px 150px 140px 110px 110px auto;gap:8px;padding:12px 14px;border-bottom:1px solid #e5dccf}.check-filter input,.check-filter select,.check-filter button,.check-filter a{min-height:38px;border:1px solid #e5dccf;border-radius:999px;padding:7px 11px;background:#fff;color:#102818;font-weight:800;text-decoration:none}.check-table-wrap{overflow:auto}.check-table{width:100%;min-width:1180px;border-collapse:separate;border-spacing:0}.check-table th{background:#16482e;color:#fff;text-align:left;padding:11px 12px;font-size:11px;letter-spacing:.03em;text-transform:uppercase}.check-table td{padding:12px;border-bottom:1px solid #e5dccf;vertical-align:top;font-size:13px}.check-table b{display:block;color:#102818}.check-table span,.check-table small{display:block;color:#776b5c;font-size:12px;margin-top:3px}.check-table tr.is-receivable td{background:#f5fff8}.check-table tr.is-payable td{background:#fff8f1}.check-table tr.is-endorsed td{background:#fff8dd}.check-table tr.is-overdue td{background:#fff1ed}.check-life{display:grid;gap:3px;margin-top:7px}.check-life em{display:block;border-left:3px solid #16482e;padding:4px 7px;color:#102818;font-size:11px;font-style:normal;background:rgba(22,72,46,.06);border-radius:7px}.doc-pills{display:flex;gap:6px;flex-wrap:wrap}.doc-pills a,.doc-pills span{display:inline-flex;align-items:center;min-height:28px;border-radius:999px;padding:5px 9px;border:1px solid #e5dccf;background:#fff;color:#102818;text-decoration:none;font-size:11px;font-weight:900}.row-control{display:grid;gap:7px;min-width:180px}.status-form{display:grid;grid-template-columns:1fr auto;gap:6px}.status-form select,.status-form button{min-height:32px;border-radius:999px;border:1px solid #e5dccf;background:#fff;color:#102818;font-weight:800;padding:5px 9px}.status-form button{background:#16482e;color:#fff;border-color:#16482e}.row-links{display:flex;gap:6px;flex-wrap:wrap}.row-links a,.row-links button{display:inline-flex;min-height:30px;border-radius:999px;padding:5px 9px;border:1px solid #e5dccf;background:#fff;color:#102818;text-decoration:none;font-size:11px;font-weight:900}.row-links button.danger{color:#b64242;border-color:rgba(182,66,66,.24)}.status-badge{display:inline-flex!important;width:max-content;border-radius:999px;padding:4px 8px;font-size:11px!important;font-weight:900}.tone-success{background:#e9f8ef;color:#167243}.tone-warning{background:#fff4d6;color:#946200}.tone-danger{background:#ffe7e2;color:#b64242}.tone-info{background:#e8f2ff;color:#2459c7}.tone-neutral{background:#f1f3f5;color:#667085}@media(max-width:1280px){.check-filter{grid-template-columns:1fr 1fr 1fr}.check-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.checks-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.checks-hero{display:block}.checks-actions{margin-top:12px}.checks-summary{grid-template-columns:1fr 1fr}.check-filter,.check-form-grid{grid-template-columns:1fr}.check-form-grid .wide{grid-column:1}.check-table{min-width:980px}.check-list-head{display:block}.check-list-head .checks-actions{margin-top:10px}}
</style>

<div class="checks-v2">
  <section class="checks-hero">
    <div><span>BİTKE ÇEK TAKİBİ</span><h2>Çekler düzenli, işlem mantığı sade.</h2><p>Önce listeyi gör, filtrele, durumunu tek formdan güncelle. Yeni çek/düzenleme formu açılır kapanır; sayfayı sıkıştırmaz.</p></div>
    <div class="checks-actions"><a href="#cek-form"><?php echo $edit ? 'Düzenlemeye git' : 'Yeni çek ekle'; ?></a><a href="cek-ek-belge.php?id=0" onclick="return false;" title="Önce listeden çek seç">Ek belge çekten açılır</a></div>
  </section>

  <section class="checks-summary">
    <article><span>Alınan bekleyen</span><strong><?php echo e(money($pendingReceived)); ?></strong></article>
    <article><span>Verilen bekleyen</span><strong><?php echo e(money($pendingGiven)); ?></strong></article>
    <article><span>Ciro edilen</span><strong><?php echo e(money($endorsedAmount)); ?></strong></article>
    <article><span>Vade uyarısı</span><strong><?php echo e($overdueCount + $todayCount + $soonCount); ?> adet</strong></article>
  </section>

  <section class="check-list-card">
    <div class="check-list-head"><div><h3>Çek listesi</h3><small><?php echo e(count($checks)); ?> kayıt</small></div><div class="checks-actions"><a href="export.php?type=checks&<?php echo e(http_build_query($_GET)); ?>">CSV indir</a><a href="cekler.php">Temizle</a></div></div>
    <form class="check-filter" method="get">
      <input name="q" placeholder="Cari, banka, çek no, açıklama ara" value="<?php echo e($q); ?>">
      <select name="direction"><option value="">Tüm yönler</option><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $direction===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <select name="status"><option value="">Tüm durumlar</option><?php foreach(['bekliyor','bankaya_verildi','ciro_edildi','tahsil_edildi','odendi','karsiliksiz','protestolu','iade'] as $st): ?><option value="<?php echo e($st); ?>" <?php echo $statusFilter===$st?'selected':''; ?>><?php echo e(cek_status_label2($st)); ?></option><?php endforeach; ?></select>
      <select name="due_filter"><option value="">Tüm vadeler</option><option value="overdue" <?php echo $dueFilter==='overdue'?'selected':''; ?>>Geciken</option><option value="today" <?php echo $dueFilter==='today'?'selected':''; ?>>Bugün</option><option value="week" <?php echo $dueFilter==='week'?'selected':''; ?>>7 gün</option></select>
      <input type="date" name="start" value="<?php echo e($start); ?>">
      <input type="date" name="end" value="<?php echo e($end); ?>">
      <button type="submit">Filtrele</button>
    </form>
    <div class="check-table-wrap"><table class="check-table"><thead><tr><th>Tür / Cari</th><th>Banka / Çek</th><th>Vade</th><th>Tutar</th><th>Durum / Geçmiş</th><th>Görseller</th><th>İşlem</th></tr></thead><tbody>
      <?php if(!$checks): ?><tr><td colspan="7" class="empty">Çek kaydı yok.</td></tr><?php endif; ?>
      <?php foreach($checks as $ch):
        $id=(int)$ch['id']; $cancelled=(int)($ch['is_cancelled'] ?? 0)===1; $status=$cancelled?'iptal':(string)($ch['status'] ?? 'bekliyor');
        $isOverdue=!$cancelled && cek_is_open_status($status) && $ch['due_date'] < $today;
        $rowClass=($ch['direction']==='alinacak'?'is-receivable ':'is-payable ') . ($status==='ciro_edildi'?'is-endorsed ':'') . ($isOverdue?'is-overdue':'');
        $docs=$extraDocs[$id] ?? []; $frontDocs=array_values(array_filter($docs, fn($d)=>($d['document_type'] ?? '')==='cek_on_gorseli')); $backDocs=array_values(array_filter($docs, fn($d)=>($d['document_type'] ?? '')==='cek_arka_gorseli'));
        $tone=cek_status_tone2($status);
      ?>
      <tr class="<?php echo e(trim($rowClass)); ?>">
        <td><b><?php echo e(check_direction_label($ch['direction'])); ?></b><span><?php echo $ch['cari_id'] ? '<a href="cari-detay.php?id='.e($ch['cari_id']).'">'.e($ch['cari_name']).'</a>' : '-'; ?></span><?php echo ((int)($ch['is_opening_balance_check'] ?? 0)===1)?'<small>Devir dengeli</small>':''; ?></td>
        <td><b><?php echo e($ch['bank_name'] ?: '-'); ?></b><span><?php echo e($ch['check_no'] ?: '-'); ?></span><?php echo $ch['drawer'] ? '<small>'.e($ch['drawer']).'</small>' : ''; ?></td>
        <td><b><?php echo e(tr_date($ch['due_date'])); ?></b><span><?php echo e(cek_due_text($ch, $today)); ?></span><?php echo $ch['issue_date'] ? '<small>Çek: '.e(tr_date($ch['issue_date'])).'</small>' : ''; ?></td>
        <td><b><?php echo e(money($ch['amount'])); ?></b><span>TRY</span></td>
        <td><span class="status-badge tone-<?php echo e($tone); ?>"><?php echo e(cek_status_label2($status)); ?></span><?php echo $ch['description'] ? '<small>'.e($ch['description']).'</small>' : ''; ?><div class="check-life"><em><?php echo e(tr_date(substr((string)($ch['created_at'] ?? $today),0,10))); ?> · Kayda alındı</em><?php if($status !== 'bekliyor'): ?><em><?php echo e(tr_date($ch['closed_at'] ?: substr((string)($ch['updated_at'] ?? $today),0,10))); ?> · <?php echo e(cek_status_label2($status)); ?></em><?php endif; ?></div></td>
        <td><div class="doc-pills"><?php if($frontDocs): ?><a href="serbest-belge-indir.php?id=<?php echo e($frontDocs[0]['id']); ?>" target="_blank">Ön</a><?php elseif(!empty($ch['document_path'])): ?><a href="cek-belge-indir.php?id=<?php echo e($id); ?>" target="_blank">Ana</a><?php else: ?><span>Ön yok</span><?php endif; ?><?php if($backDocs): ?><a href="serbest-belge-indir.php?id=<?php echo e($backDocs[0]['id']); ?>" target="_blank">Arka</a><?php else: ?><span>Arka yok</span><?php endif; ?><?php if(count($docs)>2): ?><a href="cek-ek-belge.php?id=<?php echo e($id); ?>"><?php echo e(count($docs)); ?> belge</a><?php endif; ?></div></td>
        <td><div class="row-control"><?php if(!$cancelled): ?><form method="post" class="status-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?php echo e($id); ?>"><select name="status"><?php foreach(cek_status_options_for_direction((string)$ch['direction']) as $value=>$label): ?><option value="<?php echo e($value); ?>" <?php echo $status===$value?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select><button type="submit">Kaydet</button></form><div class="row-links"><a href="cekler.php?edit=<?php echo e($id); ?>#cek-form">Düzenle</a><a href="cek-ek-belge.php?id=<?php echo e($id); ?>">Ek belge</a><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Çek silinmeyecek, iptal edildi olarak işaretlenecek. Devam edilsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($id); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal"><button class="danger" type="submit">İptal</button></form><?php endif; ?></div><?php else: ?><span class="muted">Kayıt korundu</span><?php endif; ?></div></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <details class="check-form-details" id="cek-form" <?php echo $edit ? 'open' : ''; ?>>
    <summary><span><?php echo $edit ? 'Çek düzenle' : 'Yeni çek ekle'; ?></span><small><?php echo $edit ? 'Seçili çek kaydı açık' : 'Formu aç / kapat'; ?></small></summary>
    <div class="check-form-body">
      <?php if(can_write()): ?>
      <form method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
        <div class="check-form-grid">
          <label><span>Yön</span><select name="direction" required><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['direction'] ?? 'alinacak')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select></label>
          <label><span>Tutar</span><input name="amount" type="text" inputmode="decimal" required value="<?php echo e($edit['amount'] ?? ''); ?>" placeholder="100000"></label>
          <label><span>Vade</span><input name="due_date" type="date" required value="<?php echo e($edit['due_date'] ?? date('Y-m-d')); ?>"></label>
          <label><span>Çek tarihi</span><input name="issue_date" type="date" value="<?php echo e($edit['issue_date'] ?? ''); ?>"></label>
          <label class="wide"><span>Cari</span><select name="cari_id" required><option value="">Cari seç</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label>
          <label><span>Banka</span><select name="bank_name"><option value="">Banka seç</option><?php $currentBank=trim((string)($edit['bank_name'] ?? '')); if($currentBank!==''&&!in_array($currentBank,$bankList,true)): ?><option value="<?php echo e($currentBank); ?>" selected><?php echo e($currentBank); ?></option><?php endif; ?><?php foreach($bankList as $bank): ?><option value="<?php echo e($bank); ?>" <?php echo $currentBank===$bank?'selected':''; ?>><?php echo e($bank); ?></option><?php endforeach; ?></select></label>
          <label><span>Çek no</span><input name="check_no" value="<?php echo e($edit['check_no'] ?? ''); ?>" placeholder="Çek no"></label>
          <label><span>Şube</span><input name="branch_name" value="<?php echo e($edit['branch_name'] ?? ''); ?>"></label>
          <label><span>Keşideci / Veren</span><input name="drawer" value="<?php echo e($edit['drawer'] ?? ''); ?>"></label>
          <label class="wide"><span>Bilgi amaçlı hesap</span><select name="account_id"><option value="">Hesap seçilmedi</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo ((string)($edit['account_id'] ?? '')===(string)$a['id'])?'selected':''; ?>><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select></label>
          <label class="wide"><span>Açıklama</span><textarea name="description" rows="2" placeholder="Opsiyonel not"><?php echo e($edit['description'] ?? ''); ?></textarea></label>
          <label><span>Çek ön görsel</span><input name="front_document" type="file" accept="image/*,application/pdf"></label>
          <label><span>Çek arka görsel</span><input name="back_document" type="file" accept="image/*,application/pdf"></label>
          <label class="wide"><span>Ana çek belgesi</span><input name="document" type="file" accept="image/*,application/pdf"></label>
          <label class="full check"><input type="checkbox" name="is_opening_balance_check" value="1" <?php echo ((int)($edit['is_opening_balance_check'] ?? 0)===1)?'checked':''; ?>> Bu çek eski/devir çek; cari bakiyesi daha önce net girildi</label>
        </div>
        <p class="check-note">Çek kaydı cari harekete bağlıdır. Ön/arka görsel yüklersen çekin ek belge kartında görünür.</p>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Çek Güncelle' : 'Çek Kaydet'; ?></button><?php if($edit): ?><a class="btn btn-secondary" href="cekler.php">Vazgeç</a><?php endif; ?></div>
      </form>
      <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Çek ekleme/düzenleme kapalı.</p><?php endif; ?>
    </div>
  </details>
</div>
<?php page_footer(); ?>