<?php
require_once __DIR__ . '/layout.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM cariler WHERE id=?'); $stmt->execute([$id]); $cari = $stmt->fetch();
if (!$cari) { flash('error','Cari bulunamadı.'); redirect('cariler.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'quick_movement') {
        $type = $_POST['movement_type'] ?? '';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['movement_date'] ?: date('Y-m-d');
        if (!isset(movement_types()[$type]) || $amount <= 0) {
            flash('error', 'Hızlı hareket için tip ve tutar kontrol edilmeli.');
            redirect('cari-detay.php?id=' . $id);
        }
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        if (!movement_cash_direction($type)) $accountId = null;
        try { $doc = handle_upload('document'); } catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cari-detay.php?id=' . $id); }
        $stmt = db()->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $accountId, $type, $amount, $date, $_POST['due_date'] ?: null, trim($_POST['payment_method'] ?? ''), trim($_POST['description'] ?? ''), $_POST['document_type'] ?: null, $doc['path'], $doc['name'], $doc['mime'], current_user()['id'], now(), now()]);
        $newId = (int)db()->lastInsertId();
        sync_movement_account_transaction($newId);
        log_action('Hızlı cari hareketi eklendi', $cari['name'] . ' - ' . movement_label($type) . ' ' . money($amount));
        flash('success', 'Hızlı hareket eklendi.');
        redirect('cari-detay.php?id=' . $id);
    }
}

$balance = cari_balance($id);
$accounts = accounts_for_select(true);
$mType = trim($_GET['movement_type'] ?? '');
$mStart = trim($_GET['start'] ?? '');
$mEnd = trim($_GET['end'] ?? '');
$mQ = trim($_GET['q'] ?? '');
$includeCancelled = isset($_GET['include_cancelled']);
$where=['m.cari_id=?']; $params=[$id];
if (!$includeCancelled) $where[]='COALESCE(m.is_cancelled,0)=0';
if ($mType !== '') { $where[]='m.movement_type=?'; $params[]=$mType; }
if ($mStart !== '') { $where[]='m.movement_date>=?'; $params[]=$mStart; }
if ($mEnd !== '') { $where[]='m.movement_date<=?'; $params[]=$mEnd; }
if ($mQ !== '') { $where[]='(m.description LIKE ? OR cat.name LIKE ? OR a.name LIKE ? OR m.document_name LIKE ?)'; array_push($params,"%$mQ%","%$mQ%","%$mQ%","%$mQ%"); }
$stmt = db()->prepare("SELECT m.*, cat.name AS category_name, u.display_name AS user_name, a.name AS account_name FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN users u ON u.id=m.created_by LEFT JOIN accounts a ON a.id=m.account_id WHERE " . implode(' AND ', $where) . " ORDER BY m.movement_date DESC, m.id DESC");
$stmt->execute($params); $movements = $stmt->fetchAll();

$checkStatus = trim($_GET['check_status'] ?? '');
$chWhere=['cari_id=?']; $chParams=[$id];
if ($checkStatus !== '') { $chWhere[]='status=?'; $chParams[]=$checkStatus; }
$stmt = db()->prepare("SELECT * FROM checks WHERE " . implode(' AND ', $chWhere) . " ORDER BY due_date ASC, id DESC");
$stmt->execute($chParams); $checks = $stmt->fetchAll();
$checkTotals = ['alinacak'=>0,'verilecek'=>0];
foreach ($checks as $ch) if ($ch['status']==='bekliyor') $checkTotals[$ch['direction']] += (float)$ch['amount'];
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

<section class="stats-grid four">
  <article class="stat-card"><span>Alacak</span><strong><?php echo e(money($balance['alacak'])); ?></strong><small>Tahsilat: <?php echo e(money($balance['tahsilat'])); ?></small></article>
  <article class="stat-card"><span>Kalan alacak</span><strong><?php echo e(money($balance['net_alacak'])); ?></strong><small>Alacak - tahsilat</small></article>
  <article class="stat-card"><span>Verecek</span><strong><?php echo e(money($balance['verecek'])); ?></strong><small>Ödeme: <?php echo e(money($balance['odeme'])); ?></small></article>
  <article class="stat-card"><span>Net bakiye</span><strong class="<?php echo $balance['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($balance['net'])); ?></strong><small><?php echo $balance['net'] >= 0 ? 'Alacaklı' : 'Borçlu'; ?> durum</small></article>
</section>

<?php if (can_write()): ?>
<section class="panel-card">
  <div class="card-head"><h3>Hızlı tahsilat / ödeme</h3><span>Bu cariye direkt hareket ekle</span></div>
  <form method="post" enctype="multipart/form-data" class="filterbar multi ultra">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="quick_movement">
    <select name="movement_type" required><?php foreach (movement_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $key==='tahsilat' ? 'selected' : ''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
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

<section class="content-grid compact">
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
    <div class="card-head"><h3>Bekleyen çek özeti</h3><a href="cekler.php?cari_id=<?php echo e($id); ?>&status=bekliyor">Çekleri gör</a></div>
    <section class="stats-grid two flush">
      <article class="stat-card soft"><span>Alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small>Bekleyen</small></article>
      <article class="stat-card soft"><span>Verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small>Bekleyen</small></article>
    </section>
  </article>
</section>

<section class="panel-card">
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
      <thead><tr><th>Tarih</th><th>Vade</th><th>Tip</th><th>Kategori/Hesap</th><th>Açıklama</th><th>Belge</th><th class="right">Tutar</th></tr></thead>
      <tbody>
        <?php if (!$movements): ?><tr><td colspan="7" class="empty">Bu cariye ait hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($movements as $m): $cancelled=(int)($m['is_cancelled'] ?? 0)===1; ?>
          <tr class="<?php echo $cancelled?'row-cancelled':''; ?>"><td><?php echo e(tr_date($m['movement_date'])); ?></td><td><?php echo e(tr_date($m['due_date'])); ?></td><td><?php echo $cancelled ? badge('İptal','neutral') : badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td><td><?php echo e($m['category_name'] ?: '-'); ?><small><?php echo e($m['account_name'] ?: ''); ?></small></td><td><?php echo e($m['description'] ?: '-'); ?><small><?php echo e($m['payment_method'] ?: ''); ?></small></td><td><?php echo $m['document_path'] ? '<a href="belge-indir.php?id=' . e($m['id']) . '" target="_blank">'.e(document_type_label($m['document_type'])).'</a>' : '-'; ?></td><td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel-card">
  <div class="card-head"><h3>Çek geçmişi</h3><a href="export.php?type=checks&cari_id=<?php echo e($id); ?>">Excel CSV indir</a></div>
  <form class="filterbar" method="get"><input type="hidden" name="id" value="<?php echo e($id); ?>"><select name="check_status"><option value="">Tüm çek durumları</option><?php foreach(check_statuses() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $checkStatus===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select><button class="btn btn-secondary">Filtrele</button></form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Vade</th><th>Yön</th><th>Durum</th><th>Banka</th><th>Çek No</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead>
      <tbody>
        <?php if (!$checks): ?><tr><td colspan="7" class="empty">Bu cariye ait çek yok.</td></tr><?php endif; ?>
        <?php foreach ($checks as $ch): ?>
          <tr><td><?php echo e(tr_date($ch['due_date'])); ?></td><td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td><td><?php echo badge(check_status_label($ch['status']), check_status_tone($ch['status'])); ?></td><td><?php echo e($ch['bank_name'] ?: '-'); ?><small><?php echo e($ch['branch_name'] ?: ''); ?></small></td><td><?php echo e($ch['check_no'] ?: '-'); ?></td><td><?php echo e($ch['description'] ?: '-'); ?></td><td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
