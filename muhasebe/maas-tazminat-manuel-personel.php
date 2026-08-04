<?php
require_once __DIR__ . '/layout.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

ensure_column(db(), 'salary_employees', 'exit_date', 'TEXT');
ensure_column(db(), 'salary_employees', 'exit_reason', 'TEXT');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }

    require_csrf();

    $fullName = trim((string)($_POST['manual_employee_name'] ?? ''));
    $paymentDate = trim((string)($_POST['payment_date'] ?? date('Y-m-d')));

    if ($fullName === '') {
        throw new RuntimeException('Personel adını yazmalısın.');
    }
    if (function_exists('mb_strlen') && mb_strlen($fullName, 'UTF-8') < 3) {
        throw new RuntimeException('Personel adı en az 3 karakter olmalı.');
    }
    if (!function_exists('mb_strlen') && strlen($fullName) < 3) {
        throw new RuntimeException('Personel adı en az 3 karakter olmalı.');
    }
    if (function_exists('mb_substr')) $fullName = mb_substr($fullName, 0, 180, 'UTF-8');
    else $fullName = substr($fullName, 0, 180);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) $paymentDate = date('Y-m-d');

    $stmt = db()->prepare('SELECT id, full_name, is_active FROM salary_employees WHERE full_name = ? COLLATE NOCASE ORDER BY id ASC LIMIT 1');
    $stmt->execute([$fullName]);
    $employee = $stmt->fetch() ?: null;

    if ($employee) {
        echo json_encode([
            'ok' => true,
            'employee_id' => (int)$employee['id'],
            'full_name' => (string)$employee['full_name'],
            'created' => false,
            'is_active' => (int)($employee['is_active'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now = now();
        $note = 'Tazminat ödemesi ekranından manuel olarak oluşturuldu.';
        $exitReason = 'Tazminat ödemesi için manuel kayıt';
        $stmt = $pdo->prepare('INSERT INTO salary_employees
            (full_name, department, position, phone, start_date, base_salary, is_active, note, created_by, created_at, updated_at, exit_date, exit_reason)
            VALUES (?, NULL, NULL, NULL, NULL, 0, 0, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $fullName,
            $note,
            current_user()['id'] ?? null,
            $now,
            $now,
            $paymentDate,
            $exitReason,
        ]);
        $employeeId = (int)$pdo->lastInsertId();
        audit_action('maas_personel', $employeeId, 'manuel_cikis_personeli_eklendi', null, [
            'full_name' => $fullName,
            'is_active' => 0,
            'exit_date' => $paymentDate,
            'exit_reason' => $exitReason,
        ], $fullName);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    echo json_encode([
        'ok' => true,
        'employee_id' => $employeeId,
        'full_name' => $fullName,
        'created' => true,
        'is_active' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
