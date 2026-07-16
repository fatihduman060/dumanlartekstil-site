<?php
require_once __DIR__ . '/maas-avans-lib.php';

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
    $garnishmentAmount = max(0, decimal_from_input($input['garnishment_amount'] ?? $existingPayroll['garnishment_amount'] ?? $existingRecord['garnishment_amount'] ?? 0));

    // Avans artık maaş formundan elle alınmaz. Seçili personelin dönem içindeki
    // tarihli avans hareketlerinin toplamı bordroya otomatik yansır.
    $advanceAmount = maas_avans_period_total($employeeId, $period);

    $overtimeAmount = max(0, decimal_from_input($input['overtime_amount'] ?? $existingPayroll['overtime_amount'] ?? 0));
    $bonusAmount = max(0, decimal_from_input($input['bonus_amount'] ?? $existingPayroll['bonus_amount'] ?? 0));
    $otherAddition = max(0, decimal_from_input($input['other_addition_amount'] ?? $existingPayroll['other_addition_amount'] ?? 0));

    $grossEarning = round($baseSalary + $overtimeAmount + $bonusAmount + $otherAddition, 2);
    $totalDeduction = round($absenceDeduction + $hourDeduction + $manualDeduction + $garnishmentAmount, 2);
    $netPayable = max(0, round($grossEarning - $totalDeduction - $advanceAmount, 2));
    $paidAmount = min($netPayable, max(0, decimal_from_input($input['paid_amount'] ?? $existingRecord['paid_amount'] ?? 0)));
    $remainingAmount = max(0, round($netPayable - $paidAmount, 2));
    $status = maas_puantaj_calc_status($remainingAmount, $paidAmount);
    $paymentDate = trim((string)($input['payment_date'] ?? $existingRecord['payment_date'] ?? '')) ?: null;
    $accountRaw = trim((string)($input['account_id'] ?? $existingRecord['account_id'] ?? ''));
    $accountId = $accountRaw !== '' ? (int)$accountRaw : null;
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
        'status' => $status,
    ];
}
