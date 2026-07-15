<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
magaza_odeme_dagilim_tablosunu_hazirla();
$processedDueCount = magaza_odeme_dagilim_vadesi_gelenleri_isle();

function magaza_odeme_dagilim_payload(string $period): array
{
    $items = [];
    foreach (magaza_odeme_dagilim_satirlari($period) as $row) {
        $cash = (float)($row['cash_amount'] ?? 0);
        $card = (float)($row['card_amount'] ?? 0);
        $cashCollection = (float)($row['cash_credit_collection_amount'] ?? 0);
        $cardCollection = (float)($row['card_credit_collection_amount'] ?? 0);
        $settlementDate = magaza_odeme_dagilim_kart_hesaba_gecis_tarihi((string)$row['sale_date']);
        $items[] = [
            'id' => (int)$row['id'],
            'sale_date' => (string)$row['sale_date'],
            'cash_amount' => $cash,
            'card_amount' => $card,
            'credit_amount' => (float)$row['credit_amount'],
            'cash_credit_collection_amount' => $cashCollection,
            'card_credit_collection_amount' => $cardCollection,
            'credit_collection_amount' => round($cashCollection + $cardCollection, 2),
            'cash_total_amount' => round($cash + $cashCollection, 2),
            'card_total_amount' => round($card + $cardCollection, 2),
            'cash_change_left_amount' => (float)($row['cash_change_left_amount'] ?? 0),
            'daily_total' => (float)$row['daily_total'],
            'cash_posted' => (int)($row['cash_movement_id'] ?? 0) > 0,
            'card_posted' => (int)($row['card_movement_id'] ?? 0) > 0,
            'card_settlement_date' => $settlementDate,
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
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'previous_cash_change') {
        $saleDate = trim((string)($_GET['sale_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
            throw new RuntimeException('Önceki kasa bilgisinin tarihi geçersiz.');
        }
        echo json_encode(['ok'=>true,'previous'=>magaza_odeme_dagilim_onceki_kasa_parasi($saleDate)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

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

            db()->beginTransaction();
            try {
                magaza_odeme_dagilim_hareketlerini_kaldir($old);
                db()->prepare('DELETE FROM store_daily_payment_breakdown WHERE id=?')->execute([$id]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }
            log_action('Mağaza günlük ödeme dağılımı silindi', (string)($old['sale_date'] ?? ('#' . $id)));
            audit_action('magaza_odeme_dagilimi', $id, 'silindi', $old, null, (string)($old['sale_date'] ?? 'Mağaza ödeme dağılımı'));
        } else {
            $saleDate = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
            $cash = decimal_from_input($_POST['cash_amount'] ?? '0');
            $card = decimal_from_input($_POST['card_amount'] ?? '0');
            $credit = decimal_from_input($_POST['credit_amount'] ?? '0');
            $cashCollection = decimal_from_input($_POST['cash_credit_collection_amount'] ?? ($_POST['credit_collection_amount'] ?? '0'));
            $cardCollection = decimal_from_input($_POST['card_credit_collection_amount'] ?? '0');
            $cashChangeLeft = decimal_from_input($_POST['cash_change_left_amount'] ?? '0');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) throw new RuntimeException('Satış tarihini kontrol etmelisin.');
            foreach ([$cash,$card,$credit,$cashCollection,$cardCollection,$cashChangeLeft] as $amount) if ($amount < 0) throw new RuntimeException('Tutarlar negatif olamaz.');
            if (($cash+$card+$credit+$cashCollection+$cardCollection+$cashChangeLeft) <= 0) throw new RuntimeException('En az bir alana sıfırdan büyük tutar girmelisin.');

            $dailyTotal = magaza_odeme_dagilim_gunluk_toplam($cash, $card, $credit);
            $legacyCollectionTotal = round($cashCollection + $cardCollection, 2);
            $userId = current_user()['id'] ?? null;
            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
            $stmt->execute([$saleDate]);
            $old = $stmt->fetch();

            db()->beginTransaction();
            try {
                if ($old) {
                    $id = (int)$old['id'];
                    db()->prepare('UPDATE store_daily_payment_breakdown SET cash_amount=?, card_amount=?, credit_amount=?, credit_collection_amount=?, cash_credit_collection_amount=?, card_credit_collection_amount=?, cash_change_left_amount=?, daily_total=?, updated_by=?, updated_at=? WHERE id=?')
                        ->execute([$cash,$card,$credit,$legacyCollectionTotal,$cashCollection,$cardCollection,$cashChangeLeft,$dailyTotal,$userId,now(),$id]);
                } else {
                    db()->prepare('INSERT INTO store_daily_payment_breakdown (sale_date, cash_amount, card_amount, credit_amount, credit_collection_amount, cash_credit_collection_amount, card_credit_collection_amount, cash_change_left_amount, daily_total, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$saleDate,$cash,$card,$credit,$legacyCollectionTotal,$cashCollection,$cardCollection,$cashChangeLeft,$dailyTotal,$userId,now(),$userId,now()]);
                    $id = (int)db()->lastInsertId();
                }

                magaza_odeme_dagilim_hareketlerini_senkronla($id);
                $newStmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
                $newStmt->execute([$id]);
                $saved = $newStmt->fetch() ?: [];
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }

            $cardSettlementDate = magaza_odeme_dagilim_kart_hesaba_gecis_tarihi($saleDate);
            if ($old) {
                log_action('Mağaza günlük ödeme dağılımı güncellendi', $saleDate . ' · ' . number_format($dailyTotal, 2, ',', '.') . ' TL satış');
                audit_action('magaza_odeme_dagilimi', $id, 'guncellendi', $old, $saved, $saleDate);
            } else {
                log_action('Mağaza günlük ödeme dağılımı eklendi', $saleDate . ' · kart geçişi ' . $cardSettlementDate);
                audit_action('magaza_odeme_dagilimi', $id, 'eklendi', null, $saved, $saleDate);
            }
        }
    }

    $period = magaza_odeme_dagilim_period((string)($_REQUEST['period'] ?? ''));
    $payload = magaza_odeme_dagilim_payload($period);
    $payload['ok'] = true;
    $payload['processed_due_count'] = $processedDueCount;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
