<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function barkod_kasa_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function barkod_kasa_tutar(string $date): float
{
    $stmt = db()->prepare("SELECT COALESCE(cash_change_left_amount,0) FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1");
    $stmt->execute([$date]);
    return round((float)($stmt->fetchColumn() ?: 0), 2);
}

try {
    if (!can_manage_store_sales()) {
        throw new RuntimeException('Bu bölüm için mağaza satış yetkisi gerekiyor.');
    }

    magaza_odeme_dagilim_tablosunu_hazirla();
    $today = date('Y-m-d');
    $canEditPast = is_fatih_user();
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        barkod_kasa_json(['ok'=>false,'error'=>'Geçersiz istek yöntemi.'], 405);
    }
    $date = ($method === 'GET' ? ($_GET['date'] ?? $today) : ($_POST['date'] ?? $today));
    $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > $today) {
        throw new RuntimeException('Bugün veya geçmiş bir tarih seçin.');
    }
    if ($date !== $today && !$canEditPast) {
        barkod_kasa_json(['ok'=>false,'error'=>'Önceki günlerin kasa tutarını yalnızca Fatih güncelleyebilir.'], 403);
    }
    $yesterday = $parsed->modify('-1 day')->format('Y-m-d');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        barkod_kasa_json([
            'ok' => true,
            'today_date' => $today,
            'yesterday_date' => $yesterday,
            'today_amount' => barkod_kasa_tutar($today),
            'selected_date' => $date,
            'selected_amount' => barkod_kasa_tutar($date),
            'can_edit_past' => $canEditPast,
            'yesterday_amount' => barkod_kasa_tutar($yesterday),
            'csrf_token' => csrf_token(),
        ]);
    }

    require_store_sales_write();
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.');
    }

    $amount = round(decimal_from_input($_POST['amount'] ?? '0'), 2);
    if ($amount < 0) throw new RuntimeException('Kasada bırakılan tutar negatif olamaz.');
    if ($amount > 10000000) throw new RuntimeException('Tutar çok yüksek. Rakamı kontrol edin.');

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1");
    $stmt->execute([$date]);
    $old = $stmt->fetch() ?: null;
    $userId = current_user()['id'] ?? null;
    $now = now();

    if ($old) {
        $pdo->prepare("UPDATE store_daily_payment_breakdown SET cash_change_left_amount=?,updated_by=?,updated_at=? WHERE id=?")
            ->execute([$amount,$userId,$now,(int)$old['id']]);
        $recordId = (int)$old['id'];
    } else {
        $pdo->prepare("INSERT INTO store_daily_payment_breakdown (sale_date,cash_amount,card_amount,credit_amount,manual_credit_amount,credit_collection_amount,cash_credit_collection_amount,card_credit_collection_amount,cash_change_left_amount,daily_total,created_by,created_at,updated_by,updated_at) VALUES (?,0,0,0,0,0,0,0,?,0,?,?,?,?)")
            ->execute([$date,$amount,$userId,$now,$userId,$now]);
        $recordId = (int)$pdo->lastInsertId();
    }

    $newStmt = $pdo->prepare("SELECT * FROM store_daily_payment_breakdown WHERE id=? LIMIT 1");
    $newStmt->execute([$recordId]);
    $saved = $newStmt->fetch() ?: [];

    log_action('Barkodlu satış kasada bırakılan para güncellendi', $date . ' · ' . number_format($amount, 2, ',', '.') . ' TL');
    audit_action('magaza_odeme_dagilimi', $recordId, 'kasada_birakilan_guncellendi', $old, $saved, $date);

    barkod_kasa_json([
        'ok' => true,
        'message' => tr_date($date) . ' için kasada bırakılan para kaydedildi.',
        'selected_date' => $date,
        'selected_amount' => $amount,
        'today_date' => $today,
        'yesterday_date' => $yesterday,
        'today_amount' => barkod_kasa_tutar($today),
        'yesterday_amount' => barkod_kasa_tutar($yesterday),
    ]);
} catch (Throwable $e) {
    barkod_kasa_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
