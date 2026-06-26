<?php
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

enforce_session_timeout();

function normalize_bank_key_for_auto(?string $value): string
{
    $value = trim((string)$value);
    $map = ['Ç'=>'c','Ğ'=>'g','İ'=>'i','I'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u','ç'=>'c','ğ'=>'g','ı'=>'i','i'=>'i','ö'=>'o','ş'=>'s','ü'=>'u'];
    $value = strtr($value, $map);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
    return $value;
}

function find_auto_check_bank_account(array $check): ?array
{
    $pdo = db();
    if (!empty($check['account_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id=? AND account_type='banka' AND is_active=1 LIMIT 1");
        $stmt->execute([(int)$check['account_id']]);
        $account = $stmt->fetch();
        if ($account) return $account;
    }

    $bank = trim((string)($check['bank_name'] ?? ''));
    if ($bank === '') return null;
    $target = normalize_bank_key_for_auto($bank);
    foreach (accounts_for_select(true) as $account) {
        if (($account['account_type'] ?? '') !== 'banka') continue;
        $bankKey = normalize_bank_key_for_auto($account['bank_name'] ?? '');
        $nameKey = normalize_bank_key_for_auto($account['name'] ?? '');
        if ($bankKey === $target || $nameKey === $target || ($target !== '' && (strpos($nameKey, $target) !== false || strpos($target, $nameKey) !== false))) {
            return $account;
        }
    }
    return null;
}

function auto_check_description(array $check, array $account): string
{
    $parts = ['Otomatik çek tahsilatı'];
    if (!empty($check['cari_name'])) $parts[] = $check['cari_name'];
    if (!empty($check['bank_name'])) $parts[] = $check['bank_name'];
    if (!empty($check['check_no'])) $parts[] = 'No: ' . $check['check_no'];
    if (!empty($check['due_date'])) $parts[] = 'Vade: ' . tr_date($check['due_date']);
    return implode(' / ', $parts);
}

$pdo = db();
$today = date('Y-m-d');
$processed = 0;
$skipped = [];

// Artık uygun olmayan çeklerden eski otomatik banka hareketini kaldır.
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
    $account = find_auto_check_bank_account($check);

    $pdo->prepare("DELETE FROM account_transactions WHERE source_type='check' AND source_id=?")->execute([$checkId]);

    if (!$account) {
        $skipped[] = [
            'check_id' => $checkId,
            'reason' => 'Banka hesabı bulunamadı',
            'bank_name' => $check['bank_name'] ?? '',
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
