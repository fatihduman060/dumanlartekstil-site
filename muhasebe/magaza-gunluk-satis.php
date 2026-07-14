<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
magaza_satis_tablosunu_hazirla();

function magaza_satis_payload(string $period): array
{
    $items = [];
    foreach (magaza_satis_satirlari($period) as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'sale_date' => (string)$row['sale_date'],
            'gross_amount' => (float)$row['gross_amount'],
            'vat_rate' => (float)$row['vat_rate'],
            'subtotal' => (float)$row['subtotal'],
            'vat_amount' => (float)$row['vat_amount'],
            'note' => (string)($row['note'] ?? ''),
        ];
    }

    return [
        'period' => $period,
        'summary' => magaza_satis_ozeti($period),
        'items' => $items,
        'can_write' => can_manage_store_sales(),
        'csrf_token' => csrf_token(),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_store_sales_write();
        require_csrf();
        $action = trim((string)($_POST['action'] ?? 'save'));

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Mağaza satış kaydı seçimi geçersiz.');
            $stmt = db()->prepare('SELECT * FROM store_daily_sales WHERE id=?');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Mağaza satış kaydı bulunamadı.');
            db()->prepare('DELETE FROM store_daily_sales WHERE id=?')->execute([$id]);
            log_action('Mağaza günlük satışı silindi', (string)($old['sale_date'] ?? ('#' . $id)));
            audit_action('magaza_gunluk_satis', $id, 'silindi', $old, null, (string)($old['sale_date'] ?? 'Mağaza satışı'));
        } else {
            $saleDate = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
            $gross = decimal_from_input($_POST['gross_amount'] ?? '0');
            $note = trim((string)($_POST['note'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
                throw new RuntimeException('Satış tarihini kontrol etmelisin.');
            }
            if ($gross <= 0) throw new RuntimeException('Günlük satış toplamı sıfırdan büyük olmalı.');

            $vatRate = 10.0;
            $subtotal = round($gross / 1.10, 2);
            $vat = round($gross - $subtotal, 2);
            $userId = current_user()['id'] ?? null;

            $stmt = db()->prepare('SELECT * FROM store_daily_sales WHERE sale_date=? LIMIT 1');
            $stmt->execute([$saleDate]);
            $old = $stmt->fetch();

            if ($old) {
                $id = (int)$old['id'];
                db()->prepare('UPDATE store_daily_sales SET gross_amount=?, vat_rate=?, subtotal=?, vat_amount=?, note=?, updated_by=?, updated_at=? WHERE id=?')
                    ->execute([$gross, $vatRate, $subtotal, $vat, $note, $userId, now(), $id]);
                $new = db()->prepare('SELECT * FROM store_daily_sales WHERE id=?');
                $new->execute([$id]);
                $saved = $new->fetch() ?: [];
                log_action('Mağaza günlük satışı güncellendi', $saleDate . ' · ' . number_format($gross, 2, ',', '.') . ' TL');
                audit_action('magaza_gunluk_satis', $id, 'guncellendi', $old, $saved, $saleDate);
            } else {
                db()->prepare('INSERT INTO store_daily_sales
                    (sale_date, gross_amount, vat_rate, subtotal, vat_amount, note, created_by, created_at, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$saleDate, $gross, $vatRate, $subtotal, $vat, $note, $userId, now(), $userId, now()]);
                $id = (int)db()->lastInsertId();
                log_action('Mağaza günlük satışı eklendi', $saleDate . ' · ' . number_format($gross, 2, ',', '.') . ' TL');
                audit_action('magaza_gunluk_satis', $id, 'eklendi', null, [
                    'sale_date'=>$saleDate,'gross_amount'=>$gross,'vat_rate'=>$vatRate,
                    'subtotal'=>$subtotal,'vat_amount'=>$vat,'note'=>$note,
                ], $saleDate);
            }
        }
    }

    $period = magaza_satis_period((string)($_REQUEST['period'] ?? ''));
    $payload = magaza_satis_payload($period);
    $payload['ok'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
