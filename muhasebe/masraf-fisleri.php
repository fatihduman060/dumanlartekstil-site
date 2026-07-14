<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/masraf-fisi-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
masraf_fisi_tablosunu_hazirla();

function masraf_fisi_payload(string $period): array
{
    $categories = masraf_fisi_kategorileri();
    $rows = [];
    foreach (masraf_fisi_satirlari($period) as $row) {
        $key = (string)($row['category'] ?? 'diger');
        $rows[] = [
            'id' => (int)$row['id'],
            'receipt_date' => (string)$row['receipt_date'],
            'vendor' => (string)($row['vendor'] ?? ''),
            'category' => $key,
            'category_label' => $categories[$key] ?? 'Diğer',
            'total_amount' => (float)$row['total_amount'],
            'vat_rate' => (float)$row['vat_rate'],
            'subtotal' => (float)$row['subtotal'],
            'vat_amount' => (float)$row['vat_amount'],
            'include_in_vat' => (int)$row['include_in_vat'] === 1,
            'note' => (string)($row['note'] ?? ''),
        ];
    }

    return [
        'period' => $period,
        'categories' => $categories,
        'summary' => masraf_fisi_ozeti($period),
        'items' => $rows,
        'can_write' => can_write(),
        'csrf_token' => csrf_token(),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();
        $action = trim((string)($_POST['action'] ?? 'save'));

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Masraf fişi seçimi geçersiz.');
            $stmt = db()->prepare('SELECT * FROM expense_receipts WHERE id=?');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Masraf fişi bulunamadı.');
            db()->prepare('DELETE FROM expense_receipts WHERE id=?')->execute([$id]);
            log_action('Masraf fişi silindi', '#' . $id);
            audit_action('masraf_fisi', $id, 'silindi', $old, null, (string)($old['vendor'] ?? 'Masraf fişi'));
        } else {
            $receiptDate = trim((string)($_POST['receipt_date'] ?? date('Y-m-d')));
            $vendor = trim((string)($_POST['vendor'] ?? ''));
            $category = trim((string)($_POST['category'] ?? 'diger'));
            $total = decimal_from_input($_POST['total_amount'] ?? '0');
            $rate = (float)($_POST['vat_rate'] ?? 0);
            $include = isset($_POST['include_in_vat']) && (string)$_POST['include_in_vat'] !== '0' ? 1 : 0;
            $note = trim((string)($_POST['note'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receiptDate) || strtotime($receiptDate) === false) {
                throw new RuntimeException('Fiş tarihini kontrol etmelisin.');
            }
            if ($total <= 0) throw new RuntimeException('Fiş toplamı sıfırdan büyük olmalı.');
            if (!in_array($rate, [0.0, 1.0, 10.0, 20.0], true)) throw new RuntimeException('KDV oranı geçersiz.');
            if (!array_key_exists($category, masraf_fisi_kategorileri())) $category = 'diger';

            $subtotal = $rate > 0 ? round($total / (1 + ($rate / 100)), 2) : round($total, 2);
            $vat = round($total - $subtotal, 2);
            $userId = current_user()['id'] ?? null;

            db()->prepare('INSERT INTO expense_receipts
                (receipt_date, vendor, category, total_amount, vat_rate, subtotal, vat_amount, include_in_vat, note, created_by, created_at, updated_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$receiptDate, $vendor, $category, $total, $rate, $subtotal, $vat, $include, $note, $userId, now(), $userId, now()]);
            $id = (int)db()->lastInsertId();
            log_action('Masraf fişi eklendi', ($vendor !== '' ? $vendor : '#' . $id) . ' · ' . number_format($total, 2, ',', '.') . ' TL');
            audit_action('masraf_fisi', $id, 'eklendi', null, [
                'receipt_date'=>$receiptDate,'vendor'=>$vendor,'category'=>$category,'total_amount'=>$total,
                'vat_rate'=>$rate,'subtotal'=>$subtotal,'vat_amount'=>$vat,'include_in_vat'=>$include,'note'=>$note,
            ], $vendor !== '' ? $vendor : 'Masraf fişi #' . $id);
        }
    }

    $period = masraf_fisi_period((string)($_REQUEST['period'] ?? ''));
    $payload = masraf_fisi_payload($period);
    $payload['ok'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
