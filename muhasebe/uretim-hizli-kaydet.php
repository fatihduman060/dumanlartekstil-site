<?php
require_once __DIR__ . '/layout.php';
require_login();
require_write();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('uretim-takibi.php');
require_csrf();

function uretim_hizli_group(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['A','B','C','D','E'], true) ? $value : 'A';
}

function uretim_hizli_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS production_machines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_code TEXT NOT NULL,
        machine_no TEXT NOT NULL,
        default_article TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(group_code, machine_no),
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS production_daily_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        production_date TEXT NOT NULL,
        machine_id INTEGER NOT NULL,
        article TEXT,
        produced_dozen REAL NOT NULL DEFAULT 0,
        defective_qty INTEGER NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(production_date, machine_id),
        FOREIGN KEY(machine_id) REFERENCES production_machines(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

uretim_hizli_db_ensure();
$date = trim((string)($_POST['production_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$rows = is_array($_POST['quick_rows'] ?? null) ? $_POST['quick_rows'] : [];
$pdo = db();
$saved = 0;

try {
    $pdo->beginTransaction();
    $findMachine = $pdo->prepare('SELECT id FROM production_machines WHERE group_code=? AND machine_no=? LIMIT 1');
    $maxSort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM production_machines WHERE group_code=?');
    $insertMachine = $pdo->prepare('INSERT INTO production_machines (group_code,machine_no,default_article,sort_order,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,1,?,?,?)');
    $activateMachine = $pdo->prepare('UPDATE production_machines SET is_active=1, default_article=COALESCE(NULLIF(?,\'\'),default_article), updated_at=? WHERE id=?');
    $findEntry = $pdo->prepare('SELECT id FROM production_daily_entries WHERE production_date=? AND machine_id=? LIMIT 1');
    $insertEntry = $pdo->prepare('INSERT INTO production_daily_entries (production_date,machine_id,article,produced_dozen,defective_qty,note,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $updateEntry = $pdo->prepare('UPDATE production_daily_entries SET article=?, produced_dozen=?, defective_qty=?, note=?, updated_at=? WHERE id=?');

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $group = uretim_hizli_group((string)($row['group_code'] ?? 'A'));
        $machineNo = trim((string)($row['machine_no'] ?? ''));
        $article = trim((string)($row['article'] ?? ''));
        $dozen = max(0, decimal_from_input($row['produced_dozen'] ?? 0));
        $defectiveRaw = preg_replace('/\D+/', '', (string)($row['defective_qty'] ?? '0')) ?: '0';
        $defective = max(0, (int)$defectiveRaw);
        $note = trim((string)($row['note'] ?? ''));

        if ($machineNo === '') continue;
        if ($dozen <= 0 && $defective <= 0 && $article === '' && $note === '') continue;

        $findMachine->execute([$group, $machineNo]);
        $machineId = (int)($findMachine->fetchColumn() ?: 0);
        if ($machineId <= 0) {
            $maxSort->execute([$group]);
            $sort = (int)$maxSort->fetchColumn();
            $insertMachine->execute([$group, $machineNo, $article ?: null, $sort, current_user()['id'] ?? null, now(), now()]);
            $machineId = (int)$pdo->lastInsertId();
        } else {
            $activateMachine->execute([$article, now(), $machineId]);
        }

        $findEntry->execute([$date, $machineId]);
        $entryId = (int)($findEntry->fetchColumn() ?: 0);
        if ($entryId > 0) {
            $updateEntry->execute([$article ?: null, $dozen, $defective, $note ?: null, now(), $entryId]);
        } else {
            $insertEntry->execute([$date, $machineId, $article ?: null, $dozen, $defective, $note ?: null, current_user()['id'] ?? null, now(), now()]);
        }
        $saved++;
    }

    $pdo->commit();
    flash($saved > 0 ? 'success' : 'warning', $saved > 0 ? ($saved . ' makinenin günlük üretimi kaydedildi.') : 'Kaydedilecek dolu üretim satırı bulunamadı.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Günlük üretim kaydedilemedi: ' . $e->getMessage());
}

redirect('uretim-takibi.php?date=' . urlencode($date));
