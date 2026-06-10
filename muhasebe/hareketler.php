<?php
require_once __DIR__ . '/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $type = $_POST['movement_type'] ?? '';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['movement_date'] ?: date('Y-m-d');
        if (!isset(movement_entry_types()[$type]) || $amount <= 0) {
            flash('error', 'Hareket tipi ve tutar kontrol edilmeli.');
            redirect('hareketler.php');
        }
        if (is_private_receivable_movement($type)) {
            if ($id > 0) {
                flash('error', 'Özel alacak normal hareket düzenleme içinden dönüştürülemez. Yeni kayıt olarak ekleyin.');
                redirect('hareketler.php');
            }
            $cariIdForPrivate = ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : 0;
            if ($cariIdForPrivate <= 0) {
                flash('error', 'Özel alacak için mutlaka cari seçilmeli.');
                redirect('hareketler.php');
            }
            try {
                $doc = handle_upload('document');
            } catch (Throwable $e) {
                flash('error', $e->getMessage());
                redirect('hareketler.php');
            }
            $stmt = db()->prepare('INSERT INTO private_receivables (cari_id, status, amount, receivable_date, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $privatePayload = [
                $cariIdForPrivate,
                'acik',
                $amount,
                $date,
                trim($_POST['description'] ?? ''),
                $_POST['document_type'] ?: null,
                $doc['path'], $doc['name'], $doc['mime'],
                current_user()['id'] ?? null,
                now(), now()
            ];
            $stmt->execute($privatePayload);
            $newPrivateId = (int)db()->lastInsertId();
            log_action('Özel alacak eklendi', '#' . $cariIdForPrivate . ' - ' . money($amount)); audit_action('ozel_alacak', $newPrivateId, 'eklendi', null, ['cari_id'=>$cariIdForPrivate,'amount'=>$amount,'date'=>$date,'description'=>trim($_POST['description'] ?? '')], '#' . $cariIdForPrivate);
            flash('success', 'Özel alacak kaydedildi. Genel cari bakiyeyi, kasa/bankayı ve dashboard toplamlarını etkilemez.');
            redirect('cari-detay.php?id=' . $cariIdForPrivate);
        }
        $oldDoc = null;
        $oldMovement = null;
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
            $stmt->execute([$id]);
            $oldMovement = $stmt->fetch() ?: null;
            $oldDoc = $oldMovement ? ['path'=>$oldMovement['document_path'] ?? null, 'name'=>$oldMovement['document_name'] ?? null, 'mime'=>$oldMovement['document_mime'] ?? null] : null;
        }
        try {
            $doc = handle_upload('document', $oldDoc);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('hareketler.php');
        }
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $docTypeInput = $_POST['document_type'] ?: null;
        $paymentMethodInput = trim($_POST['payment_method'] ?? '');
        $dueDateInput = $_POST['due_date'] ?: null;
        $checkLikeInput = ['movement_type' => $type, 'due_date' => $dueDateInput, 'payment_method' => $paymentMethodInput, 'document_type' => $docTypeInput];
        if (!movement_cash_direction($type) || movement_is_check_like($checkLikeInput)) $accountId = null;
        $payload = [
            ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
            ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
            $accountId,
            $type,
            $amount,
            $date,
            $dueDateInput,
            $paymentMethodInput,
            trim($_POST['description'] ?? ''),
            $docTypeInput,
            $doc['path'], $doc['name'], $doc['mime']
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE movements SET cari_id=?, category_id=?, account_id=?, movement_type=?, amount=?, movement_date=?, due_date=?, payment_method=?, description=?, document_type=?, document_path=?, document_name=?, document_mime=?, updated_at=? WHERE id=?');
            $stmt->execute(array_merge($payload, [now(), $id]));
            delete_replaced_upload($oldDoc, $doc);
            sync_movement_account_transaction($id);
            sync_movement_to_check($id);
            log_action('Hareket güncellendi', '#' . $id . ' ' . movement_label($type) . ' ' . money($amount)); audit_action('hareket', $id, 'guncellendi', $oldMovement, ['type'=>$type,'amount'=>$amount,'date'=>$date,'cari_id'=>$payload[0],'account_id'=>$accountId], movement_label($type));
            flash('success', 'Hareket güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_merge($payload, [current_user()['id'], now(), now()]));
            $newId = (int)db()->lastInsertId();
            sync_movement_account_transaction($newId);
            sync_movement_to_check($newId);
            log_action('Hareket eklendi', movement_label($type) . ' ' . money($amount)); audit_action('hareket', $newId, 'eklendi', null, ['type'=>$type,'amount'=>$amount,'date'=>$date,'cari_id'=>$payload[0],'account_id'=>$accountId], movement_label($type));
            flash('success', 'Hareket eklendi.');
        }
        redirect('hareketler.php');
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if ($m && (int)($m['is_cancelled'] ?? 0) === 0) {
            db()->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                ->execute([now(), current_user()['id'], trim($_POST['cancel_reason'] ?? 'İptal edildi'), now(), $id]);
            sync_movement_account_transaction($id);
            sync_movement_to_check($id, false);
            log_action('Hareket iptal edildi', '#' . $id . ' ' . movement_label($m['movement_type']) . ' ' . money($m['amount'])); audit_action('hareket', $id, 'iptal', $m, ['is_cancelled'=>1,'cancel_reason'=>trim($_POST['cancel_reason'] ?? 'İptal edildi')], movement_label($m['movement_type']));
            flash('success', 'Hareket iptal edildi. Kayıt silinmedi; işlem geçmişinde korunuyor.');
        }
        redirect('hareketler.php');
    }
}

$cariler = cariler_for_select();
$categories = categories();
$accounts = accounts_for_select(true);
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
    if ($edit && (int)($edit['is_cancelled'] ?? 0) === 1) {
        flash('error', 'İptal edilmiş hareket düzenlenemez. Gerekirse yeni düzeltme hareketi girin.');
        redirect('hareketler.php?include_cancelled=1');
    }
}

$q = trim($_GET['q'] ?? '');
$cariId = trim($_GET['cari_id'] ?? '');
$type = trim($_GET['movement_type'] ?? '');
$accountId = trim($_GET['account_id'] ?? '');
$docType = trim($_GET['document_type'] ?? '');
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']);
$where=[]; $params=[];
if (!$includeCancelled) { $where[]='COALESCE(m.is_cancelled,0)=0'; }
if ($q !== '') { $where[]='(CAST(m.id AS TEXT) LIKE ? OR m.description LIKE ? OR c.name LIKE ? OR cat.name LIKE ? OR a.name LIKE ? OR m.document_name LIKE ? OR ch.check_no LIKE ? OR ch.bank_name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"); }
if ($cariId !== '') { $where[]='m.cari_id=?'; $params[]=(int)$cariId; }
if ($type !== '') { $where[]='m.movement_type=?'; $params[]=$type; }
if ($accountId !== '') { $where[]='m.account_id=?'; $params[]=(int)$accountId; }
if ($docType !== '') { $where[]='m.document_type=?'; $params[]=$docType; }
if ($start !== '') { $where[]='m.movement_date>=?'; $params[]=$start; }
if ($end !== '') { $where[]='m.movement_date<=?'; $params[]=$end; }
$sql="SELECT m.*, c.name AS cari_name, cat.name AS category_name, a.name AS account_name, ch.id AS linked_check_id, ch.check_no AS linked_check_no, ch.bank_name AS linked_check_bank FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN accounts a ON a.id=m.account_id LEFT JOIN checks ch ON ch.id=m.check_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY m.movement_date DESC, m.id DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$movements=$stmt->fetchAll();
page_header('Hareketler', 'hareketler');
?>
<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Hareket düzenle' : 'Yeni hareket'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <div class="two-col">
        <label>Tip<select name="movement_type" required data-cash-type><?php $entryTypes = $edit ? movement_types() : movement_entry_types(); foreach ($entryTypes as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['movement_type'] ?? ($_GET['movement_type'] ?? ''))===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select><small>Özel Alacak seçilirse genel bakiyeye işlemez; sadece seçilen carinin özel alanına kaydolur.</small></label>
        <label>Tutar<input name="amount" type="text" inputmode="decimal" required value="<?php echo e($edit['amount'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>İşlem tarihi<input name="movement_date" type="date" required value="<?php echo e($edit['movement_date'] ?? date('Y-m-d')); ?>"></label>
        <label>Vade tarihi<input name="due_date" type="date" value="<?php echo e($edit['due_date'] ?? ''); ?>"></label>
      </div>
      <label>Cari<select name="cari_id"><option value="">Cari seçilmedi</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?> — <?php echo e($c['cari_type']); ?></option><?php endforeach; ?></select></label>
      <div class="two-col">
        <label>Kategori<select name="category_id"><option value="">Kategori yok</option><?php foreach($categories as $cat): ?><option value="<?php echo e($cat['id']); ?>" <?php echo ((string)($edit['category_id'] ?? '')===(string)$cat['id'])?'selected':''; ?>><?php echo e($cat['name']); ?></option><?php endforeach; ?></select></label>
        <label>Ödeme/Kasa hesabı<select name="account_id"><option value="">Kasa/banka seçilmedi</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo ((string)($edit['account_id'] ?? '')===(string)$a['id'])?'selected':''; ?>><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select><small>Çek için ödeme yöntemi ÇEK + vade tarihi girilirse cari çek kaydı da otomatik oluşur; kasa/banka etkisi olmaz.</small></label>
      </div>
      <div class="two-col">
        <label>Ödeme yöntemi<input name="payment_method" placeholder="Nakit, EFT, kart..." value="<?php echo e($edit['payment_method'] ?? ''); ?>"></label>
        <label>Belge türü<select name="document_type"><option value="">Belge türü yok</option><?php foreach(document_types() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['document_type'] ?? '')===$key)?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      </div>
      <label>Açıklama<textarea name="description" rows="3"><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Belge / fatura görseli <small>JPG, PNG, WEBP, HEIC veya PDF; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut belge: <a href="belge-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Belge'); ?></a></p><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Hareket ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="hareketler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Hareket ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Hareket listesi</h3><a href="export.php?type=movements&<?php echo e(http_build_query($_GET)); ?>">Excel CSV indir</a></div>
    <form class="filterbar multi ultra" method="get">
      <input name="q" placeholder="Açıklama/cari/hesap/belge ara" value="<?php echo e($q); ?>">
      <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      <select name="movement_type"><option value="">Tüm tipler</option><?php foreach(movement_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $type===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <select name="account_id"><option value="">Tüm hesaplar</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo $accountId!=='' && (int)$accountId===(int)$a['id']?'selected':''; ?>><?php echo e($a['name']); ?></option><?php endforeach; ?></select>
      <select name="document_type"><option value="">Tüm belgeler</option><?php foreach(document_types() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $docType===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
      <input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>">
      <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptalleri göster</label>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih</th><th>Tip</th><th>Cari</th><th>Kategori/Hesap</th><th>Açıklama</th><th>Belge</th><th class="right">Tutar</th><th></th></tr></thead>
        <tbody>
          <?php if (!$movements): ?><tr><td colspan="8" class="empty">Hareket bulunamadı.</td></tr><?php endif; ?>
          <?php foreach($movements as $m): $cancelled=(int)($m['is_cancelled'] ?? 0)===1; ?>
          <tr class="<?php echo $cancelled ? 'row-cancelled' : ''; ?>">
            <td><?php echo e(tr_date($m['movement_date'])); ?><small><?php echo $m['due_date'] ? 'Vade: '.e(tr_date($m['due_date'])) : ''; ?></small></td>
            <td><?php echo $cancelled ? badge('İptal','neutral') : badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
            <td><?php echo $m['cari_id'] ? '<a href="cari-detay.php?id='.e($m['cari_id']).'">'.e($m['cari_name']).'</a>' : '-'; ?></td>
            <td><?php echo e($m['category_name'] ?: '-'); ?><small><?php echo e($m['account_name'] ?: ''); ?></small></td>
            <td><?php echo e($m['description'] ?: '-'); ?><small><?php echo e($m['payment_method'] ?: ''); ?><?php echo !empty($m['linked_check_id']) ? ' · <a href="cekler.php?q=' . e($m['linked_check_no'] ?: $m['linked_check_id']) . '">Çek #' . e($m['linked_check_id']) . '</a>' : ''; ?> <?php echo $cancelled ? ' · İptal: '.e($m['cancel_reason'] ?: '') : ''; ?></small></td>
            <td><?php echo $m['document_path'] ? '<a href="belge-indir.php?id='.e($m['id']).'" target="_blank">'.e(document_type_label($m['document_type'])).'</a>' : '-'; ?></td>
            <td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td>
            <td class="row-actions"><?php if(!$cancelled): ?><a href="hareketler.php?edit=<?php echo e($m['id']); ?>">Düzenle</a><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Hareket silinmeyecek, iptal edildi olarak işaretlenecek. Devam edilsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($m['id']); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal"><button>İptal</button></form><?php endif; ?><?php else: ?><span class="muted">Kayıt korundu</span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
