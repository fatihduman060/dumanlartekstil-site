<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cmk_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function cmk_active_movement(int $movementId): bool
{
    if ($movementId <= 0) return false;
    try {
        $stmt = db()->prepare('SELECT 1 FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
        $stmt->execute([$movementId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function cmk_money(float $amount, string $currency): string
{
    return number_format($amount, 2, ',', '.') . ' ' . strtoupper(trim($currency) ?: 'TL');
}

function cmk_date(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') return '';
    $time = strtotime($date);
    return $time ? date('d.m.Y', $time) : $date;
}

function cmk_offer_label(array $row): string
{
    $title = trim((string)($row['document_title'] ?? '')) ?: 'Teklif / sipariş fişi';
    $no = trim((string)($row['offer_no'] ?? ''));
    return $title . ($no !== '' ? ' no: ' . $no : ' #' . (int)($row['id'] ?? 0));
}

function cmk_invoice_label(array $row): string
{
    $direction = (string)($row['direction'] ?? 'gelen');
    $title = $direction === 'giden' ? 'Giden fatura' : 'Gelen fatura';
    $no = trim((string)($row['invoice_no'] ?? ''));
    return $title . ($no !== '' ? ' no: ' . $no : ' #' . (int)($row['id'] ?? 0));
}

function cmk_source(string $sourceType, int $id): ?array
{
    if ($sourceType === 'offer' && cmk_table_exists('offers')) {
        $stmt = db()->prepare("SELECT id, cari_id, offer_no, offer_date, document_title, grand_total, currency, cari_movement_id FROM offers WHERE id=? AND COALESCE(is_deleted,0)=0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        return [
            'type'=>'offer',
            'id'=>(int)$row['id'],
            'cari_id'=>(int)($row['cari_id'] ?? 0),
            'amount'=>(float)($row['grand_total'] ?? 0),
            'currency'=>strtoupper(trim((string)($row['currency'] ?? 'TL'))) ?: 'TL',
            'date'=>(string)($row['offer_date'] ?? ''),
            'movement_type'=>'alacak',
            'movement_id'=>(int)($row['cari_movement_id'] ?? 0),
            'label'=>cmk_offer_label($row),
        ];
    }

    if ($sourceType === 'invoice' && cmk_table_exists('invoices')) {
        $stmt = db()->prepare("SELECT id, cari_id, invoice_no, invoice_date, direction, total_amount, currency, cari_movement_id FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $direction = (string)($row['direction'] ?? 'gelen');
        return [
            'type'=>'invoice',
            'id'=>(int)$row['id'],
            'cari_id'=>(int)($row['cari_id'] ?? 0),
            'amount'=>(float)($row['total_amount'] ?? 0),
            'currency'=>strtoupper(trim((string)($row['currency'] ?? 'TL'))) ?: 'TL',
            'date'=>(string)($row['invoice_date'] ?? ''),
            'movement_type'=>$direction === 'giden' ? 'alacak' : 'verecek',
            'movement_id'=>(int)($row['cari_movement_id'] ?? 0),
            'label'=>cmk_invoice_label($row),
        ];
    }

    return null;
}

function cmk_find_duplicates(array $source): array
{
    $cariId = (int)$source['cari_id'];
    $amount = (float)$source['amount'];
    $currency = (string)$source['currency'];
    $movementType = (string)$source['movement_type'];
    $movementId = (int)$source['movement_id'];
    if ($cariId <= 0 || $amount <= 0 || $currency === '') return [];

    $rows = [];

    if (cmk_table_exists('offers')) {
        try {
            $sql = "SELECT o.id, o.offer_no, o.offer_date, o.document_title, o.grand_total, o.currency, o.cari_movement_id
                    FROM offers o
                    INNER JOIN movements m ON m.id=o.cari_movement_id
                    WHERE o.cari_id=?
                      AND ABS(o.grand_total-?)<0.01
                      AND UPPER(COALESCE(o.currency,'TL'))=?
                      AND COALESCE(o.is_deleted,0)=0
                      AND COALESCE(m.is_cancelled,0)=0
                      AND m.movement_type=?";
            $params = [$cariId, $amount, $currency, $movementType];
            if ($source['type'] === 'offer') {
                $sql .= ' AND o.id<>?';
                $params[] = (int)$source['id'];
            }
            if ($movementId > 0) {
                $sql .= ' AND o.cari_movement_id<>?';
                $params[] = $movementId;
            }
            $sql .= ' ORDER BY o.offer_date DESC, o.id DESC LIMIT 10';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = [
                    'source_type'=>'offer',
                    'source_id'=>(int)$row['id'],
                    'movement_id'=>(int)$row['cari_movement_id'],
                    'label'=>cmk_offer_label($row),
                    'date'=>cmk_date($row['offer_date'] ?? ''),
                    'amount'=>cmk_money((float)$row['grand_total'], (string)$row['currency']),
                ];
            }
        } catch (Throwable $e) {}
    }

    if (cmk_table_exists('invoices')) {
        try {
            $sql = "SELECT i.id, i.invoice_no, i.invoice_date, i.direction, i.total_amount, i.currency, i.cari_movement_id
                    FROM invoices i
                    INNER JOIN movements m ON m.id=i.cari_movement_id
                    WHERE i.cari_id=?
                      AND ABS(i.total_amount-?)<0.01
                      AND UPPER(COALESCE(i.currency,'TL'))=?
                      AND COALESCE(i.is_cancelled,0)=0
                      AND COALESCE(m.is_cancelled,0)=0
                      AND m.movement_type=?";
            $params = [$cariId, $amount, $currency, $movementType];
            if ($source['type'] === 'invoice') {
                $sql .= ' AND i.id<>?';
                $params[] = (int)$source['id'];
            }
            if ($movementId > 0) {
                $sql .= ' AND i.cari_movement_id<>?';
                $params[] = $movementId;
            }
            $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC LIMIT 10';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = [
                    'source_type'=>'invoice',
                    'source_id'=>(int)$row['id'],
                    'movement_id'=>(int)$row['cari_movement_id'],
                    'label'=>cmk_invoice_label($row),
                    'date'=>cmk_date($row['invoice_date'] ?? ''),
                    'amount'=>cmk_money((float)$row['total_amount'], (string)$row['currency']),
                ];
            }
        } catch (Throwable $e) {}
    }

    usort($rows, function (array $a, array $b): int {
        return strcmp((string)$b['date'], (string)$a['date']);
    });
    return array_slice($rows, 0, 8);
}

$sourceType = trim((string)($_GET['source_type'] ?? ''));
$id = (int)($_GET['id'] ?? 0);
$out = [
    'ok'=>false,
    'has_duplicate'=>false,
    'already_posted'=>false,
    'source_label'=>'',
    'source_amount'=>'',
    'duplicates'=>[],
];

try {
    $source = cmk_source($sourceType, $id);
    if (!$source) throw new RuntimeException('Belge bulunamadı.');

    $out['ok'] = true;
    $out['source_label'] = $source['label'];
    $out['source_amount'] = cmk_money((float)$source['amount'], (string)$source['currency']);
    $out['already_posted'] = cmk_active_movement((int)$source['movement_id']);

    if (!$out['already_posted']) {
        $out['duplicates'] = cmk_find_duplicates($source);
        $out['has_duplicate'] = !empty($out['duplicates']);
    }
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
