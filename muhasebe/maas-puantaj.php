<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_admin();
maas_puantaj_db_ensure();

header('Content-Type: application/json; charset=utf-8');

function maas_puantaj_response_payload(int $employeeId, string $period): array
{
    $period = maas_puantaj_period($period);
    $employees = db()->query("SELECT id, full_name, department, position, base_salary, is_active FROM salary_employees WHERE is_active=1 ORDER BY full_name ASC")->fetchAll() ?: [];
    if ($employeeId <= 0 && $employees) $employeeId = (int)$employees[0]['id'];

    $accounts = accounts_for_select(true);
    $employee = maas_puantaj_employee($employeeId);
    $entries = $employee ? maas_puantaj_entries($employeeId, $period) : [];
    $summary = $employee ? maas_puantaj_summary($employeeId, $period) : [
        'recorded_days'=>0,'work_days'=>0,'paid_leave_days'=>0,'report_days'=>0,
        'absent_days'=>0,'weekly_off_days'=>0,'holiday_days'=>0,'overtime_hours'=>0,
    ];
    $payroll = $employee ? maas_puantaj_payroll($employeeId, $period) : null;
    [$start, $end] = maas_puantaj_period_bounds($period);

    return [
        'ok' => true,
        'period' => $period,
        'period_label' => month_label($period),
        'period_start' => $start,
        'period_end' => $end,
        'days_in_month' => (int)date('t', strtotime($start)),
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

                $insert = $pdo->prepare('INSERT INTO salary_attendance (employee_id, work_date, status, overtime_hours, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($decoded as $date => $entry) {
                    if (!is_array($entry)) continue;
                    $date = (string)$date;
                    if ($date < $start || $date > $end || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                    $status = (string)($entry['status'] ?? '');
                    if (!isset($statuses[$status])) continue;
                    $overtime = max(0, min(24, decimal_from_input($entry['overtime_hours'] ?? 0)));
                    $note = trim((string)($entry['note'] ?? ''));
                    $insert->execute([$employeeId, $date, $status, $overtime, $note, current_user()['id'] ?? null, now(), now()]);
                }
                audit_action('maas_puantaj', $employeeId, 'guncellendi', null, ['period'=>$period, 'entry_count'=>count($decoded)], $period);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            echo json_encode(maas_puantaj_response_payload($employeeId, $period), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_payroll') {
            $result = maas_puantaj_save_payroll($employeeId, $period, $_POST);
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
