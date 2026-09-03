<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo = db();
ensure_column($pdo, 'movements', 'card_key', 'TEXT');
ensure_column($pdo, 'movements', 'report_excluded', 'INTEGER NOT NULL DEFAULT 0');

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$start = sprintf('%04d-01-01', $year);
$end = sprintf('%04d-12-31', $year);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS count
    FROM movements
    WHERE COALESCE(is_cancelled,0)=0
      AND movement_type='odeme'
      AND COALESCE(report_excluded,0)=1
      AND COALESCE(card_key,'')<>''
      AND movement_date BETWEEN ? AND ?");
$stmt->execute([$start, $end]);
$row = $stmt->fetch() ?: ['total'=>0,'count'=>0];

echo json_encode([
    'ok'=>true,
    'year'=>$year,
    'card_payment_total'=>(float)$row['total'],
    'card_payment_count'=>(int)$row['count'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
