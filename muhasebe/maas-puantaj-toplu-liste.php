<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_once __DIR__ . '/maas-avans-lib.php';

require_salary_access();
maas_aylik_kayit_db_ensure();
maas_avans_db_ensure();

header('Content-Type: application/json; charset=utf-8');

try {
    $period = maas_puantaj_period((string)($_GET['period'] ?? date('Y-m')));
    $employees = db()->query("SELECT id, full_name, department, position, base_salary FROM salary_employees WHERE is_active=1 ORDER BY full_name ASC")->fetchAll() ?: [];

    $attendanceRows = [];
    $payrollRows = [];

    foreach ($employees as $employee) {
        $employeeId = (int)$employee['id'];
        $summary = maas_aylik_kayit_effective_summary($employeeId, $period);
        $record = maas_aylik_kayit_record($employeeId, $period);
        $payroll = maas_puantaj_payroll($employeeId, $period);
        $basis = maas_puantaj_salary_basis($employeeId, $period);
        $advanceTotal = maas_avans_period_total($employeeId, $period);

        $recordedDays = (int)($summary['recorded_days'] ?? 0);
        $monthlyOverride = $record && (int)($record['attendance_override_enabled'] ?? 0) === 1;
        $hasAttendance = $recordedDays > 0 || $monthlyOverride;
        $hasPayroll = !empty($payroll) || !empty($record);

        $attendanceRows[] = [
            'employee_id' => $employeeId,
            'full_name' => (string)$employee['full_name'],
            'department' => (string)($employee['department'] ?? ''),
            'position' => (string)($employee['position'] ?? ''),
            'recorded_days' => $recordedDays,
            'paid_days' => (float)($summary['paid_days'] ?? 30),
            'work_days' => (float)($summary['work_days'] ?? 0),
            'weekly_off_days' => (float)($summary['weekly_off_days'] ?? 0),
            'holiday_days' => (float)($summary['holiday_days'] ?? 0),
            'paid_leave_days' => (float)($summary['paid_leave_days'] ?? 0),
            'report_days' => (float)($summary['report_days'] ?? 0),
            'absent_days' => (float)($summary['absent_days'] ?? 0),
            'missing_hours' => (float)($summary['missing_hours'] ?? 0),
            'overtime_hours' => (float)($summary['overtime_hours'] ?? 0),
            'has_attendance' => $hasAttendance,
            'source' => $monthlyOverride ? 'Aylık maaş kaydı' : ($recordedDays > 0 ? 'Günlük puantaj' : 'Bekliyor'),
        ];

        $baseSalary = max(0, (float)($basis['base_salary'] ?? $employee['base_salary'] ?? 0));
        $grossEarning = max(0, (float)($payroll['gross_earning'] ?? $record['salary_amount'] ?? $baseSalary));
        $absenceDeduction = max(0, (float)($payroll['absence_deduction_amount'] ?? 0));
        $hourDeduction = max(0, (float)($payroll['hour_deduction_amount'] ?? 0));
        $garnishment = max(0, (float)($payroll['garnishment_amount'] ?? $record['garnishment_amount'] ?? 0));
        $otherDeduction = max(0, (float)($payroll['other_deduction_amount'] ?? $record['manual_deduction_amount'] ?? 0));
        $totalDeduction = round($absenceDeduction + $hourDeduction + $garnishment + $otherDeduction, 2);
        $netPayable = max(0, (float)($payroll['net_payable'] ?? 0));
        if (!$payroll) {
            $absenceDeduction = round((float)($summary['absent_days'] ?? 0) * ($baseSalary / 30), 2);
            $hourDeduction = round((float)($summary['missing_hours'] ?? 0) * (($baseSalary / 30) / 9), 2);
            $totalDeduction = round($absenceDeduction + $hourDeduction + $garnishment + $otherDeduction, 2);
            $netPayable = max(0, round($grossEarning - $totalDeduction - $advanceTotal, 2));
        }

        $paidAmount = max(0, (float)($payroll['paid_amount'] ?? $record['paid_amount'] ?? 0));
        $remainingAmount = max(0, (float)($payroll['remaining_amount'] ?? $record['remaining_amount'] ?? ($netPayable - $paidAmount)));
        $status = (string)($payroll['status'] ?? $record['status'] ?? 'bekliyor');

        $payrollRows[] = [
            'employee_id' => $employeeId,
            'full_name' => (string)$employee['full_name'],
            'department' => (string)($employee['department'] ?? ''),
            'position' => (string)($employee['position'] ?? ''),
            'paid_days' => (float)($summary['paid_days'] ?? 30),
            'base_salary' => $baseSalary,
            'gross_earning' => $grossEarning,
            'absence_deduction_amount' => $absenceDeduction,
            'hour_deduction_amount' => $hourDeduction,
            'garnishment_amount' => $garnishment,
            'other_deduction_amount' => $otherDeduction,
            'total_deduction_amount' => $totalDeduction,
            'advance_amount' => $advanceTotal,
            'net_payable' => $netPayable,
            'paid_amount' => $paidAmount,
            'remaining_amount' => $remainingAmount,
            'payment_date' => (string)($payroll['payment_date'] ?? $record['payment_date'] ?? maas_aylik_kayit_default_payment_date($period)),
            'account_name' => (string)($payroll['account_name'] ?? ''),
            'bank_name' => (string)($payroll['bank_name'] ?? ''),
            'status' => $status,
            'has_payroll' => $hasPayroll,
        ];
    }

    echo json_encode([
        'ok' => true,
        'period' => $period,
        'period_label' => month_label($period),
        'employee_count' => count($employees),
        'attendance_rows' => $attendanceRows,
        'payroll_rows' => $payrollRows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
