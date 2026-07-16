<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_admin();

function salary_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        department TEXT,
        position TEXT,
        phone TEXT,
        start_date TEXT,
        base_salary REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        period TEXT NOT NULL,
        salary_amount REAL NOT NULL DEFAULT 0,
        advance_amount REAL NOT NULL DEFAULT 0,
        deduction_amount REAL NOT NULL DEFAULT 0,
        paid_amount REAL NOT NULL DEFAULT 0,
        remaining_amount REAL NOT NULL DEFAULT 0,
        payment_date TEXT,
        account_id INTEGER,
        account_transaction_id INTEGER,
        status TEXT NOT NULL DEFAULT 'bekliyor',
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'salary_records', 'advance_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'deduction_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'remaining_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'account_transaction_id', 'INTEGER');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_records_period ON salary_records(period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_records_employee ON salary_records(employee_id)");
}

function salary_status_label(string $status): string
{
    return ['bekliyor'=>'Bekliyor','kismi'=>'Kısmi ödendi','odendi'=>'Ödendi'][$status] ?? $status;
}
function salary_status_tone(string $status): string
{
    return ['bekliyor'=>'warning','kismi'=>'info','odendi'=>'success'][$status] ?? 'neutral';
}
function salary_calc_status(float $remaining, float $paid): string
{
    if ($remaining <= 0.004) return 'odendi';
    if ($paid > 0) return 'kismi';
    return 'bekliyor';
}
function salary_sync_account_transaction(int $recordId): void
{
    $stmt = db()->prepare("SELECT sr.*, se.full_name, a.id AS account_exists FROM salary_records sr JOIN salary_employees se ON se.id=sr.employee_id LEFT JOIN accounts a ON a.id=sr.account_id WHERE sr.id=?");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    if (!$row) return;
    $oldTxn = (int)($row['account_transaction_id'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $accountId = !empty($row['account_id']) ? (int)$row['account_id'] : 0;
    if ($paid <= 0 || $accountId <= 0 || empty($row['account_exists'])) {
        if ($oldTxn > 0) db()->prepare('DELETE FROM account_transactions WHERE id=? AND source_type=?')->execute([$oldTxn, 'salary']);
        db()->prepare('UPDATE salary_records SET account_transaction_id=NULL, updated_at=? WHERE id=?')->execute([now(), $recordId]);
        return;
    }
    $date = $row['payment_date'] ?: date('Y-m-d');
    $desc = 'Maaş ödemesi: ' . ($row['full_name'] ?? '') . ' / ' . month_label($row['period'] ?? '');
    if ($oldTxn > 0) {
        db()->prepare("UPDATE account_transactions SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary', source_id=?, description=?, created_at=COALESCE(created_at, ?), created_by=COALESCE(created_by, ?) WHERE id=?")
            ->execute([$accountId, $paid, $date, $recordId, $desc, now(), current_user()['id'] ?? null, $oldTxn]);
    } else {
        db()->prepare("INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, 'out', ?, ?, 'salary', ?, ?, ?, ?)")
            ->execute([$accountId, $paid, $date, $recordId, $desc, current_user()['id'] ?? null, now()]);
        $newTxn = (int)db()->lastInsertId();
        db()->prepare('UPDATE salary_records SET account_transaction_id=?, updated_at=? WHERE id=?')->execute([$newTxn, now(), $recordId]);
    }
}

function salary_refresh_after_advance(int $employeeId, string $period): ?string
{
    $record = maas_aylik_kayit_record($employeeId, $period);
    if (!$record) return null;
    try {
        $monthlyOverride = (int)($record['attendance_override_enabled'] ?? 0) === 1;
        maas_aylik_kayit_save($employeeId, $period, [], $monthlyOverride);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

salary_db_ensure();
maas_aylik_kayit_db_ensure();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_advance') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $advanceDate = trim((string)($_POST['advance_date'] ?? date('Y-m-d')));
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));
        try {
            maas_avans_create($employeeId, $advanceDate, $amount, $accountId, $note);
            $periodForAdvance = maas_avans_period($advanceDate);
            $warning = salary_refresh_after_advance($employeeId, $periodForAdvance);
            flash('success', 'Avans hareketi kaydedildi ve aynı ayın maaş/bordro hesabına bağlandı.');
            if ($warning) flash('warning', 'Avans kaydedildi; mevcut bordro daha sonra güncellenecek: ' . $warning);
            redirect('maaslar.php?period=' . urlencode($periodForAdvance) . '&employee_id=' . $employeeId);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('maaslar.php?period=' . urlencode(maas_avans_period($advanceDate)));
        }
    }

    if ($action === 'delete_advance') {
        $row = maas_avans_delete((int)($_POST['id'] ?? 0));
        if ($row) {
            $periodForAdvance = maas_avans_period((string)$row['advance_date']);
            $warning = salary_refresh_after_advance((int)$row['employee_id'], $periodForAdvance);
            flash('success', 'Avans hareketi silindi ve maaş/bordro toplamı güncellendi.');
            if ($warning) flash('warning', 'Avans silindi; mevcut bordro daha sonra güncellenecek: ' . $warning);
            redirect('maaslar.php?period=' . urlencode($periodForAdvance) . '&employee_id=' . (int)$row['employee_id']);
        }
        flash('error', 'Avans hareketi bulunamadı.');
        redirect('maaslar.php');
    }

    if ($action === 'save_employee') {
        $id = (int)($_POST['id'] ?? 0);
        $payload = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'department' => trim($_POST['department'] ?? ''),
            'position' => trim($_POST['position'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'start_date' => $_POST['start_date'] ?: null,
            'base_salary' => decimal_from_input($_POST['base_salary'] ?? '0'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'note' => trim($_POST['note'] ?? ''),
        ];
        if ($payload['full_name'] === '') { flash('error', 'Personel adı zorunlu.'); redirect('maaslar.php'); }
        if ($id > 0) {
            $old = db()->prepare('SELECT * FROM salary_employees WHERE id=?'); $old->execute([$id]); $oldRow = $old->fetch();
            db()->prepare('UPDATE salary_employees SET full_name=:full_name, department=:department, position=:position, phone=:phone, start_date=:start_date, base_salary=:base_salary, is_active=:is_active, note=:note, updated_at=:updated_at WHERE id=:id')
                ->execute($payload + ['updated_at'=>now(), 'id'=>$id]);
            audit_action('maas_personel', $id, 'guncellendi', $oldRow, $payload, $payload['full_name']);
            flash('success', 'Personel güncellendi.');
        } else {
            db()->prepare('INSERT INTO salary_employees (full_name, department, position, phone, start_date, base_salary, is_active, note, created_by, created_at, updated_at) VALUES (:full_name,:department,:position,:phone,:start_date,:base_salary,:is_active,:note,:created_by,:created_at,:updated_at)')
                ->execute($payload + ['created_by'=>current_user()['id'] ?? null, 'created_at'=>now(), 'updated_at'=>now()]);
            $newId = (int)db()->lastInsertId();
            audit_action('maas_personel', $newId, 'eklendi', null, $payload, $payload['full_name']);
            flash('success', 'Personel eklendi.');
        }
        redirect('maaslar.php');
    }

    if ($action === 'save_salary') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $periodForSalary = trim($_POST['period'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $periodForSalary)) $periodForSalary = date('Y-m');
        try {
            maas_aylik_kayit_save($employeeId, $periodForSalary, $_POST, true);
            flash('success', 'Maaş, avans toplamı, puantaj ve bordro kaydı güncellendi.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('maaslar.php?period=' . urlencode($periodForSalary) . '&employee_id=' . $employeeId);
    }

    if ($action === 'delete_salary') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM salary_records WHERE id=?'); $stmt->execute([$id]); $old = $stmt->fetch();
        if ($old) {
            if (!empty($old['account_transaction_id'])) db()->prepare('DELETE FROM account_transactions WHERE id=? AND source_type=?')->execute([(int)$old['account_transaction_id'], 'salary']);
            db()->prepare('DELETE FROM salary_records WHERE id=?')->execute([$id]);
            audit_action('maas_kaydi', $id, 'silindi', $old, null, $old['period'] ?? '');
            flash('success', 'Maaş kaydı silindi. Tarihli avans hareketleri korunmuştur.');
        }
        redirect('maaslar.php');
    }
    redirect('maaslar.php');
}

$period = trim($_GET['period'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
$status = trim($_GET['status'] ?? '');
$employeeFilter = (int)($_GET['employee_id'] ?? 0);

$employees = db()->query('SELECT * FROM salary_employees ORDER BY is_active DESC, full_name ASC')->fetchAll();
$accounts = accounts_for_select(true);
$activeEmployees = array_values(array_filter($employees, fn($e) => (int)($e['is_active'] ?? 0) === 1));

$editEmployee = null;
if (!empty($_GET['edit_employee'])) { $stmt=db()->prepare('SELECT * FROM salary_employees WHERE id=?'); $stmt->execute([(int)$_GET['edit_employee']]); $editEmployee=$stmt->fetch() ?: null; }
$editSalary = null;
if (!empty($_GET['edit_salary'])) { $stmt=db()->prepare('SELECT * FROM salary_records WHERE id=?'); $stmt->execute([(int)$_GET['edit_salary']]); $editSalary=$stmt->fetch() ?: null; }

$where = ['sr.period=?']; $params = [$period];
if ($status !== '') { $where[] = 'sr.status=?'; $params[] = $status; }
if ($employeeFilter > 0) { $where[] = 'sr.employee_id=?'; $params[] = $employeeFilter; }
$sql = 'SELECT sr.*, se.full_name, se.department, se.position, a.name AS account_name, a.bank_name FROM salary_records sr JOIN salary_employees se ON se.id=sr.employee_id LEFT JOIN accounts a ON a.id=sr.account_id WHERE ' . implode(' AND ', $where) . ' ORDER BY se.full_name ASC, sr.id DESC';
$stmt = db()->prepare($sql); $stmt->execute($params); $records = $stmt->fetchAll();

$advanceRows = maas_avans_period_rows($period, $employeeFilter);
$advancePeriodTotal = array_reduce($advanceRows, fn($sum, $row) => $sum + (float)$row['amount'], 0.0);
$advanceDefaultDate = $period === date('Y-m') ? date('Y-m-d') : $period . '-05';

$sumSalary = $sumAdvance = $sumDeduction = $sumPaid = $sumRemaining = 0.0;
foreach ($records as $r) { $sumSalary += (float)$r['salary_amount']; $sumAdvance += (float)$r['advance_amount']; $sumDeduction += (float)$r['deduction_amount']; $sumPaid += (float)$r['paid_amount']; $sumRemaining += (float)$r['remaining_amount']; }

page_header('Maaşlar', 'maaslar');
?>
<style>
.salary-grid{display:grid;gap:16px;max-width:1500px;margin:0 auto}.salary-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.salary-hero h2{margin:4px 0 6px;color:#fff;font-size:clamp(24px,3vw,36px)}.salary-hero p{margin:0;color:#e9f5ed}.salary-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.salary-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.salary-summary article{background:#fff;border:1px solid #e5dccf;border-radius:18px;padding:15px 16px;box-shadow:0 12px 30px rgba(7,27,63,.06)}.salary-summary span{font-size:11px;color:#8a6a26;font-weight:950;text-transform:uppercase}.salary-summary strong{display:block;margin-top:7px;color:#102818;font-size:22px}.salary-columns{display:grid;grid-template-columns:380px 1fr;gap:16px}.salary-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.salary-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.salary-card-head h3{margin:0;color:#102818}.salary-card-head span{color:#8a6a26;font-size:12px;font-weight:900}.salary-body{padding:16px 18px}.salary-form{display:grid;gap:11px}.salary-form label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.salary-form input,.salary-form select,.salary-form textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.salary-form input[readonly]{background:#f2f0ea;color:#5f665f}.salary-form .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.salary-form label small{color:#776b5c;font-size:10px;font-weight:650}.salary-filter{display:grid;grid-template-columns:150px 1fr 160px auto;gap:8px;padding:12px 14px;border-bottom:1px solid #e5dccf}.salary-filter input,.salary-filter select,.salary-filter button{min-height:38px;border:1px solid #e5dccf;border-radius:999px;padding:7px 11px;background:#fff;color:#102818;font-weight:800}.salary-table-wrap{overflow:auto}.salary-table{width:100%;min-width:1050px;border-collapse:separate;border-spacing:0}.salary-table th{background:#16482e;color:#fff;text-align:left;padding:11px 12px;font-size:11px;text-transform:uppercase}.salary-table td{padding:12px;border-bottom:1px solid #e5dccf;vertical-align:top;font-size:13px}.salary-table b{display:block;color:#102818}.salary-table small{display:block;color:#776b5c;margin-top:3px}.salary-table tfoot td{background:#102818;color:#fff;font-weight:900}.salary-actions{display:flex;gap:6px;flex-wrap:wrap}.salary-actions a,.salary-actions button{border:1px solid #e5dccf;border-radius:999px;padding:6px 10px;background:#fff;color:#102818;text-decoration:none;font-size:12px;font-weight:900}.salary-actions button.danger{color:#b64242}.text-right{text-align:right}.salary-person-list{display:grid;gap:8px}.salary-person{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;padding:10px;border:1px solid #e5dccf;border-radius:14px;background:#fff}.salary-person strong{color:#102818}.salary-person small{display:block;color:#776b5c}.salary-person a{text-decoration:none;font-weight:900;color:#16482e}.salary-advance-card{grid-column:1/-1;border-color:#d8bd7d}.salary-advance-card .salary-card-head{background:#fff8e7}.advance-layout{display:grid;grid-template-columns:390px minmax(0,1fr);gap:16px}.advance-info{margin:0;padding:10px 12px;border-radius:12px;background:#edf5ef;color:#16482e;font-size:11px}.advance-table{min-width:760px}.advance-amount{color:#8a6114;font-size:15px}@media(max-width:1180px){.salary-columns{grid-template-columns:1fr}.salary-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.salary-filter{grid-template-columns:1fr 1fr}.advance-layout{grid-template-columns:1fr}}@media(max-width:700px){.salary-hero{display:block}.salary-summary{grid-template-columns:1fr}.salary-form .two,.salary-filter{grid-template-columns:1fr}}
</style>
<div class="salary-grid">
  <section class="salary-hero"><div><span>PERSONEL MAAŞ TAKİBİ</span><h2>Maaş, avans ve ödeme durumunu takip et.</h2><p>Avanslar tarihli ayrı hareket olarak girilir; aynı ayın bordrosuna otomatik düşer.</p></div><div><strong><?php echo e(month_label($period)); ?></strong></div></section>
  <section class="salary-summary">
    <article><span>Personel</span><strong><?php echo count($activeEmployees); ?></strong></article>
    <article><span>Bu ay maaş</span><strong><?php echo e(money($sumSalary)); ?></strong></article>
    <article><span>Avans/kesinti</span><strong><?php echo e(money($sumAdvance + $sumDeduction)); ?></strong></article>
    <article><span>Ödenen</span><strong><?php echo e(money($sumPaid)); ?></strong></article>
    <article><span>Kalan</span><strong><?php echo e(money($sumRemaining)); ?></strong></article>
  </section>
  <section class="salary-columns">
    <div class="salary-card salary-advance-card">
      <div class="salary-card-head"><h3>Avans hareketleri</h3><span><?php echo e(month_label($period)); ?> toplamı: <?php echo e(money($advancePeriodTotal)); ?></span></div>
      <div class="salary-body advance-layout">
        <form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_advance">
          <div class="two"><label>Avans tarihi<input type="date" name="advance_date" value="<?php echo e($advanceDefaultDate); ?>" required></label><label>Personel<select name="employee_id" required><option value="">Personel seç</option><?php foreach($activeEmployees as $emp): ?><option value="<?php echo e($emp['id']); ?>" <?php echo $employeeFilter===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['full_name']); ?></option><?php endforeach; ?></select></label></div>
          <div class="two"><label>Avans tutarı<input name="amount" inputmode="decimal" placeholder="0,00" required></label><label>Kasa/Banka hesabı<select name="account_id"><option value="">Sadece avans kaydı</option><?php foreach($accounts as $acc): ?><option value="<?php echo e($acc['id']); ?>"><?php echo e($acc['name']); ?><?php echo !empty($acc['bank_name']) ? ' / '.e($acc['bank_name']) : ''; ?></option><?php endforeach; ?></select></label></div>
          <label>Açıklama<textarea name="note" rows="2" placeholder="Örn. 5 Temmuz maaş avansı"></textarea></label>
          <p class="advance-info">Kasa/Banka seçilirse avans tarihiyle otomatik para çıkışı oluşur. Bu tutar devamsızlık değildir; aynı ayın net maaşından ayrıca düşer.</p>
          <button class="btn btn-primary">Avans hareketini kaydet</button>
        </form>
        <div class="salary-table-wrap"><table class="salary-table advance-table"><thead><tr><th>Tarih</th><th>Personel</th><th class="text-right">Avans</th><th>Hesap</th><th>Açıklama</th><th>İşlem</th></tr></thead><tbody>
          <?php if(!$advanceRows): ?><tr><td colspan="6" class="empty">Bu dönemde avans hareketi yok.</td></tr><?php endif; ?>
          <?php foreach($advanceRows as $advance): ?><tr><td><?php echo e(tr_date($advance['advance_date'])); ?></td><td><b><?php echo e($advance['full_name']); ?></b><small><?php echo e(trim(($advance['department'] ?? '') . ' ' . ($advance['position'] ?? '')) ?: '-'); ?></small></td><td class="text-right"><b class="advance-amount"><?php echo e(money($advance['amount'])); ?></b></td><td><?php echo e($advance['account_name'] ?: 'Sadece kayıt'); ?><small><?php echo e($advance['bank_name'] ?: ''); ?></small></td><td><?php echo e($advance['note'] ?: '-'); ?></td><td><form method="post" onsubmit="return confirm('Avans hareketi silinsin mi? Kasa/Banka çıkışı varsa o da silinir.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_advance"><input type="hidden" name="id" value="<?php echo e($advance['id']); ?>"><button class="danger">Sil</button></form></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><td colspan="2">Toplam</td><td class="text-right"><?php echo e(money($advancePeriodTotal)); ?></td><td colspan="3"></td></tr></tfoot></table></div>
      </div>
    </div>

    <div class="salary-card">
      <div class="salary-card-head"><h3><?php echo $editEmployee ? 'Personel düzenle' : 'Yeni personel'; ?></h3><?php if($editEmployee): ?><a class="btn btn-secondary" href="maaslar.php">Yeni</a><?php endif; ?></div>
      <div class="salary-body"><form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_employee"><input type="hidden" name="id" value="<?php echo e($editEmployee['id'] ?? 0); ?>">
        <label>Ad soyad<input name="full_name" required value="<?php echo e($editEmployee['full_name'] ?? ''); ?>"></label>
        <div class="two"><label>Bölüm<input name="department" value="<?php echo e($editEmployee['department'] ?? ''); ?>"></label><label>Görev<input name="position" value="<?php echo e($editEmployee['position'] ?? ''); ?>"></label></div>
        <div class="two"><label>Telefon<input name="phone" value="<?php echo e($editEmployee['phone'] ?? ''); ?>"></label><label>Başlama tarihi<input type="date" name="start_date" value="<?php echo e($editEmployee['start_date'] ?? ''); ?>"></label></div>
        <label>Varsayılan maaş<input name="base_salary" value="<?php echo e(isset($editEmployee['base_salary']) ? number_format((float)$editEmployee['base_salary'], 2, ',', '.') : ''); ?>" placeholder="0,00"></label>
        <label>Not<textarea name="note" rows="2"><?php echo e($editEmployee['note'] ?? ''); ?></textarea></label>
        <label class="check"><input type="checkbox" name="is_active" <?php echo !isset($editEmployee['is_active']) || (int)$editEmployee['is_active']===1 ? 'checked' : ''; ?>> Aktif personel</label>
        <button class="btn btn-primary">Kaydet</button>
      </form></div>
      <div class="salary-body" style="border-top:1px solid #e5dccf"><h3>Personel listesi</h3><div class="salary-person-list"><?php foreach($employees as $emp): ?><div class="salary-person"><div><strong><?php echo e($emp['full_name']); ?></strong><small><?php echo e(trim(($emp['department'] ?? '') . ' ' . ($emp['position'] ?? '')) ?: '-'); ?> · <?php echo e(money($emp['base_salary'] ?? 0)); ?></small></div><a href="maaslar.php?edit_employee=<?php echo e($emp['id']); ?>">Düzenle</a></div><?php endforeach; ?><?php if(!$employees): ?><p class="muted">Henüz personel yok.</p><?php endif; ?></div></div>
    </div>
    <div class="salary-card">
      <div class="salary-card-head"><h3><?php echo $editSalary ? 'Maaş kaydı düzenle' : 'Aylık maaş kaydı'; ?></h3><?php if($editSalary): ?><a class="btn btn-secondary" href="maaslar.php?period=<?php echo e($period); ?>">Yeni kayıt</a><?php endif; ?></div>
      <div class="salary-body"><form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_salary"><input type="hidden" name="id" value="<?php echo e($editSalary['id'] ?? 0); ?>">
        <div class="two"><label>Dönem<input type="month" name="period" value="<?php echo e($editSalary['period'] ?? $period); ?>"></label><label>Personel<select name="employee_id" required><option value="">Personel seç</option><?php foreach($activeEmployees as $emp): ?><option value="<?php echo e($emp['id']); ?>" data-salary="<?php echo e((float)$emp['base_salary']); ?>" <?php echo (int)($editSalary['employee_id'] ?? 0)===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['full_name']); ?></option><?php endforeach; ?></select></label></div>
        <div class="two"><label>Maaş tutarı<input name="salary_amount" value="<?php echo e(isset($editSalary['salary_amount']) ? number_format((float)$editSalary['salary_amount'], 2, ',', '.') : ''); ?>" placeholder="0,00" required></label><label>Avans toplamı (otomatik)<input name="advance_amount" readonly value="<?php echo e(isset($editSalary['employee_id']) ? number_format(maas_avans_period_total((int)$editSalary['employee_id'], (string)($editSalary['period'] ?? $period)), 2, ',', '.') : '0,00'); ?>"><small>Tarihli avans hareketlerinden otomatik gelir.</small></label></div>
        <div class="two"><label>Kesinti<input name="deduction_amount" value="<?php echo e(isset($editSalary['deduction_amount']) ? number_format((float)$editSalary['deduction_amount'], 2, ',', '.') : ''); ?>" placeholder="0,00"></label><label>Ödenen<input name="paid_amount" value="<?php echo e(isset($editSalary['paid_amount']) ? number_format((float)$editSalary['paid_amount'], 2, ',', '.') : ''); ?>" placeholder="0,00"></label></div>
        <div class="two"><label>Ödeme tarihi<input type="date" name="payment_date" value="<?php echo e($editSalary['payment_date'] ?? date('Y-m-d')); ?>"></label><label>Kasa/Banka hesabı<select name="account_id"><option value="">Sadece kayıt, kasaya işleme</option><?php foreach($accounts as $acc): ?><option value="<?php echo e($acc['id']); ?>" <?php echo (int)($editSalary['account_id'] ?? 0)===(int)$acc['id']?'selected':''; ?>><?php echo e($acc['name']); ?><?php echo !empty($acc['bank_name']) ? ' / '.e($acc['bank_name']) : ''; ?></option><?php endforeach; ?></select></label></div>
        <label>Açıklama<textarea name="note" rows="2"><?php echo e($editSalary['note'] ?? ''); ?></textarea></label>
        <button class="btn btn-primary">Maaş kaydını kaydet</button>
      </form></div>
      <form class="salary-filter" method="get"><input type="month" name="period" value="<?php echo e($period); ?>"><select name="employee_id"><option value="0">Tüm personel</option><?php foreach($employees as $emp): ?><option value="<?php echo e($emp['id']); ?>" <?php echo $employeeFilter===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['full_name']); ?></option><?php endforeach; ?></select><select name="status"><option value="">Tüm durumlar</option><?php foreach(['bekliyor'=>'Bekliyor','kismi'=>'Kısmi ödendi','odendi'=>'Ödendi'] as $k=>$v): ?><option value="<?php echo e($k); ?>" <?php echo $status===$k?'selected':''; ?>><?php echo e($v); ?></option><?php endforeach; ?></select><button>Filtrele</button></form>
      <div class="salary-table-wrap"><table class="salary-table"><thead><tr><th>Personel</th><th>Dönem</th><th class="text-right">Maaş</th><th class="text-right">Avans/Kesinti</th><th class="text-right">Ödenen</th><th class="text-right">Kalan</th><th>Durum</th><th>Hesap</th><th>İşlem</th></tr></thead><tbody><?php if(!$records): ?><tr><td colspan="9" class="empty">Bu dönemde maaş kaydı yok.</td></tr><?php endif; ?><?php foreach($records as $r): ?><tr><td><b><?php echo e($r['full_name']); ?></b><small><?php echo e(trim(($r['department'] ?? '') . ' ' . ($r['position'] ?? '')) ?: '-'); ?></small></td><td><?php echo e(month_label($r['period'])); ?></td><td class="text-right"><?php echo e(money($r['salary_amount'])); ?></td><td class="text-right"><?php echo e(money((float)$r['advance_amount'] + (float)$r['deduction_amount'])); ?><small>Avans: <?php echo e(money($r['advance_amount'])); ?> / Kesinti: <?php echo e(money($r['deduction_amount'])); ?></small></td><td class="text-right"><?php echo e(money($r['paid_amount'])); ?><small><?php echo e(tr_date($r['payment_date'])); ?></small></td><td class="text-right"><b><?php echo e(money($r['remaining_amount'])); ?></b></td><td><?php echo badge(salary_status_label($r['status']), salary_status_tone($r['status'])); ?></td><td><?php echo e($r['account_name'] ?: '-'); ?><small><?php echo e($r['bank_name'] ?: ''); ?></small></td><td><div class="salary-actions"><a href="maaslar.php?period=<?php echo e($period); ?>&edit_salary=<?php echo e($r['id']); ?>">Düzenle</a><form method="post" onsubmit="return confirm('Maaş kaydı silinsin mi? Kasa/banka çıkışı varsa o da silinir. Avans hareketleri korunur.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_salary"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button class="danger">Sil</button></form></div></td></tr><?php endforeach; ?></tbody><tfoot><tr><td colspan="2">Toplam</td><td class="text-right"><?php echo e(money($sumSalary)); ?></td><td class="text-right"><?php echo e(money($sumAdvance + $sumDeduction)); ?></td><td class="text-right"><?php echo e(money($sumPaid)); ?></td><td class="text-right"><?php echo e(money($sumRemaining)); ?></td><td colspan="3"></td></tr></tfoot></table></div>
    </div>
  </section>
</div>
<script>
document.addEventListener('change', function(e){
  if(e.target && e.target.name === 'employee_id'){
    var opt = e.target.selectedOptions && e.target.selectedOptions[0];
    var salary = opt ? Number(opt.getAttribute('data-salary') || 0) : 0;
    var form = e.target.closest('form');
    var input = form && form.querySelector('input[name="salary_amount"]');
    if(input && salary > 0 && !input.value){ input.value = new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(salary); }
  }
});
</script>
<?php page_footer(); ?>
