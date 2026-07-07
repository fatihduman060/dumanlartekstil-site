<?php
require_once __DIR__ . '/layout.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $today = date('Y-m-d');
    $weekAhead = date('Y-m-d', strtotime('+7 days'));
    $stmt = db()->prepare("SELECT ch.id, ch.direction, ch.due_date, ch.amount, ch.bank_name, ch.check_no, ch.drawer, c.id AS cari_id, c.name AS cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        WHERE COALESCE(ch.is_cancelled,0)=0
          AND ch.status IN ('bekliyor','bankaya_verildi')
          AND ch.due_date <= ?
        ORDER BY ch.due_date ASC, ch.id DESC
        LIMIT 8");
    $stmt->execute([$weekAhead]);
    $checks = [];
    foreach ($stmt->fetchAll() as $row) {
        $due = (string)($row['due_date'] ?? '');
        $checks[] = [
            'id' => (int)$row['id'],
            'direction' => (string)($row['direction'] ?? ''),
            'due_date' => $due,
            'due_text' => tr_date($due),
            'amount' => (float)($row['amount'] ?? 0),
            'amount_text' => money($row['amount'] ?? 0),
            'bank_name' => (string)($row['bank_name'] ?? ''),
            'check_no' => (string)($row['check_no'] ?? ''),
            'drawer' => (string)($row['drawer'] ?? ''),
            'cari_id' => !empty($row['cari_id']) ? (int)$row['cari_id'] : null,
            'cari_name' => (string)($row['cari_name'] ?: ($row['drawer'] ?: '-')),
            'url' => 'cekler.php?direction=' . urlencode((string)($row['direction'] ?? 'alinacak')),
        ];
    }
    echo json_encode(['ok'=>true, 'checks'=>$checks, 'count'=>count($checks)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
