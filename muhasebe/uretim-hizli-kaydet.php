<?php
require_once __DIR__ . '/layout.php';
require_login();
require_write();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('uretim-takibi.php');
require_csrf();

function uretim_shift_group(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['A','B','C','D','E'], true) ? $value : 'A';
}

function uretim_shift_code(string $value): string
{
    return $value === 'gece' ? 'gece' : 'gunduz';
}

function uretim_shift_ensure(): void
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

uretim_shift_ensure();
$date = trim((string)($_POST['production_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$shift = uretim_shift_code((string)($_POST['shift_code'] ?? 'gunduz'));
$rows = is_array($_POST['shift_rows'] ?? null) ? $_POST['shift_rows'] : [];
$pdo = db();
$saved = false;

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT id FROM production_group_shift_entries WHERE production_date=? AND shift_code=? AND group_code=? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO production_group_shift_entries (production_date,shift_code,group_code,produced_dozen,defective_qty,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
    $update = $pdo->prepare('UPDATE production_group_shift_entries SET produced_dozen=?, defective_qty=?, updated_at=? WHERE id=?');

    foreach (['A','B','C','D','E'] as $group) {
        $row = is_array($rows[$group] ?? null) ? $rows[$group] : [];
        $dozen = max(0, decimal_from_input($row['produced_dozen'] ?? 0));
        $defectiveRaw = preg_replace('/\D+/', '', (string)($row['defective_qty'] ?? '0')) ?: '0';
        $defective = max(0, (int)$defectiveRaw);
        $groupCode = uretim_shift_group($group);

        $find->execute([$date, $shift, $groupCode]);
        $id = (int)($find->fetchColumn() ?: 0);
        if ($id > 0) {
            $update->execute([$dozen, $defective, now(), $id]);
        } else {
            $insert->execute([$date, $shift, $groupCode, $dozen, $defective, current_user()['id'] ?? null, now(), now()]);
        }
    }

    $pdo->commit();
    $saved = true;
    $shiftName = $shift === 'gece' ? 'Gece vardiyası' : 'Gündüz vardiyası';
    flash('success', date('d.m.Y', strtotime($date)) . ' ' . $shiftName . ' üretimi kaydedildi.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Vardiya üretimi kaydedilemedi: ' . $e->getMessage());
}

$nextDate = $date;
if ($saved) {
    $completeStmt = db()->prepare("SELECT COUNT(DISTINCT shift_code) FROM production_group_shift_entries WHERE production_date=? AND shift_code IN ('gunduz','gece')");
    $completeStmt->execute([$date]);
    if ((int)$completeStmt->fetchColumn() >= 2) {
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        if (setting_get('production_weekend_enabled', '0') !== '1') {
            while (in_array((int)date('N', strtotime($nextDate)), [6, 7], true)) {
                $nextDate = date('Y-m-d', strtotime($nextDate . ' +1 day'));
            }
        }
    }
}
redirect('uretim-takibi.php?date=' . urlencode($nextDate));
