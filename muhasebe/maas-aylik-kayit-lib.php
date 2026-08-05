<?php
require_once __DIR__ . '/maas-avans-lib.php';
require_once __DIR__ . '/maas-haciz-lib.php';

function maas_aylik_kayit_db_ensure(): void
{
    maas_puantaj_db_ensure();
    $pdo = db();
    ensure_column($pdo, 'salary_records', 'absent_days', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'missing_hours', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'manual_deduction_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'garnishment_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_records', 'attendance_override_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_payroll_details', 'garnishment_amount', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'salary_payroll_details', 'source_mode', "TEXT NOT NULL DEFAULT 'puantaj'");
    maas_avans_db_ensure();
    maas_haciz_db_ensure();
}

function maas_aylik_kayit_key($value): string
{
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = strtr($value, [
        'ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c',
        'İ'=>'i','Ğ'=>'g','Ü'=>'u','Ş'=>'s','Ö'=>'o','Ç'=>'c',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
}

function maas_aylik_kayit_default_payment_date(string $period): string
{
    $period = maas_puantaj_period($period);
    return date('Y-m-05', strtotime($period . '-01 +1 month'));
}

function maas_aylik_kayit_default_account_id(): ?int
{
    foreach (accounts_for_select(true) as $account) {
        $key = maas_aylik_kayit_key(($account['name'] ?? '') . ' ' . ($account['bank_name'] ?? ''));
        if (strpos($key, 'garanti') !== false && strpos($key, 'dumanlar') !== false) {
            $id = (int)($account['id'] ?? 0);
            return $id > 0 ? $id : null;
        }
    }
    return null;
}

function maas_aylik_kayit_record(int $employeeId, string $period): ?array
{
    $stmt = db()->prepare('SELECT * FROM salary_records WHERE employee_id=? AND period=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$employeeId, maas_puantaj_period($period)]);
    return $stmt->fetch() ?: null;
}

function maas_aylik_kayit_effective_summary(int $employeeId, string $period): array
{
    $summary = maas_puantaj_summary($employeeId, $period);
    $record = maas_aylik_kayit_record($employeeId, $period);

    if ($record && (int)($record['attendance_override_enabled'] ?? 0) === 1) {
        $absentDays = max(0, min(30, (float)($record['absent_days'] ?? 0)));
        $missingHours = max(0, (float)($record['missing_hours'] ?? 0));
        $summary['absent_days'] = $absentDays;
        $summary['missing_hours'] = $missingHours;
        $summary['paid_days'] = max(0, round(30 - $absentDays, 2));
    }

    return $summary;
}

function maas_aylik_kayit_clear_override(int $employeeId, string $period): void
{
    db()->prepare('UPDATE salary_records SET attendance_override_enabled=0, updated_at=? WHERE employee_id=? AND period=?')
        ->execute([now(), $employeeId, maas_puantaj_period($period)]);
}

function maas_aylik_kayit_save(int $employeeId, string $period, array $input, bool $monthlyOverride = true): array
{
    maas_aylik_kayit_db_ensure();
    $period = maas_puantaj_period($period);
    $employee = maas_puantaj_employee($employeeId);
    if (!$employee) throw new RuntimeException('Personel bulunamadı.');

    $existingRecord = maas_aylik_kayit_record($employeeId, $period);
    $existingPayroll = maas_puantaj_payroll($employeeId, $period);
    $dailySummary = maas_puantaj_summary($employeeId, $period);

    $baseSalaryRaw = $input['salary_amount'] ?? $input['base_salary'] ?? $existingPayroll['base_salary'] ?? $existingRecord['salary_amount'] ?? $employee['base_salary'] ?? 0;
    $baseSalary = max(0, decimal_from_input($baseSalaryRaw));
    if ($baseSalary <= 0) throw new RuntimeException('Maaş tutarı sıfırdan büyük olmalı.');

    $currentOverride = $existingRecord && (int)($existingRecord['attendance_override_enabled'] ?? 0) === 1;
    $useOverride = $monthlyOverride || $currentOverride;

    if ($useOverride) {
        $absentRaw = array_key_exists('absent_days', $input) ? $input['absent_days'] : ($existingRecord['absent_days'] ?? 0);
        $missingRaw = array_key_exists('missing_hours', $input) ? $input['missing_hours'] : ($existingRecord['missing_hours'] ?? 0);
        $absentDays = max(0, min(30, decimal_from_input($absentRaw)));
        $missingHours = max(0, decimal_from_input($missingRaw));
        $summary = $dailySummary;
        $summary['absent_days'] = $absentDays;
        $summary['missing_hours'] = $missingHours;
        $summary['paid_days'] = max(0, round(30 - $absentDays, 2));
    } else {
        $summary = $dailySummary;
        $absentDays = (float)($summary['absent_days'] ?? 0);
        $missingHours = (float)($summary['missing_hours'] ?? 0);
    }

    $dailyRate = $baseSalary / 30;
    $hourlyRate = $dailyRate / 9;
    $absenceDeduction = round($absentDays * $dailyRate, 2);
    $hourDeduction = round($missingHours * $hourlyRate, 2);

    $manualDeductionRaw = $input['deduction_amount'] ?? $input['other_deduction_amount'] ?? $existingPayroll['other_deduction_amount'] ?? $existingRecord['manual_deduction_amount'] ?? 0;
    $manualDeduction = max(0, decimal_from_input($manualDeductionRaw));

    // Avans ve maaş haczi artık maaş formundan elle alınmaz. Seçili personelin
    // dönem içindeki tarihli ödeme hareketleri bordroya otomatik yansır.
    $advanceAmount = maas_avans_period_total($employeeId, $period);
    $garnishmentAmount = maas_haciz_period_total($employeeId, $period);

    $overtimeAmount = max(0, decimal_from_input($input['overtime_amount'] ?? $existingPayroll['overtime_amount'] ?? 0));
    $bonusAmount = max(0, decimal_from_input($input['bonus_amount'] ?? $existingPayroll['bonus_amount'] ?? 0));
    $otherAddition = max(0, decimal_from_input($input['other_addition_amount'] ?? $existingPayroll['other_addition_amount'] ?? 0));

    $grossEarning = round($baseSalary + $overtimeAmount + $bonusAmount + $otherAddition, 2);
    $totalDeduction = round($absenceDeduction + $hourDeduction + $manualDeduction + $garnishmentAmount, 2);
    $netPayable = max(0, round($grossEarning - $totalDeduction - $advanceAmount, 2));
    $paidAmount = min($netPayable, max(0, decimal_from_input($input['paid_amount'] ?? $existingRecord['paid_amount'] ?? 0)));
    $remainingAmount = max(0, round($netPayable - $paidAmount, 2));
    $status = maas_puantaj_calc_status($remainingAmount, $paidAmount);

    $forcePaymentDefaults = !empty($input['use_salary_payment_defaults']);
    $defaultPaymentDate = maas_aylik_kayit_default_payment_date($period);
    $postedPaymentDate = array_key_exists('payment_date', $input) ? trim((string)$input['payment_date']) : '';
    if ($postedPaymentDate !== '') {
        $paymentDate = $postedPaymentDate;
    } elseif ($forcePaymentDefaults || !$existingRecord || empty($existingRecord['payment_date'])) {
        $paymentDate = $defaultPaymentDate;
    } else {
        $paymentDate = (string)$existingRecord['payment_date'];
    }

    $defaultAccountId = maas_aylik_kayit_default_account_id();
    if ($period >= '2026-07') {
        // Temmuz 2026 ve sonrasındaki ödenen maaşların tamamı şirketin
        // Garanti Dumanlar hesabından çıkar.
        $accountId = $defaultAccountId;
    } elseif (array_key_exists('account_id', $input)) {
        $accountRaw = trim((string)$input['account_id']);
        $accountId = $accountRaw !== '' ? (int)$accountRaw : null;
    } elseif ($forcePaymentDefaults) {
        $accountId = $defaultAccountId;
    } elseif (!empty($existingRecord['account_id'])) {
        $accountId = (int)$existingRecord['account_id'];
    } else {
        $accountId = $defaultAccountId;
    }

    $note = trim((string)($input['note'] ?? $existingRecord['note'] ?? ''));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $recordId = (int)($existingRecord['id'] ?? 0);
        if ($recordId > 0) {
            $pdo->prepare('UPDATE salary_records SET salary_amount=?, advance_amount=?, deduction_amount=?, manual_deduction_amount=?, garnishment_amount=?, absent_days=?, missing_hours=?, attendance_override_enabled=?, paid_amount=?, remaining_amount=?, payment_date=?, account_id=?, status=?, note=?, updated_at=? WHERE id=?')
                ->execute([$grossEarning, $advanceAmount, $totalDeduction, $manualDeduction, $garnishmentAmount, $absentDays, $missingHours, $useOverride ? 1 : 0, $paidAmount, $remainingAmount, $paymentDate, $accountId, $status, $note, now(), $recordId]);
        } else {
            $pdo->prepare('INSERT INTO salary_records (employee_id, period, salary_amount, advance_amount, deduction_amount, manual_deduction_amount, garnishment_amount, absent_days, missing_hours, attendance_override_enabled, paid_amount, remaining_amount, payment_date, account_id, status, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$employeeId, $period, $grossEarning, $advanceAmount, $totalDeduction, $manualDeduction, $garnishmentAmount, $absentDays, $missingHours, $useOverride ? 1 : 0, $paidAmount, $remainingAmount, $paymentDate, $accountId, $status, $note, current_user()['id'] ?? null, now(), now()]);
            $recordId = (int)$pdo->lastInsertId();
        }

        $detailStmt = $pdo->prepare('SELECT * FROM salary_payroll_details WHERE employee_id=? AND period=? LIMIT 1');
        $detailStmt->execute([$employeeId, $period]);
        $oldDetail = $detailStmt->fetch() ?: null;

        $detailValues = [
            $recordId,
            $baseSalary,
            $summary['paid_days'] ?? max(0, 30 - $absentDays),
            $summary['work_days'] ?? 0,
            $summary['paid_leave_days'] ?? 0,
            $summary['report_days'] ?? 0,
            $absentDays,
            $summary['weekly_off_days'] ?? 0,
            $summary['holiday_days'] ?? 0,
            $summary['overtime_hours'] ?? 0,
            $missingHours,
            $overtimeAmount,
            $bonusAmount,
            $otherAddition,
            $absenceDeduction,
            $hourDeduction,
            $garnishmentAmount,
            $manualDeduction,
            $advanceAmount,
            $grossEarning,
            $netPayable,
            $note,
            $useOverride ? 'aylik_kayit' : 'puantaj',
            now(),
        ];

        if ($oldDetail) {
            $pdo->prepare('UPDATE salary_payroll_details SET salary_record_id=?, base_salary=?, paid_days=?, work_days=?, paid_leave_days=?, report_days=?, absent_days=?, weekly_off_days=?, holiday_days=?, overtime_hours=?, missing_hours=?, overtime_amount=?, bonus_amount=?, other_addition_amount=?, absence_deduction_amount=?, hour_deduction_amount=?, garnishment_amount=?, other_deduction_amount=?, advance_amount=?, gross_earning=?, net_payable=?, note=?, source_mode=?, updated_at=? WHERE id=?')
                ->execute(array_merge($detailValues, [(int)$oldDetail['id']]));
            $payrollId = (int)$oldDetail['id'];
        } else {
            $pdo->prepare('INSERT INTO salary_payroll_details (employee_id, period, salary_record_id, base_salary, paid_days, work_days, paid_leave_days, report_days, absent_days, weekly_off_days, holiday_days, overtime_hours, missing_hours, overtime_amount, bonus_amount, other_addition_amount, absence_deduction_amount, hour_deduction_amount, garnishment_amount, other_deduction_amount, advance_amount, gross_earning, net_payable, note, source_mode, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge([$employeeId, $period], array_slice($detailValues, 0, 23), [current_user()['id'] ?? null, now(), now()]));
            $payrollId = (int)$pdo->lastInsertId();
        }

        maas_puantaj_sync_account_transaction($recordId);
        audit_action('maas_kaydi', $recordId, $existingRecord ? 'guncellendi' : 'eklendi', $existingRecord, [
            'employee_id' => $employeeId,
            'period' => $period,
            'base_salary' => $baseSalary,
            'absent_days' => $absentDays,
            'missing_hours' => $missingHours,
            'garnishment_amount' => $garnishmentAmount,
            'advance_amount' => $advanceAmount,
            'payment_date' => $paymentDate,
            'account_id' => $accountId,
            'daily_rate' => round($dailyRate, 2),
            'hourly_rate' => round($hourlyRate, 2),
            'net_payable' => $netPayable,
        ], ($employee['full_name'] ?? '') . ' / ' . $period);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'record_id' => $recordId,
        'payroll_id' => $payrollId,
        'base_salary' => $baseSalary,
        'daily_rate' => round($dailyRate, 2),
        'hourly_rate' => round($hourlyRate, 2),
        'paid_days' => $summary['paid_days'] ?? max(0, 30 - $absentDays),
        'absent_days' => $absentDays,
        'missing_hours' => $missingHours,
        'absence_deduction_amount' => $absenceDeduction,
        'hour_deduction_amount' => $hourDeduction,
        'garnishment_amount' => $garnishmentAmount,
        'manual_deduction_amount' => $manualDeduction,
        'advance_amount' => $advanceAmount,
        'total_deduction_amount' => $totalDeduction,
        'net_payable' => $netPayable,
        'paid_amount' => $paidAmount,
        'remaining_amount' => $remainingAmount,
        'payment_date' => $paymentDate,
        'account_id' => $accountId,
        'status' => $status,
    ];
}


function maas_manual_aylik_toplam_db_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS salary_manual_monthly_totals (
        period TEXT PRIMARY KEY,
        amount REAL NOT NULL DEFAULT 0,
        updated_by INTEGER,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

function maas_manual_aylik_toplam_kaydet(string $period, ?float $amount): void
{
    $period = maas_puantaj_period($period);
    maas_manual_aylik_toplam_db_ensure();
    $pdo = db();

    if ($amount === null) {
        $pdo->prepare('DELETE FROM salary_manual_monthly_totals WHERE period=?')->execute([$period]);
        return;
    }

    $amount = max(0, round($amount, 2));
    $userId = current_user()['id'] ?? null;
    $updatedAt = now();
    $update = $pdo->prepare('UPDATE salary_manual_monthly_totals SET amount=?, updated_by=?, updated_at=? WHERE period=?');
    $update->execute([$amount, $userId, $updatedAt, $period]);
    if ($update->rowCount() === 0) {
        $pdo->prepare('INSERT INTO salary_manual_monthly_totals (period, amount, updated_by, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$period, $amount, $userId, $updatedAt]);
    }
}

function maas_yillik_odenen_ozeti(string $year): array
{
    if (!preg_match('/^\\d{4}$/', $year)) $year = date('Y');
    maas_manual_aylik_toplam_db_ensure();

    $manual = [];
    $stmt = db()->prepare('SELECT period, amount FROM salary_manual_monthly_totals WHERE period BETWEEN ? AND ?');
    $stmt->execute([$year . '-01', $year . '-12']);
    foreach ($stmt->fetchAll() as $row) {
        $manual[(string)$row['period']] = (float)$row['amount'];
    }

    $detailed = [];
    try {
        $stmt = db()->prepare('SELECT period, COALESCE(SUM(paid_amount),0) AS total FROM salary_records WHERE period BETWEEN ? AND ? GROUP BY period');
        $stmt->execute([$year . '-01', $year . '-12']);
        foreach ($stmt->fetchAll() as $row) {
            $detailed[(string)$row['period']] = (float)$row['total'];
        }
    } catch (Throwable $e) {
        $detailed = [];
    }

    $months = [];
    $total = 0.0;
    for ($month = 1; $month <= 12; $month++) {
        $period = sprintf('%s-%02d', $year, $month);
        $hasManual = array_key_exists($period, $manual);
        $amount = $hasManual ? $manual[$period] : ($detailed[$period] ?? 0.0);
        $amount = round((float)$amount, 2);
        $months[$period] = [
            'period' => $period,
            'amount' => $amount,
            'manual_amount' => $hasManual ? round((float)$manual[$period], 2) : null,
            'detailed_amount' => round((float)($detailed[$period] ?? 0.0), 2),
            'source' => $hasManual ? 'manual' : 'detailed',
        ];
        $total += $amount;
    }

    return ['year' => $year, 'months' => $months, 'total' => round($total, 2)];
}

function maas_garanti_odeme_sync_db_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS salary_manual_account_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        period TEXT NOT NULL UNIQUE,
        amount REAL NOT NULL DEFAULT 0,
        account_id INTEGER NOT NULL,
        account_transaction_id INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL
    )");
}

function maas_garanti_odeme_sync(string $startPeriod = '2026-07'): array
{
    maas_manual_aylik_toplam_db_ensure();
    maas_garanti_odeme_sync_db_ensure();
    $accountId = maas_aylik_kayit_default_account_id();
    if (!$accountId) throw new RuntimeException('Garanti Dumanlar hesabı bulunamadı.');

    $pdo = db();
    $summary = ['salary_records'=>0, 'manual_differences'=>0];

    // Personel bazlı ödenen kayıtları Garanti Dumanlar hesabına bağla.
    $recordStmt = $pdo->prepare('SELECT id, account_id, account_transaction_id FROM salary_records WHERE period>=? AND COALESCE(paid_amount,0)>0 ORDER BY id');
    $recordStmt->execute([$startPeriod]);
    foreach ($recordStmt->fetchAll() as $record) {
        if ((int)($record['account_id'] ?? 0) !== $accountId) {
            $pdo->prepare('UPDATE salary_records SET account_id=?, updated_at=? WHERE id=?')->execute([$accountId, now(), (int)$record['id']]);
        }
        maas_puantaj_sync_account_transaction((int)$record['id']);
        $summary['salary_records']++;
    }

    // Manuel aylık toplam, personel bazlı ödemelerin yerine geçen rapor tutarıdır.
    // Bankada yalnızca iki kaynak arasındaki pozitif fark ayrıca düşülür.
    $manualStmt = $pdo->prepare('SELECT period, amount FROM salary_manual_monthly_totals WHERE period>=? ORDER BY period');
    $manualStmt->execute([$startPeriod]);
    foreach ($manualStmt->fetchAll() as $manual) {
        $period = (string)$manual['period'];
        $manualAmount = max(0, (float)$manual['amount']);
        $detailStmt = $pdo->prepare('SELECT COALESCE(SUM(paid_amount),0) FROM salary_records WHERE period=?');
        $detailStmt->execute([$period]);
        $detailedPaid = (float)$detailStmt->fetchColumn();
        $difference = max(0, round($manualAmount - $detailedPaid, 2));

        $linkStmt = $pdo->prepare('SELECT * FROM salary_manual_account_links WHERE period=? LIMIT 1');
        $linkStmt->execute([$period]);
        $link = $linkStmt->fetch() ?: null;
        if (!$link) {
            $pdo->prepare('INSERT INTO salary_manual_account_links (period, amount, account_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$period, $difference, $accountId, now(), now()]);
            $linkId = (int)$pdo->lastInsertId();
            $transactionId = 0;
        } else {
            $linkId = (int)$link['id'];
            $transactionId = (int)($link['account_transaction_id'] ?? 0);
            $pdo->prepare('UPDATE salary_manual_account_links SET amount=?, account_id=?, updated_at=? WHERE id=?')
                ->execute([$difference, $accountId, now(), $linkId]);
        }

        if ($difference > 0) {
            $description = 'Manuel maaş ödemesi: ' . month_label($period) . ' / personel bazlı ödemeler sonrası fark';
            $transactionDate = date('Y-m-t', strtotime($period . '-01'));
            if ($transactionId > 0) {
                $pdo->prepare("UPDATE account_transactions SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary_manual', source_id=?, description=? WHERE id=?")
                    ->execute([$accountId, $difference, $transactionDate, $linkId, $description, $transactionId]);
            } else {
                $pdo->prepare("INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
                    VALUES (?, 'out', ?, ?, 'salary_manual', ?, ?, ?, ?)")
                    ->execute([$accountId, $difference, $transactionDate, $linkId, $description, current_user()['id'] ?? null, now()]);
                $transactionId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE salary_manual_account_links SET account_transaction_id=?, updated_at=? WHERE id=?')
                    ->execute([$transactionId, now(), $linkId]);
            }
            $summary['manual_differences']++;
        } elseif ($transactionId > 0) {
            // Fark daha sonra sıfırlanırsa geçmiş hareketi silmek yerine aynı tutarda
            // karşı kayıt oluştur ve bağlantıyı boşalt.
            $oldTxnStmt = $pdo->prepare("SELECT amount FROM account_transactions WHERE id=? AND source_type='salary_manual' LIMIT 1");
            $oldTxnStmt->execute([$transactionId]);
            $oldAmount = (float)($oldTxnStmt->fetchColumn() ?: 0);
            if ($oldAmount > 0) {
                $pdo->prepare("INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
                    VALUES (?, 'in', ?, ?, 'salary_manual_reversal', ?, ?, ?, ?)")
                    ->execute([$accountId, $oldAmount, date('Y-m-d'), $linkId, 'İptal karşılığı: Manuel maaş ödemesi / ' . month_label($period), current_user()['id'] ?? null, now()]);
            }
            $pdo->prepare('UPDATE salary_manual_account_links SET account_transaction_id=NULL, updated_at=? WHERE id=?')
                ->execute([now(), $linkId]);
        }
    }

    return $summary;
}

