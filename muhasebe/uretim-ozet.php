<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function uretim_ozet_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS production_group_shift_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        production_date TEXT NOT NULL,
        shift_code TEXT NOT NULL,
        group_code TEXT NOT NULL,
        produced_dozen REAL NOT NULL DEFAULT 0,
        defective_qty INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(production_date, shift_code, group_code)
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_prod_group_shift_date ON production_group_shift_entries(production_date)');
}

uretim_ozet_ensure();
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$year = (int)substr($date, 0, 4);
$month = (int)substr($date, 5, 2);

$dayStmt = db()->prepare('SELECT shift_code, group_code, produced_dozen, defective_qty FROM production_group_shift_entries WHERE production_date=?');
$dayStmt->execute([$date]);
$day = [];
foreach ($dayStmt->fetchAll() as $row) {
    $day[$row['shift_code']][$row['group_code']] = [
        'produced_dozen' => (float)$row['produced_dozen'],
        'defective_qty' => (int)$row['defective_qty'],
    ];
}

$monthStmt = db()->prepare("SELECT CAST(substr(production_date,6,2) AS INTEGER) AS month_no, SUM(produced_dozen) AS dozen_total, SUM(defective_qty) AS defective_total FROM production_group_shift_entries WHERE substr(production_date,1,4)=? GROUP BY substr(production_date,6,2) ORDER BY month_no");
$monthStmt->execute([sprintf('%04d', $year)]);
$months = [];
for ($i=1; $i<=12; $i++) $months[$i] = ['produced_dozen'=>0.0,'defective_qty'=>0];
foreach ($monthStmt->fetchAll() as $row) {
    $m = (int)$row['month_no'];
    if ($m >= 1 && $m <= 12) $months[$m] = ['produced_dozen'=>(float)$row['dozen_total'],'defective_qty'=>(int)$row['defective_total']];
}

$yearDozen = 0.0;
$yearDefective = 0;
foreach ($months as $item) {
    $yearDozen += $item['produced_dozen'];
    $yearDefective += $item['defective_qty'];
}

echo json_encode([
    'ok'=>true,
    'date'=>$date,
    'year'=>$year,
    'month'=>$month,
    'day'=>$day,
    'months'=>$months,
    'year_total'=>['produced_dozen'=>$yearDozen,'defective_qty'=>$yearDefective]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
