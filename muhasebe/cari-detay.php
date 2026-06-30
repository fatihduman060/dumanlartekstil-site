<?php
require_once __DIR__ . '/layout.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM cariler WHERE id=?');
$stmt->execute([$id]);
$cari = $stmt->fetch();
if (!$cari) {
    flash('error', 'Cari bulunamadı.');
    redirect('cariler.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_private_receivable') {
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['receivable_date'] ?: date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'acik';
        if (!isset(private_receivable_statuses()[$status])) $status = 'acik';
        if ($amount <= 0) {
            flash('error', 'Özel alacak için tutar girilmeli.');
            redirect('cari-detay.php?id=' . $id);
        }
        try { $doc = handle_upload('private_document'); }
        catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cari-detay.php?id=' . $id); }
        $payload = [$id, $status, $amount, $date, $description, $_POST['private_document_type'] ?: null, $doc['path'], $doc['name'], $doc['mime'], current_user()['id'] ?? null, now(), now()];
        db()->prepare('INSERT INTO private_receivables (cari_id, status, amount, receivable_date, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($payload);
        $newPrivateId = (int)db()->lastInsertId();
        log_action('Özel alacak eklendi', $cari['name'] . ' - ' . money($amount));
        audit_action('ozel_alacak', $newPrivateId, 'eklendi', null, ['cari_id'=>$id,'status'=>$status,'amount'=>$amount,'date'=>$date,'description'=>$description], $cari['name']);
        flash('success', 'Özel alacak eklendi. Bu kayıt genel cari bakiyeyi etkilemez.');
        redirect('cari-detay.php?id=' . $id);
    }

    if ($action === 'update_private_receivable_status') {
        $privateId = (int)($_POST['private_receivable_id'] ?? 0);
        $status = $_POST['status'] ?? 'acik';
        if (!isset(private_receivable_statuses()[$status])) {
            flash('error', 'Özel alacak durumu geçersiz.');
            redirect('cari-detay.php?id=' . $id);
        }
        $stmt = db()->prepare('SELECT * FROM private_receivables WHERE id=? AND cari_id=?');
        $stmt->execute([$privateId, $id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash('error', 'Özel alacak kaydı bulunamadı.');
            redirect('cari-detay.php?id=' . $id);
        }
        db()->prepare('UPDATE private_receivables SET status=?, updated_at=? WHERE id=? AND cari_id=?')->execute([$status, now(), $privateId, $id]);
        log_action('Özel alacak durumu güncellendi', $cari['name'] . ' - ' . private_receivable_status_label($status) . ' - ' . money($row['amount']));
        audit_action('ozel_alacak', $privateId, 'durum_guncellendi', $row, ['status'=>$status], $cari['name']);
        flash('success', 'Özel alacak durumu güncellendi. Genel cari bakiye etkilenmedi.');
        redirect('cari-detay.php?id=' . $id);
    }

    if ($action === 'quick_movement') {
        $type = $_POST['movement_type'] ?? '';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['movement_date'] ?: date('Y-m-d');
        if (!isset(movement_entry_types()[$type]) || $amount <= 0) {
            flash('error', 'Hızlı hareket için tip ve tutar kontrol edilmeli.');
            redirect('cari-detay.php?id=' . $id);
        }
        if (is_private_receivable_movement($type)) {
            try { $doc = handle_upload('document'); }
            catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cari-detay.php?id=' . $id); }
            $payload = [$id, 'acik', $amount, $date, trim($_POST['description'] ?? ''), $_POST['document_type'] ?: null, $doc['path'], $doc['name'], $doc['mime'], current_user()['id'] ?? null, now(), now()];
            db()->prepare('INSERT INTO private_receivables (cari_id, status, amount, receivable_date, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($payload);
            $newPrivateId = (int)db()->lastInsertId();
            log_action('Hızlı özel alacak eklendi', $cari['name'] . ' - ' . money($amount));
            audit_action('ozel_alacak', $newPrivateId, 'eklendi', null, ['cari_id'=>$id,'status'=>'acik','amount'=>$amount,'date'=>$date,'description'=>trim($_POST['description'] ?? '')], $cari['name']);
            flash('success', 'Özel alacak eklendi. Genel cari bakiye etkilenmedi.');
            redirect('cari-detay.php?id=' . $id);
        }
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $docTypeInput = $_POST['document_type'] ?: null;
        $paymentMethodInput = trim($_POST['payment_method'] ?? '');
        $dueDateInput = $_POST['due_date'] ?: null;
        $checkLikeInput = ['movement_type'=>$type, 'due_date'=>$dueDateInput, 'payment_method'=>$paymentMethodInput, 'document_type'=>$docTypeInput];
        if (!movement_cash_direction($type) || movement_is_check_like($checkLikeInput)) $accountId = null;
        try { $doc = handle_upload('document'); }
        catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cari-detay.php?id=' . $id); }
        $stmt = db()->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $accountId, $type, $amount, $date, $dueDateInput, $paymentMethodInput, trim($_POST['description'] ?? ''), $docTypeInput, $doc['path'], $doc['name'], $doc['mime'], current_user()['id'], now(), now()]);
        $newId = (int)db()->lastInsertId();
        sync_movement_account_transaction($newId);
        sync_movement_to_check($newId);
        log_action('Hızlı cari hareketi eklendi', $cari['name'] . ' - ' . movement_label($type) . ' ' . money($amount));
        audit_action('hareket', $newId, 'eklendi', null, ['cari_id'=>$id,'type'=>$type,'amount'=>$amount,'date'=>$date,'account_id'=>$accountId], $cari['name']);
        flash('success', 'Hızlı hareket eklendi.');
        redirect('cari-detay.php?id=' . $id);
    }
}

$balance = cari_balance($id);
$openPeriod = cari_open_period_balance($id);
$privateSummary = private_receivable_summary($id);
$stmt = db()->prepare('SELECT pr.*, u.display_name AS user_name FROM private_receivables pr LEFT JOIN users u ON u.id=pr.created_by WHERE pr.cari_id=? ORDER BY pr.receivable_date DESC, pr.id DESC');
$stmt->execute([$id]);
$privateReceivables = $stmt->fetchAll();
$accounts = accounts_for_select(true);
$mType = trim($_GET['movement_type'] ?? '');
$mStart = trim($_GET['start'] ?? '');
$mEnd = trim($_GET['end'] ?? '');
$mQ = trim($_GET['q'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']);
$where = ['m.cari_id=?'];
$params = [$id];
if (!$includeCancelled) $where[] = 'COALESCE(m.is_cancelled,0)=0';
if ($mType !== '') { $where[] = 'm.movement_type=?'; $params[] = $mType; }
if ($mStart !== '') { $where[] = 'm.movement_date>=?'; $params[] = $mStart; }
if ($mEnd !== '') { $where[] = 'm.movement_date<=?'; $params[] = $mEnd; }
if ($mQ !== '') { $where[] = '(m.description LIKE ? OR cat.name LIKE ? OR a.name LIKE ? OR m.document_name LIKE ? OR ch.check_no LIKE ? OR ch.bank_name LIKE ?)'; array_push($params, "%$mQ%", "%$mQ%", "%$mQ%", "%$mQ%", "%$mQ%", "%$mQ%"); }
$stmt = db()->prepare("SELECT m.*, cat.name AS category_name, u.display_name AS user_name, a.name AS account_name, ch.id AS linked_check_id, ch.check_no AS linked_check_no, ch.bank_name AS linked_check_bank FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN users u ON u.id=m.created_by LEFT JOIN accounts a ON a.id=m.account_id LEFT JOIN checks ch ON ch.id=m.check_id WHERE " . implode(' AND ', $where) . " ORDER BY m.movement_date DESC, m.id DESC");
$stmt->execute($params);
$movements = $stmt->fetchAll();

$chWhere = ['cari_id=?', 'COALESCE(is_cancelled,0)=0'];
$chParams = [$id];
$stmt = db()->prepare("SELECT * FROM checks WHERE " . implode(' AND ', $chWhere) . " ORDER BY due_date ASC, id DESC");
$stmt->execute($chParams);
$checks = $stmt->fetchAll();
$checkTotals = ['alinacak'=>0, 'verilecek'=>0];
foreach ($checks as $ch) $checkTotals[$ch['direction']] += (float)$ch['amount'];
$pendingCheckTotal = $checkTotals['alinacak'] + $checkTotals['verilecek'];

$lastTahsilatStmt = db()->prepare("SELECT amount, movement_date, description FROM movements WHERE cari_id=? AND movement_type='tahsilat' AND COALESCE(is_cancelled,0)=0 ORDER BY movement_date DESC, id DESC LIMIT 1");
$lastTahsilatStmt->execute([$id]);
$lastTahsilat = $lastTahsilatStmt->fetch() ?: null;
$lastMovementStmt = db()->prepare("SELECT movement_type, amount, movement_date, description FROM movements WHERE cari_id=? AND COALESCE(is_cancelled,0)=0 ORDER BY movement_date DESC, id DESC LIMIT 1");
$lastMovementStmt->execute([$id]);
$lastMovement = $lastMovementStmt->fetch() ?: null;
$daysSinceTahsilat = $lastTahsilat ? (int)floor((time() - strtotime($lastTahsilat['movement_date'])) / 86400) : null;
$cariAlertText = 'Cari dengesi normal görünüyor.';
$cariAlertTone = 'success';
if ($balance['net_alacak'] > 0 && ($daysSinceTahsilat === null || $daysSinceTahsilat >= 30)) {
    $cariAlertText = $daysSinceTahsilat === null ? 'Bu caride açık alacak var ama tahsilat kaydı görünmüyor.' : $daysSinceTahsilat . ' gündür tahsilat görünmüyor.';
    $cariAlertTone = 'danger';
} elseif ($pendingCheckTotal > 0) {
    $cariAlertText = 'Çek hareketi var; vade takibini kontrol et.';
    $cariAlertTone = 'warning';
}

page_header($cari['name'], 'cariler');
?>
<section class="hero-card detail-hero">
  <div>
    <span class="status-pill"><?php echo e($cari['cari_type']); ?></span>
    <h2><?php echo e($cari['name']); ?></h2>
    <p><?php echo e($cari['address'] ?: $cari['notes'] ?: 'Cari detayları, hareket geçmişi, belge arşivi ve çek takibi.'); ?></p>
  </div>
  <div class="hero-actions">
    <?php if (can_write()): ?><a class="btn btn-primary" href="hareketler.php?cari_id=<?php echo e($id); ?>">Hareket ekle</a><a class="btn btn-secondary" href="cekler.php?cari_id=<?php echo e($id); ?>">Çek ekle</a><a class="btn btn-secondary" href="cariler.php?edit=<?php echo e($id); ?>">Cariyi düzenle</a><?php endif; ?>
    <a class="btn btn-secondary" href="cari-ekstre.php?id=<?php echo e($id); ?>" target="_blank">Ekstre/PDF</a>
  </div>
</section>

<nav class="detail-tabs print-hide" aria-label="Cari detay bölümleri">
  <a href="#ozet">Özet</a>
  <a href="#ozel-alacak">Özel Alacak</a>
  <a href="#hareketler">Hareketler</a>
  <a href="#cekler">Çekler</a>
  <a href="#bilgiler">Cari Bilgileri</a>
</nav>

<section id="ozet" class="detail-section">
  <div class="dashboard-section-head detail-summary-head">
    <div><span>Cari Röntgeni</span><h3>Bu carinin hızlı özeti</h3></div>
    <p class="<?php echo $cariAlertTone === 'danger' ? 'text-danger' : ($cariAlertTone === 'warning' ? 'text-warning' : 'text-success'); ?>"><?php echo e($cariAlertText); ?></p>
  </div>
  <div class="stats-grid five cari-rontgen-grid">
    <article class="stat-card status"><span>Güncel cari bakiye</span><strong class="<?php echo $balance['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($balance['net'])); ?></strong><small><?php echo $balance['net'] >= 0 ? 'Alacaklı durum' : 'Borçlu durum'; ?></small></article>
    <article class="stat-card"><span>Kalan açık alacak</span><strong class="<?php echo $openPeriod['net_alacak'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($openPeriod['net_alacak'])); ?></strong><small>Açık dönem alacağı - tahsilatı</small></article>
    <article class="stat-card"><span>Kalan açık verecek</span><strong class="<?php echo $openPeriod['net_verecek'] <= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($openPeriod['net_verecek'])); ?></strong><small>Açık dönem vereceği - ödemesi</small></article>
    <article class="stat-card special"><span>Özel alacak</span><strong><?php echo e(money($privateSummary['acik'])); ?></strong><small>Genel bakiyeye dahil değil</small></article>
    <article class="stat-card soft"><span>Bekleyen çek</span><strong><?php echo e(money($pendingCheckTotal)); ?></strong><small>Alınacak: <?php echo e(money($checkTotals['alinacak'])); ?> / Verilecek: <?php echo e(money($checkTotals['verilecek'])); ?></small></article>
  </div>

  <div class="dashboard-section-head detail-summary-head thin">
    <div><span>Açık Dönem</span><h3>Son sıfırlamadan sonraki yeni hareketler</h3></div>
    <p>Ana ekranda güncel dönemi gösterir; genel ciro altta ayrıca durur.</p>
  </div>
  <div class="stats-grid five cari-rontgen-grid open-period-grid">
    <article class="stat-card cash"><span>Açık dönem alacağı</span><strong><?php echo e(money($openPeriod['alacak'])); ?></strong><small><?php echo $openPeriod['alacak_has_close'] ? 'Son kapanış: ' . e(tr_date($openPeriod['alacak_close_date'])) : 'Kapanış yok; tüm alacak dönemi'; ?></small></article>
    <article class="stat-card cash"><span>Açık dönem tahsilatı</span><strong><?php echo e(money($openPeriod['tahsilat'])); ?></strong><small>Bu açık dönemde alınan para</small></article>
    <article class="stat-card soft"><span>Açık dönem vereceği</span><strong><?php echo e(money($openPeriod['verecek'])); ?></strong><small><?php echo $openPeriod['verecek_has_close'] ? 'Son kapanış: ' . e(tr_date($openPeriod['verecek_close_date'])) : 'Kapanış yok; tüm verecek dönemi'; ?></small></article>
    <article class="stat-card soft"><span>Açık dönem ödemesi</span><strong><?php echo e(money($openPeriod['odeme'])); ?></strong><small>Bu açık dönemde yapılan ödeme</small></article>
    <article class="stat-card soft"><span>Son tahsilat</span><strong><?php echo $lastTahsilat ? e(money($lastTahsilat['amount'])) : '-'; ?></strong><small><?php echo $lastTahsilat ? e(tr_date($lastTahsilat['movement_date'])) : 'Tahsilat kaydı yok'; ?></small></article>
  </div>

  <div class="dashboard-section-head detail-summary-head thin">
    <div><span>Genel Ciro</span><h3>Bugüne kadar toplam işlem hacmi</h3></div>
    <p>Bu alan sıfırlanmaz; geçmiş ticaret toplamını gösterir.</p>
  </div>
  <div class="stats-grid five cari-rontgen-grid compact-rontgen">
    <article class="stat-card soft"><span>Son hareket tarihi</span><strong><?php echo $lastMovement ? e(tr_date($lastMovement['movement_date'])) : '-'; ?></strong><small><?php echo $lastMovement ? e(movement_label($lastMovement['movement_type']) . ' · ' . money($lastMovement['amount'])) : 'Hareket yok'; ?></small></article>
    <article class="stat-card"><span>Toplam alacak işlemi</span><strong><?php echo e(money($balance['alacak'])); ?></strong><small>Genel brüt / ciro</small></article>
    <article class="stat-card"><span>Toplam tahsilat</span><strong><?php echo e(money($balance['tahsilat'])); ?></strong><small>Bugüne kadar alınan</small></article>
    <article class="stat-card"><span>Toplam verecek işlemi</span><strong><?php echo e(money($balance['verecek'])); ?></strong><small>Genel brüt verecek</small></article>
    <article class="stat-card"><span>Toplam ödeme</span><strong><?php echo e(money($balance['odeme'])); ?></strong><small>Bugüne kadar yapılan</small></article>
  </div>
</section>

<?php if (can_write()): ?>
<section class="panel-card">
  <div class="card-head"><h3>Hızlı tahsilat / ödeme</h3><span>Bu cariye direkt hareket ekle</span></div>
  <form method="post" enctype="multipart/form-data" class="filterbar multi ultra">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="quick_movement">
    <select name="movement_type" required><?php foreach (movement_entry_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $key==='tahsilat' ? 'selected' : ''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
    <input name="amount" type="text" inputmode="decimal" placeholder="Tutar" required>
    <input name="movement_date" type="date" value="<?php echo e(date('Y-m-d')); ?>" required>
    <input name="due_date" type="date" title="Vade tarihi">
    <select name="account_id"><option value="">Kasa/banka yok</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['name']); ?></option><?php endforeach; ?></select>
    <select name="document_type"><option value="">Belge türü</option><?php foreach(document_types() as $key=>$label): ?><option value="<?php echo e($key); ?>"><?php echo e($label); ?></option><?php endforeach; ?></select>
    <input name="payment_method" placeholder="Nakit / EFT / kart">
    <input name="description" placeholder="Açıklama">
    <input name="document" type="file" accept="image/*,application/pdf">
    <button class="btn btn-primary" type="submit">Ekle</button>
  </form>
</section>
<?php endif; ?>

<section id="ozel-alacak" class="panel-card special-receivable-card detail-section">
  <div class="card-head"><h3>Özel Alacak</h3><span>Bu alan genel cari bakiyeyi, kasa/bankayı ve dashboard toplamlarını etkilemez.</span></div>
  <section class="stats-grid three flush">
    <article class="stat-card soft"><span>Açık özel alacak</span><strong><?php echo e(money($privateSummary['acik'])); ?></strong><small>Takip edilecek özel tutar</small></article>
    <article class="stat-card soft"><span>Kapanan özel alacak</span><strong><?php echo e(money($privateSummary['kapandi'])); ?></strong><small>Normal tahsilata karışmaz</small></article>
    <article class="stat-card soft"><span>Kayıt sayısı</span><strong><?php echo e((string)$privateSummary['count']); ?></strong><small>Özel takip kayıtları</small></article>
  </section>
  <?php if (can_write()): ?>
  <form method="post" enctype="multipart/form-data" class="filterbar multi">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add_private_receivable">
    <input name="amount" type="text" inputmode="decimal" placeholder="Özel alacak tutarı" required>
    <input name="receivable_date" type="date" value="<?php echo e(date('Y-m-d')); ?>" required>
    <select name="status"><?php foreach(private_receivable_statuses() as $key=>$meta): ?><option value="<?php echo e($key); ?>"><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
    <select name="private_document_type"><option value="">Belge türü</option><?php foreach(document_types() as $key=>$label): ?><option value="<?php echo e($key); ?>"><?php echo e($label); ?></option><?php endforeach; ?></select>
    <input name="description" placeholder="Açıklama / not">
    <input name="private_document" type="file" accept="image/*,application/pdf">
    <button class="btn btn-primary" type="submit">Özel alacak ekle</button>
  </form>
  <?php endif; ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Durum</th><th>Açıklama</th><th>Belge</th><th>Ekleyen</th><th class="right">Tutar</th><th></th></tr></thead>
      <tbody>
        <?php if (!$privateReceivables): ?><tr><td colspan="7" class="empty">Bu cariye ait özel alacak kaydı yok.</td></tr><?php endif; ?>
        <?php foreach ($privateReceivables as $pr): ?>
          <tr>
            <td><?php echo e(tr_date($pr['receivable_date'])); ?></td>
            <td><?php echo badge(private_receivable_status_label($pr['status']), private_receivable_status_tone($pr['status'])); ?></td>
            <td><?php echo e($pr['description'] ?: '-'); ?><small><?php echo e(tr_datetime($pr['created_at'])); ?></small></td>
            <td><?php echo !empty($pr['document_path']) ? '<a href="ozel-belge-indir.php?id=' . e($pr['id']) . '" target="_blank">' . e(document_type_label($pr['document_type'])) . '</a>' : '-'; ?></td>
            <td><?php echo e($pr['user_name'] ?: '-'); ?></td>
            <td class="right"><strong><?php echo e(money($pr['amount'])); ?></strong></td>
            <td class="row-actions">
              <?php if (can_write()): ?>
                <?php if ($pr['status'] !== 'kapandi'): ?><form method="post" onsubmit="return confirm('Bu özel alacak kapandı olarak işaretlensin mi? Genel cari bakiye etkilenmez.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_private_receivable_status"><input type="hidden" name="private_receivable_id" value="<?php echo e($pr['id']); ?>"><input type="hidden" name="status" value="kapandi"><button type="submit">Kapat</button></form><?php endif; ?>
                <?php if ($pr['status'] !== 'acik'): ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_private_receivable_status"><input type="hidden" name="private_receivable_id" value="<?php echo e($pr['id']); ?>"><input type="hidden" name="status" value="acik"><button type="submit">Aç</button></form><?php endif; ?>
                <?php if ($pr['status'] !== 'iptal'): ?><form method="post" onsubmit="return confirm('Bu özel alacak iptal edilsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update_private_receivable_status"><input type="hidden" name="private_receivable_id" value="<?php echo e($pr['id']); ?>"><input type="hidden" name="status" value="iptal"><button type="submit">İptal</button></form><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section id="bilgiler" class="content-grid compact detail-section">
  <article class="panel-card">
    <div class="card-head"><h3>Cari bilgileri</h3><span>Detay</span></div>
    <div class="info-list">
      <p><strong>Yetkili:</strong> <?php echo e($cari['authorized_person'] ?: '-'); ?></p>
      <p><strong>Telefon:</strong> <?php echo e($cari['phone'] ?: '-'); ?></p>
      <p><strong>E-posta:</strong> <?php echo e($cari['email'] ?: '-'); ?></p>
      <p><strong>Vergi:</strong> <?php echo e(trim(($cari['tax_office'] ?: '') . ' ' . ($cari['tax_no'] ?: '')) ?: '-'); ?></p>
      <p><strong>Şehir:</strong> <?php echo e($cari['city'] ?: '-'); ?></p>
      <p><strong>IBAN:</strong> <?php echo e($cari['iban'] ?: '-'); ?></p>
      <p><strong>Not:</strong> <?php echo e($cari['notes'] ?: '-'); ?></p>
    </div>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Çek özeti</h3><a href="cekler.php?cari_id=<?php echo e($id); ?>">Çekleri gör</a></div>
    <section class="stats-grid two flush">
      <article class="stat-card soft"><span>Alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small>Toplam</small></article>
      <article class="stat-card soft"><span>Verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small>Toplam</small></article>
    </section>
  </article>
</section>

<section id="hareketler" class="panel-card detail-section">
  <div class="card-head"><h3>Hareket geçmişi</h3><a href="export.php?type=movements&cari_id=<?php echo e($id); ?>&start=<?php echo e($mStart); ?>&end=<?php echo e($mEnd); ?>&movement_type=<?php echo e($mType); ?>">Excel CSV indir</a></div>
  <form class="filterbar multi" method="get">
    <input type="hidden" name="id" value="<?php echo e($id); ?>">
    <input name="q" placeholder="Hareket/belge ara" value="<?php echo e($mQ); ?>">
    <select name="movement_type"><option value="">Tüm tipler</option><?php foreach(movement_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $mType===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
    <input type="date" name="start" value="<?php echo e($mStart); ?>"><input type="date" name="end" value="<?php echo e($mEnd); ?>">
    <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptaller</label>
    <button class="btn btn-secondary" type="submit">Filtrele</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Vade</th><th>Tip</th><th>Kategori/Hesap</th><th>Açıklama</th><th>Belge</th><th class="right">Tutar</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php if (!$movements): ?><tr><td colspan="8" class="empty">Bu cariye ait hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($movements as $m): $cancelled=(int)($m['is_cancelled'] ?? 0)===1; ?>
          <tr class="<?php echo $cancelled?'row-cancelled':''; ?>">
            <td><?php echo e(tr_date($m['movement_date'])); ?></td>
            <td><?php echo e(tr_date($m['due_date'])); ?></td>
            <td><?php echo $cancelled ? badge('İptal','neutral') : badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
            <td><?php echo e($m['category_name'] ?: '-'); ?><small><?php echo e($m['account_name'] ?: ''); ?></small></td>
            <td><?php echo e($m['description'] ?: '-'); ?><small><?php echo e($m['payment_method'] ?: ''); ?><?php echo !empty($m['linked_check_id']) ? ' · Çek #' . e($m['linked_check_id']) : ''; ?></small></td>
            <td><?php echo $m['document_path'] ? '<a href="belge-indir.php?id=' . e($m['id']) . '" target="_blank">'.e(document_type_label($m['document_type'])).'</a>' : '-'; ?></td>
            <td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td>
            <td class="row-actions">
              <?php if(!$cancelled): ?><a href="hareketler.php?edit=<?php echo e($m['id']); ?>&cari_id=<?php echo e($id); ?>">İncele / Düzelt</a><?php else: ?><a href="hareketler.php?include_cancelled=1&q=<?php echo e($m['id']); ?>">İncele</a><?php endif; ?>
              <?php if(!empty($m['linked_check_id'])): ?><a href="cekler.php?direction=alinacak&edit=<?php echo e($m['linked_check_id']); ?>#cek-form">Çek kaydı</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section id="cekler" class="panel-card detail-section">
  <div class="card-head"><h3>Çek geçmişi</h3><a href="export.php?type=checks&cari_id=<?php echo e($id); ?>">Excel CSV indir</a></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Vade</th><th>Yön</th><th>Banka</th><th>Çek No</th><th>Açıklama</th><th class="right">Tutar</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php if (!$checks): ?><tr><td colspan="7" class="empty">Bu cariye ait çek yok.</td></tr><?php endif; ?>
        <?php foreach ($checks as $ch): ?>
          <tr>
            <td><?php echo e(tr_date($ch['due_date'])); ?></td>
            <td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td>
            <td><?php echo e($ch['bank_name'] ?: '-'); ?><small><?php echo e($ch['branch_name'] ?: ''); ?></small></td>
            <td><?php echo e($ch['check_no'] ?: '-'); ?></td>
            <td><?php echo e($ch['description'] ?: '-'); ?></td>
            <td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td>
            <td class="row-actions"><a href="cekler.php?direction=<?php echo e($ch['direction']); ?>&edit=<?php echo e($ch['id']); ?>#cek-form">İncele / Düzelt</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
