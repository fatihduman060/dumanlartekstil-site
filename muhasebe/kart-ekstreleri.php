<?php
require_once __DIR__ . '/layout.php';
require_login();

db()->exec("CREATE TABLE IF NOT EXISTS credit_card_statements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    card_name TEXT NOT NULL,
    statement_period TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0 CHECK(amount >= 0),
    due_date TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'bekliyor',
    paid_date TEXT,
    note TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_credit_card_statements_status_due ON credit_card_statements(status, due_date)");

function kart_date_valid(string $value): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function kart_redirect(): void
{
    redirect('kart-ekstreleri.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    $pdo = db();

    try {
        $pdo->beginTransaction();

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $cardName = trim((string)($_POST['card_name'] ?? ''));
            $period = trim((string)($_POST['statement_period'] ?? ''));
            $amount = max(0, decimal_from_input($_POST['amount'] ?? '0'));
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($cardName === '') throw new RuntimeException('Kart veya banka adını yazmalısın.');
            if ($period === '') throw new RuntimeException('Ekstre dönemini yazmalısın.');
            if ($amount <= 0) throw new RuntimeException('Ekstre tutarını kontrol et.');
            if (!kart_date_valid($dueDate)) throw new RuntimeException('Son ödeme tarihini kontrol et.');

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE credit_card_statements SET card_name=?, statement_period=?, amount=?, due_date=?, note=?, updated_at=? WHERE id=?");
                $stmt->execute([$cardName, $period, $amount, $dueDate, $note ?: null, now(), $id]);
                flash('success', 'Kart ekstresi güncellendi.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO credit_card_statements (card_name, statement_period, amount, due_date, status, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'bekliyor', ?, ?, ?, ?)");
                $stmt->execute([$cardName, $period, $amount, $dueDate, $note ?: null, current_user()['id'] ?? null, now(), now()]);
                flash('success', 'Kart ekstresi kaydedildi.');
            }
        } elseif ($action === 'mark_paid') {
            $id = (int)($_POST['id'] ?? 0);
            $paidDate = trim((string)($_POST['paid_date'] ?? date('Y-m-d')));
            if ($id <= 0 || !kart_date_valid($paidDate)) throw new RuntimeException('Ödeme bilgilerini kontrol et.');
            $stmt = $pdo->prepare("UPDATE credit_card_statements SET status='odendi', paid_date=?, updated_at=? WHERE id=?");
            $stmt->execute([$paidDate, now(), $id]);
            flash('success', 'Ekstre ödendi olarak işaretlendi.');
        } elseif ($action === 'reopen') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE credit_card_statements SET status='bekliyor', paid_date=NULL, updated_at=? WHERE id=?");
            $stmt->execute([now(), $id]);
            flash('success', 'Ekstre yeniden bekleyenlere alındı.');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM credit_card_statements WHERE id=? AND status='bekliyor'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) throw new RuntimeException('Ödenmiş ekstre silinemez.');
            flash('success', 'Bekleyen ekstre silindi.');
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }
    kart_redirect();
}

$edit = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = db()->prepare('SELECT * FROM credit_card_statements WHERE id=? LIMIT 1');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

$filter = trim((string)($_GET['status'] ?? ''));
$where = '';
$params = [];
if (in_array($filter, ['bekliyor', 'odendi'], true)) {
    $where = ' WHERE status=?';
    $params[] = $filter;
}
$stmt = db()->prepare("SELECT * FROM credit_card_statements{$where} ORDER BY CASE WHEN status='bekliyor' THEN 0 ELSE 1 END, due_date ASC, id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = db()->query("SELECT
    COALESCE(SUM(CASE WHEN status='bekliyor' AND substr(due_date,1,7)=strftime('%Y-%m','now','localtime') THEN amount ELSE 0 END),0) AS month_total,
    COALESCE(SUM(CASE WHEN status='bekliyor' THEN 1 ELSE 0 END),0) AS pending_count,
    COALESCE(SUM(CASE WHEN status='odendi' AND substr(paid_date,1,7)=strftime('%Y-%m','now','localtime') THEN 1 ELSE 0 END),0) AS paid_count,
    MIN(CASE WHEN status='bekliyor' THEN due_date ELSE NULL END) AS nearest_due
    FROM credit_card_statements")->fetch() ?: [];

page_header('Kart Ekstre Takibi', 'kart_ekstreleri');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Kredi Kartları</span>
    <h2>Kart ekstrelerini, tutarlarını ve son ödeme tarihlerini tek yerden takip et.</h2>
    <p>Ekstre geldiğinde kaydet; ödeme yapıldığında tek dokunuşla ödendi olarak işaretle.</p>
  </div>
</section>

<section class="stats-grid four section-stats">
  <article class="stat-card special"><span>Bu ay ödenecek</span><strong><?php echo e(money($summary['month_total'] ?? 0)); ?></strong><small>Bekleyen ekstreler</small></article>
  <article class="stat-card status"><span>Bekleyen ekstre</span><strong><?php echo e((string)($summary['pending_count'] ?? 0)); ?></strong><small>Henüz ödenmedi</small></article>
  <article class="stat-card soft"><span>Bu ay ödenen</span><strong><?php echo e((string)($summary['paid_count'] ?? 0)); ?></strong><small>Ödendi işaretlenen</small></article>
  <article class="stat-card cash"><span>En yakın son ödeme</span><strong><?php echo !empty($summary['nearest_due']) ? e(date('d.m.Y', strtotime($summary['nearest_due']))) : '-'; ?></strong><small>Bekleyenler içinde</small></article>
</section>

<?php if (can_write()): ?>
<section class="panel-card">
  <div class="card-head"><h3><?php echo $edit ? 'Ekstreyi düzenle' : 'Yeni ekstre ekle'; ?></h3><span>Sadece temel bilgiler</span></div>
  <form method="post" class="stack-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo e((string)($edit['id'] ?? 0)); ?>">
    <div class="two-col">
      <label>Banka / Kart adı<input name="card_name" required placeholder="Örn: Ziraat Business" value="<?php echo e($edit['card_name'] ?? ''); ?>"></label>
      <label>Ekstre dönemi<input name="statement_period" required placeholder="Örn: Temmuz 2026" value="<?php echo e($edit['statement_period'] ?? ''); ?>"></label>
    </div>
    <div class="two-col">
      <label>Ekstre tutarı<input name="amount" required inputmode="decimal" placeholder="0,00" value="<?php echo $edit ? e(number_format((float)$edit['amount'], 2, ',', '.')) : ''; ?>"></label>
      <label>Son ödeme tarihi<input name="due_date" required type="date" value="<?php echo e($edit['due_date'] ?? ''); ?>"></label>
    </div>
    <label>Not <small>(isteğe bağlı)</small><textarea name="note" rows="2" placeholder="Kısa not yazabilirsin"><?php echo e($edit['note'] ?? ''); ?></textarea></label>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Ekstreyi Kaydet'; ?></button>
      <?php if ($edit): ?><a class="btn btn-secondary" href="kart-ekstreleri.php">Vazgeç</a><?php endif; ?>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="panel-card">
  <div class="card-head">
    <h3>Ekstreler</h3>
    <div class="filter-links"><a href="kart-ekstreleri.php">Tümü</a><a href="?status=bekliyor">Bekleyen</a><a href="?status=odendi">Ödenen</a></div>
  </div>
  <div class="table-wrap">
    <table class="data-table kart-table">
      <thead><tr><th>Kart</th><th>Dönem</th><th class="right">Tutar</th><th>Son Ödeme</th><th>Durum</th><th>İşlem</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="empty-state">Henüz kart ekstresi eklenmedi.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $today = date('Y-m-d');
        $days = (int)floor((strtotime($row['due_date']) - strtotime($today)) / 86400);
        $tone = 'normal';
        if ($row['status'] === 'bekliyor') {
            if ($days < 0) $tone = 'overdue';
            elseif ($days === 0) $tone = 'today';
            elseif ($days <= 5) $tone = 'soon';
        }
      ?>
        <tr class="kart-row kart-<?php echo e($tone); ?>">
          <td><strong><?php echo e($row['card_name']); ?></strong><?php if (!empty($row['note'])): ?><small><?php echo e($row['note']); ?></small><?php endif; ?></td>
          <td><?php echo e($row['statement_period']); ?></td>
          <td class="right"><strong><?php echo e(money($row['amount'])); ?></strong></td>
          <td><strong><?php echo e(date('d.m.Y', strtotime($row['due_date']))); ?></strong><?php if ($row['status']==='bekliyor'): ?><small><?php echo $days < 0 ? e(abs($days).' gün gecikti') : ($days===0 ? 'Bugün' : e($days.' gün kaldı')); ?></small><?php endif; ?></td>
          <td><span class="status-pill <?php echo $row['status']==='odendi' ? 'success' : ''; ?>"><?php echo $row['status']==='odendi' ? 'Ödendi' : 'Bekliyor'; ?></span><?php if (!empty($row['paid_date'])): ?><small><?php echo e(date('d.m.Y', strtotime($row['paid_date']))); ?></small><?php endif; ?></td>
          <td class="kart-actions">
            <?php if (can_write() && $row['status']==='bekliyor'): ?>
              <a class="btn btn-secondary btn-sm" href="?edit=<?php echo e((string)$row['id']); ?>">Düzenle</a>
              <form method="post"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><?php echo csrf_field(); ?><input type="hidden" name="paid_date" value="<?php echo e(date('Y-m-d')); ?>"><button class="btn btn-primary btn-sm" type="submit">Ödendi</button></form>
              <form method="post" onsubmit="return confirm('Bu bekleyen ekstre silinsin mi?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><?php echo csrf_field(); ?><button class="btn btn-danger btn-sm" type="submit">Sil</button></form>
            <?php elseif (can_write()): ?>
              <form method="post"><input type="hidden" name="action" value="reopen"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><?php echo csrf_field(); ?><button class="btn btn-secondary btn-sm" type="submit">Geri Al</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<style>
.kart-table td small{display:block;margin-top:4px;color:#6d786f}.kart-actions{display:flex;gap:6px;flex-wrap:wrap}.kart-actions form{margin:0}.btn-sm{padding:8px 10px;font-size:12px}.kart-soon{background:#fff9e6}.kart-today{background:#fff0df}.kart-overdue{background:#fff0f0}.filter-links{display:flex;gap:8px}.filter-links a{font-size:12px;font-weight:800;color:#173f29;text-decoration:none;padding:7px 10px;border:1px solid #dce5de;border-radius:9px}@media(max-width:760px){.kart-table thead{display:none}.kart-table,.kart-table tbody,.kart-table tr,.kart-table td{display:block;width:100%}.kart-table tr{padding:12px;border-bottom:1px solid #e5ebe7}.kart-table td{padding:6px 0;border:0;text-align:left!important}.kart-actions{padding-top:10px!important}}
</style>
<?php page_footer(); ?>
