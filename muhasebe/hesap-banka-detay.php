<?php
require_once __DIR__ . '/layout.php';
require_admin();
header('Content-Type: application/json; charset=utf-8');

function hbd_money($amount): string { return money((float)$amount); }
function hbd_date($date): string { return tr_date((string)$date); }

try {
    $bank = trim((string)($_GET['bank'] ?? ''));
    if ($bank === '') throw new RuntimeException('Banka seçilmedi.');

    $stmt = db()->prepare("SELECT * FROM accounts
        WHERE account_type='banka'
          AND COALESCE(is_active,1)=1
          AND (bank_name = ? OR (TRIM(COALESCE(bank_name,'')) = '' AND name = ?))
        ORDER BY name ASC");
    $stmt->execute([$bank, $bank]);
    $accounts = $stmt->fetchAll();

    $rows = [];
    $ids = [];
    $total = 0.0;
    foreach ($accounts as $account) {
        $balance = account_balance((int)$account['id']);
        $total += $balance;
        $ids[] = (int)$account['id'];
        $rows[] = [
            'id' => (int)$account['id'],
            'name' => (string)$account['name'],
            'bank_name' => (string)($account['bank_name'] ?? ''),
            'iban' => (string)($account['iban'] ?? ''),
            'opening_balance' => hbd_money($account['opening_balance'] ?? 0),
            'balance' => round($balance, 2),
            'balance_text' => hbd_money($balance),
            'url' => 'hesap-dokumleri.php?account_id=' . (int)$account['id'],
        ];
    }

    $transactions = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT at.*, a.name AS account_name, a.bank_name, u.display_name AS user_name
            FROM account_transactions at
            JOIN accounts a ON a.id=at.account_id
            LEFT JOIN users u ON u.id=at.created_by
            WHERE at.account_id IN ($placeholders)
            ORDER BY at.transaction_date DESC, at.id DESC
            LIMIT 20";
        $stmt = db()->prepare($sql);
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $tr) {
            $transactions[] = [
                'date' => hbd_date($tr['transaction_date'] ?? ''),
                'account_name' => (string)($tr['account_name'] ?? ''),
                'source_type' => (string)($tr['source_type'] ?? ''),
                'description' => (string)($tr['description'] ?? ''),
                'direction' => (string)($tr['direction'] ?? ''),
                'amount' => round((float)($tr['amount'] ?? 0), 2),
                'amount_text' => hbd_money($tr['amount'] ?? 0),
                'user_name' => (string)($tr['user_name'] ?? ''),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'bank' => $bank,
        'account_count' => count($rows),
        'total' => round($total, 2),
        'total_text' => hbd_money($total),
        'accounts' => $rows,
        'transactions' => $transactions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
