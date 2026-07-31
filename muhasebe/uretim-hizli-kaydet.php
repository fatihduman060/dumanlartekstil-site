<?php
require_once __DIR__ . '/layout.php';
require_login();
require_write();

function uretim_grup_db_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS production_group_daily (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        production_date TEXT NOT NULL,
        group_code TEXT NOT NULL,
        produced_dozen REAL NOT NULL DEFAULT 0,
        defective_qty INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(production_date, group_code),
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_production_group_daily_date ON production_group_daily(production_date)');
}

function uretim_grup_kodu(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['A','B','C','D','E'], true) ? $value : '';
}

uretim_grup_db_ensure();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $stmt = db()->prepare('SELECT group_code, produced_dozen, defective_qty FROM production_group_daily WHERE production_date=?');
    $stmt->execute([$date]);
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $code = uretim_grup_kodu((string)$row['group_code']);
        if ($code === '') continue;
        $groups[$code] = [
            'produced_dozen' => (float)$row['produced_dozen'],
            'defective_qty' => (int)$row['defective_qty'],
        ];
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true, 'date'=>$date, 'groups'=>$groups], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('uretim-takibi.php');
require_csrf();

$date = trim((string)($_POST['production_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$postedGroups = is_array($_POST['groups'] ?? null) ? $_POST['groups'] : [];
$pdo = db();

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id FROM production_group_daily WHERE production_date=? AND group_code=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO production_group_daily (production_date,group_code,produced_dozen,defective_qty,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
    $update = $pdo->prepare('UPDATE production_group_daily SET produced_dozen=?, defective_qty=?, updated_at=? WHERE id=?');

    foreach (['A','B','C','D','E'] as $group) {
        $row = is_array($postedGroups[$group] ?? null) ? $postedGroups[$group] : [];
        $dozen = max(0, decimal_from_input($row['produced_dozen'] ?? 0));
        $defectiveRaw = preg_replace('/\D+/', '', (string)($row['defective_qty'] ?? '0')) ?: '0';
        $defective = max(0, (int)$defectiveRaw);

        $find->execute([$date, $group]);
        $id = (int)($find->fetchColumn() ?: 0);
        if ($id > 0) {
            $update->execute([$dozen, $defective, now(), $id]);
        } else {
            $insert->execute([$date, $group, $dozen, $defective, current_user()['id'] ?? null, now(), now()]);
        }
    }

    $pdo->commit();
    flash('success', date('d.m.Y', strtotime($date)) . ' tarihli A–E grup üretimleri kaydedildi.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Günlük üretim kaydedilemedi: ' . $e->getMessage());
}

redirect('uretim-takibi.php?date=' . urlencode($date));
