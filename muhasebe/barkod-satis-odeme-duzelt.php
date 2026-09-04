<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function pos_payment_fix_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }
    if (!pos_can_delete_sales()) {
        throw new RuntimeException('Ödeme şeklini düzeltme yetkisi yalnızca Fatih kullanıcısına aittir.');
    }
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.');
    }

    pos_db_ensure();
    magaza_odeme_dagilim_tablosunu_hazirla();

    $saleId = (int)($_POST['sale_id'] ?? 0);
    $target = trim((string)($_POST['payment_method'] ?? ''));
    if ($saleId <= 0) throw new RuntimeException('Satış seçilmedi.');
    if (!in_array($target, ['cash', 'card'], true)) throw new RuntimeException('Yeni ödeme şekli Nakit veya Kredi Kartı olmalıdır.');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM pos_sales WHERE id=? AND is_cancelled=0 LIMIT 1");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch();
    if (!$sale) throw new RuntimeException('Satış bulunamadı veya iptal edilmiş.');

    $current = (string)($sale['payment_method'] ?? '');
    if (!in_array($current, ['cash', 'card'], true)) {
        throw new RuntimeException('Yalnızca Nakit ve Kredi Kartı satışları birbirine çevrilebilir. Veresiye satışa dokunulmadı.');
    }
    if ($current === $target) {
        pos_payment_fix_json(['ok'=>true,'message'=>'Satış zaten seçilen ödeme şeklinde.']);
    }

    $amount = round((float)($sale['grand_total'] ?? 0), 2);
    if ($amount <= 0) throw new RuntimeException('Satış tutarı geçersiz.');
    $saleDate = (string)($sale['sale_date'] ?? '');

    $paymentStmt = $pdo->prepare("SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1");
    $paymentStmt->execute([$saleDate]);
    $payment = $paymentStmt->fetch();
    if (!$payment) {
        throw new RuntimeException('Bu satış gününün ödeme dağılımı bulunamadı. Toplamları bozmamak için işlem yapılmadı.');
    }
    $sourceAmount = $current === 'cash' ? (float)($payment['cash_amount'] ?? 0) : (float)($payment['card_amount'] ?? 0);
    if ($sourceAmount + 0.01 < $amount) {
        throw new RuntimeException('Günlük ödeme dağılımı satışla uyuşmuyor. Negatif toplam oluşturmamak için işlem yapılmadı.');
    }

    $cashDelta = 0.0;
    $cardDelta = 0.0;
    if ($current === 'cash' && $target === 'card') {
        $cashDelta = -$amount;
        $cardDelta = $amount;
    } elseif ($current === 'card' && $target === 'cash') {
        $cardDelta = -$amount;
        $cashDelta = $amount;
    }

    $userId = (int)(current_user()['id'] ?? 0) ?: null;
    $pdo->beginTransaction();
    try {
        // Genel satış toplamı değişmez. Yalnızca o günün Nakit/Kart dağılımı aktarılır.
        pos_daily_totals_delta($saleDate, 0.0, $cashDelta, $cardDelta, 0.0, $userId);
        $pdo->prepare("UPDATE pos_sales SET payment_method=? WHERE id=?")->execute([$target, $saleId]);
        // Ödeme şekli değiştikten sonra Z raporunu gerçek Barkodlu Satış kart toplamına eşitle.
        magaza_satis_pos_kart_senkronla($saleDate);

        audit_action('pos_sale', $saleId, 'odeme_sekli_duzeltildi', [
            'payment_method'=>$current,
            'grand_total'=>$amount,
            'sale_date'=>$saleDate,
        ], [
            'payment_method'=>$target,
            'grand_total'=>$amount,
            'sale_date'=>$saleDate,
        ], (string)($sale['receipt_no'] ?? ('POS #' . $saleId)));
        log_action('Barkodlu satış ödeme şekli düzeltildi',
            (string)($sale['receipt_no'] ?? ('#' . $saleId)) . ' / '
            . ($current === 'cash' ? 'Nakit' : 'Kredi Kartı') . ' → '
            . ($target === 'cash' ? 'Nakit' : 'Kredi Kartı') . ' / ' . money($amount)
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    pos_payment_fix_json([
        'ok'=>true,
        'message'=>'Ödeme şekli düzeltildi. Satış toplamı değişmedi; yalnız Nakit/Kart dağılımı aktarıldı.',
        'sale_id'=>$saleId,
        'payment_method'=>$target,
    ]);
} catch (Throwable $e) {
    pos_payment_fix_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
