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
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
        $date = $_POST['movement_date'] ?: date('Y-m-d');
        if (!isset(movement_types()[$type]) || $amount <= 0) {
            flash('error', 'Hareket tipi ve tutar kontrol edilmeli.');
            redirect('hareketler.php');
        }
        $oldDoc = null;
        if ($id > 0) {
            $stmt = db()->prepare('SELECT document_path AS path, document_name AS name, document_mime AS mime FROM movements WHERE id=?');
            $stmt->execute([$id]);
            $oldDoc = $stmt->fetch() ?: null;
        }
        try {
            $doc = handle_upload('document', $oldDoc);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('hareketler.php');
        }
        $payload = [
            ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
            ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
            $type,
            $amount,
            $date,
            $_POST['due_date'] ?: null,
            trim($_POST['payment_method'] ?? ''),
            trim($_POST['description'] ?? ''),
            $doc['path'], $doc['name'], $doc['mime']
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE movements SET cari_id=?, category_id=?, movement_type=?, amount=?, movement_date=?, due_date=?, payment_method=?, description=?, document_path=?, document_name=?, document_mime=?, updated_at=? WHERE id=?');
            $stmt->execute(array_merge($payload, [now(), $id]));
            log_action('Hareket güncellendi', '#' . $id . ' ' . movement_label($type) . ' ' . money($amount));
            flash('success', 'Hareket güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO movements (cari_id, category_id, movement_type, amount, movement_date, due_date, payment_method, description, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute(array_merge($payload, [current_user()['id'], now(), now()]));
            log_action('Hareket eklendi', movement_label($type) . ' ' . money($amount));
            flash('success', 'Hareket eklendi.');
        }
        redirect('hareketler.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if ($m) {
            db()->prepare('DELETE FROM movements WHERE id=?')->execute([$id]);
            log_action('Hareket silindi', '#' . $id . ' ' . movement_label($m['movement_type']) . ' ' . money($m['amount']));
            flash('success', 'Hareket silindi.');
        }
        redirect('hareketler.php');
    }
}

$cariler = cariler_for_select();
$categories = categories();
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$q = trim($_GET['q'] ?? '');
$cariId = trim($_GET['cari_id'] ?? '');
$type = trim($_GET['movement_type'] ?? '');
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$where=[]; $params=[];
if ($q !== '') { $where[]='(m.description LIKE ? OR c.name LIKE ? OR cat.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%"); }
if ($cariId !== '') { $where[]='m.cari_id=?'; $params[]=(int)$cariId; }
if ($type !== '') { $where[]='m.movement_type=?'; $params[]=$type; }
if ($start !== '') { $where[]='m.movement_date>=?'; $params[]=$start; }
if ($end !== '') { $where[]='m.movement_date<=?'; $params[]=$end; }
$sql="SELECT m.*, c.name AS cari_name, cat.name AS category_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id";
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
        <label>Tip<select name="movement_type" required><?php foreach (movement_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['movement_type'] ?? '')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select></label>
        <label>Tutar<input name="amount" type="number" step="0.01" min="0" required value="<?php echo e($edit['amount'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>İşlem tarihi<input name="movement_date" type="date" required value="<?php echo e($edit['movement_date'] ?? date('Y-m-d')); ?>"></label>
        <label>Vade tarihi<input name="due_date" type="date" value="<?php echo e($edit['due_date'] ?? ''); ?>"></label>
      </div>
      <label>Cari<select name="cari_id"><option value="">Cari seçilmedi</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?> — <?php echo e($c['cari_type']); ?></option><?php endforeach; ?></select></label>
      <div class="two-col">
        <label>Kategori<select name="category_id"><option value="">Kategori yok</option><?php foreach($categories as $cat): ?><option value="<?php echo e($cat['id']); ?>" <?php echo ((string)($edit['category_id'] ?? '')===(string)$cat['id'])?'selected':''; ?>><?php echo e($cat['name']); ?></option><?php endforeach; ?></select></label>
        <label>Ödeme yöntemi<input name="payment_method" placeholder="Nakit, EFT, kart..." value="<?php echo e($edit['payment_method'] ?? ''); ?>"></label>
      </div>
      <label>Açıklama<textarea name="description" rows="3"><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Belge / fatura görseli <small>JPG, PNG, WEBP, HEIC veya PDF; max 5 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut belge: <a href="belge-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Belge'); ?></a></p><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Hareket ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="hareketler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Hareket ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Hareket listesi</h3><a href="export.php?type=movements&<?php echo e(http_build_query($_GET)); ?>">Excel CSV indir</a></div>
    <form class="filterbar multi" method="get">
      <input name="q" placeholder="Açıklama/cari ara" value="<?php echo e($q); ?>">
      <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
      <select name="movement_type"><option value="">Tüm tipler</option><?php foreach(movement_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $type===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
      <input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>">
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih</th><th>Tip</th><th>Cari</th><th>Kategori</th><th>Açıklama</th><th>Belge</th><th class="right">Tutar</th><th></th></tr></thead>
        <tbody>
          <?php if (!$movements): ?><tr><td colspan="8" class="empty">Hareket bulunamadı.</td></tr><?php endif; ?>
          <?php foreach($movements as $m): ?>
          <tr>
            <td><?php echo e(tr_date($m['movement_date'])); ?><small><?php echo $m['due_date'] ? 'Vade: '.e(tr_date($m['due_date'])) : ''; ?></small></td>
            <td><?php echo badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
            <td><?php echo $m['cari_id'] ? '<a href="cari-detay.php?id='.e($m['cari_id']).'">'.e($m['cari_name']).'</a>' : '-'; ?></td>
            <td><?php echo e($m['category_name'] ?: '-'); ?></td>
            <td><?php echo e($m['description'] ?: '-'); ?><small><?php echo e($m['payment_method'] ?: ''); ?></small></td>
            <td><?php echo $m['document_path'] ? '<a href="belge-indir.php?id='.e($m['id']).'" target="_blank">Belge</a>' : '-'; ?></td>
            <td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td>
            <td class="row-actions"><a href="hareketler.php?edit=<?php echo e($m['id']); ?>">Düzenle</a><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Hareket silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($m['id']); ?>"><button>Sil</button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
