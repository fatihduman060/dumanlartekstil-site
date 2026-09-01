<?php

function maas_aylik_plan_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_monthly_payment_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        period TEXT NOT NULL,
        daily_wage REAL NOT NULL DEFAULT 0,
        bank_amount REAL NOT NULL DEFAULT 0,
        cash_amount REAL NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE(employee_id, period)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_monthly_plan_period ON salary_monthly_payment_plans(period)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_salary_monthly_plan_employee ON salary_monthly_payment_plans(employee_id, period)");
}

function maas_aylik_plan_period(string $period): string
{
    $period = trim($period);
    return preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
}

function maas_aylik_plan_exact(int $employeeId, string $period): ?array
{
    maas_aylik_plan_db_ensure();
    $stmt = db()->prepare('SELECT * FROM salary_monthly_payment_plans WHERE employee_id=? AND period=? LIMIT 1');
    $stmt->execute([$employeeId, maas_aylik_plan_period($period)]);
    return $stmt->fetch() ?: null;
}

function maas_aylik_plan_effective(int $employeeId, string $period, ?array $employee = null): array
{
    maas_aylik_plan_db_ensure();
    $period = maas_aylik_plan_period($period);
    $exact = maas_aylik_plan_exact($employeeId, $period);
    if ($exact) {
        $exact['is_inherited'] = 0;
        $exact['source_period'] = $period;
        return $exact;
    }

    $stmt = db()->prepare('SELECT * FROM salary_monthly_payment_plans WHERE employee_id=? AND period<? ORDER BY period DESC, id DESC LIMIT 1');
    $stmt->execute([$employeeId, $period]);
    $previous = $stmt->fetch() ?: null;
    if ($previous) {
        $previous['id'] = 0;
        $previous['period'] = $period;
        $previous['is_inherited'] = 1;
        $previous['source_period'] = (string)($previous['period_original'] ?? '');
        // Kaynak dönemi sorgu satırından kaybetmemek için yeniden bul.
        $srcStmt = db()->prepare('SELECT period FROM salary_monthly_payment_plans WHERE employee_id=? AND period<? ORDER BY period DESC, id DESC LIMIT 1');
        $srcStmt->execute([$employeeId, $period]);
        $previous['source_period'] = (string)($srcStmt->fetchColumn() ?: '');
        return $previous;
    }

    if (!$employee) {
        $stmt = db()->prepare('SELECT * FROM salary_employees WHERE id=? LIMIT 1');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch() ?: [];
    }
    $baseSalary = max(0, (float)($employee['base_salary'] ?? 0));
    return [
        'id' => 0,
        'employee_id' => $employeeId,
        'period' => $period,
        'daily_wage' => $baseSalary > 0 ? round($baseSalary / 30, 2) : 0,
        'bank_amount' => 0,
        'cash_amount' => 0,
        'note' => '',
        'is_inherited' => 1,
        'source_period' => '',
    ];
}

function maas_aylik_plan_save(int $employeeId, string $period, float $dailyWage, float $bankAmount, float $cashAmount, string $note = ''): int
{
    maas_aylik_plan_db_ensure();
    $period = maas_aylik_plan_period($period);
    $dailyWage = max(0, round($dailyWage, 2));
    $bankAmount = max(0, round($bankAmount, 2));
    $cashAmount = max(0, round($cashAmount, 2));
    if ($employeeId <= 0) throw new RuntimeException('Personel seçimi geçersiz.');
    if ($dailyWage <= 0) throw new RuntimeException('Günlük yevmiye sıfırdan büyük olmalıdır.');

    $employeeStmt = db()->prepare('SELECT id, full_name FROM salary_employees WHERE id=? LIMIT 1');
    $employeeStmt->execute([$employeeId]);
    $employee = $employeeStmt->fetch();
    if (!$employee) throw new RuntimeException('Personel bulunamadı.');

    $old = maas_aylik_plan_exact($employeeId, $period);
    $now = now();
    if ($old) {
        db()->prepare('UPDATE salary_monthly_payment_plans SET daily_wage=?, bank_amount=?, cash_amount=?, note=?, updated_at=? WHERE id=?')
            ->execute([$dailyWage, $bankAmount, $cashAmount, trim($note), $now, (int)$old['id']]);
        $id = (int)$old['id'];
    } else {
        db()->prepare('INSERT INTO salary_monthly_payment_plans (employee_id,period,daily_wage,bank_amount,cash_amount,note,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$employeeId, $period, $dailyWage, $bankAmount, $cashAmount, trim($note), current_user()['id'] ?? null, $now, $now]);
        $id = (int)db()->lastInsertId();
    }

    audit_action('maas_aylik_plan', $id, $old ? 'guncellendi' : 'eklendi', $old, [
        'employee_id' => $employeeId,
        'period' => $period,
        'daily_wage' => $dailyWage,
        'bank_amount' => $bankAmount,
        'cash_amount' => $cashAmount,
        'note' => trim($note),
    ], (string)$employee['full_name'] . ' / ' . $period);
    return $id;
}

function maas_aylik_plan_rows(array $employees, string $period): array
{
    $rows = [];
    foreach ($employees as $employee) {
        $employeeId = (int)($employee['id'] ?? 0);
        if ($employeeId <= 0) continue;
        $rows[$employeeId] = maas_aylik_plan_effective($employeeId, $period, $employee);
    }
    return $rows;
}
