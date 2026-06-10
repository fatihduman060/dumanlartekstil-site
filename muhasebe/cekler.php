<?php
require_once __DIR__ . '/layout.php';
require_login();

$today = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write(); require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? 'alinacak';
        $status = 'bekliyor';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $due = $_POST['due_date'] ?? '';
        if (!isset(check_directions()[$direction]) || $amount <= 0 || $due === '') {
            flash('error', 'Çek yönü, tutar ve vade tarihi kontrol edilmeli.'); redirect('cekler.php');
        }
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
            'closed_at' => null,
            'is_opening_balance_check' => isset($_POST['is_opening_balance_check']) ? 1 : 0,
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE checks SET cari_id=:cari_id, account_id=:account_id, direction=:direction, status=:status, amount=:amount, issue_date=:issue_date, due_date=:due_date, bank_name=:bank_name, branch_name=:branch_name, check_no=:check_no, drawer=:drawer, description=:description, document_path=:document_path, document_name=:document_name, document_mime=:document_mime, closed_at=:closed_at, is_opening_balance_check=:is_opening_balance_check, updated_at=:updated_at WHERE id=:id');
            $payload['updated_at'] = now(); $payload['id'] = $id; $stmt->execute($payload);
            sync_check_to_movement($id);
            delete_replaced_upload($oldDoc, $doc);
            log_action('Çek güncellendi', '#' . $id . ' ' . check_direction_label($direction) . ' ' . money($amount)); audit_action('cek', $id, 'guncellendi', $oldCheck, $payload, check_direction_label($direction)); flash('success','Çek güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO checks (cari_id, account_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, closed_at, is_opening_balance_check, created_by, created_at, updated_at) VALUES (:cari_id, :account_id, :direction, :status, :amount, :issue_date, :due_date, :bank_name, :branch_name, :check_no, :drawer, :description, :document_path, :document_name, :document_mime, :closed_at, :is_opening_balance_check, :created_by, :created_at, :updated_at)');
            $payload['created_by'] = current_user()['id']; $payload['created_at'] = now(); $payload['updated_at'] = now(); $stmt->execute($payload);
            $newId = (int)db()->lastInsertId();
            sync_check_to_movement($newId);
            log_action('Çek eklendi', check_direction_label($direction) . ' ' . money($amount)); audit_action('cek', $newId, 'eklendi', null, $payload, check_direction_label($direction)); flash('success','Çek eklendi.');
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
            sync_check_to_movement($id, false);
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
$q = trim($_GET['q'] ?? ''); $cariId = trim($_GET['cari_id'] ?? ''); $direction = trim($_GET['direction'] ?? ''); $accountId = trim($_GET['account_id'] ?? ''); $start = trim($_GET['start'] ?? ''); $end = trim($_GET['end'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']) && $_GET['include_cancelled'] === '1';
$where=[]; $params=[];
if (!$includeCancelled) { $where[]='COALESCE(ch.is_cancelled,0)=0'; }
if ($q !== '') { $where[]='(ch.bank_name LIKE ? OR ch.branch_name LIKE ? OR ch.check_no LIKE ? OR ch.drawer LIKE ? OR ch.description LIKE ? OR c.name LIKE ? OR a.name LIKE ?)'; array_push($params, "%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"); }
if ($cariId !== '') { $where[]='ch.cari_id=?'; $params[]=(int)$cariId; }
if ($direction !== '') { $where[]='ch.direction=?'; $params[]=$direction; }
if ($accountId !== '') { $where[]='ch.account_id=?'; $params[]=(int)$accountId; }
if ($start !== '') { $where[]='ch.due_date>=?'; $params[]=$start; }
if ($end !== '') { $where[]='ch.due_date<=?'; $params[]=$end; }
$sql="SELECT ch.*, c.name AS cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN accounts a ON a.id=ch.account_id"; if ($where) $sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY ch.due_date ASC, ch.id DESC LIMIT 500';
$stmt=db()->prepare($sql); $stmt->execute($params); $checks=$stmt->fetchAll();
$totals = ['alinacak'=>0,'verilecek'=>0]; foreach($checks as $ch) if((int)($ch['is_cancelled'] ?? 0) === 0) $totals[$ch['direction']] += (float)$ch['amount'];
$overdueCount = 0; $soonCount = 0;
foreach ($checks as $ch) {
    if ((int)($ch['is_cancelled'] ?? 0) === 1) continue;
    if ($ch['due_date'] < $today) $overdueCount++;
    elseif ($ch['due_date'] <= $weekAhead) $soonCount++;
}
page_header('Çekler', 'cekler');
?>
<section class="stats-grid four">
  <article class="stat-card"><span>Filtrede alınacak</span><strong><?php echo e(money($totals['alinacak'])); ?></strong><small>Alınacak çek toplamı</small></article>
  <article class="stat-card"><span>Filtrede verilecek</span><strong><?php echo e(money($totals['verilecek'])); ?></strong><small>Verilecek çek toplamı</small></article>
  <article class="stat-card soft"><span>Vadesi geçen</span><strong class="<?php echo $overdueCount > 0 ? 'text-danger' : ''; ?>"><?php echo e($overdueCount); ?> adet</strong><small>Vadesi geçen çek</small></article>
  <article class="stat-card soft"><span>7 gün içinde vade</span><strong class="<?php echo $soonCount > 0 ? 'text-warning' : ''; ?>"><?php echo e($soonCount); ?> adet</strong><small>Yaklaşan vade</small></article>
</section>

<div class="cek-legend print-hide">
  <span class="cek-leg overdue-leg">■ Vadesi geçmiş</span>
  <span class="cek-leg soon-leg">■ 7 gün içinde vade</span>
  <span class="cek-leg">■ Normal</span>
</div>

<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Çek düzenle' : 'Yeni çek'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <label>Yön<select name="direction" required><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['direction'] ?? '')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select><small>Yeni çekler cari harekete bağlanır. Eski/net girilmiş çeklerde aşağıdaki devir seçeneğini kullan.</small></label>
      <div class="two-col"><label>Tutar<input name="amount" type="text" inputmode="decimal" required value="<?php echo e($edit['amount'] ?? ''); ?>"></label><label>Vade tarihi<input name="due_date" type="date" required value="<?php echo e($edit['due_date'] ?? date('Y-m-d')); ?>"></label></div>
      <div class="two-col"><label>Çek tarihi<input name="issue_date" type="date" value="<?php echo e($edit['issue_date'] ?? ''); ?>"></label><label>Cari<select name="cari_id"><option value="">Cari seçilmedi</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label></div>
      <label>Bilgi amaçlı hesap<select name="account_id"><option value="">Hesap seçilmedi</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo ((string)($edit['account_id'] ?? '')===(string)$a['id'])?'selected':''; ?>><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select><small>Çek cari bakiyeye hareket olarak işler. Kasa/banka için ayrıca gerçek para hareketi girilir.</small></label>
      <label class="check"><input type="checkbox" name="is_opening_balance_check" value="1" <?php echo ((int)($edit['is_opening_balance_check'] ?? 0)===1)?'checked':''; ?>> Bu çek eski/devir çek; cari bakiyesi daha önce çek düşülmüş net tutar olarak girildi</label>
      <small class="muted">Bu seçenek işaretliyse sistem çeki yine listeler ama cari bakiyeyi bozmasın diye aynı tutarda denge hareketi oluşturur.</small>
      <div class="two-col"><label>Banka<input name="bank_name" value="<?php echo e($edit['bank_name'] ?? ''); ?>"></label><label>Şube<input name="branch_name" value="<?php echo e($edit['branch_name'] ?? ''); ?>"></label></div>
      <div class="two-col"><label>Çek no<input name="check_no" value="<?php echo e($edit['check_no'] ?? ''); ?>"></label><label>Keşideci / Veren<input name="drawer" value="<?php echo e($edit['drawer'] ?? ''); ?>"></label></div>
      <label>Açıklama<textarea name="description" rows="3"><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Çek görseli / belge <small>JPG, PNG, WEBP, HEIC veya PDF; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut belge: <a href="cek-belge-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Belge'); ?></a></p><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Çek ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="cekler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Çek ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Çek listesi</h3><a href="export.php?type=checks&<?php echo e(http_build_query($_GET)); ?>">Excel CSV indir</a></div>
    <form class="filterbar multi ultra" method="get">
      <input name="q" placeholder="Banka, çek no, cari, hesap ara" value="<?php echo e($q); ?>">
      <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      <select name="direction"><option value="">Tüm yönler</option><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $direction===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <select name="account_id"><option value="">Tüm hesaplar</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo $accountId!=='' && (int)$accountId===(int)$a['id']?'selected':''; ?>><?php echo e($a['name']); ?></option><?php endforeach; ?></select>
      <input type="date" name="start" value="<?php echo e($start); ?>">
      <input type="date" name="end" value="<?php echo e($end); ?>">
      <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptal edilenleri göster</label>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Vade</th><th>Yön</th><th>Cari</th><th>Banka / Çek No</th><th>Hareket/Belge</th><th class="right">Tutar</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php if(!$checks): ?><tr><td colspan="7" class="empty">Çek bulunamadı.</td></tr><?php endif; ?>
        <?php foreach($checks as $ch):
            $cancelled = (int)($ch['is_cancelled'] ?? 0) === 1;
            $isOverdue = !$cancelled && $ch['due_date'] < $today;
            $isSoon = !$cancelled && $ch['due_date'] >= $today && $ch['due_date'] <= $weekAhead;
            $rowClass = $cancelled ? 'row-cancelled' : ($isOverdue ? 'row-overdue' : ($isSoon ? 'row-soon' : ''));
        ?>
        <tr class="<?php echo $rowClass; ?>">
          <td>
            <?php echo e(tr_date($ch['due_date'])); ?>
            <?php if ($isOverdue): ?><small class="overdue-tag">Gecikmiş</small><?php elseif ($isSoon): ?><small class="soon-tag">Yaklaşan</small><?php endif; ?>
            <?php echo $ch['issue_date'] ? '<small>' . e(tr_date($ch['issue_date'])) . '</small>' : ''; ?>
          </td>
          <td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td>
          <td><?php echo $ch['cari_id'] ? '<a href="cari-detay.php?id='.e($ch['cari_id']).'">'.e($ch['cari_name']).'</a>' : '-'; ?></td>
          <td><?php echo e($ch['bank_name'] ?: '-'); ?><small><?php echo e(trim(($ch['branch_name'] ?: '') . ' ' . ($ch['check_no'] ?: ''))); ?></small><?php echo $ch['drawer'] ? '<small>' . e($ch['drawer']) . '</small>' : ''; ?></td>
          <td><?php echo !empty($ch['movement_id']) ? '<a href="hareketler.php?q='.e($ch['movement_id']).'">Hareket #'.e($ch['movement_id']).'</a>' : '-'; ?><?php echo ((int)($ch['is_opening_balance_check'] ?? 0)===1) ? '<small>Devir dengeli</small>' : ''; ?><small><?php echo $ch['document_path'] ? '<a href="cek-belge-indir.php?id='.e($ch['id']).'" target="_blank">Belge</a>' : ''; ?></small></td>
          <td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td>
          <td class="row-actions">
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
<?php page_footer(); ?>
