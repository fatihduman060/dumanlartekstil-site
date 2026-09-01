<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/maas-aylik-plan-lib.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_salary_access();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'message'=>'Geçersiz istek.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!can_manage_salary()) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Maaş düzenleme yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $period = maas_aylik_plan_period((string)($_POST['period'] ?? date('Y-m')));
    $dailyWage = decimal_from_input($_POST['daily_wage'] ?? '0');
    $bankAmount = decimal_from_input($_POST['bank_amount'] ?? '0');
    $cashAmount = decimal_from_input($_POST['cash_amount'] ?? '0');
    $note = trim((string)($_POST['note'] ?? ''));

    $id = maas_aylik_plan_save($employeeId, $period, $dailyWage, $bankAmount, $cashAmount, $note);

    echo json_encode([
        'ok'=>true,
        'message'=>'Yevmiye ve ödeme dağılımı kaydedildi.',
        'id'=>$id,
        'employee_id'=>$employeeId,
        'period'=>$period,
        'daily_wage'=>$dailyWage,
        'bank_amount'=>$bankAmount,
        'cash_amount'=>$cashAmount,
        'plan_total'=>round($bankAmount + $cashAmount, 2),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
