<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_once __DIR__ . '/maas-aylik-plan-lib.php';
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
    ensure_column($pdo, 'salary_employees', 'exit_date', 'TEXT');
    ensure_column($pdo, 'salary_employees', 'exit_reason', 'TEXT');
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
maas_aylik_plan_db_ensure();

try {
    maas_garanti_odeme_sync('2026-07');
} catch (Throwable $e) {
    flash('warning', 'Maaş banka eşlemesi tamamlanamadı: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_monthly_payment_plans') {
        $planPeriod = maas_aylik_plan_period((string)($_POST['plan_period'] ?? date('Y-m')));
        $dailyRows = isset($_POST['daily_wage']) && is_array($_POST['daily_wage']) ? $_POST['daily_wage'] : [];
        $bankRows = isset($_POST['bank_amount']) && is_array($_POST['bank_amount']) ? $_POST['bank_amount'] : [];
        $cashRows = isset($_POST['cash_amount']) && is_array($_POST['cash_amount']) ? $_POST['cash_amount'] : [];
        $noteRows = isset($_POST['plan_note']) && is_array($_POST['plan_note']) ? $_POST['plan_note'] : [];
        $saved = 0;
        try {
            foreach ($dailyRows as $employeeIdRaw => $dailyRaw) {
                $employeeId = (int)$employeeIdRaw;
                if ($employeeId <= 0) continue;
                $dailyText = trim((string)$dailyRaw);
                if ($dailyText === '') continue;
                $dailyWage = decimal_from_input($dailyText);
                $bankAmount = decimal_from_input((string)($bankRows[$employeeIdRaw] ?? '0'));
                $cashAmount = decimal_from_input((string)($cashRows[$employeeIdRaw] ?? '0'));
                $note = trim((string)($noteRows[$employeeIdRaw] ?? ''));
                maas_aylik_plan_save($employeeId, $planPeriod, $dailyWage, $bankAmount, $cashAmount, $note);
                $saved++;
            }
            flash('success', month_label($planPeriod) . ' için ' . $saved . ' personelin yevmiye / banka / elden planı kaydedildi.');
        } catch (Throwable $e) {
            flash('error', 'Aylık yevmiye planı kaydedilemedi: ' . $e->getMessage());
        }
        redirect('maaslar.php?period=' . urlencode($planPeriod) . '#aylik-yevmiye-plani');
    }

    if (!empty($_POST['deduct_manual_period'])) {
        $manualPeriod = trim((string)$_POST['deduct_manual_period']);
        if (!preg_match('/^\d{4}-\d{2}$/', $manualPeriod) || $manualPeriod < '2026-07') {
            flash('error', 'Garanti Dumanlar hesabından düşmek için Temmuz 2026 veya sonrasındaki geçerli bir ay seçin.');
            redirect('maaslar.php#manuel-aylik-maaslar');
        }
        $manualMonth = substr($manualPeriod, 5, 2);
        $manualField = 'manual_amount_' . $manualMonth;
        $manualRaw = trim((string)($_POST[$manualField] ?? ''));
        if ($manualRaw === '') {
            flash('error', 'Önce ' . month_label($manualPeriod) . ' için manuel maaş toplamını girin.');
            redirect('maaslar.php?period=' . urlencode($manualPeriod) . '&salary_year=' . urlencode(substr($manualPeriod, 0, 4)) . '#manuel-aylik-maaslar');
        }
        try {
            $manualAmount = max(0, decimal_from_input($manualRaw));
            maas_manual_aylik_toplam_kaydet($manualPeriod, $manualAmount);
            maas_garanti_odeme_sync('2026-07', true, $manualPeriod);
            $linkStmt = db()->prepare('SELECT amount FROM salary_manual_account_links WHERE period=? LIMIT 1');
            $linkStmt->execute([$manualPeriod]);
            $deductedAmount = (float)($linkStmt->fetchColumn() ?: 0);
            audit_action('maas_manuel_banka', 0, 'garantiden_dusuldu', null, ['period'=>$manualPeriod, 'manual_amount'=>$manualAmount, 'bank_amount'=>$deductedAmount], $manualPeriod);
            flash('success', month_label($manualPeriod) . ' manuel maaş toplamı kaydedildi; personel bazlı ödemeler sonrası ' . money($deductedAmount) . ' Garanti Dumanlar hesabından düşüldü.');
        } catch (Throwable $e) {
            flash('error', 'Maaş banka çıkışı oluşturulamadı: ' . $e->getMessage());
        }
        redirect('maaslar.php?period=' . urlencode($manualPeriod) . '&salary_year=' . urlencode(substr($manualPeriod, 0, 4)) . '#manuel-aylik-maaslar');
    }

    if ($action === 'save_manual_monthly_totals') {
        $manualYear = trim((string)($_POST['manual_year'] ?? date('Y')));
        if (!preg_match('/^\\d{4}$/', $manualYear)) $manualYear = date('Y');
        $oldSummary = maas_yillik_odenen_ozeti($manualYear);
        for ($month = 1; $month <= 12; $month++) {
            $periodKey = sprintf('%s-%02d', $manualYear, $month);
            $field = 'manual_amount_' . sprintf('%02d', $month);
            $raw = trim((string)($_POST[$field] ?? ''));
            maas_manual_aylik_toplam_kaydet($periodKey, $raw === '' ? null : decimal_from_input($raw));
        }
        $newSummary = maas_yillik_odenen_ozeti($manualYear);
        audit_action('maas_yillik_manuel', 0, 'guncellendi', $oldSummary, $newSummary, $manualYear);
        flash('success', $manualYear . ' aylık maaş toplamları kaydedildi.');
        redirect('maaslar.php?period=' . urlencode($manualYear . '-01') . '&salary_year=' . urlencode($manualYear) . '#manuel-aylik-maaslar');
    }

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

    if ($action === 'save_garnishment') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $accountId = ($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));
        try {
            maas_haciz_create($employeeId, $paymentDate, $amount, $accountId, $note);
            $periodForGarnishment = maas_avans_period($paymentDate);
            $warning = salary_refresh_after_advance($employeeId, $periodForGarnishment);
            flash('success', 'Maaş haczi ödemesi kaydedildi ve aynı ayın net maaşından düşüldü.');
            if ($warning) flash('warning', 'Ödeme kaydedildi; mevcut bordro daha sonra güncellenecek: ' . $warning);
            redirect('maaslar.php?period=' . urlencode($periodForGarnishment) . '&employee_id=' . $employeeId . '#maas-haczi');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('maaslar.php?period=' . urlencode(maas_avans_period($paymentDate)) . '#maas-haczi');
        }
    }

    if ($action === 'cancel_garnishment') {
        $row = maas_haciz_cancel((int)($_POST['id'] ?? 0), trim((string)($_POST['cancel_reason'] ?? '')));
        if ($row) {
            $periodForGarnishment = maas_avans_period((string)$row['payment_date']);
            $warning = salary_refresh_after_advance((int)$row['employee_id'], $periodForGarnishment);
            flash('success', 'Maaş haczi ödemesi iptal edildi; geçmiş kayıt ve banka iade hareketi korundu.');
            if ($warning) flash('warning', 'İptal kaydedildi; mevcut bordro daha sonra güncellenecek: ' . $warning);
            redirect('maaslar.php?period=' . urlencode($periodForGarnishment) . '&employee_id=' . (int)$row['employee_id'] . '#maas-haczi');
        }
        flash('error', 'Maaş haczi ödemesi bulunamadı veya daha önce iptal edilmiş.');
        redirect('maaslar.php#maas-haczi');
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
            'exit_date' => !empty($_POST['exit_date']) ? (string)$_POST['exit_date'] : null,
            'exit_reason' => trim((string)($_POST['exit_reason'] ?? '')),
            'note' => trim($_POST['note'] ?? ''),
        ];
        if ($payload['is_active'] === 1) {
            $payload['exit_date'] = null;
            $payload['exit_reason'] = '';
        } elseif (!$payload['exit_date']) {
            $payload['exit_date'] = date('Y-m-d');
        }
        if ($payload['full_name'] === '') { flash('error', 'Personel adı zorunlu.'); redirect('maaslar.php'); }
        if ($id > 0) {
            $old = db()->prepare('SELECT * FROM salary_employees WHERE id=?'); $old->execute([$id]); $oldRow = $old->fetch();
            db()->prepare('UPDATE salary_employees SET full_name=:full_name, department=:department, position=:position, phone=:phone, start_date=:start_date, base_salary=:base_salary, is_active=:is_active, exit_date=:exit_date, exit_reason=:exit_reason, note=:note, updated_at=:updated_at WHERE id=:id')
                ->execute($payload + ['updated_at'=>now(), 'id'=>$id]);
            audit_action('maas_personel', $id, 'guncellendi', $oldRow, $payload, $payload['full_name']);
            flash('success', 'Personel güncellendi.');
        } else {
            db()->prepare('INSERT INTO salary_employees (full_name, department, position, phone, start_date, base_salary, is_active, exit_date, exit_reason, note, created_by, created_at, updated_at) VALUES (:full_name,:department,:position,:phone,:start_date,:base_salary,:is_active,:exit_date,:exit_reason,:note,:created_by,:created_at,:updated_at)')
                ->execute($payload + ['created_by'=>current_user()['id'] ?? null, 'created_at'=>now(), 'updated_at'=>now()]);
            $newId = (int)db()->lastInsertId();
            audit_action('maas_personel', $newId, 'eklendi', null, $payload, $payload['full_name']);
            flash('success', 'Personel eklendi.');
        }
        redirect('maaslar.php');
    }

    if ($action === 'deactivate_employee') {
        $id = (int)($_POST['id'] ?? 0);
        $exitDate = trim((string)($_POST['exit_date'] ?? date('Y-m-d')));
        $exitReason = trim((string)($_POST['exit_reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exitDate)) $exitDate = date('Y-m-d');
        $stmt = db()->prepare('SELECT * FROM salary_employees WHERE id=?');
        $stmt->execute([$id]);
        $oldRow = $stmt->fetch() ?: null;
        if ($oldRow) {
            db()->prepare('UPDATE salary_employees SET is_active=0, exit_date=?, exit_reason=?, updated_at=? WHERE id=?')
                ->execute([$exitDate, $exitReason, now(), $id]);
            $newRow = $oldRow;
            $newRow['is_active'] = 0; $newRow['exit_date'] = $exitDate; $newRow['exit_reason'] = $exitReason;
            audit_action('maas_personel', $id, 'cikis_yapti', $oldRow, $newRow, (string)$oldRow['full_name']);
            flash('success', $oldRow['full_name'] . ' çıkış yaptı olarak işaretlendi. Geçmiş maaş ve puantaj kayıtları korundu.');
        }
        redirect('maaslar.php?employee_view=inactive');
    }

    if ($action === 'reactivate_employee') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM salary_employees WHERE id=?');
        $stmt->execute([$id]);
        $oldRow = $stmt->fetch() ?: null;
        if ($oldRow) {
            db()->prepare('UPDATE salary_employees SET is_active=1, exit_date=NULL, exit_reason=NULL, updated_at=? WHERE id=?')->execute([now(), $id]);
            audit_action('maas_personel', $id, 'yeniden_aktif', $oldRow, ['is_active'=>1], (string)$oldRow['full_name']);
            flash('success', $oldRow['full_name'] . ' yeniden aktif personele alındı.');
        }
        redirect('maaslar.php');
    }

    if ($action === 'save_salary') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $periodForSalary = trim($_POST['period'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $periodForSalary)) $periodForSalary = date('Y-m');
        try {
            $plan = maas_aylik_plan_effective($employeeId, $periodForSalary);
            if ((float)($plan['daily_wage'] ?? 0) > 0) {
                $_POST['salary_amount'] = round((float)$plan['daily_wage'] * 30, 2);
            }
            maas_aylik_kayit_save($employeeId, $periodForSalary, $_POST, true);
            flash('success', 'Maaş, avans toplamı, puantaj ve bordro kaydı güncellendi. Yevmiye tanımlıysa aylık hesap yevmiye × 30 üzerinden alındı.');
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
$employeeView = (string)($_GET['employee_view'] ?? '') === 'inactive' ? 'inactive' : 'active';
$salaryYear = trim((string)($_GET['salary_year'] ?? substr($period, 0, 4)));
if (!preg_match('/^\\d{4}$/', $salaryYear)) $salaryYear = date('Y');
$salaryAnnualSummary = maas_yillik_odenen_ozeti($salaryYear);
$manualAccountLinks = [];
try {
    $manualLinkStmt = db()->prepare('SELECT period, amount, account_transaction_id FROM salary_manual_account_links WHERE period BETWEEN ? AND ?');
    $manualLinkStmt->execute([$salaryYear . '-01', $salaryYear . '-12']);
    foreach ($manualLinkStmt->fetchAll() as $manualLink) $manualAccountLinks[(string)$manualLink['period']] = $manualLink;
} catch (Throwable $e) {
    $manualAccountLinks = [];
}
$salaryMonthNames = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];

$employees = db()->query('SELECT * FROM salary_employees ORDER BY is_active DESC, full_name ASC')->fetchAll();
$accounts = accounts_for_select(true);
$activeEmployees = array_values(array_filter($employees, fn($e) => (int)($e['is_active'] ?? 0) === 1));
$inactiveEmployees = array_values(array_filter($employees, fn($e) => (int)($e['is_active'] ?? 0) === 0));
$visibleEmployees = $employeeView === 'inactive' ? $inactiveEmployees : $activeEmployees;
$monthlyPlans = maas_aylik_plan_rows($activeEmployees, $period);
$planBankTotal = $planCashTotal = $planWageMonthlyTotal = 0.0;
foreach ($activeEmployees as $employee) {
    $plan = $monthlyPlans[(int)$employee['id']] ?? [];
    $planBankTotal += (float)($plan['bank_amount'] ?? 0);
    $planCashTotal += (float)($plan['cash_amount'] ?? 0);
    $planWageMonthlyTotal += (float)($plan['daily_wage'] ?? 0) * 30;
}

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

$garnishmentRows = maas_haciz_period_rows($period, $employeeFilter);
$garnishmentPeriodTotal = array_reduce($garnishmentRows, fn($sum, $row) => $sum + ((int)($row['is_cancelled'] ?? 0) === 0 ? (float)$row['amount'] : 0.0), 0.0);
$garnishmentDefaultDate = $period === date('Y-m') ? date('Y-m-d') : $period . '-05';

$sumSalary = $sumAdvance = $sumDeduction = $sumPaid = $sumRemaining = 0.0;
foreach ($records as $r) { $sumSalary += (float)$r['salary_amount']; $sumAdvance += (float)$r['advance_amount']; $sumDeduction += (float)$r['deduction_amount']; $sumPaid += (float)$r['paid_amount']; $sumRemaining += (float)$r['remaining_amount']; }

page_header('Maaşlar', 'maaslar');
?>
<style>
.salary-grid{display:grid;gap:16px;max-width:1500px;margin:0 auto}.salary-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff;box-shadow:0 18px 50px rgba(7,27,63,.10)}.salary-hero h2{margin:4px 0 6px;color:#fff;font-size:clamp(24px,3vw,36px)}.salary-hero p{margin:0;color:#e9f5ed}.salary-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.16);font-size:11px;font-weight:900;letter-spacing:.08em}.salary-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.salary-summary article{background:#fff;border:1px solid #e5dccf;border-radius:18px;padding:15px 16px;box-shadow:0 12px 30px rgba(7,27,63,.06)}.salary-summary span{font-size:11px;color:#8a6a26;font-weight:950;text-transform:uppercase}.salary-summary strong{display:block;margin-top:7px;color:#102818;font-size:22px}.salary-manual-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.salary-manual-month{display:grid;gap:6px;padding:13px;border:1px solid #e5dccf;border-radius:16px;background:#fff}.salary-manual-month>span{font-size:12px;font-weight:950;color:#102818}.salary-manual-month input{width:100%;min-height:42px;border:1px solid #e5dccf;border-radius:12px;padding:9px 11px}.salary-manual-month small{color:#776b5c;line-height:1.35}.salary-manual-deduct{min-height:34px;border:0;border-radius:10px;padding:7px 9px;background:#16482e;color:#fff;font-size:11px;font-weight:950;cursor:pointer}.salary-manual-deduct.done{background:#8a6a26}.salary-manual-tools{display:flex;align-items:end;justify-content:space-between;gap:12px;margin-top:14px}.salary-manual-tools label{display:grid;gap:5px;font-size:12px;font-weight:850}.salary-manual-tools select{min-height:42px;border:1px solid #e5dccf;border-radius:12px;padding:8px 10px;background:#fff}.salary-columns{display:grid;grid-template-columns:380px 1fr;gap:16px}.salary-card{background:#fff;border:1px solid #e5dccf;border-radius:22px;box-shadow:0 12px 34px rgba(7,27,63,.06);overflow:hidden}.salary-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.salary-card-head h3{margin:0;color:#102818}.salary-card-head span{color:#8a6a26;font-size:12px;font-weight:900}.salary-body{padding:16px 18px}.salary-form{display:grid;gap:11px}.salary-form label{display:grid;gap:6px;font-size:12px;color:#102818;font-weight:850}.salary-form input,.salary-form select,.salary-form textarea{min-height:42px;border:1px solid #e5dccf;border-radius:13px;padding:9px 11px;background:#fff;color:#102818;width:100%}.salary-form input[readonly]{background:#f2f0ea;color:#5f665f}.salary-form .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.salary-form label small{color:#776b5c;font-size:10px;font-weight:650}.salary-filter{display:grid;grid-template-columns:150px 1fr 160px auto;gap:8px;padding:12px 14px;border-bottom:1px solid #e5dccf}.salary-filter input,.salary-filter select,.salary-filter button{min-height:38px;border:1px solid #e5dccf;border-radius:999px;padding:7px 11px;background:#fff;color:#102818;font-weight:800}.salary-table-wrap{overflow:auto}.salary-table{width:100%;min-width:1050px;border-collapse:separate;border-spacing:0}.salary-table th{background:#16482e;color:#fff;text-align:left;padding:11px 12px;font-size:11px;text-transform:uppercase}.salary-table td{padding:12px;border-bottom:1px solid #e5dccf;vertical-align:top;font-size:13px}.salary-table b{display:block;color:#102818}.salary-table small{display:block;color:#776b5c;margin-top:3px}.salary-table tfoot td{background:#102818;color:#fff;font-weight:900}.salary-actions{display:flex;gap:6px;flex-wrap:wrap}.salary-actions a,.salary-actions button{border:1px solid #e5dccf;border-radius:999px;padding:6px 10px;background:#fff;color:#102818;text-decoration:none;font-size:12px;font-weight:900}.salary-actions button.danger{color:#b64242}.text-right{text-align:right}.salary-person-list{display:grid;gap:8px}.salary-person{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;padding:10px;border:1px solid #e5dccf;border-radius:14px;background:#fff}.salary-person strong{color:#102818}.salary-person small{display:block;color:#776b5c}.salary-person a{text-decoration:none;font-weight:900;color:#16482e}.salary-advance-card,.salary-garnishment-card{grid-column:1/-1;border-color:#d8bd7d}.salary-advance-card .salary-card-head{background:#fff8e7}.salary-garnishment-card .salary-card-head{background:#fff0f0}.garnishment-amount{color:#a33f35;font-size:15px}.garnishment-cancelled{opacity:.62;background:#faf8f4}.advance-layout{display:grid;grid-template-columns:390px minmax(0,1fr);gap:16px}.advance-info{margin:0;padding:10px 12px;border-radius:12px;background:#edf5ef;color:#16482e;font-size:11px}.advance-table{min-width:760px}.advance-amount{color:#8a6114;font-size:15px}
.salary-plan-card{border-color:#b7d6c1}.salary-plan-card .salary-card-head{background:linear-gradient(135deg,#edf8f0,#fff)}.salary-plan-head-tools{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.salary-plan-head-tools label{display:grid;gap:4px;font-size:10px;font-weight:900;color:#66736b}.salary-plan-head-tools input{min-height:38px;border:1px solid #bad2c1;border-radius:11px;padding:7px 10px;background:#fff;font-weight:900}.salary-plan-totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.salary-plan-total{padding:13px 14px;border-radius:15px;background:#123f2b;color:#fff}.salary-plan-total span{display:block;color:#c9dfd0;font-size:10px;font-weight:900;text-transform:uppercase}.salary-plan-total strong{display:block;margin-top:5px;font-size:20px}.salary-plan-table{min-width:930px}.salary-plan-table input{width:100%;min-width:120px;min-height:40px;border:1px solid #d8dfd9;border-radius:10px;padding:8px 10px;background:#fff;font-weight:800}.salary-plan-table input:focus{outline:none;border-color:#2d754d;box-shadow:0 0 0 3px rgba(45,117,77,.12)}.salary-plan-source{color:#8a6a26!important;font-weight:850}.salary-plan-save{display:flex;justify-content:space-between;gap:14px;align-items:center;padding-top:14px}.salary-plan-save small{max-width:760px;color:#66736b}.salary-plan-row-total{white-space:nowrap;font-weight:900;color:#16482e}
@media(max-width:1180px){.salary-columns{grid-template-columns:1fr}.salary-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.salary-filter{grid-template-columns:1fr 1fr}.advance-layout{grid-template-columns:1fr}}@media(max-width:1000px){.salary-manual-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.salary-plan-totals{grid-template-columns:1fr}}@media(max-width:700px){.salary-manual-grid{grid-template-columns:1fr}.salary-manual-tools{align-items:stretch;flex-direction:column}.salary-manual-tools .btn{width:100%}.salary-hero{display:block}.salary-summary{grid-template-columns:1fr}.salary-form .two,.salary-filter{grid-template-columns:1fr}.salary-plan-head-tools{width:100%}.salary-plan-head-tools label{width:100%}.salary-plan-head-tools input{width:100%}.salary-plan-save{align-items:stretch;flex-direction:column}.salary-plan-save .btn{width:100%}}
</style>
<style>
.salary-grid.salary-pending{visibility:hidden;height:0;overflow:hidden}.salary-page-loading{display:grid;place-items:center;min-height:220px;border:1px solid #e5dccf;border-radius:22px;background:#fff;color:#16482e;font-weight:900}.salary-hero-tools{display:grid;gap:9px;justify-items:end}.salary-excel-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border-radius:999px;background:#fff;color:#16482e;text-decoration:none;font-size:12px;font-weight:950}.salary-person-tabs{display:flex;gap:7px;margin:10px 0 13px}.salary-person-tabs a{padding:7px 11px;border:1px solid #e5dccf;border-radius:999px;color:#16482e;text-decoration:none;font-size:12px;font-weight:900}.salary-person-tabs a.active{background:#16482e;color:#fff;border-color:#16482e}.salary-person-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.salary-person-actions>a,.salary-person-actions button{border:1px solid #e5dccf;border-radius:999px;padding:6px 9px;background:#fff;color:#16482e;text-decoration:none;font-size:11px;font-weight:900}.salary-person-actions button.exit{color:#a33f35}.salary-exit-form{display:flex;gap:5px;align-items:center;flex-wrap:wrap;grid-column:1/-1;padding-top:8px;border-top:1px dashed #e5dccf}.salary-exit-form input{min-height:34px;border:1px solid #e5dccf;border-radius:10px;padding:6px 8px;font-size:11px}.salary-exit-form input[type=date]{width:132px}.salary-exit-form input[type=text]{flex:1;min-width:130px}.salary-exited{background:#faf8f4}.salary-exited-label{color:#a33f35;font-weight:900}.salary-person-empty{margin:0;color:#776b5c}.salary-person-form-toggle{display:none}.salary-top-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.salary-top-person-button{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid rgba(255,255,255,.35);border-radius:999px;background:#f0c86f;color:#173422;font-size:12px;font-weight:950;cursor:pointer}
@media(max-width:700px){
.salary-hero-tools{justify-items:start;margin-top:14px}.salary-person{grid-template-columns:1fr}.salary-person-actions{justify-content:flex-start}.salary-exit-form input{width:100%!important}
.salary-grid{display:flex!important;flex-direction:column;gap:14px}.salary-columns{display:contents!important}
.salary-hero{order:1}.salary-summary{order:2;width:100%}.salary-plan-card{order:3;width:100%}#manuel-aylik-maaslar{order:4;width:100%}.salary-employee-card{order:5;width:100%}.salary-records-card{order:6;width:100%}.salary-advance-card{order:7;width:100%}.salary-garnishment-card{order:8;width:100%}
.salary-top-actions{display:grid;grid-template-columns:1fr 1fr;width:100%;gap:8px}.salary-top-actions>*{width:100%;box-sizing:border-box;text-align:center}.salary-person-card-toggle{display:none!important}
.salary-employee-card .salary-card-head{display:grid;align-items:stretch}
.salary-person-form-body{display:none}.salary-person-form-body.is-open{display:block}
.salary-person-list{width:100%;min-width:0}.salary-person{width:100%;min-width:0;box-sizing:border-box}.salary-person>div{min-width:0}.salary-person strong,.salary-person small{overflow-wrap:anywhere}
.salary-table-wrap{width:100%;max-width:100%;min-width:0;overflow:visible}
.salary-plan-table,.salary-records-card .salary-table,.salary-advance-card .salary-table,.salary-garnishment-card .salary-table{width:100%!important;min-width:0!important;display:block}
.salary-plan-table thead,.salary-plan-table tfoot,.salary-records-card .salary-table thead,.salary-records-card .salary-table tfoot,.salary-advance-card .salary-table thead,.salary-advance-card .salary-table tfoot,.salary-garnishment-card .salary-table thead,.salary-garnishment-card .salary-table tfoot{display:none}
.salary-plan-table tbody,.salary-records-card .salary-table tbody,.salary-advance-card .salary-table tbody,.salary-garnishment-card .salary-table tbody{display:grid;gap:10px;width:100%}
.salary-plan-table tr,.salary-records-card .salary-table tr,.salary-advance-card .salary-table tr,.salary-garnishment-card .salary-table tr{display:grid;width:100%;padding:12px;border:1px solid #e5dccf;border-radius:15px;background:#fff;box-sizing:border-box;overflow:hidden}
.salary-plan-table td,.salary-records-card .salary-table td,.salary-advance-card .salary-table td,.salary-garnishment-card .salary-table td{display:grid!important;grid-template-columns:105px minmax(0,1fr);gap:10px;align-items:start;width:100%;padding:7px 2px!important;border:0!important;text-align:left!important;box-sizing:border-box;min-width:0;overflow-wrap:anywhere}
.salary-plan-table td:before,.salary-records-card .salary-table td:before,.salary-advance-card .salary-table td:before,.salary-garnishment-card .salary-table td:before{font-size:10px;font-weight:900;color:#776b5c;text-transform:uppercase}
.salary-plan-table td:nth-child(1):before,.salary-records-card .salary-table td:nth-child(1):before,.salary-advance-card .salary-table td:nth-child(2):before,.salary-garnishment-card .salary-table td:nth-child(2):before{content:'Personel'}
.salary-plan-table td:nth-child(2):before{content:'Günlük yevmiye'}.salary-plan-table td:nth-child(3):before{content:'Bankaya yatacak'}.salary-plan-table td:nth-child(4):before{content:'Elden verilecek'}.salary-plan-table td:nth-child(5):before{content:'Plan toplamı'}.salary-plan-table td:nth-child(6):before{content:'Not'}
.salary-records-card .salary-table td:nth-child(2):before{content:'Dönem'}.salary-records-card .salary-table td:nth-child(3):before{content:'Maaş'}.salary-records-card .salary-table td:nth-child(4):before{content:'Avans / Kesinti'}.salary-records-card .salary-table td:nth-child(5):before{content:'Ödenen'}.salary-records-card .salary-table td:nth-child(6):before{content:'Kalan'}.salary-records-card .salary-table td:nth-child(7):before{content:'Durum'}.salary-records-card .salary-table td:nth-child(8):before{content:'Hesap'}.salary-records-card .salary-table td:nth-child(9):before{content:'İşlem'}
.salary-advance-card .salary-table td:nth-child(1):before,.salary-garnishment-card .salary-table td:nth-child(1):before{content:'Tarih'}.salary-advance-card .salary-table td:nth-child(3):before{content:'Avans'}.salary-garnishment-card .salary-table td:nth-child(3):before{content:'Haciz'}.salary-advance-card .salary-table td:nth-child(4):before,.salary-garnishment-card .salary-table td:nth-child(4):before{content:'Hesap'}.salary-advance-card .salary-table td:nth-child(5):before,.salary-garnishment-card .salary-table td:nth-child(5):before{content:'Açıklama'}.salary-advance-card .salary-table td:nth-child(6):before,.salary-garnishment-card .salary-table td:nth-child(6):before{content:'İşlem'}
.salary-plan-table input{width:100%;min-width:0}.salary-actions,.salary-person-actions{min-width:0}
.salary-records-card .empty,.salary-plan-table .empty,.salary-advance-card .empty,.salary-garnishment-card .empty{display:block!important}
.salary-records-card .empty:before,.salary-plan-table .empty:before,.salary-advance-card .empty:before,.salary-garnishment-card .empty:before{display:none}
}
</style>
<div class="salary-page-loading" id="salaryPageLoading">Maaş ve personel ekranı hazırlanıyor…</div>
<noscript><style>.salary-page-loading{display:none}.salary-grid.salary-pending{visibility:visible;height:auto;overflow:visible}</style></noscript>
<div class="salary-grid salary-pending">
  <section class="salary-hero"><div><span>PERSONEL MAAŞ TAKİBİ</span><h2>Maaş, avans ve ödeme durumunu takip et.</h2><p>Net maaş referansları sistemde korunur; aylık hesap yevmiye planıyla yönetilir.</p></div><div class="salary-hero-tools"><strong><?php echo e(month_label($period)); ?></strong><div class="salary-top-actions"><a class="salary-excel-link" href="maas-puantaj-toplu-excel.php?period=<?php echo e(urlencode($period)); ?>&amp;include_inactive=1" download>Bordro / Maaş çizelgesi</a><button type="button" class="salary-top-person-button" data-personel-form-toggle>Yeni personel ekle</button></div></div></section>
  <section class="salary-summary">
    <article><span>Personel</span><strong><?php echo count($activeEmployees); ?></strong></article>
    <article><span>Bu ay maaş</span><strong><?php echo e(money($sumSalary)); ?></strong></article>
    <article><span>Avans/kesinti</span><strong><?php echo e(money($sumAdvance + $sumDeduction)); ?></strong></article>
    <article><span>Ödenen</span><strong><?php echo e(money($sumPaid)); ?></strong></article>
    <article><span>Kalan</span><strong><?php echo e(money($sumRemaining)); ?></strong></article>
  </section>

  <section class="salary-card salary-plan-card" id="aylik-yevmiye-plani">
    <div class="salary-card-head">
      <div><h3>Aylık yevmiye ve ödeme planı</h3><span>Yevmiyeyi, bankaya yatacak ve elden verilecek tutarı personel bazında elle gir.</span></div>
      <div class="salary-plan-head-tools"><label>Dönem<input type="month" value="<?php echo e($period); ?>" onchange="location.href='maaslar.php?period='+this.value+'#aylik-yevmiye-plani'"></label></div>
    </div>
    <div class="salary-body">
      <div class="salary-plan-totals">
        <div class="salary-plan-total"><span>30 günlük yevmiye karşılığı</span><strong><?php echo e(money($planWageMonthlyTotal)); ?></strong></div>
        <div class="salary-plan-total"><span>Bankaya yatacak toplam</span><strong><?php echo e(money($planBankTotal)); ?></strong></div>
        <div class="salary-plan-total"><span>Elden verilecek toplam</span><strong><?php echo e(money($planCashTotal)); ?></strong></div>
      </div>
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_monthly_payment_plans"><input type="hidden" name="plan_period" value="<?php echo e($period); ?>">
        <div class="salary-table-wrap"><table class="salary-table salary-plan-table"><thead><tr><th>Personel</th><th>Günlük yevmiye</th><th>Bankaya yatacak</th><th>Elden verilecek</th><th>Plan toplamı</th><th>Not</th></tr></thead><tbody>
          <?php if(!$activeEmployees): ?><tr><td colspan="6" class="empty">Aktif personel bulunmuyor.</td></tr><?php endif; ?>
          <?php foreach($activeEmployees as $emp): $plan=$monthlyPlans[(int)$emp['id']] ?? []; $planInherited=(int)($plan['is_inherited'] ?? 0)===1; $sourcePeriod=(string)($plan['source_period'] ?? ''); $planTotal=(float)($plan['bank_amount'] ?? 0)+(float)($plan['cash_amount'] ?? 0); ?>
          <tr>
            <td><b><?php echo e($emp['full_name']); ?></b><small><?php echo e(trim(($emp['department'] ?? '').' '.($emp['position'] ?? '')) ?: '-'); ?></small><?php if($planInherited): ?><small class="salary-plan-source"><?php echo $sourcePeriod !== '' ? e(month_label($sourcePeriod)).' ayından getirildi' : 'İlk değer referans net maaştan önerildi'; ?></small><?php else: ?><small>Bu aya kaydedildi</small><?php endif; ?></td>
            <td><input name="daily_wage[<?php echo e($emp['id']); ?>]" inputmode="decimal" value="<?php echo e(number_format((float)($plan['daily_wage'] ?? 0),2,',','.')); ?>" required></td>
            <td><input name="bank_amount[<?php echo e($emp['id']); ?>]" inputmode="decimal" value="<?php echo e(number_format((float)($plan['bank_amount'] ?? 0),2,',','.')); ?>"></td>
            <td><input name="cash_amount[<?php echo e($emp['id']); ?>]" inputmode="decimal" value="<?php echo e(number_format((float)($plan['cash_amount'] ?? 0),2,',','.')); ?>"></td>
            <td class="salary-plan-row-total"><?php echo e(money($planTotal)); ?></td>
            <td><input name="plan_note[<?php echo e($emp['id']); ?>]" value="<?php echo e($plan['note'] ?? ''); ?>" placeholder="İsteğe bağlı"></td>
          </tr>
          <?php endforeach; ?>
        </tbody></table></div>
        <div class="salary-plan-save"><small>Bu alan ödeme hareketi oluşturmaz. Sadece o ayın yevmiye ve banka/elden dağılım planını kaydeder. Sonraki ay açıldığında son kaydettiğin değerler otomatik gelir.</small><button class="btn btn-primary" type="submit"><?php echo e(month_label($period)); ?> planını kaydet</button></div>
      </form>
    </div>
  </section>

  <section class="salary-card" id="manuel-aylik-maaslar">
    <div class="salary-card-head"><div><h3>Aylık manuel ödenen maaşlar</h3><span>Eski dönem raporlama yapısı; kayıtlar korunur.</span></div><strong><?php echo e($salaryYear); ?> toplamı: <?php echo e(money($salaryAnnualSummary['total'])); ?></strong></div>
    <div class="salary-body">
      <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_manual_monthly_totals">
        <input type="hidden" name="manual_year" value="<?php echo e($salaryYear); ?>">
        <div class="salary-manual-grid">
          <?php foreach ($salaryAnnualSummary['months'] as $salaryMonth): $salaryMonthNumber=(int)substr($salaryMonth['period'],5,2); $manualLink=$manualAccountLinks[$salaryMonth['period']] ?? null; $manualDeducted=$manualLink && !empty($manualLink['account_transaction_id']); ?>
          <label class="salary-manual-month">
            <span><?php echo e($salaryMonthNames[$salaryMonthNumber]); ?></span>
            <input name="manual_amount_<?php echo e(sprintf('%02d',$salaryMonthNumber)); ?>" inputmode="decimal" placeholder="0,00" value="<?php echo $salaryMonth['manual_amount'] !== null ? e(number_format((float)$salaryMonth['manual_amount'],2,',','.')) : ''; ?>">
            <small><?php if ($salaryMonth['source']==='manual'): ?>Manuel toplam kullanılıyor<?php elseif ((float)$salaryMonth['detailed_amount']>0): ?>Personel kayıtları: <?php echo e(money($salaryMonth['detailed_amount'])); ?><?php else: ?>Henüz kayıt yok<?php endif; ?></small>
            <?php if ($salaryMonth['period'] >= '2026-07'): ?>
              <button class="salary-manual-deduct <?php echo $manualDeducted?'done':''; ?>" type="submit" name="deduct_manual_period" value="<?php echo e($salaryMonth['period']); ?>"><?php echo $manualDeducted ? 'Garanti’den güncelle' : 'Garanti Dumanlar’dan düş'; ?></button>
              <?php if ($manualDeducted): ?><small>Bankadan düşülen fark: <?php echo e(money((float)$manualLink['amount'])); ?></small><?php endif; ?>
            <?php endif; ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="salary-manual-tools">
          <label>Gösterilen yıl<select onchange="location.href='maaslar.php?salary_year='+this.value+'#manuel-aylik-maaslar'"><?php for($y=(int)date('Y')-3;$y<=(int)date('Y')+2;$y++): ?><option value="<?php echo e($y); ?>" <?php echo $y===(int)$salaryYear?'selected':''; ?>><?php echo e($y); ?></option><?php endfor; ?></select></label>
          <small>Bir ayı boş bırakırsan rapor, o ayın personel bazlı ödenen maaş kayıtlarını kullanır.</small>
          <button class="btn btn-primary" type="submit">Aylık toplamları kaydet</button>
        </div>
      </form>
    </div>
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

    <div class="salary-card salary-garnishment-card" id="maas-haczi">
      <div class="salary-card-head"><h3>Maaş haczi ödemeleri</h3><span><?php echo e(month_label($period)); ?> toplamı: <?php echo e(money($garnishmentPeriodTotal)); ?></span></div>
      <div class="salary-body advance-layout">
        <form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_garnishment">
          <div class="two"><label>Ödeme tarihi<input type="date" name="payment_date" value="<?php echo e($garnishmentDefaultDate); ?>" required></label><label>Personel<select name="employee_id" required><option value="">Personel seç</option><?php foreach($activeEmployees as $emp): ?><option value="<?php echo e($emp['id']); ?>" <?php echo $employeeFilter===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['full_name']); ?></option><?php endforeach; ?></select></label></div>
          <div class="two"><label>Haciz tutarı<input name="amount" inputmode="decimal" placeholder="0,00" required></label><label>Kasa/Banka hesabı<select name="account_id"><option value="">Sadece haciz kaydı</option><?php foreach($accounts as $acc): ?><option value="<?php echo e($acc['id']); ?>"><?php echo e($acc['name']); ?><?php echo !empty($acc['bank_name']) ? ' / '.e($acc['bank_name']) : ''; ?></option><?php endforeach; ?></select></label></div>
          <label>Açıklama<textarea name="note" rows="2" placeholder="Örn. İcra dosya numarası veya kurum açıklaması"></textarea></label>
          <p class="advance-info">Kaydedilen tutar aynı ayın net maaşından otomatik düşer. Kasa/Banka seçilirse ayrıca tek bir para çıkışı oluşturulur.</p>
          <button class="btn btn-primary">Maaş haczi ödemesini kaydet</button>
        </form>
        <div class="salary-table-wrap"><table class="salary-table advance-table"><thead><tr><th>Tarih</th><th>Personel</th><th class="text-right">Haciz</th><th>Hesap</th><th>Açıklama</th><th>Durum / İşlem</th></tr></thead><tbody>
          <?php if(!$garnishmentRows): ?><tr><td colspan="6" class="empty">Bu dönemde maaş haczi ödemesi yok.</td></tr><?php endif; ?>
          <?php foreach($garnishmentRows as $garnishment): $garnishmentCancelled=(int)($garnishment['is_cancelled'] ?? 0)===1; ?><tr class="<?php echo $garnishmentCancelled?'garnishment-cancelled':''; ?>"><td><?php echo e(tr_date($garnishment['payment_date'])); ?></td><td><b><?php echo e($garnishment['full_name']); ?></b><small><?php echo e(trim(($garnishment['department'] ?? '') . ' ' . ($garnishment['position'] ?? '')) ?: '-'); ?></small></td><td class="text-right"><b class="garnishment-amount"><?php echo e(money($garnishment['amount'])); ?></b></td><td><?php echo e($garnishment['account_name'] ?: 'Sadece kayıt'); ?><small><?php echo e($garnishment['bank_name'] ?: ''); ?></small></td><td><?php echo e($garnishment['note'] ?: '-'); ?></td><td><?php if($garnishmentCancelled): ?><b>İptal edildi</b><small><?php echo e($garnishment['cancel_reason'] ?: ''); ?></small><?php else: ?><form method="post" onsubmit="return confirm('Maaş haczi ödemesi iptal edilsin mi? Banka çıkışı varsa iade hareketi oluşturulur.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel_garnishment"><input type="hidden" name="id" value="<?php echo e($garnishment['id']); ?>"><input type="hidden" name="cancel_reason" value="Kullanıcı tarafından iptal edildi."><button class="danger">İptal et</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><td colspan="2">Aktif toplam</td><td class="text-right"><?php echo e(money($garnishmentPeriodTotal)); ?></td><td colspan="3"></td></tr></tfoot></table></div>
      </div>
    </div>

    <div class="salary-card salary-employee-card">
      <div class="salary-card-head"><h3>Personeller</h3><button type="button" class="btn btn-primary salary-person-form-toggle salary-person-card-toggle" data-personel-form-toggle><?php echo $editEmployee ? 'Düzenleme formunu aç' : 'Yeni personel ekle'; ?></button></div>
      <div class="salary-body salary-person-form-body <?php echo $editEmployee ? 'is-open' : ''; ?>" id="yeni-personel-formu"><form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_employee"><input type="hidden" name="id" value="<?php echo e($editEmployee['id'] ?? 0); ?>">
        <label>Ad soyad<input name="full_name" required value="<?php echo e($editEmployee['full_name'] ?? ''); ?>"></label>
        <div class="two"><label>Bölüm<input name="department" value="<?php echo e($editEmployee['department'] ?? ''); ?>"></label><label>Görev<input name="position" value="<?php echo e($editEmployee['position'] ?? ''); ?>"></label></div>
        <div class="two"><label>Telefon<input name="phone" value="<?php echo e($editEmployee['phone'] ?? ''); ?>"></label><label>Başlama tarihi<input type="date" name="start_date" value="<?php echo e($editEmployee['start_date'] ?? ''); ?>"></label></div>
        <label>Referans net maaş <small>Sadece sistemde referans olarak saklanır; aylık hesap yevmiyeden yapılır.</small><input name="base_salary" value="<?php echo e(isset($editEmployee['base_salary']) ? number_format((float)$editEmployee['base_salary'], 2, ',', '.') : ''); ?>" placeholder="0,00"></label>
        <?php if($editEmployee && (int)($editEmployee['is_active'] ?? 1) === 0): ?><div class="two"><label>Çıkış tarihi<input type="date" name="exit_date" value="<?php echo e($editEmployee['exit_date'] ?? ''); ?>"></label><label>Çıkış nedeni<input name="exit_reason" value="<?php echo e($editEmployee['exit_reason'] ?? ''); ?>"></label></div><?php endif; ?>
        <label>Not<textarea name="note" rows="2"><?php echo e($editEmployee['note'] ?? ''); ?></textarea></label>
        <label class="check"><input type="checkbox" name="is_active" <?php echo !isset($editEmployee['is_active']) || (int)$editEmployee['is_active']===1 ? 'checked' : ''; ?>> Aktif personel</label>
        <button class="btn btn-primary">Kaydet</button>
      </form></div>
      <div class="salary-body" style="border-top:1px solid #e5dccf"><h3>Personel listesi</h3>
        <nav class="salary-person-tabs"><a class="<?php echo $employeeView === 'active' ? 'active' : ''; ?>" href="maaslar.php">Aktif (<?php echo count($activeEmployees); ?>)</a><a class="<?php echo $employeeView === 'inactive' ? 'active' : ''; ?>" href="maaslar.php?employee_view=inactive">Çıkış yapanlar (<?php echo count($inactiveEmployees); ?>)</a></nav>
        <div class="salary-person-list">
          <?php foreach($visibleEmployees as $emp): ?><div class="salary-person <?php echo (int)$emp['is_active'] === 0 ? 'salary-exited' : ''; ?>"><div><strong><?php echo e($emp['full_name']); ?></strong><small><?php echo e(trim(($emp['department'] ?? '') . ' ' . ($emp['position'] ?? '')) ?: '-'); ?></small><?php if((int)$emp['is_active'] === 0): ?><small class="salary-exited-label">Çıkış yaptı<?php echo !empty($emp['exit_date']) ? ' · '.e(tr_date($emp['exit_date'])) : ''; ?><?php echo !empty($emp['exit_reason']) ? ' · '.e($emp['exit_reason']) : ''; ?></small><?php endif; ?></div><div class="salary-person-actions"><a href="maaslar.php?employee_view=<?php echo e($employeeView); ?>&amp;edit_employee=<?php echo e($emp['id']); ?>">Düzenle</a><?php if((int)$emp['is_active'] === 0): ?><form method="post" onsubmit="return confirm('Personel yeniden aktif listeye alınsın mı?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="reactivate_employee"><input type="hidden" name="id" value="<?php echo e($emp['id']); ?>"><button>Aktife al</button></form><?php endif; ?></div><?php if((int)$emp['is_active'] === 1): ?><form class="salary-exit-form" method="post" onsubmit="return confirm('Personel çıkış yaptı olarak pasife alınsın mı? Geçmiş maaş kayıtları korunacaktır.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="deactivate_employee"><input type="hidden" name="id" value="<?php echo e($emp['id']); ?>"><input type="date" name="exit_date" value="<?php echo e(date('Y-m-d')); ?>" required><input type="text" name="exit_reason" placeholder="Çıkış nedeni (isteğe bağlı)"><button class="exit">Çıkış yaptı</button></form><?php endif; ?></div><?php endforeach; ?>
          <?php if(!$visibleEmployees): ?><p class="salary-person-empty"><?php echo $employeeView === 'inactive' ? 'Henüz çıkış yapan personel yok.' : 'Henüz aktif personel yok.'; ?></p><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="salary-card salary-records-card">
      <div class="salary-card-head"><h3><?php echo $editSalary ? 'Maaş kaydı düzenle' : 'Aylık maaş kaydı'; ?></h3><?php if($editSalary): ?><a class="btn btn-secondary" href="maaslar.php?period=<?php echo e($period); ?>">Yeni kayıt</a><?php endif; ?></div>
      <div class="salary-body"><form class="salary-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_salary"><input type="hidden" name="id" value="<?php echo e($editSalary['id'] ?? 0); ?>">
        <div class="two"><label>Dönem<input type="month" name="period" value="<?php echo e($editSalary['period'] ?? $period); ?>"></label><label>Personel<select name="employee_id" required><option value="">Personel seç</option><?php foreach($activeEmployees as $emp): $empPlan=$monthlyPlans[(int)$emp['id']] ?? []; $empMonthly=(float)($empPlan['daily_wage'] ?? 0)*30; ?><option value="<?php echo e($emp['id']); ?>" data-salary="<?php echo e($empMonthly); ?>" <?php echo (int)($editSalary['employee_id'] ?? 0)===(int)$emp['id']?'selected':''; ?>><?php echo e($emp['full_name']); ?></option><?php endforeach; ?></select></label></div>
        <div class="two"><label>Aylık hesap (yevmiye × 30)<input name="salary_amount" readonly value="<?php echo e(isset($editSalary['salary_amount']) ? number_format((float)$editSalary['salary_amount'], 2, ',', '.') : ''); ?>" placeholder="Yevmiye planından gelir" required><small>Bu tutar yukarıdaki aylık yevmiye planından hesaplanır.</small></label><label>Avans toplamı (otomatik)<input name="advance_amount" readonly value="<?php echo e(isset($editSalary['employee_id']) ? number_format(maas_avans_period_total((int)$editSalary['employee_id'], (string)($editSalary['period'] ?? $period)), 2, ',', '.') : '0,00'); ?>"><small>Tarihli avans hareketlerinden otomatik gelir.</small></label></div>
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
(function(){
  if(!window.matchMedia('(max-width: 900px)').matches) return;
  var grid=document.querySelector('.salary-grid');
  var monthly=grid&&grid.querySelector('.salary-records-card');
  var advance=grid&&grid.querySelector('.salary-advance-card');
  if(monthly&&advance&&advance.parentNode) advance.parentNode.insertBefore(monthly,advance);
})();
document.addEventListener('click', function(e){
  var toggle=e.target.closest('[data-personel-form-toggle]');
  if(!toggle) return;
  var panel=document.querySelector('.salary-person-form-body');
  if(!panel) return;
  panel.classList.toggle('is-open');
  toggle.textContent=panel.classList.contains('is-open') ? 'Personel formunu kapat' : 'Yeni personel ekle';
  if(panel.classList.contains('is-open')) panel.scrollIntoView({behavior:'smooth',block:'start'});
});
document.addEventListener('change', function(e){
  if(e.target && e.target.name === 'employee_id'){
    var opt = e.target.selectedOptions && e.target.selectedOptions[0];
    var salary = opt ? Number(opt.getAttribute('data-salary') || 0) : 0;
    var form = e.target.closest('form');
    var input = form && form.querySelector('input[name="salary_amount"]');
    if(input && salary > 0){ input.value = new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(salary); }
  }
});
document.addEventListener('input', function(e){
  if(!e.target || (!e.target.name.startsWith('bank_amount[') && !e.target.name.startsWith('cash_amount['))) return;
  var row=e.target.closest('tr'); if(!row) return;
  function val(sel){var x=row.querySelector(sel); if(!x)return 0; var s=String(x.value||'').replace(/\./g,'').replace(',','.'); return Number(s)||0;}
  var total=val('input[name^="bank_amount["]')+val('input[name^="cash_amount["]');
  var cell=row.querySelector('.salary-plan-row-total'); if(cell) cell.textContent=new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(total)+' TL';
});
</script>
<?php page_footer(); ?>
