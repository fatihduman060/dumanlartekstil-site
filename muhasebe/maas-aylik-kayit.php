<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_salary_access();
maas_aylik_kayit_db_ensure();

header('Content-Type: application/json; charset=utf-8');

try {
    $employeeId = (int)($_REQUEST['employee_id'] ?? 0);
    $period = maas_puantaj_period((string)($_REQUEST['period'] ?? date('Y-m')));
    if ($employeeId <= 0 || !maas_puantaj_employee($employeeId)) {
        throw new RuntimeException('Geçerli bir personel seçin.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $result = maas_aylik_kayit_save($employeeId, $period, $_POST, true);
        echo json_encode(['ok'=>true, 'saved'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $record = maas_aylik_kayit_record($employeeId, $period);
    $payroll = maas_puantaj_payroll($employeeId, $period);
    $employee = maas_puantaj_employee($employeeId);
    $baseSalary = (float)($payroll['base_salary'] ?? 0);
    if ($baseSalary <= 0 && $record) {
        $gross = (float)($record['salary_amount'] ?? 0);
        $overtime = (float)($payroll['overtime_amount'] ?? 0);
        $bonus = (float)($payroll['bonus_amount'] ?? 0);
        $addition = (float)($payroll['other_addition_amount'] ?? 0);
        $baseSalary = max(0, $gross - $overtime - $bonus - $addition);
    }
    if ($baseSalary <= 0) $baseSalary = (float)($employee['base_salary'] ?? 0);

    $manualDeduction = (float)($payroll['other_deduction_amount'] ?? 0);
    if ($record) {
        $storedManual = (float)($record['manual_deduction_amount'] ?? 0);
        if ($storedManual > 0 || (int)($record['attendance_override_enabled'] ?? 0) === 1) {
            $manualDeduction = $storedManual;
        } elseif (!$payroll && (float)($record['deduction_amount'] ?? 0) > 0) {
            $manualDeduction = (float)$record['deduction_amount'];
        }
    }

    $advanceTotal = maas_avans_period_total($employeeId, $period);
    $advanceRows = maas_avans_period_rows($period, $employeeId);
    $defaultPaymentDate = maas_aylik_kayit_default_payment_date($period);
    $defaultAccountId = maas_aylik_kayit_default_account_id();

    if (!$record) $record = [];
    $record['advance_amount'] = $advanceTotal;
    if (empty($record['payment_date'])) $record['payment_date'] = $defaultPaymentDate;
    if (empty($record['account_id']) && $defaultAccountId) $record['account_id'] = $defaultAccountId;

    echo json_encode([
        'ok' => true,
        'period' => $period,
        'employee_id' => $employeeId,
        'record' => $record,
        'payroll' => $payroll,
        'base_salary' => $baseSalary,
        'daily_rate' => round($baseSalary / 30, 2),
        'hourly_rate' => round(($baseSalary / 30) / 9, 2),
        'absent_days' => (float)($record['absent_days'] ?? $payroll['absent_days'] ?? 0),
        'missing_hours' => (float)($record['missing_hours'] ?? $payroll['missing_hours'] ?? 0),
        'garnishment_amount' => (float)($record['garnishment_amount'] ?? $payroll['garnishment_amount'] ?? 0),
        'manual_deduction_amount' => $manualDeduction,
        'advance_amount' => $advanceTotal,
        'advance_rows' => array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'advance_date' => (string)$row['advance_date'],
                'amount' => (float)$row['amount'],
                'account_name' => (string)($row['account_name'] ?? ''),
                'note' => (string)($row['note'] ?? ''),
            ];
        }, $advanceRows),
        'default_payment_date' => $defaultPaymentDate,
        'default_account_id' => $defaultAccountId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
