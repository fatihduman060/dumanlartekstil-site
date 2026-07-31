<?php
require_once __DIR__ . '/layout.php';
require_login();

function kart_ekstre_ensure_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS card_statements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_name TEXT NOT NULL,
        statement_period TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0 CHECK(amount >= 0),
        due_date TEXT NOT NULL,
        note TEXT,
        status TEXT NOT NULL DEFAULT 'bekliyor',
        paid_date TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_card_statements_status_due ON card_statements(status, due_date)");
}

kart_ekstre_ensure_table();

function kart_ekstre_valid_date(string $value): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $cardName = trim((string)($_POST['card_name'] ?? ''));
        $period = trim((string)($_POST['statement_period'] ?? ''));
        $amount = max(0, decimal_from_input($_POST['amount'] ?? 0));
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($cardName === '' || $period === '' || $amount <= 0 || !kart_ekstre_valid_date($dueDate)) {
            flash('error', 'Kart adı, ekstre dönemi, tutar ve son ödeme tarihini kontrol et.');
            redirect('kart-ekstre-takibi.php');
        }

        if ($id > 0) {
            $stmt = db()->prepare("UPDATE card_statements SET card_name=?, statement_period=?, amount=?, due_date=?, note=?, updated_at=? WHERE id=?");
            $stmt->execute([$cardName, $period, $amount, $dueDate, $note ?: null, now(), $id]);
            flash('success', 'Kart ekstresi güncellendi.');
        } else {
            $stmt = db()->prepare("INSERT INTO card_statements (card_name, statement_period, amount, due_date, note, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,'bekliyor',?,?,?)");
            $stmt->execute([$cardName, $period, $amount, $dueDate, $note ?: null, current_user()['id'] ?? null, now(), now()]);
            flash('success', 'Kart ekstresi kaydedildi.');
        }
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $paidDate = trim((string)($_POST['paid_date'] ?? date('Y-m-d')));
        if ($id <= 0 || !kart_ekstre_valid_date($paidDate)) {
            flash('error', 'Ödeme tarihini kontrol et.');
            redirect('kart-ekstre-takibi.php');
        }
        db()->prepare("UPDATE card_statements SET status='odendi', paid_date=?, updated_at=? WHERE id=?")
            ->execute([$paidDate, now(), $id]);
        flash('success', 'Ekstre ödendi olarak işaretlendi.');
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'undo_paid') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE card_statements SET status='bekliyor', paid_date=NULL, updated_at=? WHERE id=?")
            ->execute([now(), $id]);
        flash('success', 'Ekstre tekrar bekliyor durumuna alındı.');
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT status FROM card_statements WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $status = (string)($stmt->fetchColumn() ?: '');
        if ($status === 'odendi') {
            flash('error', 'Ödenmiş ekstre silinemez. Önce ödemeyi geri al.');
        } else {
            db()->prepare('DELETE FROM card_statements WHERE id=?')->execute([$id]);
            flash('success', 'Bekleyen ekstre silindi.');
        }
        redirect('kart-ekstre-takibi.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM card_statements WHERE id=? LIMIT 1');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$filter = trim((string)($_GET['status'] ?? ''));
$where = '';
$params = [];
if (in_array($filter, ['bekliyor', 'odendi'], true)) {
    $where = ' WHERE status=?';
    $params[] = $filter;
}
$stmt = db()->prepare("SELECT * FROM card_statements{$where} ORDER BY CASE WHEN status='bekliyor' THEN 0 ELSE 1 END, due_date ASC, id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = db()->query("SELECT
    COALESCE(SUM(CASE WHEN status='bekliyor' AND substr(due_date,1,7)=strftime('%Y-%m','now','localtime') THEN amount ELSE 0 END),0) AS month_pending,
    COALESCE(SUM(CASE WHEN status='bekliyor' THEN 1 ELSE 0 END),0) AS pending_count,
    COALESCE(SUM(CASE WHEN status='odendi' AND substr(paid_date,1,7)=strftime('%Y-%m','now','localtime') THEN 1 ELSE 0 END),0) AS paid_count,
    MIN(CASE WHEN status='bekliyor' THEN due_date ELSE NULL END) AS nearest_due
    FROM card_statements")->fetch() ?: [];

page_header('Kart Ekstre Takibi', 'kart_ekstre');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">KART EKSTRE TAKİBİ</span>
    <h2>Kart ekstre tutarlarını ve son ödeme tarihlerini tek yerde takip et.</h2>
    <p>Ekstre geldiğinde tutarı ve son ödeme tarihini gir; ödeme yapınca ödendi olarak işaretle.</p>
  </div>
</section>

<section class="stats-grid four section-stats">
  <article class="stat-card special"><span>Bu ay ödenecek</span><strong><?php echo e(money($summary['month_pending'] ?? 0)); ?></strong><small>Bekleyen ekstreler</small></article>
  <article class="stat-card status"><span>Bekleyen ekstre</span><strong><?php echo e((string)($summary['pending_count'] ?? 0)); ?></strong><small>Toplam kayıt</small></article>
  <article class="stat-card soft"><span>Bu ay ödenen</span><strong><?php echo e((string)($summary['paid_count'] ?? 0)); ?></strong><small>Ekstre sayısı</small></article>
  <article class="stat-card cash"><span>En yakın son ödeme</span><strong><?php echo !empty($summary['nearest_due']) ? e(date('d.m.Y', strtotime($summary['nearest_due']))) : '—'; ?></strong><small>Bekleyen ekstre</small></article>
</section>

<?php if (can_write()): ?>
<section class="panel-card">
  <div class="card-head"><h3><?php echo $editRow ? 'Ekstreyi düzenle' : 'Yeni ekstre ekle'; ?></h3><span>Yalnızca gerekli bilgileri gir</span></div>
  <form method="post" class="stack-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo e((string)($editRow['id'] ?? 0)); ?>">
    <div class="two-col">
      <label>Banka / Kart adı<input name="card_name" required placeholder="Örn: Ziraat Business" value="<?php echo e($editRow['card_name'] ?? ''); ?>"></label>
      <label>Ekstre dönemi<input name="statement_period" required placeholder="Örn: Temmuz 2026" value="<?php echo e($editRow['statement_period'] ?? ''); ?>"></label>
    </div>
    <div class="two-col">
      <label>Ekstre tutarı<input name="amount" inputmode="decimal" required placeholder="0,00" value="<?php echo isset($editRow['amount']) ? e(number_format((float)$editRow['amount'], 2, ',', '.')) : ''; ?>"></label>
      <label>Son ödeme tarihi<input name="due_date" type="date" required value="<?php echo e($editRow['due_date'] ?? ''); ?>"></label>
    </div>
    <label>Not<textarea name="note" placeholder="İsteğe bağlı not"><?php echo e($editRow['note'] ?? ''); ?></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $editRow ? 'Güncelle' : 'Ekstreyi kaydet'; ?></button><?php if ($editRow): ?><a class="btn btn-secondary" href="kart-ekstre-takibi.php">Vazgeç</a><?php endif; ?></div>
  </form>
</section>
<?php endif; ?>

<section class="panel-card">
  <div class="card-head"><h3>Ekstre listesi</h3><div class="row-actions"><a class="btn btn-secondary" href="kart-ekstre-takibi.php">Tümü</a><a class="btn btn-secondary" href="?status=bekliyor">Bekleyen</a><a class="btn btn-secondary" href="?status=odendi">Ödenen</a></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Kart</th><th>Dönem</th><th class="right">Tutar</th><th>Son ödeme</th><th>Durum</th><th>Not</th><th>İşlem</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty">Henüz kart ekstresi eklenmedi.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $days = (int)floor((strtotime($row['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $rowStyle = '';
        if ($row['status'] === 'bekliyor') {
            if ($days < 0) $rowStyle = 'background:#fff0f0';
            elseif ($days === 0) $rowStyle = 'background:#fff1df';
            elseif ($days <= 5) $rowStyle = 'background:#fff9df';
        }
      ?>
        <tr style="<?php echo e($rowStyle); ?>">
          <td><strong><?php echo e($row['card_name']); ?></strong></td>
          <td><?php echo e($row['statement_period']); ?></td>
          <td class="right"><strong><?php echo e(money($row['amount'])); ?></strong></td>
          <td><?php echo e(date('d.m.Y', strtotime($row['due_date']))); ?></td>
          <td><?php echo badge($row['status'] === 'odendi' ? 'Ödendi' : 'Bekliyor', $row['status'] === 'odendi' ? 'success' : 'warning'); ?></td>
          <td><?php echo e($row['note'] ?: '—'); ?></td>
          <td><div class="row-actions">
            <?php if (can_write()): ?>
              <a href="?edit=<?php echo e((string)$row['id']); ?>">Düzenle</a>
              <?php if ($row['status'] === 'bekliyor'): ?>
                <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><input type="hidden" name="paid_date" value="<?php echo e(date('Y-m-d')); ?>"><button type="submit">Ödendi</button></form>
                <form method="post" style="display:inline" onsubmit="return confirm('Bu bekleyen ekstre silinsin mi?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><button type="submit">Sil</button></form>
              <?php else: ?>
                <form method="post" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="undo_paid"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><button type="submit">Geri al</button></form>
              <?php endif; ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
