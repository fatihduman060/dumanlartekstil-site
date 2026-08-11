<?php
require_once __DIR__ . '/layout.php';
require_admin();

function mk_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
        'Â'=>'A','Î'=>'I','Û'=>'U','â'=>'A','î'=>'I','û'=>'U',
    ];
    $value = strtoupper(strtr(trim($value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function mk_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function mk_table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function mk_columns(string $table): array
{
    if (!mk_table_exists($table)) return [];
    $rows = db()->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name !== '') $out[$name] = true;
    }
    return $out;
}

function mk_has_column(string $table, string $column): bool
{
    $columns = mk_columns($table);
    return isset($columns[$column]);
}

function mk_ensure_movement_ignore_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS duplicate_movement_ignores (
        first_movement_id INTEGER NOT NULL,
        second_movement_id INTEGER NOT NULL,
        ignored_by INTEGER,
        ignored_at TEXT NOT NULL,
        PRIMARY KEY (first_movement_id, second_movement_id)
    )");
}

function mk_ignore_movement_pair(int $firstId, int $secondId): void
{
    $lowerId = min($firstId, $secondId);
    $higherId = max($firstId, $secondId);
    $firstId = $lowerId;
    $secondId = $higherId;
    if ($firstId <= 0 || $secondId <= 0 || $firstId === $secondId) {
        throw new RuntimeException('Hareket seçimi geçersiz.');
    }

    $pairExists = false;
    foreach (mk_movement_pairs() as $pair) {
        if ((int)$pair['first_id'] === $firstId && (int)$pair['second_id'] === $secondId) {
            $pairExists = true;
            break;
        }
    }
    if (!$pairExists) {
        throw new RuntimeException('Olası mükerrer hareket çifti artık bulunamadı.');
    }

    mk_ensure_movement_ignore_table();
    db()->prepare("INSERT OR IGNORE INTO duplicate_movement_ignores
        (first_movement_id, second_movement_id, ignored_by, ignored_at)
        VALUES (?, ?, ?, ?)")
        ->execute([$firstId, $secondId, current_user()['id'] ?? null, now()]);

    audit_action('hareket', $firstId, 'mukerrer_degil', null, [
        'first_movement_id'=>$firstId,
        'second_movement_id'=>$secondId,
    ], 'Mükerrer değil');
    log_action('Hareket çifti mükerrer değil işaretlendi', '#' . $firstId . ' / #' . $secondId);
}

function mk_cari_meta(int $cariId): array
{
    $meta = [
        'movement_count'=>0,
        'invoice_count'=>0,
        'check_count'=>0,
        'document_count'=>0,
        'balance'=>0.0,
    ];

    if (mk_table_exists('movements')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM movements WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$cariId]);
        $meta['movement_count'] = (int)$stmt->fetchColumn();
    }
    if (mk_table_exists('invoices') && mk_has_column('invoices', 'cari_id')) {
        $cancelSql = mk_has_column('invoices', 'is_cancelled') ? ' AND COALESCE(is_cancelled,0)=0' : '';
        $stmt = db()->prepare("SELECT COUNT(*) FROM invoices WHERE cari_id=?" . $cancelSql);
        $stmt->execute([$cariId]);
        $meta['invoice_count'] = (int)$stmt->fetchColumn();
    }
    if (mk_table_exists('checks')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM checks WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$cariId]);
        $meta['check_count'] = (int)$stmt->fetchColumn();
    }
    if (mk_table_exists('standalone_documents') && mk_has_column('standalone_documents', 'cari_id')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM standalone_documents WHERE cari_id=?");
        $stmt->execute([$cariId]);
        $meta['document_count'] = (int)$stmt->fetchColumn();
    }
    $balance = cari_balance($cariId);
    $meta['balance'] = (float)($balance['net'] ?? 0);
    return $meta;
}

function mk_cari_duplicate_groups(): array
{
    $rows = db()->query("SELECT * FROM cariler ORDER BY id ASC")->fetchAll();
    $nameGroups = [];
    $taxGroups = [];

    foreach ($rows as $row) {
        $nameKey = mk_norm((string)($row['name'] ?? ''));
        if ($nameKey !== '') {
            if (!isset($nameGroups[$nameKey])) $nameGroups[$nameKey] = [];
            $nameGroups[$nameKey][] = $row;
        }

        $taxKey = mk_digits((string)($row['tax_no'] ?? ''));
        if ($taxKey !== '') {
            if (!isset($taxGroups[$taxKey])) $taxGroups[$taxKey] = [];
            $taxGroups[$taxKey][] = $row;
        }
    }

    $nameGroups = array_filter($nameGroups, function ($group) {
        return count($group) > 1;
    });
    $taxGroups = array_filter($taxGroups, function ($group) {
        return count($group) > 1;
    });

    return [$nameGroups, $taxGroups];
}

function mk_movement_pairs(): array
{
    if (!mk_table_exists('movements')) return [];
    mk_ensure_movement_ignore_table();

    $sql = "SELECT
        a.id AS first_id,
        b.id AS second_id,
        a.cari_id,
        COALESCE(c.name,'Cari yok') AS cari_name,
        a.movement_type,
        a.amount,
        COALESCE(a.currency,'TL') AS currency,
        a.movement_date,
        a.account_id,
        COALESCE(ac.name,'Hesap yok') AS account_name,
        COALESCE(a.description,'') AS first_description,
        COALESCE(b.description,'') AS second_description,
        COALESCE(a.payment_method,'') AS first_payment_method,
        COALESCE(b.payment_method,'') AS second_payment_method,
        a.created_at AS first_created_at,
        b.created_at AS second_created_at,
        CASE
          WHEN COALESCE(a.description,'') LIKE 'Vade kapatma #%'
            OR COALESCE(b.description,'') LIKE 'Vade kapatma #%'
          THEN 1 ELSE 0
        END AS is_due_pair,
        CASE
          WHEN COALESCE(a.description,'') = COALESCE(b.description,'')
            AND COALESCE(a.payment_method,'') = COALESCE(b.payment_method,'')
          THEN 1 ELSE 0
        END AS is_exact_text
      FROM movements a
      JOIN movements b ON b.id>a.id
        AND COALESCE(b.is_cancelled,0)=0
        AND b.movement_type=a.movement_type
        AND ABS(b.amount-a.amount)<0.005
        AND COALESCE(b.currency,'TL')=COALESCE(a.currency,'TL')
        AND b.movement_date=a.movement_date
        AND COALESCE(b.account_id,0)=COALESCE(a.account_id,0)
        AND COALESCE(b.cari_id,0)=COALESCE(a.cari_id,0)
      LEFT JOIN cariler c ON c.id=a.cari_id
      LEFT JOIN accounts ac ON ac.id=a.account_id
      WHERE COALESCE(a.is_cancelled,0)=0
        AND NOT EXISTS (
          SELECT 1 FROM duplicate_movement_ignores ignored
          WHERE ignored.first_movement_id=a.id
            AND ignored.second_movement_id=b.id
        )
        AND a.movement_type IN ('tahsilat','odeme','gelir','gider')
        AND (
          COALESCE(a.description,'') LIKE 'Vade kapatma #%'
          OR COALESCE(b.description,'') LIKE 'Vade kapatma #%'
          OR (
            COALESCE(a.description,'') = COALESCE(b.description,'')
            AND COALESCE(a.payment_method,'') = COALESCE(b.payment_method,'')
          )
        )
      ORDER BY a.movement_date DESC, cari_name ASC, a.id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_account_source_duplicates(): array
{
    if (!mk_table_exists('account_transactions')) return [];

    $sql = "SELECT
        account_id,
        direction,
        amount,
        transaction_date,
        source_type,
        source_id,
        COUNT(*) AS duplicate_count,
        MIN(id) AS first_id,
        MAX(id) AS last_id,
        GROUP_CONCAT(id) AS ids,
        GROUP_CONCAT(COALESCE(description,''), ' || ') AS descriptions
      FROM account_transactions
      WHERE source_id IS NOT NULL
        AND TRIM(COALESCE(source_type,'')) <> ''
      GROUP BY account_id, direction, amount, transaction_date, source_type, source_id
      HAVING COUNT(*) > 1
      ORDER BY transaction_date DESC, last_id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_check_duplicates(): array
{
    if (!mk_table_exists('checks')) return [];

    $sql = "SELECT
        a.id AS first_id,
        b.id AS second_id,
        a.cari_id,
        COALESCE(c.name,'Cari yok') AS cari_name,
        a.direction,
        a.amount,
        a.due_date,
        COALESCE(a.bank_name,'') AS bank_name,
        COALESCE(a.check_no,'') AS check_no
      FROM checks a
      JOIN checks b ON b.id>a.id
        AND COALESCE(b.is_cancelled,0)=0
        AND b.direction=a.direction
        AND ABS(b.amount-a.amount)<0.005
        AND b.due_date=a.due_date
        AND COALESCE(b.cari_id,0)=COALESCE(a.cari_id,0)
        AND UPPER(TRIM(COALESCE(b.bank_name,'')))=UPPER(TRIM(COALESCE(a.bank_name,'')))
        AND UPPER(TRIM(COALESCE(b.check_no,'')))=UPPER(TRIM(COALESCE(a.check_no,'')))
      LEFT JOIN cariler c ON c.id=a.cari_id
      WHERE COALESCE(a.is_cancelled,0)=0
        AND TRIM(COALESCE(a.check_no,'')) <> ''
      ORDER BY a.due_date DESC, a.id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_invoice_duplicates(): array
{
    if (!mk_table_exists('invoices')) return [];
    $columns = mk_columns('invoices');

    $required = ['id','direction','invoice_date','total_amount'];
    foreach ($required as $column) {
        if (!isset($columns[$column])) return [];
    }

    $cariA = isset($columns['cari_id']) ? 'COALESCE(a.cari_id,0)' : '0';
    $cariB = isset($columns['cari_id']) ? 'COALESCE(b.cari_id,0)' : '0';
    $currencyA = isset($columns['currency']) ? "COALESCE(a.currency,'TL')" : "'TL'";
    $currencyB = isset($columns['currency']) ? "COALESCE(b.currency,'TL')" : "'TL'";
    $cancelA = isset($columns['is_cancelled']) ? 'COALESCE(a.is_cancelled,0)=0' : '1=1';
    $cancelB = isset($columns['is_cancelled']) ? 'COALESCE(b.is_cancelled,0)=0' : '1=1';

    if (isset($columns['invoice_no'])) {
        $identityA = "UPPER(TRIM(COALESCE(a.invoice_no,'')))";
        $identityB = "UPPER(TRIM(COALESCE(b.invoice_no,'')))";
        $identitySelect = "COALESCE(a.invoice_no,'')";
        $identityWhere = "TRIM(COALESCE(a.invoice_no,'')) <> ''";
    } elseif (isset($columns['document_name'])) {
        $identityA = "UPPER(TRIM(COALESCE(a.document_name,'')))";
        $identityB = "UPPER(TRIM(COALESCE(b.document_name,'')))";
        $identitySelect = "COALESCE(a.document_name,'')";
        $identityWhere = "TRIM(COALESCE(a.document_name,'')) <> ''";
    } else {
        return [];
    }

    $cariJoin = isset($columns['cari_id']) ? 'LEFT JOIN cariler c ON c.id=a.cari_id' : '';
    $cariName = isset($columns['cari_id']) ? "COALESCE(c.name,'Cari yok')" : "'Cari yok'";

    $sql = "SELECT
        a.id AS first_id,
        b.id AS second_id,
        {$cariName} AS cari_name,
        a.direction,
        a.invoice_date,
        a.total_amount,
        {$currencyA} AS currency,
        {$identitySelect} AS identity_value
      FROM invoices a
      JOIN invoices b ON b.id>a.id
        AND {$cancelB}
        AND b.direction=a.direction
        AND ABS(b.total_amount-a.total_amount)<0.005
        AND b.invoice_date=a.invoice_date
        AND {$cariB}={$cariA}
        AND {$currencyB}={$currencyA}
        AND {$identityB}={$identityA}
      {$cariJoin}
      WHERE {$cancelA}
        AND {$identityWhere}
      ORDER BY a.invoice_date DESC, a.id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_card_duplicates(): array
{
    if (!mk_table_exists('card_statements')) return [];
    $columns = mk_columns('card_statements');

    foreach (['id','amount'] as $required) {
        if (!isset($columns[$required])) return [];
    }

    $cardCol = isset($columns['card_key']) ? 'card_key' : (isset($columns['card_name']) ? 'card_name' : '');
    $periodCol = isset($columns['statement_period']) ? 'statement_period' : '';
    if ($cardCol === '' || $periodCol === '') return [];

    $dueCompare = isset($columns['due_date'])
        ? "AND COALESCE(b.due_date,'')=COALESCE(a.due_date,'')"
        : '';
    $statusFilterA = isset($columns['status'])
        ? "AND COALESCE(a.status,'bekliyor')<>'iptal'"
        : '';
    $statusFilterB = isset($columns['status'])
        ? "AND COALESCE(b.status,'bekliyor')<>'iptal'"
        : '';

    $sql = "SELECT
        a.id AS first_id,
        b.id AS second_id,
        COALESCE(a.{$cardCol},'') AS card_label,
        COALESCE(a.{$periodCol},'') AS statement_period,
        a.amount
      FROM card_statements a
      JOIN card_statements b ON b.id>a.id
        {$statusFilterB}
        AND UPPER(TRIM(COALESCE(b.{$cardCol},'')))=UPPER(TRIM(COALESCE(a.{$cardCol},'')))
        AND COALESCE(b.{$periodCol},'')=COALESCE(a.{$periodCol},'')
        AND ABS(b.amount-a.amount)<0.005
        {$dueCompare}
      WHERE 1=1 {$statusFilterA}
      ORDER BY a.id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_compensation_duplicates(): array
{
    if (!mk_table_exists('salary_compensation_payments')) return [];

    $sql = "SELECT
        a.id AS first_id,
        b.id AS second_id,
        a.employee_id,
        COALESCE(se.full_name,'Personel') AS employee_name,
        a.compensation_type,
        a.amount,
        a.payment_date,
        a.account_id
      FROM salary_compensation_payments a
      JOIN salary_compensation_payments b ON b.id>a.id
        AND COALESCE(b.is_cancelled,0)=0
        AND b.employee_id=a.employee_id
        AND b.compensation_type=a.compensation_type
        AND ABS(b.amount-a.amount)<0.005
        AND b.payment_date=a.payment_date
        AND COALESCE(b.account_id,0)=COALESCE(a.account_id,0)
      LEFT JOIN salary_employees se ON se.id=a.employee_id
      WHERE COALESCE(a.is_cancelled,0)=0
      ORDER BY a.payment_date DESC, a.id DESC";
    return db()->query($sql)->fetchAll();
}

function mk_parse_due_source_id(string $description): int
{
    if (preg_match('/Vade kapatma #(\d+)/i', $description, $match)) {
        return (int)$match[1];
    }
    return 0;
}

function mk_movements_match(array $first, array $second): bool
{
    return (string)$first['movement_type'] === (string)$second['movement_type']
        && abs((float)$first['amount'] - (float)$second['amount']) < 0.005
        && (string)($first['currency'] ?? 'TL') === (string)($second['currency'] ?? 'TL')
        && (string)$first['movement_date'] === (string)$second['movement_date']
        && (int)($first['account_id'] ?? 0) === (int)($second['account_id'] ?? 0)
        && (int)($first['cari_id'] ?? 0) === (int)($second['cari_id'] ?? 0);
}

function mk_cancel_duplicate_movement(int $keepId, int $cancelId, string $reason): void
{
    if ($keepId <= 0 || $cancelId <= 0 || $keepId === $cancelId) {
        throw new RuntimeException('Hareket seçimi geçersiz.');
    }

    $stmt = db()->prepare("SELECT * FROM movements WHERE id IN (?,?) ORDER BY id ASC");
    $stmt->execute([$keepId, $cancelId]);
    $rows = $stmt->fetchAll();
    $byId = [];
    foreach ($rows as $row) $byId[(int)$row['id']] = $row;

    $keep = $byId[$keepId] ?? null;
    $cancel = $byId[$cancelId] ?? null;
    if (!$keep || !$cancel) throw new RuntimeException('Hareketlerden biri bulunamadı.');
    if ((int)($keep['is_cancelled'] ?? 0) === 1 || (int)($cancel['is_cancelled'] ?? 0) === 1) {
        throw new RuntimeException('Hareketlerden biri daha önce iptal edilmiş.');
    }
    if (!mk_movements_match($keep, $cancel)) {
        throw new RuntimeException('Kayıtlar aynı cari, tutar, tarih, para birimi ve hesapta değil.');
    }

    $keepDesc = (string)($keep['description'] ?? '');
    $cancelDesc = (string)($cancel['description'] ?? '');
    $isDuePair = mk_parse_due_source_id($keepDesc) > 0 || mk_parse_due_source_id($cancelDesc) > 0;
    $isExactText = trim($keepDesc) === trim($cancelDesc)
        && trim((string)($keep['payment_method'] ?? '')) === trim((string)($cancel['payment_method'] ?? ''));
    if (!$isDuePair && !$isExactText) {
        throw new RuntimeException('Bu iki kayıt kesin mükerrer olarak doğrulanamadı.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE movements
            SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=?
            WHERE id=? AND COALESCE(is_cancelled,0)=0")
            ->execute([now(), current_user()['id'] ?? null, $reason, now(), $cancelId]);

        if (mk_has_column('movements', 'reminder_settlement_movement_id')) {
            $pdo->prepare("UPDATE movements
                SET reminder_settlement_movement_id=?, updated_at=?
                WHERE reminder_settlement_movement_id=?")
                ->execute([$keepId, now(), $cancelId]);
        }

        sync_movement_account_transaction($cancelId);
        if (function_exists('sync_movement_to_check')) {
            sync_movement_to_check($cancelId, false);
        }

        audit_action('hareket', $cancelId, 'mukerrer_iptal', $cancel, [
            'is_cancelled'=>1,
            'kept_movement_id'=>$keepId,
            'reason'=>$reason,
        ], 'Mükerrer hareket');
        log_action('Mükerrer hareket iptal edildi', '#' . $cancelId . ' → korunan #' . $keepId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function mk_merge_cari(int $keepId, int $removeId): void
{
    if ($keepId <= 0 || $removeId <= 0 || $keepId === $removeId) {
        throw new RuntimeException('Cari seçimi geçersiz.');
    }

    $stmt = db()->prepare("SELECT * FROM cariler WHERE id IN (?,?) ORDER BY id ASC");
    $stmt->execute([$keepId, $removeId]);
    $rows = $stmt->fetchAll();
    $byId = [];
    foreach ($rows as $row) $byId[(int)$row['id']] = $row;

    $keep = $byId[$keepId] ?? null;
    $remove = $byId[$removeId] ?? null;
    if (!$keep || !$remove) throw new RuntimeException('Cari kayıtlarından biri bulunamadı.');

    $sameName = mk_norm((string)$keep['name']) !== ''
        && mk_norm((string)$keep['name']) === mk_norm((string)$remove['name']);
    $keepTax = mk_digits((string)($keep['tax_no'] ?? ''));
    $removeTax = mk_digits((string)($remove['tax_no'] ?? ''));
    $sameTax = $keepTax !== '' && $keepTax === $removeTax;
    if (!$sameName && !$sameTax) {
        throw new RuntimeException('Bu iki cari aynı ünvan veya aynı vergi numarasıyla eşleşmiyor.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tables = $pdo->query("SELECT name FROM sqlite_master
            WHERE type='table' AND name NOT LIKE 'sqlite_%'
            ORDER BY name")->fetchAll();

        $updated = [];
        foreach ($tables as $tableRow) {
            $table = (string)($tableRow['name'] ?? '');
            if ($table === '' || $table === 'cariler') continue;
            $columns = mk_columns($table);
            if (!isset($columns['cari_id'])) continue;

            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET cari_id=? WHERE cari_id=?');
            $stmt->execute([$keepId, $removeId]);
            $count = $stmt->rowCount();
            if ($count > 0) $updated[$table] = $count;
        }

        $mergeFields = ['tax_no','tax_office','authorized_person','phone','email','city','address','iban','notes'];
        $setParts = [];
        $params = [];
        foreach ($mergeFields as $field) {
            $keepValue = trim((string)($keep[$field] ?? ''));
            $removeValue = trim((string)($remove[$field] ?? ''));
            if ($keepValue === '' && $removeValue !== '') {
                $setParts[] = $field . '=?';
                $params[] = $removeValue;
            }
        }
        if ($setParts) {
            $setParts[] = 'updated_at=?';
            $params[] = now();
            $params[] = $keepId;
            $pdo->prepare('UPDATE cariler SET ' . implode(', ', $setParts) . ' WHERE id=?')->execute($params);
        }

        $pdo->prepare('DELETE FROM cariler WHERE id=?')->execute([$removeId]);

        audit_action('cari', $removeId, 'mukerrer_birlestirildi', $remove, [
            'kept_cari_id'=>$keepId,
            'updated_tables'=>$updated,
        ], (string)$remove['name']);
        log_action('Mükerrer cari birleştirildi', '#' . $removeId . ' → #' . $keepId . ' / ' . (string)$keep['name']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function mk_auto_fix_safe(): array
{
    $result = [
        'account_sync'=>null,
        'movement_cancelled'=>0,
    ];

    if (function_exists('repair_account_sync')) {
        $result['account_sync'] = repair_account_sync(true);
    }

    $pairs = mk_movement_pairs();
    $done = [];
    foreach ($pairs as $pair) {
        $firstId = (int)$pair['first_id'];
        $secondId = (int)$pair['second_id'];
        if (isset($done[$firstId]) || isset($done[$secondId])) continue;

        $sourceId = mk_parse_due_source_id((string)$pair['first_description']);
        if ($sourceId <= 0) $sourceId = mk_parse_due_source_id((string)$pair['second_description']);
        if ($sourceId <= 0 || !mk_has_column('movements', 'reminder_settlement_movement_id')) continue;

        $stmt = db()->prepare("SELECT reminder_settlement_movement_id
            FROM movements WHERE id=? LIMIT 1");
        $stmt->execute([$sourceId]);
        $linkedId = (int)($stmt->fetchColumn() ?: 0);
        if ($linkedId !== $firstId && $linkedId !== $secondId) continue;

        $keepId = $linkedId;
        $cancelId = $keepId === $firstId ? $secondId : $firstId;
        mk_cancel_duplicate_movement(
            $keepId,
            $cancelId,
            'Mükerrer kontrolü: aynı vade kapatma işleminden oluşan ikinci kayıt'
        );
        $done[$keepId] = true;
        $done[$cancelId] = true;
        $result['movement_cancelled']++;
    }

    return $result;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();

    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'auto_fix_safe') {
            $result = mk_auto_fix_safe();
            $syncText = '';
            if (is_array($result['account_sync'])) {
                $syncText = ' Kasa/banka kaynak senkronu tamamlandı.';
            }
            flash('success', 'Güvenli düzeltme tamamlandı. İptal edilen kesin mükerrer hareket: '
                . (int)$result['movement_cancelled'] . '.' . $syncText);
        } elseif ($action === 'repair_account_sync') {
            if (!function_exists('repair_account_sync')) {
                throw new RuntimeException('Kasa/banka senkron aracı bulunamadı.');
            }
            $sync = repair_account_sync(true);
            flash('success', 'Kasa/banka kaynak senkronu tamamlandı. Hareket: '
                . (int)($sync['movement_synced'] ?? 0)
                . ', çek: ' . (int)($sync['check_synced'] ?? 0)
                . ', temizlenen eski kaynak kayıt: ' . (int)($sync['deleted'] ?? 0) . '.');
        } elseif ($action === 'cancel_movement_duplicate') {
            $keepId = (int)($_POST['keep_id'] ?? 0);
            $cancelId = (int)($_POST['cancel_id'] ?? 0);
            mk_cancel_duplicate_movement(
                $keepId,
                $cancelId,
                'Mükerrer kontrol ekranından iptal edildi; korunan hareket #' . $keepId
            );
            flash('success', 'Mükerrer hareket iptal edildi. Korunan kayıt #' . $keepId . '.');
        } elseif ($action === 'ignore_movement_duplicate') {
            $firstId = (int)($_POST['first_id'] ?? 0);
            $secondId = (int)($_POST['second_id'] ?? 0);
            mk_ignore_movement_pair($firstId, $secondId);
            flash('success', 'Hareket çifti mükerrer değil olarak işaretlendi ve listeden kaldırıldı.');
        } elseif ($action === 'merge_cari') {
            $keepId = (int)($_POST['keep_id'] ?? 0);
            $removeId = (int)($_POST['remove_id'] ?? 0);
            mk_merge_cari($keepId, $removeId);
            flash('success', 'Mükerrer cari kartı birleştirildi. Ana kayıt #' . $keepId . '.');
        } else {
            throw new RuntimeException('Geçersiz işlem.');
        }
    } catch (Throwable $e) {
        flash('error', 'Mükerrer düzeltmesi yapılamadı: ' . $e->getMessage());
    }
    redirect('mukerrer-kontrol.php');
}

list($nameGroups, $taxGroups) = mk_cari_duplicate_groups();
$movementPairs = mk_movement_pairs();
$accountSourceDuplicates = mk_account_source_duplicates();
$checkDuplicates = mk_check_duplicates();
$invoiceDuplicates = mk_invoice_duplicates();
$cardDuplicates = mk_card_duplicates();
$compensationDuplicates = mk_compensation_duplicates();

$totalDuplicateCariGroups = count($nameGroups) + count($taxGroups);
$totalFinancialCandidates = count($movementPairs)
    + count($accountSourceDuplicates)
    + count($checkDuplicates)
    + count($invoiceDuplicates)
    + count($cardDuplicates)
    + count($compensationDuplicates);

page_header('Mükerrer Kontrolü', 'raporlar');
?>
<style>
.mk-wrap{display:grid;gap:16px;max-width:1500px;margin:0 auto}
.mk-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px;border-radius:22px;background:linear-gradient(135deg,#5d321f,#aa7138);color:#fff}
.mk-hero h2{margin:4px 0 6px;color:#fff}.mk-hero p{margin:0;color:#fff2e3}
.mk-hero-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.mk-hero a,.mk-hero button{display:inline-flex;padding:9px 13px;border:0;border-radius:999px;background:#fff;color:#5d321f;text-decoration:none;font-weight:900;cursor:pointer}
.mk-hero button{background:#173f29;color:#fff}
.mk-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.mk-stats article,.mk-card{background:#fff;border:1px solid #e5dccf;border-radius:18px;box-shadow:0 10px 28px rgba(25,20,15,.06)}
.mk-stats article{padding:15px}.mk-stats span{font-size:10px;font-weight:950;color:#8a5b27;text-transform:uppercase}.mk-stats strong{display:block;margin-top:6px;font-size:23px;color:#3b2619}
.mk-card{overflow:hidden}.mk-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;background:#fff7ec;border-bottom:1px solid #e5dccf}.mk-head h3{margin:0;color:#3b2619}
.mk-body{padding:15px 17px}.mk-note{margin:0;padding:12px 13px;border-radius:13px;background:#fff6df;color:#735424;line-height:1.55;font-size:12px}
.mk-group{display:grid;gap:9px;margin-top:12px}.mk-group article{border:1px solid #eadfce;border-radius:14px;padding:12px;background:#fff}.mk-group h4{margin:0 0 9px;color:#3b2619}
.mk-cari-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:8px}.mk-cari{padding:10px;border-radius:11px;background:#faf7f1}.mk-cari strong,.mk-cari small{display:block}.mk-cari small{margin-top:4px;color:#75685a}
.mk-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.mk-actions a,.mk-actions button{display:inline-flex;padding:6px 9px;border:1px solid #d7cab8;border-radius:999px;background:#fff;color:#4f321f;text-decoration:none;font-size:11px;font-weight:900;cursor:pointer}
.mk-actions button.danger{border-color:#e7b8b3;color:#9c3229;background:#fff5f4}
.mk-merge{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;margin-top:10px;padding-top:10px;border-top:1px dashed #dfd1be}.mk-merge label{display:grid;gap:4px;font-size:10px;font-weight:900}.mk-merge select{min-height:38px;border:1px solid #d7cab8;border-radius:10px;background:#fff;padding:6px 8px}
.mk-table-wrap{overflow:auto}.mk-table{width:100%;min-width:1050px;border-collapse:separate;border-spacing:0}.mk-table th{padding:10px;background:#5d321f;color:#fff;text-align:left;font-size:10px;text-transform:uppercase}.mk-table td{padding:10px;border-bottom:1px solid #eee4d7;vertical-align:top;font-size:12px}.mk-table small{display:block;margin-top:3px;color:#75685a}
.mk-empty{padding:24px!important;text-align:center;color:#75685a}.mk-badge{display:inline-flex;padding:4px 7px;border-radius:999px;background:#fff0d2;color:#8a5f16;font-size:10px;font-weight:900}.mk-badge.ok{background:#e8f5ed;color:#1f6b3d}
@media(max-width:900px){.mk-stats{grid-template-columns:1fr 1fr}.mk-merge{grid-template-columns:1fr}}
@media(max-width:760px){.mk-hero{display:block}.mk-hero-actions{justify-content:flex-start;margin-top:12px}.mk-stats{grid-template-columns:1fr}}
</style>

<div class="mk-wrap">
  <section class="mk-hero">
    <div>
      <span>TAM VERİ TARAMASI</span>
      <h2>Cari ve finansal mükerrerleri güvenli biçimde düzelt.</h2>
      <p>Hareket, kasa/banka kaynağı, çek, fatura, kart ekstresi ve tazminat kayıtları birlikte taranır. Finansal kayıtlar silinmez; hareket mükerrerleri iptal edilerek geçmişte korunur.</p>
    </div>
    <div class="mk-hero-actions">
      <form method="post" onsubmit="return confirm('Kesin olarak doğrulanan mükerrerleri iptal edip kasa/banka kaynaklarını yeniden senkronlayalım mı?');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="auto_fix_safe">
        <button type="submit">Güvenli düzeltmeleri uygula</button>
      </form>
      <a href="dashboard.php">← Genel bakışa dön</a>
    </div>
  </section>

  <section class="mk-stats">
    <article><span>Mükerrer cari grubu</span><strong><?php echo e($totalDuplicateCariGroups); ?></strong></article>
    <article><span>Olası hareket çifti</span><strong><?php echo e(count($movementPairs)); ?></strong></article>
    <article><span>Kaynak banka tekerrürü</span><strong><?php echo e(count($accountSourceDuplicates)); ?></strong></article>
    <article><span>Diğer şüpheli kayıt</span><strong><?php echo e($totalFinancialCandidates - count($movementPairs) - count($accountSourceDuplicates)); ?></strong></article>
  </section>

  <section class="mk-card">
    <div class="mk-head">
      <h3>Kasa / banka kaynak senkronu</h3>
      <span class="mk-badge <?php echo !$accountSourceDuplicates ? 'ok' : ''; ?>"><?php echo e(count($accountSourceDuplicates)); ?> grup</span>
    </div>
    <div class="mk-body">
      <p class="mk-note">Aynı hareket, çek, kart ekstresi veya tazminat kaynağı banka tablosuna birden fazla yazılmışsa burada görünür. Senkron işlemi kaynak kayıtları yeniden üretir ve türemiş mükerrer satırları temizler.</p>
      <div class="mk-actions">
        <form method="post" onsubmit="return confirm('Kasa/banka kaynak hareketleri yeniden senkronlansın mı? Manuel ve virman kayıtlarına dokunulmaz.');">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="repair_account_sync">
          <button type="submit">Kaynak senkronunu çalıştır</button>
        </form>
      </div>
    </div>
    <div class="mk-table-wrap">
      <table class="mk-table">
        <thead><tr><th>Tarih</th><th>Kaynak</th><th>Hesap</th><th>Yön</th><th>Tutar</th><th>Adet / ID</th><th>Açıklama</th></tr></thead>
        <tbody>
        <?php if (!$accountSourceDuplicates): ?><tr><td colspan="7" class="mk-empty">Aynı kaynaktan oluşmuş mükerrer kasa/banka satırı bulunmadı.</td></tr><?php endif; ?>
        <?php foreach ($accountSourceDuplicates as $row): ?>
          <tr>
            <td><?php echo e(tr_date($row['transaction_date'])); ?></td>
            <td><strong><?php echo e($row['source_type']); ?> #<?php echo e($row['source_id']); ?></strong></td>
            <td>#<?php echo e($row['account_id']); ?></td>
            <td><?php echo e($row['direction'] === 'in' ? 'Giriş' : 'Çıkış'); ?></td>
            <td><strong><?php echo e(money($row['amount'])); ?></strong></td>
            <td><?php echo e($row['duplicate_count']); ?> kayıt<small><?php echo e($row['ids']); ?></small></td>
            <td><?php echo e($row['descriptions']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="mk-card">
    <div class="mk-head">
      <h3>Mükerrer cari kartları</h3>
      <span class="mk-badge <?php echo !$totalDuplicateCariGroups ? 'ok' : ''; ?>"><?php echo e($totalDuplicateCariGroups); ?> grup</span>
    </div>
    <div class="mk-body">
      <p class="mk-note">Birleştirme, seçilen ikinci carinin bütün hareket, çek, fatura ve belge bağlantılarını ana cariye taşır; yalnızca boşta kalan mükerrer cari kartını kaldırır. Finansal kayıtlar korunur.</p>
      <?php if (!$nameGroups && !$taxGroups): ?><p class="mk-empty">Aynı isim veya vergi numarasıyla açılmış cari grubu bulunmadı.</p><?php endif; ?>
      <div class="mk-group">
        <?php
        $allGroups = [];
        foreach ($nameGroups as $key=>$rows) $allGroups['İsim: ' . $key] = $rows;
        foreach ($taxGroups as $key=>$rows) $allGroups['Vergi no: ' . $key] = $rows;
        ?>
        <?php foreach ($allGroups as $label=>$rows): ?>
          <article>
            <h4><?php echo e($label); ?></h4>
            <div class="mk-cari-grid">
              <?php foreach ($rows as $row): $meta=mk_cari_meta((int)$row['id']); ?>
                <div class="mk-cari">
                  <strong>#<?php echo e($row['id']); ?> · <?php echo e($row['name']); ?></strong>
                  <small>Vergi no: <?php echo e($row['tax_no'] ?: '-'); ?> · Şehir: <?php echo e($row['city'] ?: '-'); ?></small>
                  <small>Hareket: <?php echo e($meta['movement_count']); ?> · Fatura: <?php echo e($meta['invoice_count']); ?> · Çek: <?php echo e($meta['check_count']); ?> · Belge: <?php echo e($meta['document_count']); ?></small>
                  <small>Net bakiye: <?php echo e(money($meta['balance'])); ?></small>
                  <div class="mk-actions">
                    <a href="cari-detay.php?id=<?php echo e($row['id']); ?>">Cariyi aç</a>
                    <a href="cariler.php?edit=<?php echo e($row['id']); ?>">Düzenle</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <form method="post" class="mk-merge" onsubmit="return confirm('İkinci carinin tüm bağlantıları ana cariye taşınacak ve boşta kalan mükerrer kart kaldırılacak. Devam edilsin mi?');">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="merge_cari">
              <label>Ana kayıt
                <select name="keep_id" required>
                  <?php foreach ($rows as $row): ?><option value="<?php echo e($row['id']); ?>">#<?php echo e($row['id']); ?> · <?php echo e($row['name']); ?></option><?php endforeach; ?>
                </select>
              </label>
              <label>Birleştirilecek kayıt
                <select name="remove_id" required>
                  <?php foreach ($rows as $row): ?><option value="<?php echo e($row['id']); ?>">#<?php echo e($row['id']); ?> · <?php echo e($row['name']); ?></option><?php endforeach; ?>
                </select>
              </label>
              <button class="btn btn-secondary" type="submit">Carileri birleştir</button>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-card">
    <div class="mk-head">
      <h3>Olası mükerrer tahsilat / ödeme / gelir / gider</h3>
      <span class="mk-badge <?php echo !$movementPairs ? 'ok' : ''; ?>"><?php echo e(count($movementPairs)); ?> eşleşme</span>
    </div>
    <div class="mk-body">
      <p class="mk-note">Aynı gün, aynı cari, aynı tutar, aynı hesap ve aynı işlem türündeki kayıtlar gösterilir. “Vade kapatma” bağlantısı olan kesin eşleşmeler güvenli düzeltmede otomatik iptal edilebilir; diğerlerinde hangi kaydın korunacağını sen seçersin.</p>
    </div>
    <div class="mk-table-wrap">
      <table class="mk-table">
        <thead><tr><th>Tarih</th><th>Cari</th><th>Tür / Hesap</th><th>Birinci kayıt</th><th>İkinci kayıt</th><th>Tutar</th><th>Düzelt</th></tr></thead>
        <tbody>
        <?php if(!$movementPairs): ?><tr><td colspan="7" class="mk-empty">Mükerrer olabilecek nakit hareket bulunmadı.</td></tr><?php endif; ?>
        <?php foreach($movementPairs as $row): ?>
          <tr>
            <td><?php echo e(tr_date($row['movement_date'])); ?></td>
            <td><strong><?php echo e($row['cari_name']); ?></strong><small>Cari #<?php echo e($row['cari_id'] ?: '-'); ?></small></td>
            <td><?php echo e(movement_label($row['movement_type'])); ?><small><?php echo e($row['account_name']); ?></small></td>
            <td><strong>#<?php echo e($row['first_id']); ?></strong><small><?php echo e($row['first_description'] ?: '-'); ?></small><small><?php echo e(tr_datetime($row['first_created_at'])); ?></small></td>
            <td><strong>#<?php echo e($row['second_id']); ?></strong><small><?php echo e($row['second_description'] ?: '-'); ?></small><small><?php echo e(tr_datetime($row['second_created_at'])); ?></small></td>
            <td><strong><?php echo e(number_format((float)$row['amount'],2,',','.') . ' ' . $row['currency']); ?></strong></td>
            <td>
              <div class="mk-actions">
                <a href="hareketler.php?q=<?php echo e(urlencode((string)$row['first_id'])); ?>">#<?php echo e($row['first_id']); ?></a>
                <a href="hareketler.php?q=<?php echo e(urlencode((string)$row['second_id'])); ?>">#<?php echo e($row['second_id']); ?></a>
                <form method="post" onsubmit="return confirm('#<?php echo e($row['second_id']); ?> iptal edilecek, #<?php echo e($row['first_id']); ?> korunacak. Devam edilsin mi?');">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="cancel_movement_duplicate">
                  <input type="hidden" name="keep_id" value="<?php echo e($row['first_id']); ?>">
                  <input type="hidden" name="cancel_id" value="<?php echo e($row['second_id']); ?>">
                  <button class="danger" type="submit">İkinciyi iptal et</button>
                </form>
                <form method="post" onsubmit="return confirm('#<?php echo e($row['first_id']); ?> iptal edilecek, #<?php echo e($row['second_id']); ?> korunacak. Devam edilsin mi?');">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="cancel_movement_duplicate">
                  <input type="hidden" name="keep_id" value="<?php echo e($row['second_id']); ?>">
                  <input type="hidden" name="cancel_id" value="<?php echo e($row['first_id']); ?>">
                  <button class="danger" type="submit">Birinciyi iptal et</button>
                </form>
                <form method="post">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="ignore_movement_duplicate">
                  <input type="hidden" name="first_id" value="<?php echo e($row['first_id']); ?>">
                  <input type="hidden" name="second_id" value="<?php echo e($row['second_id']); ?>">
                  <button type="submit">Mükerrer değil</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Çek mükerrerleri</h3><span class="mk-badge <?php echo !$checkDuplicates ? 'ok' : ''; ?>"><?php echo e(count($checkDuplicates)); ?></span></div>
    <div class="mk-table-wrap"><table class="mk-table"><thead><tr><th>Vade</th><th>Cari</th><th>Yön</th><th>Banka / No</th><th>Tutar</th><th>Kayıtlar</th></tr></thead><tbody>
      <?php if(!$checkDuplicates): ?><tr><td colspan="6" class="mk-empty">Aynı çek numarası, banka, cari, tutar ve vadede aktif mükerrer çek bulunmadı.</td></tr><?php endif; ?>
      <?php foreach($checkDuplicates as $row): ?><tr><td><?php echo e(tr_date($row['due_date'])); ?></td><td><?php echo e($row['cari_name']); ?></td><td><?php echo e(check_direction_label($row['direction'])); ?></td><td><?php echo e(trim($row['bank_name'] . ' ' . $row['check_no'])); ?></td><td><?php echo e(money($row['amount'])); ?></td><td><div class="mk-actions"><a href="cekler.php?edit=<?php echo e($row['first_id']); ?>">#<?php echo e($row['first_id']); ?></a><a href="cekler.php?edit=<?php echo e($row['second_id']); ?>">#<?php echo e($row['second_id']); ?></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Fatura mükerrerleri</h3><span class="mk-badge <?php echo !$invoiceDuplicates ? 'ok' : ''; ?>"><?php echo e(count($invoiceDuplicates)); ?></span></div>
    <div class="mk-table-wrap"><table class="mk-table"><thead><tr><th>Tarih</th><th>Cari</th><th>Yön</th><th>Fatura / Dosya</th><th>Tutar</th><th>Kayıtlar</th></tr></thead><tbody>
      <?php if(!$invoiceDuplicates): ?><tr><td colspan="6" class="mk-empty">Aynı fatura numarası veya dosya adıyla eşleşen aktif mükerrer fatura bulunmadı.</td></tr><?php endif; ?>
      <?php foreach($invoiceDuplicates as $row): ?><tr><td><?php echo e(tr_date($row['invoice_date'])); ?></td><td><?php echo e($row['cari_name']); ?></td><td><?php echo e($row['direction']); ?></td><td><?php echo e($row['identity_value']); ?></td><td><?php echo e(number_format((float)$row['total_amount'],2,',','.') . ' ' . $row['currency']); ?></td><td><div class="mk-actions"><a href="faturalar.php?q=<?php echo e(urlencode((string)$row['first_id'])); ?>">#<?php echo e($row['first_id']); ?></a><a href="faturalar.php?q=<?php echo e(urlencode((string)$row['second_id'])); ?>">#<?php echo e($row['second_id']); ?></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Kart ekstresi mükerrerleri</h3><span class="mk-badge <?php echo !$cardDuplicates ? 'ok' : ''; ?>"><?php echo e(count($cardDuplicates)); ?></span></div>
    <div class="mk-table-wrap"><table class="mk-table"><thead><tr><th>Kart</th><th>Dönem</th><th>Tutar</th><th>Kayıtlar</th></tr></thead><tbody>
      <?php if(!$cardDuplicates): ?><tr><td colspan="4" class="mk-empty">Aynı kart, dönem ve tutarda mükerrer ekstre bulunmadı.</td></tr><?php endif; ?>
      <?php foreach($cardDuplicates as $row): ?><tr><td><?php echo e($row['card_label']); ?></td><td><?php echo e($row['statement_period']); ?></td><td><?php echo e(money($row['amount'])); ?></td><td><div class="mk-actions"><a href="kart-ekstre-takibi.php">#<?php echo e($row['first_id']); ?></a><a href="kart-ekstre-takibi.php">#<?php echo e($row['second_id']); ?></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Tazminat ödemesi mükerrerleri</h3><span class="mk-badge <?php echo !$compensationDuplicates ? 'ok' : ''; ?>"><?php echo e(count($compensationDuplicates)); ?></span></div>
    <div class="mk-table-wrap"><table class="mk-table"><thead><tr><th>Tarih</th><th>Personel</th><th>Tür</th><th>Tutar</th><th>Kayıtlar</th></tr></thead><tbody>
      <?php if(!$compensationDuplicates): ?><tr><td colspan="5" class="mk-empty">Aynı personel, tür, tarih, hesap ve tutarda mükerrer tazminat ödemesi bulunmadı.</td></tr><?php endif; ?>
      <?php foreach($compensationDuplicates as $row): ?><tr><td><?php echo e(tr_date($row['payment_date'])); ?></td><td><?php echo e($row['employee_name']); ?></td><td><?php echo e($row['compensation_type']); ?></td><td><?php echo e(money($row['amount'])); ?></td><td><div class="mk-actions"><a href="maas-tazminat.php?edit=<?php echo e($row['first_id']); ?>">#<?php echo e($row['first_id']); ?></a><a href="maas-tazminat.php?edit=<?php echo e($row['second_id']); ?>">#<?php echo e($row['second_id']); ?></a></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
</div>
<?php page_footer(); ?>
