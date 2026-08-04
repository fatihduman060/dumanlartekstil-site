<?php
require_once __DIR__ . '/layout.php';
require_admin();

function maas_tazminat_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_compensation_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        compensation_type TEXT NOT NULL DEFAULT 'kidem',
        amount REAL NOT NULL DEFAULT 0,
        payment_date TEXT NOT NULL,
        account_id INTEGER,
        account_transaction_id INTEGER,
        reversal_transaction_id INTEGER,
        note TEXT,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        cancel_reason TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE RESTRICT,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(reversal_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'salary_compensation_payments', 'reversal_transaction_id', 'INTEGER');
    ensure_column($pdo, 'salary_compensation_payments', 'is_cancelled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_compensation_payments', 'cancelled_at', 'TEXT');
    ensure_column($pdo, 'salary_compensation_payments', 'cancelled_by', 'INTEGER');
    ensure_column($pdo, 'salary_compensation_payments', 'cancel_reason', 'TEXT');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_compensation_date ON salary_compensation_payments(payment_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_compensation_employee ON salary_compensation_payments(employee_id)");
}

function maas_tazminat_turleri(): array
{
    return [
        'kidem' => 'Kıdem tazminatı',
        'ihbar' => 'İhbar tazminatı',
        'izin' => 'Kullanılmayan izin ücreti',
        'diger' => 'Diğer tazminat / ödeme',
    ];
}

function maas_tazminat_tur_etiketi(string $type): string
{
    $types = maas_tazminat_turleri();
    return isset($types[$type]) ? $types[$type] : 'Tazminat ödemesi';
}

function maas_tazminat_employee(int $employeeId): ?array
{
    $stmt = db()->prepare('SELECT * FROM salary_employees WHERE id=? LIMIT 1');
    $stmt->execute([$employeeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function maas_tazminat_account_exists(int $accountId): bool
{
    if ($accountId <= 0) return false;
    $stmt = db()->prepare('SELECT COUNT(*) FROM accounts WHERE id=? AND is_active=1');
    $stmt->execute([$accountId]);
    return (int)$stmt->fetchColumn() > 0;
}

function maas_tazminat_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM salary_compensation_payments WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function maas_tazminat_sync_account(int $id): void
{
    $stmt = db()->prepare("SELECT scp.*, se.full_name
        FROM salary_compensation_payments scp
        JOIN salary_employees se ON se.id=scp.employee_id
        WHERE scp.id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || (int)($row['is_cancelled'] ?? 0) === 1) return;

    $oldTransactionId = (int)($row['account_transaction_id'] ?? 0);
    $accountId = (int)($row['account_id'] ?? 0);
    $amount = (float)($row['amount'] ?? 0);

    if ($oldTransactionId > 0 && ($accountId <= 0 || !maas_tazminat_account_exists($accountId))) {
        throw new RuntimeException('Kasa/banka hareketi oluşmuş bir kayıtta hesap boş bırakılamaz. Kaydı iptal edip yeni kayıt açmalısın.');
    }
    if ($accountId <= 0 || $amount <= 0) return;

    $description = 'Tazminat ödemesi: ' . (string)$row['full_name'] . ' / ' . maas_tazminat_tur_etiketi((string)$row['compensation_type']);
    if ($oldTransactionId > 0) {
        db()->prepare("UPDATE account_transactions
            SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary_compensation', source_id=?, description=?
            WHERE id=?")
            ->execute([$accountId, $amount, (string)$row['payment_date'], $id, $description, $oldTransactionId]);
        return;
    }

    db()->prepare("INSERT INTO account_transactions
        (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
        VALUES (?, 'out', ?, ?, 'salary_compensation', ?, ?, ?, ?)")
        ->execute([$accountId, $amount, (string)$row['payment_date'], $id, $description, current_user()['id'] ?? null, now()]);
    db()->prepare('UPDATE salary_compensation_payments SET account_transaction_id=?, updated_at=? WHERE id=?')
        ->execute([(int)db()->lastInsertId(), now(), $id]);
}

function maas_tazminat_cancel(int $id, string $reason): void
{
    $row = maas_tazminat_get($id);
    if (!$row || (int)($row['is_cancelled'] ?? 0) === 1) {
        throw new RuntimeException('Tazminat kaydı bulunamadı veya daha önce iptal edilmiş.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $reversalId = 0;
        $oldTransactionId = (int)($row['account_transaction_id'] ?? 0);
        $accountId = (int)($row['account_id'] ?? 0);
        if ($oldTransactionId > 0 && $accountId > 0) {
            $employee = maas_tazminat_employee((int)$row['employee_id']);
            $description = 'İptal karşılığı: Tazminat ödemesi / ' . ($employee['full_name'] ?? 'Personel') . ' / ' . maas_tazminat_tur_etiketi((string)$row['compensation_type']);
            db()->prepare("INSERT INTO account_transactions
                (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
                VALUES (?, 'in', ?, ?, 'salary_compensation_reversal', ?, ?, ?, ?)")
                ->execute([$accountId, (float)$row['amount'], date('Y-m-d'), $id, $description, current_user()['id'] ?? null, now()]);
            $reversalId = (int)db()->lastInsertId();
        }

        db()->prepare("UPDATE salary_compensation_payments
            SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, reversal_transaction_id=?, updated_at=?
            WHERE id=?")
            ->execute([now(), current_user()['id'] ?? null, $reason, $reversalId > 0 ? $reversalId : null, now(), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

maas_tazminat_db_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $type = trim((string)($_POST['compensation_type'] ?? 'kidem'));
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));

        try {
            $employee = maas_tazminat_employee($employeeId);
            if (!$employee) throw new RuntimeException('Personel seçimi geçersiz.');
            if (!isset(maas_tazminat_turleri()[$type])) throw new RuntimeException('Tazminat türü geçersiz.');
            if ($amount <= 0) throw new RuntimeException('Tazminat tutarı sıfırdan büyük olmalı.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) throw new RuntimeException('Ödeme tarihi geçersiz.');
            if ($accountId !== null && !maas_tazminat_account_exists($accountId)) throw new RuntimeException('Kasa/banka hesabı geçersiz veya pasif.');

            $pdo = db();
            $pdo->beginTransaction();
            $oldRow = null;
            if ($id > 0) {
                $oldRow = maas_tazminat_get($id);
                if (!$oldRow || (int)($oldRow['is_cancelled'] ?? 0) === 1) throw new RuntimeException('İptal edilmiş kayıt düzenlenemez.');
                db()->prepare("UPDATE salary_compensation_payments
                    SET employee_id=?, compensation_type=?, amount=?, payment_date=?, account_id=?, note=?, updated_at=?
                    WHERE id=?")
                    ->execute([$employeeId, $type, $amount, $paymentDate, $accountId, $note, now(), $id]);
            } else {
                db()->prepare("INSERT INTO salary_compensation_payments
                    (employee_id, compensation_type, amount, payment_date, account_id, note, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$employeeId, $type, $amount, $paymentDate, $accountId, $note, current_user()['id'] ?? null, now(), now()]);
                $id = (int)db()->lastInsertId();
            }
            maas_tazminat_sync_account($id);
            $newRow = maas_tazminat_get($id);
            audit_action('maas_tazminat', $id, $oldRow ? 'guncellendi' : 'eklendi', $oldRow, $newRow, (string)$employee['full_name']);
            $pdo->commit();
            flash('success', $oldRow ? 'Tazminat ödemesi güncellendi.' : 'Tazminat ödemesi kaydedildi.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error', $e->getMessage());
        }
        redirect('maas-tazminat.php?year=' . urlencode(substr($paymentDate, 0, 4)));
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['cancel_reason'] ?? 'Tazminat ödemesi iptal edildi'));
        if ($reason === '') $reason = 'Tazminat ödemesi iptal edildi';
        try {
            $oldRow = maas_tazminat_get($id);
            maas_tazminat_cancel($id, $reason);
            audit_action('maas_tazminat', $id, 'iptal', $oldRow, maas_tazminat_get($id), $reason);
            flash('success', 'Tazminat kaydı iptal edildi. Kayıt silinmedi; kasa/banka hareketi varsa ters kayıt oluşturuldu.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('maas-tazminat.php');
    }
}

$year = trim((string)($_GET['year'] ?? date('Y')));
if (!preg_match('/^\d{4}$/', $year)) $year = date('Y');
$employeeFilter = (int)($_GET['employee_id'] ?? 0);
$includeCancelled = isset($_GET['include_cancelled']);
$edit = null;
if (!empty($_GET['edit'])) $edit = maas_tazminat_get((int)$_GET['edit']);

$employees = db()->query('SELECT * FROM salary_employees ORDER BY is_active DESC, full_name ASC')->fetchAll();
$accounts = accounts_for_select(true);
$where = ['scp.payment_date BETWEEN ? AND ?'];
$params = [$year . '-01-01', $year . '-12-31'];
if (!$includeCancelled) $where[] = 'COALESCE(scp.is_cancelled,0)=0';
if ($employeeFilter > 0) { $where[] = 'scp.employee_id=?'; $params[] = $employeeFilter; }
$stmt = db()->prepare("SELECT scp.*, se.full_name, se.department, se.position, se.is_active AS employee_active,
    a.name AS account_name, a.bank_name
    FROM salary_compensation_payments scp
    JOIN salary_employees se ON se.id=scp.employee_id
    LEFT JOIN accounts a ON a.id=scp.account_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY scp.payment_date DESC, scp.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = 0.0;
$typeTotals = array_fill_keys(array_keys(maas_tazminat_turleri()), 0.0);
foreach ($rows as $row) {
    if ((int)($row['is_cancelled'] ?? 0) === 1) continue;
    $amount = (float)$row['amount'];
    $total += $amount;
    if (isset($typeTotals[$row['compensation_type']])) $typeTotals[$row['compensation_type']] += $amount;
}

page_header('Tazminat Ödemeleri', 'maaslar');
?>
<style>
.comp-wrap{display:grid;gap:16px;max-width:1500px;margin:0 auto}.comp-hero{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:21px 23px;border-radius:23px;background:linear-gradient(135deg,#5b321e,#a36832);color:#fff}.comp-hero h2{margin:4px 0 6px;color:#fff}.comp-hero p{margin:0;color:#fff1e3}.comp-hero span{font-size:11px;font-weight:950;letter-spacing:.08em}.comp-actions{display:flex;gap:8px;flex-wrap:wrap}.comp-actions a{display:inline-flex;padding:9px 13px;border-radius:999px;background:#fff;color:#5b321e;text-decoration:none;font-weight:900}.comp-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px}.comp-summary article,.comp-card{background:#fff;border:1px solid #e5dccf;border-radius:19px;box-shadow:0 10px 28px rgba(25,20,15,.06)}.comp-summary article{padding:14px 15px}.comp-summary span{font-size:10px;color:#8a5b27;font-weight:950;text-transform:uppercase}.comp-summary strong{display:block;margin-top:7px;color:#3b2619;font-size:19px}.comp-grid{display:grid;grid-template-columns:390px minmax(0,1fr);gap:16px}.comp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 17px;background:#fff7ec;border-bottom:1px solid #e5dccf;border-radius:19px 19px 0 0}.comp-head h3{margin:0;color:#3b2619}.comp-body{padding:16px 17px}.comp-form{display:grid;gap:11px}.comp-form label{display:grid;gap:6px;font-size:12px;font-weight:850;color:#3b2619}.comp-form input,.comp-form select,.comp-form textarea{width:100%;min-height:42px;border:1px solid #decfbd;border-radius:12px;padding:9px 11px;background:#fff}.comp-form .two{display:grid;grid-template-columns:1fr 1fr;gap:9px}.comp-note{margin:0;padding:10px 11px;border-radius:11px;background:#fff7e8;color:#795326;font-size:11px;line-height:1.45}.comp-filter{display:grid;grid-template-columns:130px minmax(180px,1fr) auto auto;gap:8px;padding:12px 14px;border-bottom:1px solid #e5dccf}.comp-filter input,.comp-filter select,.comp-filter button{min-height:39px;border:1px solid #decfbd;border-radius:999px;padding:7px 11px;background:#fff}.comp-table-wrap{overflow:auto}.comp-table{width:100%;min-width:970px;border-collapse:separate;border-spacing:0}.comp-table th{padding:10px 11px;background:#5b321e;color:#fff;text-align:left;font-size:10px;text-transform:uppercase}.comp-table td{padding:11px;border-bottom:1px solid #eee3d5;vertical-align:top;font-size:12px}.comp-table small{display:block;margin-top:3px;color:#786a5d}.comp-table .right{text-align:right;white-space:nowrap}.comp-table .cancelled{opacity:.58;text-decoration:line-through}.comp-row-actions{display:flex;gap:6px;flex-wrap:wrap}.comp-row-actions a,.comp-row-actions button{border:1px solid #decfbd;border-radius:999px;padding:6px 9px;background:#fff;color:#5b321e;text-decoration:none;font-size:11px;font-weight:900}.comp-row-actions button{color:#a03932}.comp-empty{padding:24px!important;text-align:center;color:#786a5d}@media(max-width:1050px){.comp-grid{grid-template-columns:1fr}.comp-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.comp-hero{display:block}.comp-actions{margin-top:13px}.comp-summary,.comp-form .two,.comp-filter{grid-template-columns:1fr}}
</style>
<div class="comp-wrap">
  <section class="comp-hero">
    <div><span>MAAŞLAR / TAZMİNAT ÖDEMESİ</span><h2>Personel tazminatlarını ayrı takip et.</h2><p>Kıdem, ihbar, izin ücreti ve diğer ödemeler normal maaş toplamına karışmaz.</p></div>
    <div class="comp-actions"><a href="maaslar.php">← Maaşlara dön</a></div>
  </section>

  <section class="comp-summary">
    <article><span><?php echo e($year); ?> toplam</span><strong><?php echo e(money($total)); ?></strong></article>
    <?php foreach (maas_tazminat_turleri() as $key=>$label): ?><article><span><?php echo e($label); ?></span><strong><?php echo e(money($typeTotals[$key])); ?></strong></article><?php endforeach; ?>
  </section>

  <section class="comp-grid">
    <article class="comp-card">
      <div class="comp-head"><h3><?php echo $edit ? 'Tazminat kaydını düzenle' : 'Yeni tazminat ödemesi'; ?></h3><?php if($edit): ?><a href="maas-tazminat.php" class="btn btn-secondary">Yeni</a><?php endif; ?></div>
      <div class="comp-body">
        <form class="comp-form" method="post">
          <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
          <label>Personel<select name="employee_id" required><option value="">Personel seç</option><?php foreach($employees as $employee): ?><option value="<?php echo e($employee['id']); ?>" <?php echo (int)($edit['employee_id'] ?? 0)===(int)$employee['id']?'selected':''; ?>><?php echo e($employee['full_name']); ?><?php echo (int)$employee['is_active']===0?' — Çıkış yaptı':''; ?></option><?php endforeach; ?></select></label>
          <div class="two"><label>Ödeme türü<select name="compensation_type"><?php foreach(maas_tazminat_turleri() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo ($edit['compensation_type'] ?? 'kidem')===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label><label>Tutar<input name="amount" inputmode="decimal" required placeholder="0,00" value="<?php echo isset($edit['amount']) ? e(number_format((float)$edit['amount'],2,',','.')) : ''; ?>"></label></div>
          <div class="two"><label>Ödeme tarihi<input type="date" name="payment_date" required value="<?php echo e($edit['payment_date'] ?? date('Y-m-d')); ?>"></label><label>Kasa/Banka hesabı<select name="account_id"><option value="">Sadece kayıt, hesaba işleme</option><?php foreach($accounts as $account): ?><option value="<?php echo e($account['id']); ?>" <?php echo (int)($edit['account_id'] ?? 0)===(int)$account['id']?'selected':''; ?>><?php echo e($account['name']); ?><?php echo !empty($account['bank_name'])?' / '.e($account['bank_name']):''; ?></option><?php endforeach; ?></select></label></div>
          <label>Açıklama<textarea name="note" rows="3" placeholder="Örn. İşten ayrılış kıdem tazminatı, 1. taksit"><?php echo e($edit['note'] ?? ''); ?></textarea></label>
          <p class="comp-note">Taksitli ödeme varsa her taksiti ayrı kayıt olarak girebilirsin. Kasa/Banka seçilirse ödeme tarihinde otomatik para çıkışı oluşur.</p>
          <button class="btn btn-primary" type="submit"><?php echo $edit ? 'Tazminat kaydını güncelle' : 'Tazminat ödemesini kaydet'; ?></button>
        </form>
      </div>
    </article>

    <article class="comp-card">
      <div class="comp-head"><h3>Tazminat ödeme geçmişi</h3><strong><?php echo count($rows); ?> kayıt</strong></div>
      <form class="comp-filter" method="get"><select name="year"><?php for($y=(int)date('Y')-5;$y<=(int)date('Y')+1;$y++): ?><option value="<?php echo e($y); ?>" <?php echo $year===(string)$y?'selected':''; ?>><?php echo e($y); ?></option><?php endfor; ?></select><select name="employee_id"><option value="0">Tüm personel</option><?php foreach($employees as $employee): ?><option value="<?php echo e($employee['id']); ?>" <?php echo $employeeFilter===(int)$employee['id']?'selected':''; ?>><?php echo e($employee['full_name']); ?></option><?php endforeach; ?></select><label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptalleri göster</label><button type="submit">Filtrele</button></form>
      <div class="comp-table-wrap"><table class="comp-table"><thead><tr><th>Tarih</th><th>Personel</th><th>Tür</th><th>Hesap</th><th>Açıklama</th><th class="right">Tutar</th><th>İşlem</th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="7" class="comp-empty">Bu filtrede tazminat ödemesi yok.</td></tr><?php endif; ?>
        <?php foreach($rows as $row): $cancelled=(int)($row['is_cancelled'] ?? 0)===1; ?><tr class="<?php echo $cancelled?'cancelled':''; ?>"><td><?php echo e(tr_date($row['payment_date'])); ?><?php if($cancelled): ?><small>İptal: <?php echo e(tr_datetime($row['cancelled_at'])); ?></small><?php endif; ?></td><td><strong><?php echo e($row['full_name']); ?></strong><small><?php echo e(trim(($row['department'] ?? '').' '.($row['position'] ?? '')) ?: '-'); ?><?php echo (int)$row['employee_active']===0?' · Çıkış yaptı':''; ?></small></td><td><?php echo e(maas_tazminat_tur_etiketi((string)$row['compensation_type'])); ?></td><td><?php echo e($row['account_name'] ?: 'Sadece kayıt'); ?><small><?php echo e($row['bank_name'] ?: ''); ?></small></td><td><?php echo e($row['note'] ?: '-'); ?><?php if($cancelled): ?><small><?php echo e($row['cancel_reason'] ?: 'İptal edildi'); ?></small><?php endif; ?></td><td class="right"><strong><?php echo e(money($row['amount'])); ?></strong></td><td><div class="comp-row-actions"><?php if(!$cancelled): ?><a href="maas-tazminat.php?year=<?php echo e($year); ?>&edit=<?php echo e($row['id']); ?>">Düzenle</a><form method="post" onsubmit="return confirm('Tazminat kaydı iptal edilsin mi? Kayıt silinmeyecek; kasa/banka çıkışı varsa ters kayıt oluşturulacak.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($row['id']); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal"><button type="submit">İptal</button></form><?php else: ?><span>İptal edildi</span><?php endif; ?></div></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </article>
  </section>
</div>
<?php page_footer(); ?>
