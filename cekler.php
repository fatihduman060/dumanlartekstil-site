<?php
require_once __DIR__ . '/layout.php';
require_login();

$today = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write(); require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'quick_status') {
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $ciroCariId = ($_POST['ciro_cari_id'] ?? '') !== '' ? (int)$_POST['ciro_cari_id'] : null;
        $ciroNote = trim($_POST['ciro_note'] ?? '');
        $allowed = array_keys(check_statuses());
        if ($id > 0 && in_array($newStatus, $allowed, true)) {
            $oldStmt = db()->prepare('SELECT * FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0');
            $oldStmt->execute([$id]);
            $oldCheck = $oldStmt->fetch() ?: null;
            if ($oldCheck) {
                if ($newStatus === 'ciro_edildi') {
                    if (($oldCheck['direction'] ?? '') !== 'alinacak') {
                        flash('error', 'Sadece alınan çek ciro edilebilir.');
                        redirect('cekler.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
                    }
                    if (!$ciroCariId) {
                        flash('error', 'Ciro için kime ciro edildiği seçilmeli.');
                        redirect('cekler.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
                    }
                    $stmt = db()->prepare('UPDATE checks SET status=?, ciro_cari_id=?, ciro_date=?, ciro_note=?, closed_at=?, updated_at=? WHERE id=?');
                    $stmt->execute([$newStatus, $ciroCariId, date('Y-m-d'), $ciroNote, now(), now(), $id]);
                } else {
                    $closedAt = in_array($newStatus, check_closed_statuses(), true) ? now() : null;
                    $stmt = db()->prepare('UPDATE checks SET status=?, account_id=COALESCE(?, account_id), closed_at=?, updated_at=? WHERE id=?');
                    $stmt->execute([$newStatus, $accountId, $closedAt, now(), $id]);
                }
                sync_check_cari_movement($id);
                sync_check_account_transaction($id);
                log_action('Çek durumu güncellendi', '#' . $id . ' → ' . check_status_label($newStatus));
                audit_action('cek', $id, 'durum_guncellendi', $oldCheck, ['status'=>$newStatus,'account_id'=>$accountId,'ciro_cari_id'=>$ciroCariId], check_status_label($newStatus));
                flash('success', 'Çek durumu güncellendi: ' . check_status_label($newStatus));
            }
        }
        redirect('cekler.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? 'alinacak';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $due = $_POST['due_date'] ?? '';
        $oldDoc = null; $oldCheck = null;
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
            $stmt->execute([$id]);
            $oldCheck = $stmt->fetch() ?: null;
            if (!$oldCheck || (int)($oldCheck['is_cancelled'] ?? 0) === 1) {
                flash('error', 'İptal edilmiş çek düzenlenemez. Gerekirse yeni çek kaydı girin.');
                redirect('cekler.php?include_cancelled=1');
            }
            $oldDoc = ['path'=>$oldCheck['document_path'] ?? null, 'name'=>$oldCheck['document_name'] ?? null, 'mime'=>$oldCheck['document_mime'] ?? null];
        }
        $status = $oldCheck['status'] ?? 'bekliyor';
        $closedAt = $oldCheck['closed_at'] ?? null;
        if (!isset(check_directions()[$direction]) || $amount <= 0 || $due === '') {
            flash('error', 'Çek tipi, tutar ve vade tarihi kontrol edilmeli.'); redirect('cekler.php');
        }
        try { $doc = handle_upload('document', $oldDoc); } catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cekler.php'); }
        $payload = [
            'cari_id' => ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
            'account_id' => ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null,
            'direction' => $direction, 'status' => $status, 'amount' => $amount,
            'issue_date' => $_POST['issue_date'] ?: null, 'due_date' => $due,
            'bank_name' => trim($_POST['bank_name'] ?? ''), 'branch_name' => trim($_POST['branch_name'] ?? ''),
            'check_no' => trim($_POST['check_no'] ?? ''), 'drawer' => trim($_POST['drawer'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'document_path' => $doc['path'], 'document_name' => $doc['name'], 'document_mime' => $doc['mime'],
            'closed_at' => $closedAt,
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE checks SET cari_id=:cari_id, account_id=:account_id, direction=:direction, status=:status, amount=:amount, issue_date=:issue_date, due_date=:due_date, bank_name=:bank_name, branch_name=:branch_name, check_no=:check_no, drawer=:drawer, description=:description, document_path=:document_path, document_name=:document_name, document_mime=:document_mime, closed_at=:closed_at, updated_at=:updated_at WHERE id=:id');
            $payload['updated_at'] = now(); $payload['id'] = $id; $stmt->execute($payload);
            delete_replaced_upload($oldDoc, $doc);
            sync_check_cari_movement($id);
            sync_check_account_transaction($id);
            log_action('Çek güncellendi', '#' . $id . ' ' . check_direction_label($direction) . ' ' . money($amount));
            audit_action('cek', $id, 'guncellendi', $oldCheck, $payload, check_direction_label($direction));
            flash('success','Çek güncellendi. Durum değişikliği işlem butonlarından yapılır.');
        } else {
            $stmt = db()->prepare('INSERT INTO checks (cari_id, account_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, closed_at, created_by, created_at, updated_at) VALUES (:cari_id, :account_id, :direction, :status, :amount, :issue_date, :due_date, :bank_name, :branch_name, :check_no, :drawer, :description, :document_path, :document_name, :document_mime, :closed_at, :created_by, :created_at, :updated_at)');
            $payload['created_by'] = current_user()['id']; $payload['created_at'] = now(); $payload['updated_at'] = now(); $stmt->execute($payload);
            $newId = (int)db()->lastInsertId();
            sync_check_cari_movement($newId);
            sync_check_account_transaction($newId);
            log_action('Çek eklendi', check_direction_label($direction) . ' ' . money($amount));
            audit_action('cek', $newId, 'eklendi', null, $payload, check_direction_label($direction));
            flash('success','Çek portföye eklendi. Durum otomatik portföyde kabul edildi.');
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
            db()->prepare('UPDATE checks SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                ->execute([now(), current_user()['id'], $reason, now(), $id]);
            sync_check_cari_movement($id);
            sync_check_account_transaction($id);
            log_action('Çek iptal edildi', '#' . $id . ' ' . money($ch['amount']));
            audit_action('cek', $id, 'iptal', $ch, ['is_cancelled'=>1,'cancel_reason'=>$reason], money($ch['amount']));
            flash('success','Çek iptal edildi. Kayıt silinmedi; geçmişte korunuyor.');
        }
        redirect('cekler.php');
    }
}

$cariler = cariler_for_select();
$accounts = accounts_for_select(true);
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if ($edit && (int)($edit['is_cancelled'] ?? 0) === 1) {
        flash('error', 'İptal edilmiş çek düzenlenemez. Gerekirse yeni çek kaydı girin.');
        redirect('cekler.php?include_cancelled=1');
    }
}
$q = trim($_GET['q'] ?? ''); $cariId = trim($_GET['cari_id'] ?? ''); $direction = trim($_GET['direction'] ?? ''); $status = trim($_GET['status'] ?? ''); $accountId = trim($_GET['account_id'] ?? ''); $start = trim($_GET['start'] ?? ''); $end = trim($_GET['end'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']) && $_GET['include_cancelled'] === '1';
$where=[]; $params=[];
if (!$includeCancelled) { $where[]='COALESCE(ch.is_cancelled,0)=0'; }
if ($q !== '') { $where[]='(ch.bank_name LIKE ? OR ch.branch_name LIKE ? OR ch.check_no LIKE ? OR ch.drawer LIKE ? OR ch.description LIKE ? OR ch.ciro_note LIKE ? OR c.name LIKE ? OR cc.name LIKE ? OR a.name LIKE ?)'; array_push($params, "%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"); }
if ($cariId !== '') { $where[]='(ch.cari_id=? OR ch.ciro_cari_id=?)'; $params[]=(int)$cariId; $params[]=(int)$cariId; }
if ($direction !== '') { $where[]='ch.direction=?'; $params[]=$direction; }
if ($status !== '') { $where[]='ch.status=?'; $params[]=$status; }
if ($accountId !== '') { $where[]='ch.account_id=?'; $params[]=(int)$accountId; }
if ($start !== '') { $where[]='ch.due_date>=?'; $params[]=$start; }
if ($end !== '') { $where[]='ch.due_date<=?'; $params[]=$end; }
$sql="SELECT ch.*, c.name AS cari_name, cc.name AS ciro_cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN cariler cc ON cc.id=ch.ciro_cari_id LEFT JOIN accounts a ON a.id=ch.account_id"; if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY ch.due_date ASC, ch.id DESC LIMIT 500';
$stmt=db()->prepare($sql); $stmt->execute($params); $checks=$stmt->fetchAll();
$totals = ['alinacak'=>0,'verilecek'=>0,'ciro'=>0,'bad_count'=>0];
$overdueCount = 0; $soonCount = 0;
foreach ($checks as $ch) {
    if ((int)($ch['is_cancelled'] ?? 0) === 1) continue;
    if ($ch['status'] === 'bekliyor') {
        $totals[$ch['direction']] += (float)$ch['amount'];
        if ($ch['due_date'] < $today) $overdueCount++;
        elseif ($ch['due_date'] <= $weekAhead) $soonCount++;
    }
    if ($ch['status'] === 'ciro_edildi') $totals['ciro'] += (float)$ch['amount'];
    if (in_array($ch['status'], ['karsiliksiz','protestolu'], true)) $totals['bad_count']++;
}
$ciroStmt = db()->query("SELECT ch.*, c.name AS cari_name, cc.name AS ciro_cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN cariler cc ON cc.id=ch.ciro_cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.status='ciro_edildi' ORDER BY COALESCE(ch.ciro_date, ch.closed_at, ch.updated_at) DESC, ch.id DESC LIMIT 100");
$ciroChecks = $ciroStmt->fetchAll();
page_header('Çekler', 'cekler');
?>
<style>
.cek-help{margin:0 0 14px;color:#64748b;font-size:13px;line-height:1.5}.row-bad{background:#fff1f2}.row-bad td{border-color:#fecdd3}.row-ciro{background:#fffbeb}.quick-status-form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:2px 0}.quick-status-form select,.quick-status-form input{min-width:120px;max-width:170px;padding:6px 8px;font-size:12px}.btn-quick-danger{background:#dc2626!important;color:#fff!important}.btn-quick-warn{background:#d97706!important;color:#fff!important}.status-muted{color:#64748b;font-size:12px}.ciro-note{color:#92400e;font-size:12px}
</style>
<section class="stats-grid four">
  <article class="stat-card"><span>Portföyde alınan çek</span><strong><?php echo e(money($totals['alinacak'])); ?></strong><small>Tarih gelene kadar nakit sayılmaz</small></article>
  <article class="stat-card"><span>Portföyde verilen çek</span><strong><?php echo e(money($totals['verilecek'])); ?></strong><small>Tarih gelene kadar nakit çıkışı sayılmaz</small></article>
  <article class="stat-card soft"><span>Ciro edilen çek</span><strong><?php echo e(money($totals['ciro'])); ?></strong><small>Kime ciro edildiyse onun vereceğinden düşer</small></article>
  <article class="stat-card soft"><span>Karşılıksız / problemli</span><strong class="<?php echo $totals['bad_count'] > 0 ? 'text-danger' : ''; ?>"><?php echo e($totals['bad_count']); ?> adet</strong><small>Kırmızı görünür, cari borç/alacak tekrar açılır</small></article>
</section>

<p class="cek-help"><strong>Okuma notu:</strong> Yeni çek eklerken durum seçilmez; sistem çekleri otomatik portföyde tutar. Vadesi gelince tahsil/ödendi veya karşılıksız seçilir. Alınan çek ciro edilirse kime verildiyse o carinin vereceği düşer; çek geri dönerse hem ilk alınan cari hem de ciro edilen cari tekrar açılır.</p>

<div class="cek-legend print-hide">
  <span class="cek-leg overdue-leg">■ Vadesi geçmiş portföy çeki</span>
  <span class="cek-leg soon-leg">■ 7 gün içinde vade</span>
  <span class="cek-leg">■ Normal</span>
</div>

<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Çek düzenle' : 'Yeni çek'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <label>Çek tipi<select name="direction" required><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['direction'] ?? '')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select><small>Alınan çek: müşteriden aldığın çek. Verilen çek: senin verdiğin çek.</small></label>
      <div class="two-col"><label>Tutar<input name="amount" type="text" inputmode="decimal" required value="<?php echo e($edit['amount'] ?? ''); ?>"></label><label>Vade tarihi<input name="due_date" type="date" required value="<?php echo e($edit['due_date'] ?? date('Y-m-d')); ?>"></label></div>
      <div class="two-col"><label>Çek tarihi<input name="issue_date" type="date" value="<?php echo e($edit['issue_date'] ?? ''); ?>"></label><label>Cari<select name="cari_id"><option value="">Cari seçilmedi</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select><small>Alınan çekte kimden aldın, verilen çekte kime verdin.</small></label></div>
      <label>Tahsil/ödeme hesabı<select name="account_id"><option value="">Kasa/banka seçilmedi</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo ((string)($edit['account_id'] ?? '')===(string)$a['id'])?'selected':''; ?>><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select><small>İstersen boş bırak. Kasa/banka yalnızca Tahsil edildi/Ödendi seçilince işler.</small></label>
      <div class="two-col"><label>Banka<input name="bank_name" value="<?php echo e($edit['bank_name'] ?? ''); ?>"></label><label>Şube<input name="branch_name" value="<?php echo e($edit['branch_name'] ?? ''); ?>"></label></div>
      <div class="two-col"><label>Çek no<input name="check_no" value="<?php echo e($edit['check_no'] ?? ''); ?>"></label><label>Keşideci / Veren<input name="drawer" value="<?php echo e($edit['drawer'] ?? ''); ?>"></label></div>
      <label>Açıklama<textarea name="description" rows="3"><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Çek görseli / belge <small>JPG, PNG, WEBP, HEIC veya PDF; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut belge: <a href="cek-belge-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Belge'); ?></a></p><?php endif; ?>
      <?php if ($edit): ?><p class="muted">Durum: <?php echo e(check_status_label($edit['status'] ?? 'bekliyor')); ?>. Durum değiştirme işlemleri listeden yapılır.</p><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Çek ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="cekler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Çek ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Çek listesi</h3><a href="export.php?type=checks&<?php echo e(http_build_query($_GET)); ?>">Excel CSV indir</a></div>
    <form class="filterbar multi ultra" method="get">
      <input name="q" placeholder="Banka, çek no, cari, ciro edilen cari ara" value="<?php echo e($q); ?>">
      <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      <select name="direction"><option value="">Tüm çek tipleri</option><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $direction===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <select name="status"><option value="">Tüm son işlemler</option><?php foreach(check_statuses() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $status===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <select name="account_id"><option value="">Tüm hesaplar</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo $accountId!=='' && (int)$accountId===(int)$a['id']?'selected':''; ?>><?php echo e($a['name']); ?></option><?php endforeach; ?></select>
      <input type="date" name="start" value="<?php echo e($start); ?>">
      <input type="date" name="end" value="<?php echo e($end); ?>">
      <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptal edilenleri göster</label>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vade</th><th>Tip</th><th>Son işlem</th><th>Cari</th><th>Banka / Çek No</th><th>Hesap / Ciro</th><th class="right">Tutar</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php if(!$checks): ?><tr><td colspan="8" class="empty">Çek bulunamadı.</td></tr><?php endif; ?>
        <?php foreach($checks as $ch):
            $cancelled = (int)($ch['is_cancelled'] ?? 0) === 1;
            $isBad = in_array($ch['status'], ['karsiliksiz','protestolu'], true);
            $isCiro = $ch['status'] === 'ciro_edildi';
            $isOverdue = !$cancelled && $ch['status'] === 'bekliyor' && $ch['due_date'] < $today;
            $isSoon = !$cancelled && $ch['status'] === 'bekliyor' && $ch['due_date'] >= $today && $ch['due_date'] <= $weekAhead;
            $rowClass = $cancelled ? 'row-cancelled' : ($isBad ? 'row-bad' : ($isCiro ? 'row-ciro' : ($isOverdue ? 'row-overdue' : ($isSoon ? 'row-soon' : ''))));
            $quickAction = '';
            if (can_write() && !$cancelled && $ch['status'] === 'bekliyor') {
                if ($ch['due_date'] <= $today) {
                    $newSt = $ch['direction'] === 'alinacak' ? 'tahsil_edildi' : 'odendi';
                    $newStLabel = $ch['direction'] === 'alinacak' ? 'Tahsil edildi' : 'Ödendi';
                    $quickAction .= '<form method="post" class="quick-status-form">' . csrf_field() . '<input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="' . e($ch['id']) . '"><input type="hidden" name="status" value="' . e($newSt) . '"><select name="account_id"><option value="">Hesap seç</option>';
                    foreach($accounts as $a) { $sel = ((string)($ch['account_id'] ?? '') === (string)$a['id']) ? ' selected' : ''; $quickAction .= '<option value="' . e($a['id']) . '"' . $sel . '>' . e($a['name']) . '</option>'; }
                    $quickAction .= '</select><button class="btn-quick-ok" title="' . e($newStLabel) . ' olarak işaretle">' . e($newStLabel) . '</button></form>';
                    $quickAction .= '<form method="post" class="quick-status-form" onsubmit="return confirm(\'Bu çek karşılıksız/ödenmedi olarak işaretlensin mi? Cari bakiye tekrar açılacak.\');">' . csrf_field() . '<input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="' . e($ch['id']) . '"><input type="hidden" name="status" value="karsiliksiz"><button class="btn-quick-danger" title="Karşılıksız işaretle">Karşılıksız</button></form>';
                } else {
                    $quickAction .= '<span class="status-muted">Vade gelince tahsil/ödeme işlemi açılır.</span>';
                }
                if ($ch['direction'] === 'alinacak') {
                    $quickAction .= '<form method="post" class="quick-status-form" onsubmit="return confirm(\'Bu alınan çek seçili cariye ciro edilsin mi?\');">' . csrf_field() . '<input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="' . e($ch['id']) . '"><input type="hidden" name="status" value="ciro_edildi"><select name="ciro_cari_id" required><option value="">Kime ciro?</option>';
                    foreach($cariler as $c) { $quickAction .= '<option value="' . e($c['id']) . '">' . e($c['name']) . '</option>'; }
                    $quickAction .= '</select><input name="ciro_note" placeholder="Not" maxlength="120"><button class="btn-quick-warn" title="Ciro edildi olarak işaretle">Ciro et</button></form>';
                }
            } elseif (can_write() && !$cancelled && $ch['status'] === 'ciro_edildi') {
                $quickAction .= '<form method="post" class="quick-status-form" onsubmit="return confirm(\'Ciro edilen çek karşılıksız/geri döndü olarak işaretlensin mi? İlgili cariler tekrar açılacak.\');">' . csrf_field() . '<input type="hidden" name="action" value="quick_status"><input type="hidden" name="id" value="' . e($ch['id']) . '"><input type="hidden" name="status" value="karsiliksiz"><button class="btn-quick-danger">Karşılıksız / geri döndü</button></form>';
            }
            $statusCell = $cancelled ? badge('İptal','neutral') : ($ch['status'] === 'bekliyor' ? '<span class="status-muted">Portföyde · işlem yok</span>' : badge(check_status_label($ch['status']), check_status_tone($ch['status'])));
            if ($isCiro && !empty($ch['ciro_cari_name'])) $statusCell .= '<small class="ciro-note">Ciro: ' . e($ch['ciro_cari_name']) . '</small>';
            if ($cancelled && !empty($ch['cancel_reason'])) $statusCell .= '<small>'.e($ch['cancel_reason']).'</small>';
        ?>
        <tr class="<?php echo $rowClass; ?>">
          <td>
            <?php echo e(tr_date($ch['due_date'])); ?>
            <?php if ($isOverdue): ?><small class="overdue-tag">Vade geçti</small><?php elseif ($isSoon): ?><small class="soon-tag">Yaklaşan</small><?php endif; ?>
            <?php echo $ch['issue_date'] ? '<small>Çek tarihi: ' . e(tr_date($ch['issue_date'])) . '</small>' : ''; ?>
          </td>
          <td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td>
          <td><?php echo $statusCell; ?></td>
          <td><?php echo $ch['cari_id'] ? '<a href="cari-detay.php?id='.e($ch['cari_id']).'">'.e($ch['cari_name']).'</a>' : '-'; ?><small><?php echo $ch['direction']==='alinacak' ? 'Kimden alındı' : 'Kime verildi'; ?></small></td>
          <td><?php echo e($ch['bank_name'] ?: '-'); ?><small><?php echo e(trim(($ch['branch_name'] ?: '') . ' ' . ($ch['check_no'] ?: ''))); ?></small><?php echo $ch['drawer'] ? '<small>' . e($ch['drawer']) . '</small>' : ''; ?></td>
          <td><?php echo e($ch['account_name'] ?: '-'); ?><?php echo $ch['ciro_cari_name'] ? '<small>Ciro edilen: <a href="cari-detay.php?id='.e($ch['ciro_cari_id']).'">'.e($ch['ciro_cari_name']).'</a></small>' : ''; ?><small><?php echo $ch['document_path'] ? '<a href="cek-belge-indir.php?id='.e($ch['id']).'" target="_blank">Belge</a>' : ''; ?></small></td>
          <td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td>
          <td class="row-actions">
            <?php echo $quickAction; ?>
            <?php if(!$cancelled): ?>
              <a href="cekler.php?edit=<?php echo e($ch['id']); ?>">Düzenle</a>
              <?php if(can_write()): ?>
              <form method="post" onsubmit="return confirm('Çek silinmeyecek, iptal edildi olarak işaretlenecek. Devam edilsin mi?');">
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($ch['id']); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal"><button>İptal</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">Kayıt korundu</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Ciro edilen çekler</h3><span>Ayrı takip listesi</span></div>
  <p class="cek-help">Bu listede alınan çeklerden başka cariye ciro edilenler tutulur. Ciro edilen cari tarafında ödeme gibi çalışır; çek karşılıksız dönerse o ödeme tersine alınır ve borç tekrar açılır.</p>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Ciro tarihi</th><th>Vade</th><th>Kimden alındı</th><th>Kime ciro edildi</th><th>Banka / Çek No</th><th class="right">Tutar</th><th>Durum</th></tr></thead>
      <tbody>
      <?php if(!$ciroChecks): ?><tr><td colspan="7" class="empty">Ciro edilen çek yok.</td></tr><?php endif; ?>
      <?php foreach($ciroChecks as $ch): ?>
        <tr class="row-ciro">
          <td><?php echo e(tr_date($ch['ciro_date'] ?: $ch['closed_at'])); ?></td>
          <td><?php echo e(tr_date($ch['due_date'])); ?></td>
          <td><?php echo $ch['cari_id'] ? '<a href="cari-detay.php?id='.e($ch['cari_id']).'">'.e($ch['cari_name']).'</a>' : '-'; ?></td>
          <td><?php echo $ch['ciro_cari_id'] ? '<a href="cari-detay.php?id='.e($ch['ciro_cari_id']).'">'.e($ch['ciro_cari_name']).'</a>' : '-'; ?></td>
          <td><?php echo e($ch['bank_name'] ?: '-'); ?><small><?php echo e(trim(($ch['branch_name'] ?: '') . ' ' . ($ch['check_no'] ?: ''))); ?></small></td>
          <td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td>
          <td><?php echo badge(check_status_label($ch['status']), check_status_tone($ch['status'])); ?><?php echo $ch['ciro_note'] ? '<small>'.e($ch['ciro_note']).'</small>' : ''; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
