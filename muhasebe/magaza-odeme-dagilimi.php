<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
magaza_odeme_dagilim_tablosunu_hazirla();

function magaza_odeme_dagilim_payload(string $period): array
{
    $items = [];
    foreach (magaza_odeme_dagilim_satirlari($period) as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'sale_date' => (string)$row['sale_date'],
            'cash_amount' => (float)$row['cash_amount'],
            'card_amount' => (float)$row['card_amount'],
            'credit_amount' => (float)$row['credit_amount'],
            'credit_collection_amount' => (float)$row['credit_collection_amount'],
            'daily_total' => (float)$row['daily_total'],
        ];
    }

    return [
        'period' => $period,
        'summary' => magaza_odeme_dagilim_ozeti($period),
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
            if ($id <= 0) throw new RuntimeException('Mağaza ödeme kaydı seçimi geçersiz.');

            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Mağaza ödeme kaydı bulunamadı.');

            db()->prepare('DELETE FROM store_daily_payment_breakdown WHERE id=?')->execute([$id]);
            log_action('Mağaza günlük ödeme dağılımı silindi', (string)($old['sale_date'] ?? ('#' . $id)));
            audit_action('magaza_odeme_dagilimi', $id, 'silindi', $old, null, (string)($old['sale_date'] ?? 'Mağaza ödeme dağılımı'));
        } else {
            $saleDate = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
            $cash = decimal_from_input($_POST['cash_amount'] ?? '0');
            $card = decimal_from_input($_POST['card_amount'] ?? '0');
            $credit = decimal_from_input($_POST['credit_amount'] ?? '0');
            $creditCollection = decimal_from_input($_POST['credit_collection_amount'] ?? '0');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
                throw new RuntimeException('Satış tarihini kontrol etmelisin.');
            }
            foreach ([$cash, $card, $credit, $creditCollection] as $amount) {
                if ($amount < 0) throw new RuntimeException('Ödeme tutarları negatif olamaz.');
            }
            if (($cash + $card + $credit + $creditCollection) <= 0) {
                throw new RuntimeException('En az bir ödeme alanına sıfırdan büyük tutar girmelisin.');
            }

            $dailyTotal = magaza_odeme_dagilim_gunluk_toplam($cash, $card, $credit);
            $userId = current_user()['id'] ?? null;

            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
            $stmt->execute([$saleDate]);
            $old = $stmt->fetch();

            if ($old) {
                $id = (int)$old['id'];
                db()->prepare('UPDATE store_daily_payment_breakdown SET cash_amount=?, card_amount=?, credit_amount=?, credit_collection_amount=?, daily_total=?, updated_by=?, updated_at=? WHERE id=?')
                    ->execute([$cash, $card, $credit, $creditCollection, $dailyTotal, $userId, now(), $id]);

                $newStmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
                $newStmt->execute([$id]);
                $saved = $newStmt->fetch() ?: [];
                log_action('Mağaza günlük ödeme dağılımı güncellendi', $saleDate . ' · ' . number_format($dailyTotal, 2, ',', '.') . ' TL');
                audit_action('magaza_odeme_dagilimi', $id, 'guncellendi', $old, $saved, $saleDate);
            } else {
                db()->prepare('INSERT INTO store_daily_payment_breakdown
                    (sale_date, cash_amount, card_amount, credit_amount, credit_collection_amount, daily_total, created_by, created_at, updated_by, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$saleDate, $cash, $card, $credit, $creditCollection, $dailyTotal, $userId, now(), $userId, now()]);
                $id = (int)db()->lastInsertId();

                log_action('Mağaza günlük ödeme dağılımı eklendi', $saleDate . ' · ' . number_format($dailyTotal, 2, ',', '.') . ' TL');
                audit_action('magaza_odeme_dagilimi', $id, 'eklendi', null, [
                    'sale_date' => $saleDate,
                    'cash_amount' => $cash,
                    'card_amount' => $card,
                    'credit_amount' => $credit,
                    'credit_collection_amount' => $creditCollection,
                    'daily_total' => $dailyTotal,
                ], $saleDate);
            }
        }
    }

    $period = magaza_odeme_dagilim_period((string)($_REQUEST['period'] ?? ''));
    $payload = magaza_odeme_dagilim_payload($period);
    $payload['ok'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
