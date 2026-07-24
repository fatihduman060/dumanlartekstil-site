<?php
require_once __DIR__ . '/layout.php';
require_login();
ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");

db()->exec("CREATE TABLE IF NOT EXISTS tax_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tax_type TEXT NOT NULL,
    tax_period TEXT,
    document_no TEXT,
    amount REAL NOT NULL CHECK(amount >= 0),
    due_date TEXT,
    status TEXT NOT NULL DEFAULT 'bekliyor',
    paid_date TEXT,
    account_id INTEGER,
    movement_id INTEGER,
    payment_method TEXT,
    description TEXT,
    document_path TEXT,
    document_name TEXT,
    document_mime TEXT,
    created_by INTEGER,
    paid_by INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY(movement_id) REFERENCES movements(id) ON DELETE SET NULL,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY(paid_by) REFERENCES users(id) ON DELETE SET NULL
)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_tax_payments_status_due ON tax_payments(status, due_date)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_tax_payments_movement ON tax_payments(movement_id)");

function vergi_category_id(): int
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $stmt->execute(['Vergi']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;
    db()->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)')->execute(['Vergi', 'gider', now()]);
    return (int)db()->lastInsertId();
}

function vergi_safe_unlink(?string $relativePath): void
{
    if (!$relativePath) return;
    $base = realpath(UPLOAD_DIR);
    $path = realpath(UPLOAD_DIR . '/' . $relativePath);
    if ($base && $path && strpos($path, $base) === 0 && is_file($path)) @unlink($path);
}

function vergi_status_label(string $status): string
{
    return $status === 'odendi' ? 'Ödendi' : 'Bekliyor';
}

function vergi_status_tone(string $status): string
{
    return $status === 'odendi' ? 'success' : 'warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add') {
        $taxType = trim((string)($_POST['tax_type'] ?? ''));
        $taxPeriod = trim((string)($_POST['tax_period'] ?? ''));
        $documentNo = trim((string)($_POST['document_no'] ?? ''));
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $dueDate = trim((string)($_POST['due_date'] ?? '')) ?: null;
        $description = trim((string)($_POST['description'] ?? ''));

        if ($taxType === '') {
            flash('error', 'Vergi türünü yazmalısın.');
            redirect('vergi-odemeleri.php');
        }
        if ($amount <= 0) {
            flash('error', 'Vergi tutarını kontrol et.');
            redirect('vergi-odemeleri.php');
        }
        if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            flash('error', 'Vade tarihini kontrol et.');
            redirect('vergi-odemeleri.php');
        }

        try { $doc = handle_upload('document'); }
        catch (Throwable $e) { flash('error', $e->getMessage()); redirect('vergi-odemeleri.php'); }

        if (empty($doc['path'])) {
            flash('error', 'Vergi makbuzu veya tahakkuk belgesi yüklemelisin.');
            redirect('vergi-odemeleri.php');
        }

        db()->prepare("INSERT INTO tax_payments (
            tax_type, tax_period, document_no, amount, due_date, status, description,
            document_path, document_name, document_mime, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, 'bekliyor', ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $taxType, $taxPeriod ?: null, $documentNo ?: null, $amount, $dueDate, $description,
                $doc['path'], $doc['name'], $doc['mime'], current_user()['id'] ?? null, now(), now(),
            ]);
        $id = (int)db()->lastInsertId();
        log_action('Vergi ödeme kaydı eklendi', $taxType . ' · ' . money($amount));
        audit_action('vergi', $id, 'eklendi', null, ['tax_type'=>$taxType,'amount'=>$amount,'due_date'=>$dueDate,'period'=>$taxPeriod], $taxType);
        flash('success', 'Vergi belgesi kaydedildi. Ödeme yaptığında “Ödendi” düğmesinden banka hesabını seçebilirsin.');
        redirect('vergi-odemeleri.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);
        $paidDate = trim((string)($_POST['paid_date'] ?? date('Y-m-d')));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? 'EFT'));

        $stmt = db()->prepare('SELECT * FROM tax_payments WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { flash('error', 'Vergi kaydı bulunamadı.'); redirect('vergi-odemeleri.php'); }
        if ($row['status'] === 'odendi') { flash('error', 'Bu vergi zaten ödendi olarak işaretlenmiş.'); redirect('vergi-odemeleri.php'); }
        if ($accountId <= 0) { flash('error', 'Paranın düşeceği banka veya kasa hesabını seçmelisin.'); redirect('vergi-odemeleri.php'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidDate)) { flash('error', 'Ödeme tarihini kontrol et.'); redirect('vergi-odemeleri.php'); }

        $accountStmt = db()->prepare('SELECT * FROM accounts WHERE id=? AND is_active=1 LIMIT 1');
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch();
        if (!$account) { flash('error', 'Seçilen banka/kasa hesabı bulunamadı veya aktif değil.'); redirect('vergi-odemeleri.php'); }

        $descriptionParts = ['Vergi ödemesi', (string)$row['tax_type']];
        if (!empty($row['tax_period'])) $descriptionParts[] = 'Dönem: ' . $row['tax_period'];
        if (!empty($row['document_no'])) $descriptionParts[] = 'Belge: ' . $row['document_no'];
        if (!empty($row['description'])) $descriptionParts[] = $row['description'];
        $movementDescription = implode(' / ', $descriptionParts);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO movements (
                cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
                payment_method, description, document_type, document_path, document_name, document_mime,
                created_by, created_at, updated_at
            ) VALUES (NULL, ?, ?, 'gider', ?, 'TL', ?, NULL, ?, ?, 'makbuz', ?, ?, ?, ?, ?, ?)")
                ->execute([
                    vergi_category_id(), $accountId, (float)$row['amount'], $paidDate, $paymentMethod,
                    $movementDescription, $row['document_path'], $row['document_name'], $row['document_mime'],
                    current_user()['id'] ?? null, now(), now(),
                ]);
            $movementId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE tax_payments SET status='odendi', paid_date=?, account_id=?, movement_id=?, payment_method=?, paid_by=?, updated_at=? WHERE id=?")
                ->execute([$paidDate, $accountId, $movementId, $paymentMethod, current_user()['id'] ?? null, now(), $id]);
            $pdo->commit();
            sync_movement_account_transaction($movementId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        log_action('Vergi ödendi', $row['tax_type'] . ' · ' . money($row['amount']) . ' · ' . $account['name']);
        audit_action('vergi', $id, 'odendi', $row, ['status'=>'odendi','account_id'=>$accountId,'movement_id'=>$movementId,'paid_date'=>$paidDate], $row['tax_type']);
        flash('success', 'Vergi ödendi olarak kaydedildi ve ' . $account['name'] . ' hesabından ' . money($row['amount']) . ' düşüldü.');
        redirect('vergi-odemeleri.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM tax_payments WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { flash('error', 'Vergi kaydı bulunamadı.'); redirect('vergi-odemeleri.php'); }
        if ($row['status'] === 'odendi' || !empty($row['movement_id'])) {
            flash('error', 'Ödenmiş vergi kaydı silinemez. Önce bağlı gider hareketi incelenmelidir.');
            redirect('vergi-odemeleri.php');
        }
        db()->prepare('DELETE FROM tax_payments WHERE id=?')->execute([$id]);
        vergi_safe_unlink($row['document_path'] ?? null);
        log_action('Vergi ödeme kaydı silindi', $row['tax_type'] . ' · ' . money($row['amount']));
        audit_action('vergi', $id, 'silindi', $row, null, $row['tax_type']);
        flash('success', 'Bekleyen vergi kaydı silindi.');
        redirect('vergi-odemeleri.php');
    }
}

$accounts = accounts_for_select(true);
$filter = trim((string)($_GET['status'] ?? ''));
$where = [];
$params = [];
if (in_array($filter, ['bekliyor','odendi'], true)) { $where[] = 'tp.status=?'; $params[] = $filter; }
$sql = "SELECT tp.*, a.name AS account_name, u.display_name AS created_name, pu.display_name AS paid_name
        FROM tax_payments tp
        LEFT JOIN accounts a ON a.id=tp.account_id
        LEFT JOIN users u ON u.id=tp.created_by
        LEFT JOIN users pu ON pu.id=tp.paid_by";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= " ORDER BY CASE WHEN tp.status='bekliyor' THEN 0 ELSE 1 END, COALESCE(tp.due_date,'9999-12-31') ASC, tp.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = db()->query("SELECT
    COALESCE(SUM(CASE WHEN status='bekliyor' THEN amount ELSE 0 END),0) AS pending_total,
    COALESCE(SUM(CASE WHEN status='bekliyor' THEN 1 ELSE 0 END),0) AS pending_count,
    COALESCE(SUM(CASE WHEN status='odendi' AND substr(paid_date,1,7)=strftime('%Y-%m','now','localtime') THEN amount ELSE 0 END),0) AS paid_month,
    COALESCE(SUM(CASE WHEN status='bekliyor' AND due_date IS NOT NULL AND due_date < date('now','localtime') THEN 1 ELSE 0 END),0) AS overdue_count
    FROM tax_payments")->fetch() ?: [];

page_header('Vergi Ödemeleri', 'vergi_odemeleri');
?>
<section class="hero-card vergi-hero">
  <div>
    <span class="status-pill">Vergi Takibi</span>
    <h2>Vergi makbuzlarını yükle, tutarı kontrol et ve ödemeyi bankadan düş.</h2>
    <p>Belge seçildiğinde vergi türü, dönem, vade ve tutar otomatik okunmaya çalışılır. Kaydetmeden önce bilgileri kontrol et.</p>
  </div>
  <div class="hero-actions"><a class="btn btn-secondary" href="dashboard.php">Genel bakışa dön</a></div>
</section>

<section class="stats-grid four section-stats vergi-summary">
  <article class="stat-card special"><span>Bekleyen vergi</span><strong><?php echo e(money($summary['pending_total'] ?? 0)); ?></strong><small><?php echo e((string)($summary['pending_count'] ?? 0)); ?> kayıt</small></article>
  <article class="stat-card soft"><span>Bu ay ödenen</span><strong><?php echo e(money($summary['paid_month'] ?? 0)); ?></strong><small>Banka/kasadan düşülen</small></article>
  <article class="stat-card <?php echo ((int)($summary['overdue_count'] ?? 0)>0)?'status':'soft'; ?>"><span>Vadesi geçen</span><strong class="<?php echo ((int)($summary['overdue_count'] ?? 0)>0)?'text-danger':''; ?>"><?php echo e((string)($summary['overdue_count'] ?? 0)); ?></strong><small>Bekleyen kayıt</small></article>
  <article class="stat-card cash"><span>Toplam kayıt</span><strong><?php echo e((string)count($rows)); ?></strong><small>Filtrelenen liste</small></article>
</section>

<?php if (can_write()): ?>
<section class="panel-card vergi-upload-card">
  <div class="card-head"><h3>Vergi belgesi yükle</h3><span>PDF veya görselden bilgiler otomatik doldurulur</span></div>
  <form method="post" enctype="multipart/form-data" class="stack-form" id="taxPaymentForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add">
    <label class="vergi-file-box">Vergi makbuzu / tahakkuk belgesi
      <input name="document" type="file" accept="image/*,application/pdf" required data-vergi-document>
      <small>PDF, JPG, PNG, WEBP veya HEIC · en fazla 10 MB</small>
    </label>
    <div class="vergi-read-status" data-vergi-read-status>Belgeyi seçtiğinde okumaya başlayacağım.</div>
    <div class="two-col">
      <label>Vergi türü<input name="tax_type" placeholder="Örn: KDV, Muhtasar, SGK, Kurumlar Vergisi" required></label>
      <label>Vergilendirme dönemi<input name="tax_period" placeholder="Örn: 2026/06 veya Haziran 2026"></label>
    </div>
    <div class="three-col vergi-three-col">
      <label>Belge / tahakkuk no<input name="document_no" placeholder="Belgede yazan numara"></label>
      <label>Vade tarihi<input name="due_date" type="date"></label>
      <label>Ödenecek tutar<input name="amount" type="text" inputmode="decimal" placeholder="0,00" required></label>
    </div>
    <label>Açıklama<textarea name="description" rows="2" placeholder="İstersen ek not yaz"></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Vergi kaydını ekle</button></div>
  </form>
</section>
<?php endif; ?>

<section class="panel-card">
  <div class="card-head">
    <h3>Vergi ödeme listesi</h3>
    <div class="vergi-filters"><a href="vergi-odemeleri.php" class="<?php echo $filter===''?'active':''; ?>">Tümü</a><a href="?status=bekliyor" class="<?php echo $filter==='bekliyor'?'active':''; ?>">Bekleyen</a><a href="?status=odendi" class="<?php echo $filter==='odendi'?'active':''; ?>">Ödenen</a></div>
  </div>
  <div class="table-wrap">
    <table class="vergi-table">
      <thead><tr><th>Vergi / Dönem</th><th>Vade</th><th>Belge</th><th>Durum</th><th>Hesap</th><th class="right">Tutar</th><th>İşlem</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">Vergi ödeme kaydı bulunmuyor.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): $overdue=$row['status']==='bekliyor'&&!empty($row['due_date'])&&$row['due_date']<date('Y-m-d'); ?>
        <tr class="<?php echo $overdue?'vergi-overdue':''; ?>">
          <td><strong><?php echo e($row['tax_type']); ?></strong><small><?php echo e($row['tax_period'] ?: 'Dönem belirtilmedi'); ?><?php echo $row['description'] ? ' · ' . e($row['description']) : ''; ?></small></td>
          <td><?php echo e(tr_date($row['due_date'])); ?><?php if($overdue): ?><small class="text-danger">Vadesi geçti</small><?php endif; ?></td>
          <td><a href="vergi-belge-indir.php?id=<?php echo e($row['id']); ?>" target="_blank"><?php echo e($row['document_no'] ?: ($row['document_name'] ?: 'Belgeyi aç')); ?></a></td>
          <td><?php echo badge(vergi_status_label($row['status']), vergi_status_tone($row['status'])); ?><small><?php echo $row['paid_date'] ? e(tr_date($row['paid_date'])) : ''; ?></small></td>
          <td><?php echo e($row['account_name'] ?: '-'); ?><small><?php echo e($row['payment_method'] ?: ''); ?></small></td>
          <td class="right"><strong><?php echo e(money($row['amount'])); ?></strong></td>
          <td class="row-actions vergi-actions">
            <?php if ($row['status'] === 'bekliyor' && can_write()): ?>
              <button type="button" class="vergi-paid-button" data-vergi-paid-open="<?php echo e($row['id']); ?>">Ödendi</button>
              <form method="post" class="vergi-paid-form" data-vergi-paid-form="<?php echo e($row['id']); ?>" hidden>
                <?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
                <select name="account_id" required><option value="">Banka/kasa seç</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select>
                <input name="paid_date" type="date" value="<?php echo e(date('Y-m-d')); ?>" required>
                <input name="payment_method" value="EFT" placeholder="EFT / Nakit">
                <button class="btn btn-primary" type="submit">Hesaptan düş</button>
                <button type="button" class="btn btn-secondary" data-vergi-paid-close>Vazgeç</button>
              </form>
              <form method="post" onsubmit="return confirm('Bekleyen vergi kaydı silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($row['id']); ?>"><button type="submit">Sil</button></form>
            <?php elseif (!empty($row['movement_id'])): ?>
              <a href="hareketler.php?edit=<?php echo e($row['movement_id']); ?>">Gider hareketi</a>
            <?php else: ?><span class="muted">Kayıt tamamlandı</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<style>
.vergi-summary{margin:16px 0}.vergi-upload-card{margin-bottom:16px}.vergi-file-box{padding:14px;border:1px dashed #cbb484;border-radius:14px;background:#fff9ed}.vergi-file-box input{margin-top:8px}.vergi-read-status{padding:10px 12px;border-radius:11px;background:#f4f1ea;color:var(--muted);font-size:11px}.vergi-read-status.is-loading{background:#eaf3fb;color:#23598b}.vergi-read-status.is-success{background:#e8f6ed;color:#17693b}.vergi-read-status.is-warning{background:#fff4db;color:#7b540d}.vergi-read-status.is-danger{background:#fdecec;color:#9f2929}.vergi-three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}.vergi-filters{display:flex;gap:7px}.vergi-filters a{padding:6px 9px;border-radius:9px;background:#f3eee5;font-size:10px;font-weight:800}.vergi-filters a.active{background:#173e2b;color:#fff}.vergi-table small{display:block;margin-top:3px}.vergi-overdue{background:#fff8ee}.vergi-actions{min-width:210px}.vergi-paid-button{border:0;border-radius:9px;background:#173e2b;color:#fff;font-weight:900;padding:7px 11px;cursor:pointer}.vergi-paid-form{position:fixed;inset:0;z-index:14000;display:grid;place-content:center;grid-template-columns:minmax(220px,320px);gap:9px;padding:20px;background:rgba(13,24,20,.72)}.vergi-paid-form[hidden]{display:none!important}.vergi-paid-form select,.vergi-paid-form input{width:100%;padding:11px;border:1px solid var(--border);border-radius:11px;background:#fff}.vergi-paid-form:before{content:'Ödeme hesabını seç';display:block;padding:14px 14px 3px;background:#fff;border-radius:16px 16px 0 0;font-size:15px;font-weight:900}.vergi-paid-form>*{margin-left:0;margin-right:0}.vergi-paid-form select{margin-top:-9px;border-radius:0}.vergi-paid-form .btn:last-child{border-radius:0 0 16px 16px}
@media(max-width:760px){.vergi-three-col{grid-template-columns:1fr}.vergi-table{min-width:900px}.vergi-summary{grid-template-columns:1fr 1fr!important}}
</style>
<script src="assets/vergi-makbuz-oku.js?v=1"></script>
<?php page_footer(); ?>
