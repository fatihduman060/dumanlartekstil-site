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
        if ($name === '') { flash('error', 'Cari adı boş olamaz.'); redirect('cariler.php'); }
        $payload = [
            'cari_type' => trim($_POST['cari_type'] ?? 'Firma'),
            'name' => $name,
            'tax_no' => trim($_POST['tax_no'] ?? ''),
            'tax_office' => trim($_POST['tax_office'] ?? ''),
            'authorized_person' => trim($_POST['authorized_person'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'city' => trim($_POST['city'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'iban' => trim($_POST['iban'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
        ];
        if ($id > 0) {
            $oldStmt = db()->prepare('SELECT * FROM cariler WHERE id=?');
            $oldStmt->execute([$id]);
            $oldCari = $oldStmt->fetch() ?: null;
            $stmt = db()->prepare('UPDATE cariler SET cari_type=:cari_type, name=:name, tax_no=:tax_no, tax_office=:tax_office, authorized_person=:authorized_person, phone=:phone, email=:email, city=:city, address=:address, iban=:iban, notes=:notes, updated_at=:updated_at WHERE id=:id');
            $payload['updated_at'] = now(); $payload['id'] = $id; $stmt->execute($payload);
            log_action('Cari güncellendi', $name); audit_action('cari', $id, 'guncellendi', $oldCari, $payload, $name); flash('success', 'Cari güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO cariler (cari_type, name, tax_no, tax_office, authorized_person, phone, email, city, address, iban, notes, created_at, updated_at) VALUES (:cari_type, :name, :tax_no, :tax_office, :authorized_person, :phone, :email, :city, :address, :iban, :notes, :created_at, :updated_at)');
            $payload['created_at'] = now(); $payload['updated_at'] = now(); $stmt->execute($payload);
            $newId = (int)db()->lastInsertId();
            log_action('Cari eklendi', $name); audit_action('cari', $newId, 'eklendi', null, $payload, $name); flash('success', 'Cari eklendi.');
        }
        redirect('cariler.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM cariler WHERE id=?'); $stmt->execute([$id]); $oldCari = $stmt->fetch(); $name = $oldCari['name'] ?? '';
        $stmt = db()->prepare('DELETE FROM cariler WHERE id=?'); $stmt->execute([$id]);
        log_action('Cari silindi', (string)$name); audit_action('cari', $id, 'silindi', $oldCari, null, (string)$name); flash('success', 'Cari silindi. Bağlı hareket/çek varsa cari bağı kaldırıldı, kayıtlar korunur.'); redirect('cariler.php');
    }
}

$q = trim($_GET['q'] ?? '');
$type = trim($_GET['type'] ?? '');

function cari_search_normalize($value): string
{
    $value = (string)$value;
    $map = [
        'Ç'=>'c','Ğ'=>'g','İ'=>'i','I'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u',
        'Â'=>'a','Î'=>'i','Û'=>'u','Ä'=>'a','Ë'=>'e','Ï'=>'i','Ô'=>'o','È'=>'e','É'=>'e','Ê'=>'e',
        'ç'=>'c','ğ'=>'g','ı'=>'i','i'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
        'â'=>'a','î'=>'i','û'=>'u','ä'=>'a','ë'=>'e','ï'=>'i','ô'=>'o','è'=>'e','é'=>'e','ê'=>'e',
    ];
    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function cari_search_score(array $cari, string $query): int
{
    $query = cari_search_normalize($query);
    if ($query === '') return 1;

    $name = cari_search_normalize($cari['name'] ?? '');
    $authorized = cari_search_normalize($cari['authorized_person'] ?? '');
    $city = cari_search_normalize($cari['city'] ?? '');
    $taxNo = cari_search_normalize($cari['tax_no'] ?? '');
    $taxOffice = cari_search_normalize($cari['tax_office'] ?? '');
    $phone = cari_search_normalize($cari['phone'] ?? '');
    $email = cari_search_normalize($cari['email'] ?? '');
    $notes = cari_search_normalize($cari['notes'] ?? '');

    $haystack = trim($name . ' ' . $authorized . ' ' . $city . ' ' . $taxNo . ' ' . $taxOffice . ' ' . $phone . ' ' . $email . ' ' . $notes);
    $compactHaystack = str_replace(' ', '', $haystack);
    $compactQuery = str_replace(' ', '', $query);

    $tokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $score = 0;

    if ($query !== '' && $name === $query) $score += 300;
    if ($query !== '' && strpos($name, $query) === 0) $score += 180;
    if ($query !== '' && preg_match('/(^| )' . preg_quote($query, '/') . '/', $name)) $score += 130;
    if ($query !== '' && strpos($name, $query) !== false) $score += 90;
    if ($query !== '' && strpos($authorized, $query) !== false) $score += 70;
    if ($query !== '' && strpos($city, $query) !== false) $score += 60;
    if ($query !== '' && strpos($taxNo, $query) !== false) $score += 55;
    if ($query !== '' && strpos($taxOffice, $query) !== false) $score += 45;
    if ($query !== '' && strpos($phone, $query) !== false) $score += 40;
    if ($query !== '' && strpos($email, $query) !== false) $score += 35;
    if ($compactQuery !== '' && strpos($compactHaystack, $compactQuery) !== false) $score += 35;

    foreach ($tokens as $token) {
        if ($token === '') continue;
        if (strpos($name, $token) === 0) $score += 70;
        if (preg_match('/(^| )' . preg_quote($token, '/') . '/', $name)) $score += 55;
        if (strpos($haystack, $token) !== false) $score += 25;
    }

    return $score;
}

$where=[]; $params=[];
if ($type !== '') { $where[]='cari_type = ?'; $params[]=$type; }
$sql='SELECT * FROM cariler'; if ($where) $sql .= ' WHERE ' . implode(' AND ', $where); $sql .= ' ORDER BY name ASC';
$stmt = db()->prepare($sql); $stmt->execute($params); $cariler = $stmt->fetchAll();

if ($q !== '') {
    $scored = [];
    foreach ($cariler as $cari) {
        $score = cari_search_score($cari, $q);
        if ($score > 0) {
            $cari['_search_score'] = $score;
            $scored[] = $cari;
        }
    }
    usort($scored, function ($a, $b) {
        $scoreCompare = ($b['_search_score'] ?? 0) <=> ($a['_search_score'] ?? 0);
        if ($scoreCompare !== 0) return $scoreCompare;
        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $cariler = $scored;
}
$edit = null;
if (!empty($_GET['edit'])) { $stmt = db()->prepare('SELECT * FROM cariler WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit = $stmt->fetch() ?: null; }
page_header('Cariler', 'cariler');
?>
<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Cari düzenle' : 'Yeni cari'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <div class="two-col">
        <label>Cari tipi<select name="cari_type"><option <?php echo (($edit['cari_type'] ?? '')==='Firma')?'selected':''; ?>>Firma</option><option <?php echo (($edit['cari_type'] ?? '')==='Kişi')?'selected':''; ?>>Kişi</option></select></label>
        <label>Ad / Ünvan<input name="name" required value="<?php echo e($edit['name'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>Yetkili kişi<input name="authorized_person" value="<?php echo e($edit['authorized_person'] ?? ''); ?>"></label>
        <label>Şehir<input name="city" value="<?php echo e($edit['city'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>Vergi / T.C. No<input name="tax_no" value="<?php echo e($edit['tax_no'] ?? ''); ?>"></label>
        <label>Vergi dairesi<input name="tax_office" value="<?php echo e($edit['tax_office'] ?? ''); ?>"></label>
      </div>
      <div class="two-col">
        <label>Telefon<input name="phone" value="<?php echo e($edit['phone'] ?? ''); ?>"></label>
        <label>E-posta<input type="email" name="email" value="<?php echo e($edit['email'] ?? ''); ?>"></label>
      </div>
      <label>IBAN<input name="iban" value="<?php echo e($edit['iban'] ?? ''); ?>"></label>
      <label>Adres<textarea name="address" rows="2"><?php echo e($edit['address'] ?? ''); ?></textarea></label>
      <label>Not<textarea name="notes" rows="2"><?php echo e($edit['notes'] ?? ''); ?></textarea></label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Cari ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="cariler.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Cari ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Cari listesi</h3><a href="export.php?type=cariler">Excel CSV indir</a></div>
    <form class="filterbar" method="get">
      <input name="q" placeholder="Cari, yetkili, vergi no, telefon ara..." value="<?php echo e($q); ?>">
      <select name="type"><option value="">Tümü</option><option value="Firma" <?php echo $type==='Firma'?'selected':''; ?>>Firma</option><option value="Kişi" <?php echo $type==='Kişi'?'selected':''; ?>>Kişi</option></select>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Cari</th><th>Yetkili / Şehir</th><th>Vergi</th><th>İletişim</th><th class="right">Net</th><th></th></tr></thead>
        <tbody>
        <?php if (!$cariler): ?><tr><td colspan="6" class="empty">Cari bulunamadı.</td></tr><?php endif; ?>
        <?php foreach ($cariler as $c): $b=cari_balance((int)$c['id']); ?>
          <tr>
            <td><a href="cari-detay.php?id=<?php echo e($c['id']); ?>"><strong><?php echo e($c['name']); ?></strong></a><small><?php echo badge($c['cari_type'], 'neutral'); ?></small></td>
            <td><?php echo e($c['authorized_person'] ?: '-'); ?><small><?php echo e($c['city'] ?: ''); ?></small></td>
            <td><?php echo e($c['tax_no'] ?: '-'); ?><small><?php echo e($c['tax_office'] ?: ''); ?></small></td>
            <td><small><?php echo e(trim(($c['phone'] ?: '') . ' ' . ($c['email'] ?: '')) ?: '-'); ?></small></td>
            <td class="right"><strong class="<?php echo $b['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($b['net'])); ?></strong></td>
            <td class="row-actions"><a href="cariler.php?edit=<?php echo e($c['id']); ?>">Düzenle</a><?php if (can_write()): ?><form method="post" onsubmit="return confirm('Cari silinsin mi? Hareket/çek kayıtları korunur, cari bağı kaldırılır.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($c['id']); ?>"><button type="submit">Sil</button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
