<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_salary_access();
maas_aylik_kayit_db_ensure();

header('Content-Type: application/json; charset=utf-8');

function maas_puantaj_response_payload(int $employeeId, string $period): array
{
    $period = maas_puantaj_period($period);
    $employees = db()->query("SELECT id, full_name, department, position, base_salary, is_active FROM salary_employees WHERE is_active=1 ORDER BY full_name ASC")->fetchAll() ?: [];
    if ($employeeId <= 0 && $employees) $employeeId = (int)$employees[0]['id'];

    $accounts = accounts_for_select(true);
    $employee = maas_puantaj_employee($employeeId);
    $entries = $employee ? maas_puantaj_entries($employeeId, $period) : [];
    $summary = $employee ? maas_aylik_kayit_effective_summary($employeeId, $period) : [
        'recorded_days'=>0,'paid_days'=>30,'work_days'=>0,'paid_leave_days'=>0,'report_days'=>0,
        'absent_days'=>0,'weekly_off_days'=>0,'holiday_days'=>0,'overtime_hours'=>0,'missing_hours'=>0,
    ];
    $record = $employee ? maas_aylik_kayit_record($employeeId, $period) : null;
    $payroll = $employee ? maas_puantaj_payroll($employeeId, $period) : null;
    $salaryBasis = $employee ? maas_puantaj_salary_basis($employeeId, $period) : [
        'base_salary'=>0,'daily_rate'=>0,'hourly_rate'=>0,'source'=>'personel_karti',
    ];
    [$start, $end] = maas_puantaj_period_bounds($period);

    return [
        'ok' => true,
        'period' => $period,
        'period_label' => month_label($period),
        'period_start' => $start,
        'period_end' => $end,
        'days_in_month' => (int)date('t', strtotime($start)),
        'salary_day_basis' => 30,
        'daily_work_hours' => 9,
        'employee_id' => $employeeId,
        'employee' => $employee,
        'employees' => $employees,
        'accounts' => array_map(function ($account) {
            return [
                'id' => (int)$account['id'],
                'name' => (string)$account['name'],
                'bank_name' => (string)($account['bank_name'] ?? ''),
            ];
        }, $accounts),
        'statuses' => maas_puantaj_statuses(),
        'entries' => $entries,
        'summary' => $summary,
        'summary_source' => $record && (int)($record['attendance_override_enabled'] ?? 0) === 1 ? 'aylik_kayit' : 'gunluk_puantaj',
        'salary_basis' => $salaryBasis,
        'payroll' => $payroll,
    ];
}

try {
    $period = maas_puantaj_period((string)($_REQUEST['period'] ?? date('Y-m')));
    $employeeId = (int)($_REQUEST['employee_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($employeeId <= 0 || !maas_puantaj_employee($employeeId)) {
            throw new RuntimeException('Geçerli bir personel seçin.');
        }

        if ($action === 'save_attendance') {
            $decoded = json_decode((string)($_POST['entries_json'] ?? '{}'), true);
            if (!is_array($decoded)) throw new RuntimeException('Puantaj verisi okunamadı.');

            [$start, $end] = maas_puantaj_period_bounds($period);
            $statuses = maas_puantaj_statuses();
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM salary_attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?')
                    ->execute([$employeeId, $start, $end]);

                $insert = $pdo->prepare('INSERT INTO salary_attendance (employee_id, work_date, status, overtime_hours, missing_hours, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $savedCount = 0;
                foreach ($decoded as $date => $entry) {
                    if (!is_array($entry)) continue;
                    $date = (string)$date;
                    if ($date < $start || $date > $end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                    $status = (string)($entry['status'] ?? '');
                    if (!isset($statuses[$status])) continue;
                    $overtime = max(0, min(24, decimal_from_input($entry['overtime_hours'] ?? 0)));
                    $missingHours = $status === 'gelmedi' ? 0 : max(0, min(9, decimal_from_input($entry['missing_hours'] ?? 0)));
                    $note = trim((string)($entry['note'] ?? ''));
                    $insert->execute([$employeeId, $date, $status, $overtime, $missingHours, $note, current_user()['id'] ?? null, now(), now()]);
                    $savedCount++;
                }
                maas_aylik_kayit_clear_override($employeeId, $period);
                audit_action('maas_puantaj', $employeeId, 'guncellendi', null, ['period'=>$period, 'entry_count'=>$savedCount], $period);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $autoPayroll = null;
            $warning = null;
            try {
                $autoPayroll = maas_aylik_kayit_save($employeeId, $period, [], false);
            } catch (Throwable $e) {
                $warning = 'Puantaj kaydedildi ancak bordro otomatik güncellenemedi: ' . $e->getMessage();
            }

            $payload = maas_puantaj_response_payload($employeeId, $period);
            $payload['auto_payroll'] = $autoPayroll;
            if ($warning) $payload['warning'] = $warning;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_payroll') {
            $record = maas_aylik_kayit_record($employeeId, $period);
            $monthlyOverride = $record && (int)($record['attendance_override_enabled'] ?? 0) === 1;
            $result = maas_aylik_kayit_save($employeeId, $period, $_POST, $monthlyOverride);
            $payload = maas_puantaj_response_payload($employeeId, $period);
            $payload['saved_payroll'] = $result;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new RuntimeException('Geçersiz işlem.');
    }

    echo json_encode(maas_puantaj_response_payload($employeeId, $period), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
