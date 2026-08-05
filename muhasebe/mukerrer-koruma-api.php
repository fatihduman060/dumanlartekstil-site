<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function mk_guard_norm(string $value): string
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

function mk_guard_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function mk_guard_cari(): array
{
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $taxNo = mk_guard_digits((string)($_POST['tax_no'] ?? ''));
    $nameKey = mk_guard_norm($name);

    if ($nameKey === '' && $taxNo === '') {
        return ['ok'=>true, 'duplicate'=>null];
    }

    $rows = db()->query("SELECT id, name, tax_no, city FROM cariler ORDER BY id ASC")->fetchAll();
    foreach ($rows as $row) {
        $rowId = (int)$row['id'];
        if ($rowId === $id) continue;

        $sameName = $nameKey !== '' && mk_guard_norm((string)$row['name']) === $nameKey;
        $sameTax = $taxNo !== '' && mk_guard_digits((string)($row['tax_no'] ?? '')) === $taxNo;
        if (!$sameName && !$sameTax) continue;

        return [
            'ok'=>true,
            'duplicate'=>[
                'id'=>$rowId,
                'name'=>(string)$row['name'],
                'tax_no'=>(string)($row['tax_no'] ?? ''),
                'city'=>(string)($row['city'] ?? ''),
                'match'=>$sameTax ? 'vergi_no' : 'unvan',
            ],
        ];
    }

    return ['ok'=>true, 'duplicate'=>null];
}

function mk_guard_currency(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['TL','USD','EUR'], true) ? $value : 'TL';
}

function mk_guard_movement(): array
{
    $id = (int)($_POST['id'] ?? 0);
    $type = trim((string)($_POST['movement_type'] ?? ''));
    $amount = decimal_from_input($_POST['amount'] ?? '0');
    $currency = mk_guard_currency((string)($_POST['currency'] ?? 'TL'));
    $date = trim((string)($_POST['movement_date'] ?? ''));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $cariId = (int)($_POST['cari_id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
    $documentType = trim((string)($_POST['document_type'] ?? ''));

    if ($type === '' || $amount <= 0 || $date === '') {
        return ['ok'=>true, 'duplicate'=>null];
    }

    $where = [
        'COALESCE(is_cancelled,0)=0',
        'movement_type=?',
        'ABS(amount-?)<0.005',
        "COALESCE(currency,'TL')=?",
        'movement_date=?',
        'COALESCE(cari_id,0)=?',
        'COALESCE(account_id,0)=?',
        "TRIM(COALESCE(due_date,''))=?",
        "TRIM(COALESCE(description,''))=?",
        "TRIM(COALESCE(payment_method,''))=?",
        "TRIM(COALESCE(document_type,''))=?",
    ];
    $params = [
        $type,
        $amount,
        $currency,
        $date,
        $cariId,
        $accountId,
        $dueDate,
        $description,
        $paymentMethod,
        $documentType,
    ];
    if ($id > 0) {
        $where[] = 'id<>?';
        $params[] = $id;
    }

    $stmt = db()->prepare('SELECT id, movement_type, amount, currency, movement_date, description
        FROM movements WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();

    return [
        'ok'=>true,
        'duplicate'=>$row ? [
            'id'=>(int)$row['id'],
            'movement_type'=>(string)$row['movement_type'],
            'amount'=>(float)$row['amount'],
            'currency'=>(string)($row['currency'] ?? 'TL'),
            'movement_date'=>(string)$row['movement_date'],
            'description'=>(string)($row['description'] ?? ''),
        ] : null,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }

    $action = trim((string)($_POST['guard_action'] ?? ''));
    if ($action === 'cari') {
        $payload = mk_guard_cari();
    } elseif ($action === 'movement') {
        $payload = mk_guard_movement();
    } else {
        throw new RuntimeException('Kontrol türü geçersiz.');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
