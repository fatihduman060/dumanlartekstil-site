<?php

function maas_haciz_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_garnishment_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        payment_date TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        account_id INTEGER,
        account_transaction_id INTEGER,
        reversal_transaction_id INTEGER,
        note TEXT,
        source_salary_record_id INTEGER,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        cancel_reason TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE RESTRICT,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(reversal_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(source_salary_record_id) REFERENCES salary_records(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_salary_garnishment_employee_date ON salary_garnishment_payments(employee_id, payment_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_salary_garnishment_date ON salary_garnishment_payments(payment_date)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_salary_garnishment_source_record ON salary_garnishment_payments(source_salary_record_id) WHERE source_salary_record_id IS NOT NULL');

    if (setting_get('migration_salary_garnishments_v1', '0') === '1') return;
    $salaryTableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='salary_records' LIMIT 1")->fetchColumn();
    if (!$salaryTableExists) return;

    $rows = $pdo->query("SELECT id, employee_id, period, garnishment_amount, payment_date, created_by, created_at
        FROM salary_records WHERE COALESCE(garnishment_amount,0)>0 ORDER BY id ASC")->fetchAll() ?: [];
    $insert = $pdo->prepare("INSERT OR IGNORE INTO salary_garnishment_payments
        (employee_id, payment_date, amount, note, source_salary_record_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $row) {
        $period = preg_match('/^\d{4}-\d{2}$/', (string)($row['period'] ?? '')) ? (string)$row['period'] : date('Y-m');
        $date = (string)($row['payment_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || substr($date, 0, 7) !== $period) $date = $period . '-01';
        $createdAt = trim((string)($row['created_at'] ?? '')) ?: now();
        $insert->execute([
            (int)$row['employee_id'],
            $date,
            (float)$row['garnishment_amount'],
            'Önceki maaş kaydından aktarılan maaş haczi.',
            (int)$row['id'],
            !empty($row['created_by']) ? (int)$row['created_by'] : null,
            $createdAt,
            $createdAt,
        ]);
    }
    setting_set('migration_salary_garnishments_v1', '1');
}

function maas_haciz_period_total(int $employeeId, string $period): float
{
    maas_haciz_db_ensure();
    [$start, $end] = maas_avans_period_bounds($period);
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM salary_garnishment_payments WHERE employee_id=? AND payment_date BETWEEN ? AND ? AND COALESCE(is_cancelled,0)=0');
    $stmt->execute([$employeeId, $start, $end]);
    return round((float)$stmt->fetchColumn(), 2);
}

function maas_haciz_period_rows(string $period, int $employeeId = 0): array
{
    maas_haciz_db_ensure();
    [$start, $end] = maas_avans_period_bounds($period);
    $where = ['sgp.payment_date BETWEEN ? AND ?'];
    $params = [$start, $end];
    if ($employeeId > 0) {
        $where[] = 'sgp.employee_id=?';
        $params[] = $employeeId;
    }
    $sql = "SELECT sgp.*, se.full_name, se.department, se.position, a.name AS account_name, a.bank_name
        FROM salary_garnishment_payments sgp
        JOIN salary_employees se ON se.id=sgp.employee_id
        LEFT JOIN accounts a ON a.id=sgp.account_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY sgp.payment_date DESC, sgp.id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function maas_haciz_find(int $id): ?array
{
    maas_haciz_db_ensure();
    $stmt = db()->prepare('SELECT sgp.*, se.full_name FROM salary_garnishment_payments sgp JOIN salary_employees se ON se.id=sgp.employee_id WHERE sgp.id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function maas_haciz_sync_account_transaction(int $id): void
{
    $row = maas_haciz_find($id);
    if (!$row || (int)($row['is_cancelled'] ?? 0) === 1) return;
    $accountId = (int)($row['account_id'] ?? 0);
    $amount = (float)($row['amount'] ?? 0);
    if ($accountId <= 0 || $amount <= 0) return;

    $accountStmt = db()->prepare('SELECT id FROM accounts WHERE id=? AND is_active=1 LIMIT 1');
    $accountStmt->execute([$accountId]);
    if (!$accountStmt->fetchColumn()) throw new RuntimeException('Kasa/Banka hesabı bulunamadı.');

    $description = 'Maaş haczi ödemesi: ' . ($row['full_name'] ?? 'Personel');
    if (!empty($row['note'])) $description .= ' / ' . trim((string)$row['note']);
    $oldId = (int)($row['account_transaction_id'] ?? 0);
    if ($oldId > 0) {
        db()->prepare("UPDATE account_transactions SET account_id=?, direction='out', amount=?, transaction_date=?, source_type='salary_garnishment', source_id=?, description=? WHERE id=?")
            ->execute([$accountId, $amount, $row['payment_date'], $id, $description, $oldId]);
        return;
    }
    db()->prepare("INSERT INTO account_transactions
        (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
        VALUES (?, 'out', ?, ?, 'salary_garnishment', ?, ?, ?, ?)")
        ->execute([$accountId, $amount, $row['payment_date'], $id, $description, current_user()['id'] ?? null, now()]);
    db()->prepare('UPDATE salary_garnishment_payments SET account_transaction_id=?, updated_at=? WHERE id=?')
        ->execute([(int)db()->lastInsertId(), now(), $id]);
}

function maas_haciz_create(int $employeeId, string $paymentDate, float $amount, ?int $accountId, string $note = ''): array
{
    maas_haciz_db_ensure();
    if ($employeeId <= 0) throw new RuntimeException('Personel seçin.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) throw new RuntimeException('Geçerli bir ödeme tarihi girin.');
    if ($amount <= 0) throw new RuntimeException('Maaş haczi tutarı sıfırdan büyük olmalı.');

    $employeeStmt = db()->prepare('SELECT id, full_name FROM salary_employees WHERE id=? AND is_active=1 LIMIT 1');
    $employeeStmt->execute([$employeeId]);
    $employee = $employeeStmt->fetch();
    if (!$employee) throw new RuntimeException('Personel bulunamadı veya aktif değil.');

    db()->prepare("INSERT INTO salary_garnishment_payments
        (employee_id, payment_date, amount, account_id, note, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$employeeId, $paymentDate, round($amount, 2), $accountId ?: null, trim($note), current_user()['id'] ?? null, now(), now()]);
    $id = (int)db()->lastInsertId();
    maas_haciz_sync_account_transaction($id);
    $row = maas_haciz_find($id) ?: [];
    audit_action('maas_haczi', $id, 'odendi', null, $row, ($employee['full_name'] ?? '') . ' / ' . $paymentDate);
    return $row;
}

function maas_haciz_cancel(int $id, string $reason): ?array
{
    $row = maas_haciz_find($id);
    if (!$row || (int)($row['is_cancelled'] ?? 0) === 1) return null;
    $reason = trim($reason);
    if ($reason === '') $reason = 'Maaş haczi ödemesi iptal edildi.';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $reversalId = null;
        $oldTransactionId = (int)($row['account_transaction_id'] ?? 0);
        $accountId = (int)($row['account_id'] ?? 0);
        if ($oldTransactionId > 0 && $accountId > 0) {
            $description = 'İptal karşılığı: Maaş haczi ödemesi / ' . ($row['full_name'] ?? 'Personel');
            $pdo->prepare("INSERT INTO account_transactions
                (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
                VALUES (?, 'in', ?, ?, 'salary_garnishment_reversal', ?, ?, ?, ?)")
                ->execute([$accountId, (float)$row['amount'], date('Y-m-d'), $id, $description, current_user()['id'] ?? null, now()]);
            $reversalId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE salary_garnishment_payments SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, reversal_transaction_id=?, updated_at=? WHERE id=?')
            ->execute([now(), current_user()['id'] ?? null, $reason, $reversalId, now(), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit_action('maas_haczi', $id, 'iptal', $row, maas_haciz_find($id), $reason);
    return $row;
}
