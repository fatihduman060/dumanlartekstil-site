<?php

function maas_avans_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_advances (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        advance_date TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        account_id INTEGER,
        account_transaction_id INTEGER,
        note TEXT,
        source_salary_record_id INTEGER,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(source_salary_record_id) REFERENCES salary_records(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_salary_advances_employee_date ON salary_advances(employee_id, advance_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_salary_advances_date ON salary_advances(advance_date)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_salary_advances_source_record ON salary_advances(source_salary_record_id) WHERE source_salary_record_id IS NOT NULL');

    if (setting_get('migration_salary_advances_v1', '0') === '1') return;

    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='salary_records' LIMIT 1")->fetchColumn();
    if (!$tableExists) return;

    $rows = $pdo->query("SELECT id, employee_id, period, advance_amount, payment_date, created_by, created_at
        FROM salary_records WHERE COALESCE(advance_amount,0)>0 ORDER BY id ASC")->fetchAll() ?: [];
    $insert = $pdo->prepare("INSERT OR IGNORE INTO salary_advances
        (employee_id, advance_date, amount, account_id, account_transaction_id, note, source_salary_record_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)");

    foreach ($rows as $row) {
        $period = preg_match('/^\d{4}-\d{2}$/', (string)($row['period'] ?? '')) ? (string)$row['period'] : date('Y-m');
        $date = (string)($row['payment_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || substr($date, 0, 7) !== $period) {
            $date = $period . '-01';
        }
        $createdAt = trim((string)($row['created_at'] ?? '')) ?: now();
        $insert->execute([
            (int)$row['employee_id'],
            $date,
            (float)$row['advance_amount'],
            'Önceki maaş kaydından otomatik aktarılan avans.',
            (int)$row['id'],
            !empty($row['created_by']) ? (int)$row['created_by'] : null,
            $createdAt,
            $createdAt,
        ]);
    }

    setting_set('migration_salary_advances_v1', '1');
}

function maas_avans_period(string $dateOrPeriod): string
{
    $value = trim($dateOrPeriod);
    if (preg_match('/^\d{4}-\d{2}/', $value, $match)) return $match[0];
    return date('Y-m');
}

function maas_avans_period_bounds(string $period): array
{
    $period = maas_avans_period($period);
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function maas_avans_period_total(int $employeeId, string $period): float
{
    maas_avans_db_ensure();
    [$start, $end] = maas_avans_period_bounds($period);
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM salary_advances WHERE employee_id=? AND advance_date BETWEEN ? AND ?');
    $stmt->execute([$employeeId, $start, $end]);
    return round((float)$stmt->fetchColumn(), 2);
}

function maas_avans_period_rows(string $period, int $employeeId = 0): array
{
    maas_avans_db_ensure();
    [$start, $end] = maas_avans_period_bounds($period);
    $where = ['sa.advance_date BETWEEN ? AND ?'];
    $params = [$start, $end];
    if ($employeeId > 0) {
        $where[] = 'sa.employee_id=?';
        $params[] = $employeeId;
    }
    $sql = "SELECT sa.*, se.full_name, se.department, se.position, a.name AS account_name, a.bank_name
        FROM salary_advances sa
        JOIN salary_employees se ON se.id=sa.employee_id
        LEFT JOIN accounts a ON a.id=sa.account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sa.advance_date DESC, sa.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function maas_avans_find(int $id): ?array
{
    maas_avans_db_ensure();
    $stmt = db()->prepare("SELECT sa.*, se.full_name FROM salary_advances sa JOIN salary_employees se ON se.id=sa.employee_id WHERE sa.id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function maas_avans_sync_account_transaction(int $advanceId): void
{
    $stmt = db()->prepare("SELECT sa.*, se.full_name, a.id AS account_exists
        FROM salary_advances sa
        JOIN salary_employees se ON se.id=sa.employee_id
        LEFT JOIN accounts a ON a.id=sa.account_id
        WHERE sa.id=? LIMIT 1");
    $stmt->execute([$advanceId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $oldTxn = (int)($row['account_transaction_id'] ?? 0);
    $accountId = (int)($row['account_id'] ?? 0);
    $amount = (float)($row['amount'] ?? 0);

    if ($accountId <= 0 || $amount <= 0 || empty($row['account_exists'])) {
        if ($oldTxn > 0) {
            db()->prepare("DELETE FROM account_transactions WHERE id=? AND source_type='salary_advance'")->execute([$oldTxn]);
        }
        db()->prepare('UPDATE salary_advances SET account_transaction_id=NULL, updated_at=? WHERE id=?')->execute([now(), $advanceId]);
        return;
    }

    $description = 'Maaş avansı: ' . ($row['full_name'] ?? '') . ' / ' . tr_date($row['advance_date'] ?? null);
    if (!empty($row['note'])) $description .= ' / ' . trim((string)$row['note']);

    if ($oldTxn > 0) {
        db()->prepare("UPDATE account_transactions SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary_advance', source_id=?, description=? WHERE id=?")
            ->execute([$accountId, $amount, $row['advance_date'], $advanceId, $description, $oldTxn]);
    } else {
        db()->prepare("INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
            VALUES (?, 'out', ?, ?, 'salary_advance', ?, ?, ?, ?)")
            ->execute([$accountId, $amount, $row['advance_date'], $advanceId, $description, current_user()['id'] ?? null, now()]);
        $newId = (int)db()->lastInsertId();
        db()->prepare('UPDATE salary_advances SET account_transaction_id=?, updated_at=? WHERE id=?')->execute([$newId, now(), $advanceId]);
    }
}

function maas_avans_create(int $employeeId, string $advanceDate, float $amount, ?int $accountId, string $note = ''): array
{
    maas_avans_db_ensure();
    if ($employeeId <= 0) throw new RuntimeException('Personel seçin.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $advanceDate)) throw new RuntimeException('Geçerli bir avans tarihi girin.');
    if ($amount <= 0) throw new RuntimeException('Avans tutarı sıfırdan büyük olmalı.');

    $employeeStmt = db()->prepare('SELECT id, full_name FROM salary_employees WHERE id=? AND is_active=1 LIMIT 1');
    $employeeStmt->execute([$employeeId]);
    $employee = $employeeStmt->fetch();
    if (!$employee) throw new RuntimeException('Personel bulunamadı veya aktif değil.');

    if ($accountId) {
        $accountStmt = db()->prepare('SELECT id FROM accounts WHERE id=? LIMIT 1');
        $accountStmt->execute([$accountId]);
        if (!$accountStmt->fetchColumn()) throw new RuntimeException('Kasa/Banka hesabı bulunamadı.');
    }

    db()->prepare("INSERT INTO salary_advances
        (employee_id, advance_date, amount, account_id, note, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$employeeId, $advanceDate, round($amount, 2), $accountId ?: null, trim($note), current_user()['id'] ?? null, now(), now()]);
    $id = (int)db()->lastInsertId();
    maas_avans_sync_account_transaction($id);

    $row = maas_avans_find($id) ?: [];
    audit_action('maas_avans', $id, 'eklendi', null, $row, ($employee['full_name'] ?? '') . ' / ' . $advanceDate);
    return $row;
}

function maas_avans_delete(int $id): ?array
{
    maas_avans_db_ensure();
    $row = maas_avans_find($id);
    if (!$row) return null;

    $txnId = (int)($row['account_transaction_id'] ?? 0);
    if ($txnId > 0) {
        db()->prepare("DELETE FROM account_transactions WHERE id=? AND source_type='salary_advance'")->execute([$txnId]);
    }
    db()->prepare('DELETE FROM salary_advances WHERE id=?')->execute([$id]);
    audit_action('maas_avans', $id, 'silindi', $row, null, ($row['full_name'] ?? '') . ' / ' . ($row['advance_date'] ?? ''));
    return $row;
}
