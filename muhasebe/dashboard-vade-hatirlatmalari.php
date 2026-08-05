<?php
require_once __DIR__ . '/layout.php';
require_login();

ensure_column(db(), 'movements', 'reminder_status', "TEXT NOT NULL DEFAULT 'bekliyor'");
ensure_column(db(), 'movements', 'reminder_completed_at', 'TEXT');
ensure_column(db(), 'movements', 'reminder_completed_by', 'INTEGER');
ensure_column(db(), 'movements', 'reminder_settlement_movement_id', 'INTEGER');
ensure_column(db(), 'checks', 'reminder_settlement_movement_id', 'INTEGER');

header('Content-Type: application/json; charset=utf-8');

function dashboard_reminder_money($amount, ?string $currency = 'TL'): string
{
    $currency = strtoupper(trim((string)($currency ?: 'TL')));
    if (!in_array($currency, ['TL', 'USD', 'EUR'], true)) $currency = 'TL';
    return number_format((float)$amount, 2, ',', '.') . ' ' . $currency;
}

function dashboard_reminder_bucket(string $dueDate, string $today): string
{
    if ($dueDate < $today) return 'overdue';
    if ($dueDate === $today) return 'today';
    return 'week';
}

function dashboard_reminder_due_text(string $dueDate, string $today): string
{
    $diff = (int)round((strtotime($dueDate) - strtotime($today)) / 86400);
    if ($diff < 0) return abs($diff) . ' gün gecikti';
    if ($diff === 0) return 'Bugün';
    if ($diff === 1) return 'Yarın';
    return $diff . ' gün kaldı';
}

function dashboard_reminder_account(int $accountId, bool $bankOnly = false): ?array
{
    if ($accountId <= 0) return null;
    $sql = "SELECT * FROM accounts WHERE id=? AND is_active=1";
    if ($bankOnly) $sql .= " AND account_type='banka'";
    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dashboard_reminder_card_default_account_id(string $cardKey): int
{
    $accountNames = [
        'garanti_9029' => ['Garanti Bankası Fatih Duman', 'Garanti Fatih', 'Garanti Bankası Fatih', 'Garanti Fatih Duman', 'GARANTİ FATİH DUMAN'],
        'garanti_1018' => ['Garanti Bankası Fatih Duman', 'Garanti Fatih', 'Garanti Bankası Fatih', 'Garanti Fatih Duman', 'GARANTİ FATİH DUMAN'],
        'isbank_3833' => ['İş Bankası Fatih Duman', 'İş Bankası Fatih', 'İşbank Fatih', 'İşbank Fatih Duman'],
        'ziraat_7754' => ['Ziraat Bankası Fatih Duman', 'Ziraat Fatih', 'Ziraat Bankası Fatih', 'Ziraat Fatih Duman'],
        'ziraat_4091' => ['Ziraat Bankası Fatih Duman', 'Ziraat Fatih', 'Ziraat Bankası Fatih', 'Ziraat Fatih Duman'],
        'kuveyt_4357' => ['Kuveyt Türk Fatih Duman', 'Kuveyt Fatih', 'Kuveyt Türk Fatih', 'Kuveyt Fatih Duman', 'KUVEYT FATİH DUMAN'],
        'vakif_1125' => ['VakıfBank Fatih Duman', 'VakıfBank Fatih', 'Vakıf Fatih', 'Vakıf Fatih Duman'],
    ];
    $names = $accountNames[$cardKey] ?? [];
    if (!$names) return 0;

    $stmt = db()->prepare("SELECT id FROM accounts WHERE name=? AND account_type='banka' AND is_active=1 LIMIT 1");
    foreach ($names as $name) {
        $stmt->execute([$name]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) return $id;
    }

    $bankNames = [
        'garanti_9029' => 'Garanti BBVA',
        'garanti_1018' => 'Garanti BBVA',
        'isbank_3833' => 'Türkiye İş Bankası',
        'ziraat_7754' => 'T.C. Ziraat Bankası',
        'ziraat_4091' => 'T.C. Ziraat Bankası',
        'kuveyt_4357' => 'Kuveyt Türk Katılım Bankası',
        'vakif_1125' => 'VakıfBank',
    ];
    $bankName = $bankNames[$cardKey] ?? '';
    if ($bankName !== '') {
        $stmt = db()->prepare("SELECT id FROM accounts WHERE account_type='banka' AND is_active=1 AND bank_name=? AND name LIKE ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([$bankName, '%Fatih%']);
        return (int)($stmt->fetchColumn() ?: 0);
    }
    return 0;
}

function dashboard_reminder_create_settlement(array $source, string $movementType, int $accountId, string $description, ?int $checkId = null): int
{
    $currency = strtoupper(trim((string)($source['currency'] ?? 'TL')));
    if ($currency !== 'TL') {
        throw new RuntimeException('Dövizli vadeler kasa/banka hesabına otomatik işlenemez. Hareketler ekranından kapatmalısın.');
    }
    db()->prepare("INSERT INTO movements (
        cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
        payment_method, description, document_type, check_id, created_by, created_at, updated_at
    ) VALUES (?, NULL, ?, ?, ?, 'TL', ?, NULL, ?, ?, NULL, ?, ?, ?, ?)")
        ->execute([
            !empty($source['cari_id']) ? (int)$source['cari_id'] : null,
            $accountId,
            $movementType,
            (float)$source['amount'],
            date('Y-m-d'),
            $checkId ? ($movementType === 'tahsilat' ? 'Çek tahsilatı' : 'Çek ödemesi') : 'Vade kapatma',
            $description,
            $checkId ?: null,
            current_user()['id'] ?? null,
            now(),
            now(),
        ]);
    $settlementId = (int)db()->lastInsertId();
    sync_movement_account_transaction($settlementId);
    return $settlementId;
}

function dashboard_reminder_cancel_settlement(int $settlementId, string $reason): void
{
    if ($settlementId <= 0) return;
    db()->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=?
        WHERE id=? AND COALESCE(is_cancelled,0)=0")
        ->execute([now(), current_user()['id'] ?? null, $reason, now(), $settlementId]);
    sync_movement_account_transaction($settlementId);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $action = (string)($_POST['action'] ?? '');
        $source = (string)($_POST['source'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);
        if ($id <= 0 || !in_array($source, ['movement','check','card_statement'], true) || !in_array($action, ['complete','reopen'], true)) {
            throw new RuntimeException('Vade durumu güncellenemedi.');
        }

        if ($source === 'movement') {
            $stmt = db()->prepare("SELECT * FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0 AND movement_type IN ('alacak','verecek')");
            $stmt->execute([$id]);
            $movement = $stmt->fetch();
            if (!$movement) throw new RuntimeException('Vadeli hareket bulunamadı.');

            $incoming = (string)$movement['movement_type'] === 'alacak';
            $label = $incoming ? 'Alındı' : 'Ödendi';
            if ($action === 'complete') {
                $account = dashboard_reminder_account($accountId);
                if (!$account) throw new RuntimeException('Aktif bir kasa veya banka hesabı seçmelisin.');
                if (strtoupper((string)($movement['currency'] ?? 'TL')) !== 'TL') {
                    throw new RuntimeException('Dövizli vadeler otomatik kapatılamaz; Hareketler ekranından işlem yapmalısın.');
                }
                if ((string)($movement['reminder_status'] ?? 'bekliyor') === 'tamamlandi'
                    && (int)($movement['reminder_settlement_movement_id'] ?? 0) > 0) {
                    echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }

                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $description = 'Vade kapatma #' . $id;
                    if (trim((string)($movement['description'] ?? '')) !== '') $description .= ' / ' . trim((string)$movement['description']);
                    $settlementType = $incoming ? 'tahsilat' : 'odeme';
                    $settlementId = dashboard_reminder_create_settlement($movement, $settlementType, (int)$account['id'], $description);
                    $completedAt = now();
                    $completedBy = current_user()['id'] ?? null;
                    $pdo->prepare("UPDATE movements SET reminder_status='tamamlandi', reminder_completed_at=?, reminder_completed_by=?,
                            reminder_settlement_movement_id=?, updated_at=? WHERE id=?")
                        ->execute([$completedAt, $completedBy, $settlementId, now(), $id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                log_action('Vade ödemesi kaydedildi', '#' . $id . ' ' . $label . ' / ' . (string)$account['name']);
                audit_action('hareket', $id, 'vade_tamamlandi', $movement, [
                    'reminder_status'=>'tamamlandi',
                    'reminder_settlement_movement_id'=>$settlementId,
                    'account_id'=>(int)$account['id'],
                ], $label);
                echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $settlementId = (int)($movement['reminder_settlement_movement_id'] ?? 0);
            $pdo = db();
            $pdo->beginTransaction();
            try {
                dashboard_reminder_cancel_settlement($settlementId, 'Vade hatırlatması yeniden açıldı');
                $pdo->prepare("UPDATE movements SET reminder_status='bekliyor', reminder_completed_at=NULL, reminder_completed_by=NULL,
                        reminder_settlement_movement_id=NULL, updated_at=? WHERE id=?")
                    ->execute([now(), $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            log_action('Vade hatırlatması yeniden açıldı', '#' . $id);
            audit_action('hareket', $id, 'vade_yeniden_acildi', $movement, ['reminder_status'=>'bekliyor'], 'Bekliyor');
            echo json_encode(['ok'=>true, 'status'=>'bekliyor', 'label'=>'Bekliyor'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($source === 'card_statement') {
            if ($action !== 'complete') throw new RuntimeException('Kart ekstresi bu ekrandan yeniden açılamaz.');

            $stmt = db()->prepare("SELECT * FROM card_statements WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $statement = $stmt->fetch();
            if (!$statement) throw new RuntimeException('Kart ekstresi bulunamadı.');
            if ((string)$statement['status'] === 'odendi') {
                echo json_encode(['ok'=>true, 'status'=>'odendi', 'label'=>'Ödendi'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if ((string)$statement['status'] !== 'bekliyor') {
                throw new RuntimeException('Bu kart ekstresi bekleyen durumda değil.');
            }

            $account = dashboard_reminder_account($accountId, true);
            if (!$account) throw new RuntimeException('Kart ekstresi için aktif bir banka hesabı seçmelisin.');

            $paidDate = date('Y-m-d');
            $description = (string)$statement['card_name'] . ' / ' . (string)$statement['statement_period'] . ' ekstre ödemesi';
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO account_transactions
                    (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at)
                    VALUES (?, 'out', ?, ?, 'card_statement', ?, ?, ?, ?)")
                    ->execute([
                        (int)$account['id'],
                        (float)$statement['amount'],
                        $paidDate,
                        $id,
                        $description,
                        current_user()['id'] ?? null,
                        now(),
                    ]);
                $transactionId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE card_statements SET status='odendi', paid_date=?, payment_account_id=?,
                        payment_transaction_id=?, reversal_transaction_id=NULL, updated_at=? WHERE id=?")
                    ->execute([$paidDate, (int)$account['id'], $transactionId, now(), $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            log_action('Kart ekstresi vadesi kapatıldı', '#' . $id . ' Ödendi / ' . (string)$account['name']);
            audit_action('kart_ekstresi', $id, 'odendi', $statement, [
                'paid_date'=>$paidDate,
                'account_id'=>(int)$account['id'],
                'transaction_id'=>$transactionId,
            ], (string)$statement['card_name']);
            echo json_encode(['ok'=>true, 'status'=>'odendi', 'label'=>'Ödendi'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $stmt = db()->prepare("SELECT * FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$id]);
        $check = $stmt->fetch();
        if (!$check) throw new RuntimeException('Çek bulunamadı.');

        $incoming = (string)$check['direction'] === 'alinacak';
        $label = $incoming ? 'Tahsil edildi' : 'Ödendi';
        if ($action === 'complete') {
            $account = dashboard_reminder_account($accountId, true);
            if (!$account) throw new RuntimeException('Çek işlemi için aktif bir banka hesabı seçmelisin.');
            $doneStatus = $incoming ? 'tahsil_edildi' : 'odendi';
            if ((string)$check['status'] === $doneStatus) {
                echo json_encode(['ok'=>true, 'status'=>$doneStatus, 'label'=>$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!in_array((string)$check['status'], ['bekliyor','bankaya_verildi'], true)) {
                throw new RuntimeException('Bu çek açık durumda değil; Çekler ekranından kontrol etmelisin.');
            }

            $settlementType = $incoming ? 'tahsilat' : 'odeme';
            $existingStmt = db()->prepare("SELECT * FROM movements
                WHERE check_id=? AND movement_type=? AND COALESCE(is_cancelled,0)=0
                ORDER BY COALESCE(is_check_adjustment,0) ASC, id ASC");
            $existingStmt->execute([$id, $settlementType]);
            $existingPayments = $existingStmt->fetchAll();
            if (count($existingPayments) !== 1) {
                throw new RuntimeException(count($existingPayments) > 1
                    ? 'Bu çeke bağlı birden fazla geçmiş ödeme/tahsilat bulundu. Yeni hareket oluşturulmadı; kayıt beklemeye devam ediyor.'
                    : 'Bu çeke ait geçmiş ödeme/tahsilat bulunamadı. Yeni hareket oluşturulmadı; kayıt beklemeye devam ediyor.');
            }
            $settlementId = (int)$existingPayments[0]['id'];

            $pdo = db();
            $pdo->beginTransaction();
            try {
                if ((int)($existingPayments[0]['account_id'] ?? 0) <= 0) {
                    $pdo->prepare('UPDATE movements SET account_id=?, updated_at=? WHERE id=?')
                        ->execute([(int)$account['id'], now(), $settlementId]);
                    sync_movement_account_transaction($settlementId);
                }
                $pdo->prepare("UPDATE checks SET status=?, account_id=?, closed_at=?, reminder_settlement_movement_id=?, updated_at=? WHERE id=?")
                    ->execute([$doneStatus, (int)$account['id'], date('Y-m-d'), $settlementId, now(), $id]);
                sync_check_to_movement($id);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            log_action('Çek vadesi kapatıldı', '#' . $id . ' ' . $label . ' / ' . (string)$account['name']);
            audit_action('cek', $id, 'vade_tamamlandi', $check, [
                'status'=>$doneStatus,
                'account_id'=>(int)$account['id'],
                'reminder_settlement_movement_id'=>$settlementId,
            ], $label);
            echo json_encode(['ok'=>true, 'status'=>$doneStatus, 'label'=>$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $settlementId = (int)($check['reminder_settlement_movement_id'] ?? 0);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            dashboard_reminder_cancel_settlement($settlementId, 'Çek vadesi yeniden açıldı');
            $pdo->prepare("UPDATE checks SET status='bekliyor', closed_at=NULL, reminder_settlement_movement_id=NULL, updated_at=? WHERE id=?")
                ->execute([now(), $id]);
            sync_check_to_movement($id);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        log_action('Çek vadesi yeniden açıldı', '#' . $id);
        audit_action('cek', $id, 'vade_yeniden_acildi', $check, ['status'=>'bekliyor'], 'Bekliyor');
        echo json_encode(['ok'=>true, 'status'=>'bekliyor', 'label'=>'Bekliyor'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $today = date('Y-m-d');
    $weekAhead = date('Y-m-d', strtotime('+7 days'));
    $groups = [
        'overdue' => ['key'=>'overdue', 'label'=>'Vadesi geçmiş', 'tone'=>'danger', 'items'=>[]],
        'today' => ['key'=>'today', 'label'=>'Bugün', 'tone'=>'warning', 'items'=>[]],
        'week' => ['key'=>'week', 'label'=>'7 gün içinde', 'tone'=>'info', 'items'=>[]],
    ];

    // Kapanmayan geçmiş vadeler, ödendi/tahsil edildi denene kadar listede kalır.
    // Çeklerden oluşan bağlı hareketler ayrıca listelenmesin; çek kendi başlığıyla tek kez gösterilsin.
    $movementStmt = db()->prepare("SELECT m.id, m.cari_id, m.movement_type, m.amount, COALESCE(m.currency,'TL') AS currency,
            m.due_date, m.description, c.name AS cari_name
        FROM movements m
        LEFT JOIN cariler c ON c.id=m.cari_id
        WHERE COALESCE(m.is_cancelled,0)=0
          AND COALESCE(m.is_check_adjustment,0)=0
          AND COALESCE(m.check_id,0)=0
          AND (
              COALESCE(m.reminder_status,'bekliyor')!='tamamlandi'
              OR COALESCE(m.reminder_settlement_movement_id,0)=0
          )
          AND m.due_date IS NOT NULL
          AND m.due_date != ''
          AND m.due_date <= ?
          AND m.movement_type IN ('alacak','verecek')
        ORDER BY m.due_date ASC, m.id DESC
        LIMIT 200");
    $movementStmt->execute([$weekAhead]);
    foreach ($movementStmt->fetchAll() as $row) {
        $dueDate = (string)$row['due_date'];
        $incoming = (string)$row['movement_type'] === 'alacak';
        $bucket = dashboard_reminder_bucket($dueDate, $today);
        $groups[$bucket]['items'][] = [
            'source' => 'movement',
            'id' => (int)$row['id'],
            'kind' => $incoming ? 'Vadeli alacak' : 'Vadeli borç / ödeme',
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'tone' => $incoming ? 'success' : 'danger',
            'cari_name' => (string)($row['cari_name'] ?: 'Cari seçilmedi'),
            'description' => trim((string)($row['description'] ?? '')),
            'amount' => (float)$row['amount'],
            'currency' => (string)$row['currency'],
            'amount_text' => dashboard_reminder_money($row['amount'], $row['currency'] ?? 'TL'),
            'due_date' => $dueDate,
            'due_text' => tr_date($dueDate),
            'state_text' => dashboard_reminder_due_text($dueDate, $today),
            'status_text' => 'Bekliyor',
            'can_complete' => can_write() && strtoupper((string)$row['currency']) === 'TL',
            'complete_label' => $incoming ? 'Alındı' : 'Ödendi',
            'account_scope' => 'all',
            'default_account_id' => '',
            'url' => 'hareketler.php?edit=' . (int)$row['id'],
        ];
    }

    // Bekleyen kredi kartı ekstreleri de son ödeme tarihine göre aynı vade akışında gösterilir.
    $cardTableExists = (bool)db()->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='card_statements' LIMIT 1")->fetchColumn();
    if ($cardTableExists) {
        $cardStmt = db()->prepare("SELECT id, card_key, card_name, statement_period, amount, due_date, note
            FROM card_statements
            WHERE status='bekliyor'
              AND due_date IS NOT NULL
              AND due_date != ''
              AND due_date <= ?
            ORDER BY due_date ASC, id DESC
            LIMIT 200");
        $cardStmt->execute([$weekAhead]);
        foreach ($cardStmt->fetchAll() as $row) {
            $dueDate = (string)$row['due_date'];
            $bucket = dashboard_reminder_bucket($dueDate, $today);
            $periodText = trim((string)($row['statement_period'] ?? ''));
            $noteText = trim((string)($row['note'] ?? ''));
            $description = trim(implode(' · ', array_filter([
                $periodText !== '' ? $periodText . ' dönemi' : '',
                $noteText,
            ])));
            $groups[$bucket]['items'][] = [
                'source' => 'card_statement',
                'id' => (int)$row['id'],
                'kind' => 'Kredi kartı ekstresi',
                'direction' => 'outgoing',
                'tone' => 'danger',
                'cari_name' => (string)$row['card_name'],
                'description' => $description,
                'amount' => (float)$row['amount'],
                'currency' => 'TL',
                'amount_text' => dashboard_reminder_money($row['amount'], 'TL'),
                'due_date' => $dueDate,
                'due_text' => tr_date($dueDate),
                'state_text' => dashboard_reminder_due_text($dueDate, $today),
                'status_text' => 'Bekliyor',
                'can_complete' => can_write(),
                'complete_label' => 'Ödendi',
                'account_scope' => 'bank',
                'default_account_id' => dashboard_reminder_card_default_account_id((string)($row['card_key'] ?? '')),
                'url' => 'kart-ekstre-takibi.php?edit=' . (int)$row['id'],
            ];
        }
    }

    $checkStmt = db()->prepare("SELECT ch.id, ch.cari_id, ch.account_id, ch.direction, ch.status, ch.amount, ch.due_date, ch.bank_name,
            ch.check_no, ch.drawer, ch.description, c.name AS cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        WHERE COALESCE(ch.is_cancelled,0)=0
          AND ch.status IN ('bekliyor','bankaya_verildi')
          AND ch.due_date IS NOT NULL
          AND ch.due_date != ''
          AND ch.due_date <= ?
        ORDER BY ch.due_date ASC, ch.id DESC
        LIMIT 200");
    $checkStmt->execute([$weekAhead]);
    foreach ($checkStmt->fetchAll() as $row) {
        $dueDate = (string)$row['due_date'];
        $incoming = (string)$row['direction'] === 'alinacak';
        $bucket = dashboard_reminder_bucket($dueDate, $today);
        $checkInfo = trim(implode(' · ', array_filter([
            trim((string)($row['bank_name'] ?? '')),
            trim((string)($row['check_no'] ?? '')),
            trim((string)($row['description'] ?? '')),
        ])));
        $groups[$bucket]['items'][] = [
            'source' => 'check',
            'id' => (int)$row['id'],
            'kind' => $incoming ? 'Alınacak çek' : 'Verilecek çek',
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'tone' => $incoming ? 'success' : 'danger',
            'cari_name' => (string)($row['cari_name'] ?: ($row['drawer'] ?: 'Cari seçilmedi')),
            'description' => $checkInfo,
            'amount' => (float)$row['amount'],
            'currency' => 'TL',
            'amount_text' => dashboard_reminder_money($row['amount'], 'TL'),
            'due_date' => $dueDate,
            'due_text' => tr_date($dueDate),
            'state_text' => dashboard_reminder_due_text($dueDate, $today),
            'status_text' => (string)$row['status'] === 'bankaya_verildi' ? 'Bankaya verildi' : 'Bekliyor',
            'can_complete' => can_write(),
            'complete_label' => $incoming ? 'Tahsil edildi' : 'Ödendi',
            'account_scope' => 'bank',
            'default_account_id' => !empty($row['account_id']) ? (int)$row['account_id'] : '',
            'url' => 'cekler.php?direction=' . urlencode((string)$row['direction']) . '&edit=' . (int)$row['id'] . '#cek-form',
        ];
    }

    $totalCount = 0;
    foreach ($groups as &$group) {
        usort($group['items'], function(array $a, array $b): int {
            $dateCompare = strcmp((string)$a['due_date'], (string)$b['due_date']);
            if ($dateCompare !== 0) return $dateCompare;
            return ((int)$b['id']) <=> ((int)$a['id']);
        });
        $group['count'] = count($group['items']);
        $group['incoming_count'] = count(array_filter($group['items'], fn($item) => ($item['direction'] ?? '') === 'incoming'));
        $group['outgoing_count'] = $group['count'] - $group['incoming_count'];
        $totalCount += $group['count'];
    }
    unset($group);

    echo json_encode([
        'ok' => true,
        'today' => $today,
        'week_ahead' => $weekAhead,
        'count' => $totalCount,
        'csrf_token' => csrf_token(),
        'accounts' => array_values(array_map(function(array $account): array {
            return [
                'id'=>(int)$account['id'],
                'name'=>(string)$account['name'],
                'bank_name'=>(string)($account['bank_name'] ?? ''),
                'account_type'=>(string)($account['account_type'] ?? ''),
            ];
        }, accounts_for_select(true))),
        'groups' => array_values($groups),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
