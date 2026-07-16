<?php

function maas_puantaj_db_ensure(): void
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_attendance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        work_date TEXT NOT NULL,
        status TEXT NOT NULL,
        overtime_hours REAL NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE(employee_id, work_date)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_payroll_details (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        period TEXT NOT NULL,
        salary_record_id INTEGER,
        base_salary REAL NOT NULL DEFAULT 0,
        work_days REAL NOT NULL DEFAULT 0,
        paid_leave_days REAL NOT NULL DEFAULT 0,
        report_days REAL NOT NULL DEFAULT 0,
        absent_days REAL NOT NULL DEFAULT 0,
        weekly_off_days REAL NOT NULL DEFAULT 0,
        holiday_days REAL NOT NULL DEFAULT 0,
        overtime_hours REAL NOT NULL DEFAULT 0,
        overtime_amount REAL NOT NULL DEFAULT 0,
        bonus_amount REAL NOT NULL DEFAULT 0,
        other_addition_amount REAL NOT NULL DEFAULT 0,
        absence_deduction_amount REAL NOT NULL DEFAULT 0,
        other_deduction_amount REAL NOT NULL DEFAULT 0,
        advance_amount REAL NOT NULL DEFAULT 0,
        gross_earning REAL NOT NULL DEFAULT 0,
        net_payable REAL NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(salary_record_id) REFERENCES salary_records(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE(employee_id, period)
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_attendance_employee_date ON salary_attendance(employee_id, work_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_payroll_period ON salary_payroll_details(period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_payroll_employee ON salary_payroll_details(employee_id)");
}

function maas_puantaj_statuses(): array
{
    return [
        'calisti' => ['label' => 'Çalıştı', 'short' => 'Ç'],
        'izinli' => ['label' => 'İzinli', 'short' => 'İ'],
        'raporlu' => ['label' => 'Raporlu', 'short' => 'R'],
        'gelmedi' => ['label' => 'Gelmedi', 'short' => 'G'],
        'hafta_tatili' => ['label' => 'Hafta tatili', 'short' => 'H'],
        'resmi_tatil' => ['label' => 'Resmî tatil', 'short' => 'T'],
    ];
}

function maas_puantaj_period(string $period): string
{
    return preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
}

function maas_puantaj_period_bounds(string $period): array
{
    $period = maas_puantaj_period($period);
    $start = $period . '-01';
    return [$start, date('Y-m-t', strtotime($start))];
}

function maas_puantaj_employee(int $employeeId): ?array
{
    if ($employeeId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM salary_employees WHERE id=? LIMIT 1');
    $stmt->execute([$employeeId]);
    return $stmt->fetch() ?: null;
}

function maas_puantaj_entries(int $employeeId, string $period): array
{
    [$start, $end] = maas_puantaj_period_bounds($period);
    $stmt = db()->prepare('SELECT work_date, status, overtime_hours, note FROM salary_attendance WHERE employee_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date ASC');
    $stmt->execute([$employeeId, $start, $end]);
    $entries = [];
    foreach ($stmt->fetchAll() as $row) {
        $entries[$row['work_date']] = [
            'status' => (string)$row['status'],
            'overtime_hours' => (float)($row['overtime_hours'] ?? 0),
            'note' => (string)($row['note'] ?? ''),
        ];
    }
    return $entries;
}

function maas_puantaj_summary(int $employeeId, string $period): array
{
    [$start, $end] = maas_puantaj_period_bounds($period);
    $stmt = db()->prepare("SELECT
        COUNT(*) AS recorded_days,
        COALESCE(SUM(CASE WHEN status='calisti' THEN 1 ELSE 0 END),0) AS work_days,
        COALESCE(SUM(CASE WHEN status='izinli' THEN 1 ELSE 0 END),0) AS paid_leave_days,
        COALESCE(SUM(CASE WHEN status='raporlu' THEN 1 ELSE 0 END),0) AS report_days,
        COALESCE(SUM(CASE WHEN status='gelmedi' THEN 1 ELSE 0 END),0) AS absent_days,
        COALESCE(SUM(CASE WHEN status='hafta_tatili' THEN 1 ELSE 0 END),0) AS weekly_off_days,
        COALESCE(SUM(CASE WHEN status='resmi_tatil' THEN 1 ELSE 0 END),0) AS holiday_days,
        COALESCE(SUM(overtime_hours),0) AS overtime_hours
        FROM salary_attendance
        WHERE employee_id=? AND work_date BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $start, $end]);
    $row = $stmt->fetch() ?: [];
    return [
        'recorded_days' => (int)($row['recorded_days'] ?? 0),
        'work_days' => (float)($row['work_days'] ?? 0),
        'paid_leave_days' => (float)($row['paid_leave_days'] ?? 0),
        'report_days' => (float)($row['report_days'] ?? 0),
        'absent_days' => (float)($row['absent_days'] ?? 0),
        'weekly_off_days' => (float)($row['weekly_off_days'] ?? 0),
        'holiday_days' => (float)($row['holiday_days'] ?? 0),
        'overtime_hours' => (float)($row['overtime_hours'] ?? 0),
    ];
}

function maas_puantaj_payroll(int $employeeId, string $period): ?array
{
    $stmt = db()->prepare("SELECT spd.*, sr.paid_amount, sr.remaining_amount, sr.payment_date, sr.account_id, sr.status,
        a.name AS account_name, a.bank_name
        FROM salary_payroll_details spd
        LEFT JOIN salary_records sr ON sr.id=spd.salary_record_id
        LEFT JOIN accounts a ON a.id=sr.account_id
        WHERE spd.employee_id=? AND spd.period=? LIMIT 1");
    $stmt->execute([$employeeId, maas_puantaj_period($period)]);
    return $stmt->fetch() ?: null;
}

function maas_puantaj_calc_status(float $remaining, float $paid): string
{
    if ($remaining <= 0.004) return 'odendi';
    return $paid > 0 ? 'kismi' : 'bekliyor';
}

function maas_puantaj_sync_account_transaction(int $recordId): void
{
    $stmt = db()->prepare("SELECT sr.*, se.full_name, a.id AS account_exists
        FROM salary_records sr
        JOIN salary_employees se ON se.id=sr.employee_id
        LEFT JOIN accounts a ON a.id=sr.account_id
        WHERE sr.id=?");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $oldTxn = (int)($row['account_transaction_id'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $accountId = !empty($row['account_id']) ? (int)$row['account_id'] : 0;

    if ($paid <= 0 || $accountId <= 0 || empty($row['account_exists'])) {
        if ($oldTxn > 0) {
            db()->prepare('DELETE FROM account_transactions WHERE id=? AND source_type=?')->execute([$oldTxn, 'salary']);
        }
        db()->prepare('UPDATE salary_records SET account_transaction_id=NULL, updated_at=? WHERE id=?')->execute([now(), $recordId]);
        return;
    }

    $date = $row['payment_date'] ?: date('Y-m-d');
    $description = 'Maaş ödemesi: ' . ($row['full_name'] ?? '') . ' / ' . month_label($row['period'] ?? '');

    if ($oldTxn > 0) {
        db()->prepare("UPDATE account_transactions SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary', source_id=?, description=?, created_at=COALESCE(created_at, ?), created_by=COALESCE(created_by, ?) WHERE id=?")
            ->execute([$accountId, $paid, $date, $recordId, $description, now(), current_user()['id'] ?? null, $oldTxn]);
    } else {
        db()->prepare("INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, 'out', ?, ?, 'salary', ?, ?, ?, ?)")
            ->execute([$accountId, $paid, $date, $recordId, $description, current_user()['id'] ?? null, now()]);
        $newTxnId = (int)db()->lastInsertId();
        db()->prepare('UPDATE salary_records SET account_transaction_id=?, updated_at=? WHERE id=?')->execute([$newTxnId, now(), $recordId]);
    }
}

function maas_puantaj_save_payroll(int $employeeId, string $period, array $input): array
{
    $period = maas_puantaj_period($period);
    $employee = maas_puantaj_employee($employeeId);
    if (!$employee) throw new RuntimeException('Personel bulunamadı.');

    $summary = maas_puantaj_summary($employeeId, $period);
    $baseSalary = max(0, (float)($employee['base_salary'] ?? 0));
    $dailyRate = $baseSalary / 30;
    $absenceDeduction = round($summary['absent_days'] * $dailyRate, 2);
    $overtimeAmount = max(0, decimal_from_input($input['overtime_amount'] ?? 0));
    $bonusAmount = max(0, decimal_from_input($input['bonus_amount'] ?? 0));
    $otherAddition = max(0, decimal_from_input($input['other_addition_amount'] ?? 0));
    $otherDeduction = max(0, decimal_from_input($input['other_deduction_amount'] ?? 0));
    $advanceAmount = max(0, decimal_from_input($input['advance_amount'] ?? 0));
    $grossEarning = round($baseSalary + $overtimeAmount + $bonusAmount + $otherAddition, 2);
    $netPayable = max(0, round($grossEarning - $absenceDeduction - $otherDeduction - $advanceAmount, 2));
    $paidAmount = min($netPayable, max(0, decimal_from_input($input['paid_amount'] ?? 0)));
    $remainingAmount = max(0, round($netPayable - $paidAmount, 2));
    $status = maas_puantaj_calc_status($remainingAmount, $paidAmount);
    $paymentDate = trim((string)($input['payment_date'] ?? '')) ?: null;
    $accountId = trim((string)($input['account_id'] ?? '')) !== '' ? (int)$input['account_id'] : null;
    $note = trim((string)($input['note'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $detailStmt = $pdo->prepare('SELECT * FROM salary_payroll_details WHERE employee_id=? AND period=? LIMIT 1');
        $detailStmt->execute([$employeeId, $period]);
        $oldDetail = $detailStmt->fetch() ?: null;
        $recordId = (int)($oldDetail['salary_record_id'] ?? 0);

        if ($recordId <= 0) {
            $recordStmt = $pdo->prepare('SELECT id FROM salary_records WHERE employee_id=? AND period=? ORDER BY id DESC LIMIT 1');
            $recordStmt->execute([$employeeId, $period]);
            $recordId = (int)($recordStmt->fetchColumn() ?: 0);
        }

        $recordPayload = [
            $employeeId,
            $period,
            $grossEarning,
            $advanceAmount,
            round($absenceDeduction + $otherDeduction, 2),
            $paidAmount,
            $remainingAmount,
            $paymentDate,
            $accountId,
            $status,
            $note,
            now(),
        ];

        if ($recordId > 0) {
            $pdo->prepare('UPDATE salary_records SET employee_id=?, period=?, salary_amount=?, advance_amount=?, deduction_amount=?, paid_amount=?, remaining_amount=?, payment_date=?, account_id=?, status=?, note=?, updated_at=? WHERE id=?')
                ->execute(array_merge($recordPayload, [$recordId]));
        } else {
            $pdo->prepare('INSERT INTO salary_records (employee_id, period, salary_amount, advance_amount, deduction_amount, paid_amount, remaining_amount, payment_date, account_id, status, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge(array_slice($recordPayload, 0, 11), [current_user()['id'] ?? null, now(), now()]));
            $recordId = (int)$pdo->lastInsertId();
        }

        $detailPayload = [
            $recordId,
            $baseSalary,
            $summary['work_days'],
            $summary['paid_leave_days'],
            $summary['report_days'],
            $summary['absent_days'],
            $summary['weekly_off_days'],
            $summary['holiday_days'],
            $summary['overtime_hours'],
            $overtimeAmount,
            $bonusAmount,
            $otherAddition,
            $absenceDeduction,
            $otherDeduction,
            $advanceAmount,
            $grossEarning,
            $netPayable,
            $note,
            now(),
        ];

        if ($oldDetail) {
            $pdo->prepare('UPDATE salary_payroll_details SET salary_record_id=?, base_salary=?, work_days=?, paid_leave_days=?, report_days=?, absent_days=?, weekly_off_days=?, holiday_days=?, overtime_hours=?, overtime_amount=?, bonus_amount=?, other_addition_amount=?, absence_deduction_amount=?, other_deduction_amount=?, advance_amount=?, gross_earning=?, net_payable=?, note=?, updated_at=? WHERE id=?')
                ->execute(array_merge($detailPayload, [(int)$oldDetail['id']]));
            $payrollId = (int)$oldDetail['id'];
        } else {
            $pdo->prepare('INSERT INTO salary_payroll_details (employee_id, period, salary_record_id, base_salary, work_days, paid_leave_days, report_days, absent_days, weekly_off_days, holiday_days, overtime_hours, overtime_amount, bonus_amount, other_addition_amount, absence_deduction_amount, other_deduction_amount, advance_amount, gross_earning, net_payable, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge([$employeeId, $period], array_slice($detailPayload, 0, 18), [current_user()['id'] ?? null, now(), now()]));
            $payrollId = (int)$pdo->lastInsertId();
        }

        maas_puantaj_sync_account_transaction($recordId);
        audit_action('maas_bordro', $payrollId, $oldDetail ? 'guncellendi' : 'eklendi', $oldDetail, [
            'employee_id' => $employeeId,
            'period' => $period,
            'gross_earning' => $grossEarning,
            'net_payable' => $netPayable,
            'paid_amount' => $paidAmount,
        ], ($employee['full_name'] ?? '') . ' / ' . $period);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'payroll_id' => $payrollId,
        'salary_record_id' => $recordId,
        'gross_earning' => $grossEarning,
        'net_payable' => $netPayable,
        'paid_amount' => $paidAmount,
        'remaining_amount' => $remainingAmount,
        'absence_deduction_amount' => $absenceDeduction,
        'status' => $status,
    ];
}
