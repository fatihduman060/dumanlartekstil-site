<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_once __DIR__ . '/magaza-veresiye-auto-only.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
magaza_odeme_dagilim_tablosunu_hazirla();

/**
 * Barkodlu Satış kullanılan günlerde mağazanın kart satış tutarı POS fişlerinden gelir.
 * Eski/manüel günlük kart tutarının yeni POS satışlarının üstüne eklenmesini engeller.
 * Kart veresiye tahsilatları bu toplama dahil değildir; ayrı alanda tutulur.
 */
function magaza_odeme_dagilim_pos_kart_bilgisi(string $saleDate): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || $saleDate < '2026-09-04') return null;
    $pdo = db();
    $exists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pos_sales' LIMIT 1")->fetchColumn();
    if (!$exists) return null;

    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(pos_sales)')->fetchAll() ?: [] as $column) {
        $columns[(string)($column['name'] ?? '')] = true;
    }
    if (empty($columns['sale_date']) || empty($columns['payment_method']) || empty($columns['grand_total'])) return null;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_sales WHERE sale_date=?');
    $countStmt->execute([$saleDate]);
    $receiptCount = (int)($countStmt->fetchColumn() ?: 0);
    if ($receiptCount < 1) return null;

    $cardExpr = !empty($columns['card_amount'])
        ? "CASE WHEN COALESCE(card_amount,0)>0 THEN card_amount WHEN payment_method='card' THEN grand_total ELSE 0 END"
        : "CASE WHEN payment_method='card' THEN grand_total ELSE 0 END";
    $cancelFilter = !empty($columns['is_cancelled']) ? ' AND COALESCE(is_cancelled,0)=0' : '';
    $stmt = $pdo->prepare("SELECT COALESCE(SUM({$cardExpr}),0) FROM pos_sales WHERE sale_date=?{$cancelFilter}");
    $stmt->execute([$saleDate]);

    return [
        'amount' => round((float)($stmt->fetchColumn() ?: 0), 2),
        'receipt_count' => $receiptCount,
    ];
}

function magaza_odeme_dagilim_pos_kart_gun_senkronla(string $saleDate): ?float
{
    $pos = magaza_odeme_dagilim_pos_kart_bilgisi($saleDate);
    if ($pos === null) return null;

    $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $stmt->execute([$saleDate]);
    $old = $stmt->fetch();
    if (!$old) return (float)$pos['amount'];

    $card = round((float)$pos['amount'], 2);
    $oldCard = round((float)($old['card_amount'] ?? 0), 2);
    if (abs($oldCard - $card) < 0.005) return $card;

    $dailyTotal = magaza_odeme_dagilim_gunluk_toplam(
        (float)($old['cash_amount'] ?? 0),
        $card,
        (float)($old['credit_amount'] ?? 0)
    );
    $userId = current_user()['id'] ?? null;
    db()->prepare('UPDATE store_daily_payment_breakdown SET card_amount=?,daily_total=?,updated_by=?,updated_at=? WHERE id=?')
        ->execute([$card,$dailyTotal,$userId,now(),(int)$old['id']]);

    // Vadesi gelmiş / daha önce oluşturulmuş banka hareketi varsa doğru kart tutarına çek.
    magaza_odeme_dagilim_hareketlerini_senkronla((int)$old['id']);

    $newStmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
    $newStmt->execute([(int)$old['id']]);
    $new = $newStmt->fetch() ?: [];
    if (function_exists('audit_action')) {
        audit_action('magaza_odeme_dagilimi', (int)$old['id'], 'pos_kart_senkronlandi', $old, $new,
            $saleDate . ' · kart satış POS toplamına eşitlendi');
    }
    return $card;
}

function magaza_odeme_dagilim_pos_kart_period_senkronla(string $period): void
{
    $period = magaza_odeme_dagilim_period($period);
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $exists = (bool)db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='pos_sales' LIMIT 1")->fetchColumn();
    if (!$exists) return;

    $stmt = db()->prepare('SELECT DISTINCT sale_date FROM pos_sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date ASC');
    $stmt->execute([$start,$end]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        magaza_odeme_dagilim_pos_kart_gun_senkronla((string)($row['sale_date'] ?? ''));
    }
}

function magaza_odeme_dagilim_payload(string $period): array
{
    // Ekran açıldığında eski/manüel kart birikimini de otomatik onar.
    magaza_odeme_dagilim_pos_kart_period_senkronla($period);
    magaza_veresiye_auto_only_sync_period($period);
    $items = [];
    foreach (magaza_odeme_dagilim_satirlari($period) as $row) {
        $cash = (float)($row['cash_amount'] ?? 0);
        $card = (float)($row['card_amount'] ?? 0);
        $cashCollection = (float)($row['cash_credit_collection_amount'] ?? 0);
        $cardCollection = (float)($row['card_credit_collection_amount'] ?? 0);
        $saleDate = (string)$row['sale_date'];
        $settlementDate = magaza_odeme_dagilim_kart_hesaba_gecis_tarihi($saleDate);
        $personnelCredit = magaza_odeme_dagilim_personel_veresiye_toplami($saleDate);
        $items[] = [
            'id' => (int)$row['id'],
            'sale_date' => $saleDate,
            'cash_amount' => $cash,
            'card_amount' => $card,
            'credit_amount' => (float)$row['credit_amount'],
            'manual_credit_amount' => 0.0,
            'personnel_credit_amount' => $personnelCredit,
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
        'can_quick_edit_cash_left' => is_fatih_user(),
        'csrf_token' => csrf_token(),
        'credit_mode' => 'personnel_auto',
        'card_delay_days' => MAGAZA_KART_HESABA_GECIS_GUN,
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

        if ($action === 'quick_cash_left') {
            if (!is_fatih_user()) throw new RuntimeException('Bu hızlı düzeltme yalnızca Fatih kullanıcısına açıktır.');

            $saleDate = trim((string)($_POST['sale_date'] ?? ''));
            $cashChangeLeft = decimal_from_input($_POST['cash_change_left_amount'] ?? '0');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) {
                throw new RuntimeException('Düzeltilecek gün bulunamadı.');
            }
            if ($cashChangeLeft < 0) throw new RuntimeException('Kasada kalan tutar negatif olamaz.');

            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
            $stmt->execute([$saleDate]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Bu güne ait mağaza kaydı bulunamadı.');

            $userId = current_user()['id'] ?? null;
            db()->prepare('UPDATE store_daily_payment_breakdown SET cash_change_left_amount=?, updated_by=?, updated_at=? WHERE id=?')
                ->execute([$cashChangeLeft, $userId, now(), (int)$old['id']]);

            $newStmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
            $newStmt->execute([(int)$old['id']]);
            $saved = $newStmt->fetch() ?: [];
            log_action('Kasada kalan para hızlı düzeltildi', $saleDate . ' · ' . number_format($cashChangeLeft, 2, ',', '.') . ' TL');
            audit_action('magaza_odeme_dagilimi', (int)$old['id'], 'kasada_kalan_duzeltildi', $old, $saved, $saleDate);
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['cancel_reason'] ?? ''));
            $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
            if ($id <= 0) throw new RuntimeException('Mağaza ödeme kaydı seçimi geçersiz.');
            if ($reasonLength < 6) throw new RuntimeException('İptal nedeni en az 6 karakter olmalıdır.');

            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
            $stmt->execute([$id]);
            $old = $stmt->fetch();
            if (!$old) throw new RuntimeException('Mağaza ödeme kaydı bulunamadı.');

            $pdo = db();
            $pdo->beginTransaction();
            try {
                magaza_odeme_dagilim_hareketlerini_kaldir($old);
                $snapshot = json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($snapshot === false) throw new RuntimeException('Mağaza ödeme kaydı arşivlenemedi.');
                $pdo->prepare("INSERT INTO store_daily_payment_breakdown_archive
                    (original_id,sale_date,snapshot,cancel_reason,cancelled_by,cancelled_at)
                    VALUES(?,?,?,?,?,?)")
                    ->execute([
                        $id,
                        (string)($old['sale_date'] ?? ''),
                        $snapshot,
                        $reason,
                        current_user()['id'] ?? null,
                        now(),
                    ]);
                $archiveId = (int)$pdo->lastInsertId();
                $pdo->prepare('DELETE FROM store_daily_payment_breakdown WHERE id=?')->execute([$id]);
                audit_action('magaza_odeme_dagilimi', $id, 'iptal', $old, [
                    'archive_id'=>$archiveId,
                    'cancel_reason'=>$reason,
                    'active_record_deleted'=>true,
                    'generated_movements_preserved_as_cancelled'=>true,
                ], (string)($old['sale_date'] ?? 'Mağaza ödeme dağılımı'));
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            log_action('Mağaza günlük ödeme dağılımı iptal edildi', (string)($old['sale_date'] ?? ('#' . $id)) . ' / ' . $reason);
        } else {
            $saleDate = trim((string)($_POST['sale_date'] ?? date('Y-m-d')));
            $cash = decimal_from_input($_POST['cash_amount'] ?? '0');
            $card = decimal_from_input($_POST['card_amount'] ?? '0');
            $submittedManualCredit = decimal_from_input($_POST['credit_amount'] ?? '0');
            $cashChangeLeft = decimal_from_input($_POST['cash_change_left_amount'] ?? '0');

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate) || strtotime($saleDate) === false) throw new RuntimeException('Satış tarihini kontrol etmelisin.');
            foreach ([$cash,$card,$submittedManualCredit,$cashChangeLeft] as $amount) if ($amount < 0) throw new RuntimeException('Tutarlar negatif olamaz.');

            // O güne ait Barkodlu Satış fişi varsa kart satışını kullanıcı girdisi değil POS belirler.
            $posCard = magaza_odeme_dagilim_pos_kart_bilgisi($saleDate);
            if ($posCard !== null) $card = (float)$posCard['amount'];

            if ($saleDate >= magaza_veresiye_auto_only_cutoff() && $submittedManualCredit > 0) {
                throw new RuntimeException('Manuel veresiye girişi kapatıldı. Veresiye satışını Personel Veresiye veya Barkodlu Satış bölümünden kaydetmelisin.');
            }

            $manualCredit = $saleDate >= magaza_veresiye_auto_only_cutoff() ? 0.0 : $submittedManualCredit;
            $personnelCredit = magaza_odeme_dagilim_personel_veresiye_toplami($saleDate);
            $credit = round($manualCredit + $personnelCredit, 2);

            $userId = current_user()['id'] ?? null;
            $stmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
            $stmt->execute([$saleDate]);
            $old = $stmt->fetch();
            $cashCollection = $old ? (float)($old['cash_credit_collection_amount'] ?? 0) : 0.0;
            $cardCollection = $old ? (float)($old['card_credit_collection_amount'] ?? 0) : 0.0;

            if (($cash+$card+$credit+$cashCollection+$cardCollection+$cashChangeLeft) <= 0) throw new RuntimeException('En az bir alana sıfırdan büyük tutar girmelisin.');

            $dailyTotal = magaza_odeme_dagilim_gunluk_toplam($cash, $card, $credit);
            $legacyCollectionTotal = round($cashCollection + $cardCollection, 2);

            db()->beginTransaction();
            try {
                if ($old) {
                    $id = (int)$old['id'];
                    db()->prepare('UPDATE store_daily_payment_breakdown SET cash_amount=?, card_amount=?, manual_credit_amount=?, credit_amount=?, credit_collection_amount=?, cash_credit_collection_amount=?, card_credit_collection_amount=?, cash_change_left_amount=?, daily_total=?, updated_by=?, updated_at=? WHERE id=?')
                        ->execute([$cash,$card,$manualCredit,$credit,$legacyCollectionTotal,$cashCollection,$cardCollection,$cashChangeLeft,$dailyTotal,$userId,now(),$id]);
                } else {
                    db()->prepare('INSERT INTO store_daily_payment_breakdown (sale_date, cash_amount, card_amount, manual_credit_amount, credit_amount, credit_collection_amount, cash_credit_collection_amount, card_credit_collection_amount, cash_change_left_amount, daily_total, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$saleDate,$cash,$card,$manualCredit,$credit,$legacyCollectionTotal,$cashCollection,$cardCollection,$cashChangeLeft,$dailyTotal,$userId,now(),$userId,now()]);
                    $id = (int)db()->lastInsertId();
                }

                if ($saleDate >= magaza_veresiye_auto_only_cutoff()) {
                    magaza_veresiye_auto_only_sync_date($saleDate, $userId ? (int)$userId : null);
                } else {
                    magaza_odeme_dagilim_hareketlerini_senkronla($id);
                }

                $newStmt = db()->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
                $newStmt->execute([$id]);
                $saved = $newStmt->fetch() ?: [];
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }

            $dailyTotal = (float)($saved['daily_total'] ?? $dailyTotal);
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
    // Önce kart satışını POS gerçek toplamına eşitle, sonra vadesi gelen hareketleri işle.
    magaza_odeme_dagilim_pos_kart_period_senkronla($period);
    $processedDueCount = magaza_odeme_dagilim_vadesi_gelenleri_isle();
    $payload = magaza_odeme_dagilim_payload($period);
    $payload['ok'] = true;
    $payload['processed_due_count'] = $processedDueCount;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
