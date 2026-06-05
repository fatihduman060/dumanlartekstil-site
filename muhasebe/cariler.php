<?php
require_once __DIR__ . '/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            flash('error', 'Cari adı boş olamaz.');
            redirect('cariler.php');
        }
        $data = [
            trim($_POST['cari_type'] ?? 'Firma'), $name, trim($_POST['tax_no'] ?? ''), trim($_POST['phone'] ?? ''),
            trim($_POST['email'] ?? ''), trim($_POST['address'] ?? ''), trim($_POST['notes'] ?? ''), now(), now()
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE cariler SET cari_type=?, name=?, tax_no=?, phone=?, email=?, address=?, notes=?, updated_at=? WHERE id=?');
            $stmt->execute([$data[0],$data[1],$data[2],$data[3],$data[4],$data[5],$data[6],now(),$id]);
            log_action('Cari güncellendi', $name);
            flash('success', 'Cari güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO cariler (cari_type, name, tax_no, phone, email, address, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute($data);
            log_action('Cari eklendi', $name);
            flash('success', 'Cari eklendi.');
        }
        redirect('cariler.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $movCount = (int)db()->prepare('SELECT COUNT(*) FROM movements WHERE cari_id=?')->execute([$id]);
        $stmt = db()->prepare('SELECT name FROM cariler WHERE id=?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        $stmt = db()->prepare('DELETE FROM cariler WHERE id=?');
        $stmt->execute([$id]);
        log_action('Cari silindi', (string)$name);
        flash('success', 'Cari silindi. Bağlı hareket varsa cari bağı kaldırıldı, hareketler korunur.');
        redirect('cariler.php');
    }
}

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');
$where = [];
$params = [];
if ($q !== '') { $where[] = '(name LIKE ? OR tax_no LIKE ? OR phone LIKE ? OR email LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($type !== '') { $where[] = 'cari_type = ?'; $params[] = $type; }
$sql = 'SELECT * FROM cariler';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$cariler = $stmt->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM cariler WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
page_header('Cariler', 'cariler');
?>
<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Cari düzenle' : 'Yeni cari'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <div class="two-col">
        <label>Cari tipi<select name="cari_type"><option <?php echo (($edit['cari_type'] ?? '')==='Firma')?'selected':''; ?>>Firma</option><option <?php echo (($edit['cari_type'] ?? '')==='Kişi')?'selected':''; ?>>Kişi</option></select></label>
        <label>Ad / Ünvan<input name="name" required value="<?php echo e($edit['name'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>Vergi / T.C. No<input name="tax_no" value="<?php echo e($edit['tax_no'] ?? ''); ?>"></label>
        <label>Telefon<input name="phone" value="<?php echo e($edit['phone'] ?? ''); ?>"></label>
      </div>
      <label>E-posta<input type="email" name="email" value="<?php echo e($edit['email'] ?? ''); ?>"></label>
      <label>Adres<textarea name="address" rows="2"><?php echo e($edit['address'] ?? ''); ?></textarea></label>
      <label>Not<textarea name="notes" rows="2"><?php echo e($edit['notes'] ?? ''); ?></textarea></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Cari ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="cariler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Cari ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Cari listesi</h3><span><?php echo count($cariler); ?> kayıt</span></div>
    <form class="filterbar" method="get">
      <input name="q" placeholder="Cari ara..." value="<?php echo e($q); ?>">
      <select name="type"><option value="">Tümü</option><option value="Firma" <?php echo $type==='Firma'?'selected':''; ?>>Firma</option><option value="Kişi" <?php echo $type==='Kişi'?'selected':''; ?>>Kişi</option></select>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cari</th><th>Tip</th><th>İletişim</th><th class="right">Net</th><th></th></tr></thead>
        <tbody>
        <?php if (!$cariler): ?><tr><td colspan="5" class="empty">Cari bulunamadı.</td></tr><?php endif; ?>
        <?php foreach ($cariler as $c): $b=cari_balance((int)$c['id']); ?>
          <tr>
            <td><a href="cari-detay.php?id=<?php echo e($c['id']); ?>"><strong><?php echo e($c['name']); ?></strong></a><small><?php echo e($c['tax_no'] ?: ''); ?></small></td>
            <td><?php echo badge($c['cari_type'], 'neutral'); ?></td>
            <td><small><?php echo e(trim(($c['phone'] ?: '') . ' ' . ($c['email'] ?: '')) ?: '-'); ?></small></td>
            <td class="right"><strong class="<?php echo $b['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($b['net'])); ?></strong></td>
            <td class="row-actions"><a href="cariler.php?edit=<?php echo e($c['id']); ?>">Düzenle</a><?php if (can_write()): ?><form method="post" onsubmit="return confirm('Cari silinsin mi? Hareketler korunur, cari bağı kaldırılır.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button type="submit">Sil</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
