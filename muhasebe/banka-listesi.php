<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$out = [];
try {
    $stmt = db()->query("SELECT id, account_type, name, bank_name, iban FROM accounts WHERE COALESCE(is_active,1)=1 ORDER BY account_type DESC, name ASC");
    foreach ($stmt->fetchAll() as $row) {
        $accountType = (string)($row['account_type'] ?? '');
        $name = trim((string)($row['name'] ?? ''));
        $bank = trim((string)($row['bank_name'] ?? ''));
        $iban = trim((string)($row['iban'] ?? ''));
        if ($accountType !== 'banka' && $bank === '') continue;
        $label = $bank !== '' ? $bank : $name;
        if ($label === '') continue;
        $detail = $name;
        if ($bank !== '' && $name !== '' && strcasecmp($bank, $name) !== 0) $detail = $bank . ' - ' . $name;
        if ($iban !== '') $detail .= ' / ' . $iban;
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => $label,
            'detail' => trim($detail),
        ];
    }
} catch (Throwable $e) {}

$unique = [];
foreach ($out as $item) {
    $key = mb_strtolower($item['name']);
    if (!isset($unique[$key])) $unique[$key] = $item;
}

echo json_encode(array_values($unique), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
