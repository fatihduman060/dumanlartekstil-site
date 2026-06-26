<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

enforce_session_timeout();

function find_auto_check_collection_account(array $check): ?array
{
    if (empty($check['account_id'])) return null;
    $stmt = db()->prepare("SELECT * FROM accounts WHERE id=? AND account_type='banka' AND is_active=1 LIMIT 1");
    $stmt->execute([(int)$check['account_id']]);
    $account = $stmt->fetch();
    return $account ?: null;
}

function auto_check_description(array $check, array $account): string
{
    $parts = ['Otomatik çek tahsilatı'];
    if (!empty($check['cari_name'])) $parts[] = $check['cari_name'];
    if (!empty($check['bank_name'])) $parts[] = 'Çek bankası: ' . $check['bank_name'];
    if (!empty($account['name'])) $parts[] = 'Tahsil hesabı: ' . $account['name'];
    if (!empty($check['check_no'])) $parts[] = 'No: ' . $check['check_no'];
    if (!empty($check['due_date'])) $parts[] = 'Vade: ' . tr_date($check['due_date']);
    return implode(' / ', $parts);
}

$pdo = db();
$today = date('Y-m-d');
$processed = 0;
$skipped = [];

// Uygun olmayan veya artık açık olmayan çeklerden eski otomatik banka hareketini kaldır.
$pdo->prepare("DELETE FROM account_transactions
    WHERE source_type='check'
      AND source_id IN (
        SELECT id FROM checks
        WHERE COALESCE(is_cancelled,0)=1
           OR direction!='alinacak'
           OR status NOT IN ('bankaya_verildi','tahsil_edildi')
           OR due_date > ?
      )")->execute([$today]);

$stmt = $pdo->prepare("SELECT ch.*, c.name AS cari_name
    FROM checks ch
    LEFT JOIN cariler c ON c.id=ch.cari_id
    WHERE COALESCE(ch.is_cancelled,0)=0
      AND ch.direction='alinacak'
      AND ch.status IN ('bankaya_verildi','tahsil_edildi')
      AND ch.due_date<=?
    ORDER BY ch.due_date ASC, ch.id ASC");
$stmt->execute([$today]);

foreach ($stmt->fetchAll() as $check) {
    $checkId = (int)$check['id'];
    $account = find_auto_check_collection_account($check);

    $pdo->prepare("DELETE FROM account_transactions WHERE source_type='check' AND source_id=?")->execute([$checkId]);

    if (!$account) {
        $skipped[] = [
            'check_id' => $checkId,
            'reason' => 'Tahsil hesabı seçilmedi',
            'check_bank' => $check['bank_name'] ?? '',
        ];
        continue;
    }

    $pdo->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$account['id'], 'in', (float)$check['amount'], $check['due_date'], 'check', $checkId, auto_check_description($check, $account), current_user()['id'] ?? null, now()]);

    if (($check['status'] ?? '') === 'bankaya_verildi') {
        $pdo->prepare('UPDATE checks SET status=?, closed_at=?, updated_at=? WHERE id=?')
            ->execute(['tahsil_edildi', $check['due_date'], now(), $checkId]);
    }

    $processed++;
}

echo json_encode(['ok' => true, 'processed' => $processed, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
