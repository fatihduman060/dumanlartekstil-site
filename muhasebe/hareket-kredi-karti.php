<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/kredi-kartlari-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$pdo = db();
ensure_column($pdo, 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
ensure_column($pdo, 'movements', 'card_key', 'TEXT');
ensure_column($pdo, 'movements', 'report_excluded', 'INTEGER NOT NULL DEFAULT 0');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_card_key ON movements(card_key)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_report_excluded ON movements(report_excluded)');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $items = [];
        foreach (muhasebe_kredi_kartlari() as $key => $card) {
            $items[] = [
                'key' => $key,
                'name' => (string)$card['name'],
                'bank_name' => (string)$card['bank_name'],
                'last4' => (string)$card['last4'],
            ];
        }
        echo json_encode(['ok'=>true,'cards'=>$items,'csrf_token'=>csrf_token()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!can_write()) throw new RuntimeException('Bu işlem için düzenleme yetkin yok.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar dene.');

    $cariId = (int)($_POST['cari_id'] ?? 0);
    $amount = decimal_from_input($_POST['amount'] ?? '0');
    $date = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
    $cardKey = trim((string)($_POST['card_key'] ?? ''));
    $card = muhasebe_kredi_karti($cardKey);
    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $description = trim((string)($_POST['description'] ?? ''));
    $documentType = trim((string)($_POST['document_type'] ?? '')) ?: null;

    if ($cariId <= 0) throw new RuntimeException('Kredi kartı ile ödeme için cari seçmelisin.');
    if ($amount <= 0) throw new RuntimeException('Ödeme tutarı sıfırdan büyük olmalı.');
    if (!$card) throw new RuntimeException('Kullanılacak kredi kartını seçmelisin.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) throw new RuntimeException('İşlem tarihini kontrol etmelisin.');

    $cariStmt = $pdo->prepare('SELECT id, name FROM cariler WHERE id=? LIMIT 1');
    $cariStmt->execute([$cariId]);
    $cari = $cariStmt->fetch();
    if (!$cari) throw new RuntimeException('Seçilen cari bulunamadı.');

    try {
        $doc = handle_upload('document');
    } catch (Throwable $e) {
        throw new RuntimeException($e->getMessage());
    }

    $paymentMethod = 'KREDİ KARTI · ' . (string)$card['name'];
    if ($description === '') $description = 'Kredi kartı ile cari borç ödemesi';

    $pdo->prepare("INSERT INTO movements
        (cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
         payment_method, description, document_type, document_path, document_name, document_mime,
         card_key, report_excluded, created_by, created_at, updated_at)
        VALUES (?, ?, NULL, 'odeme', ?, 'TL', ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)")
        ->execute([
            $cariId, $categoryId, $amount, $date, $paymentMethod, $description,
            $documentType, $doc['path'], $doc['name'], $doc['mime'], $cardKey,
            current_user()['id'] ?? null, now(), now()
        ]);
    $movementId = (int)$pdo->lastInsertId();

    // Bilerek hesap hareketi oluşturulmaz. Bu kayıt yalnızca cari borcu azaltır.
    // Kart borcunun gerçek banka çıkışı Kart Ekstre Takibi'nde ekstre ödendiğinde oluşur.
    sync_movement_account_transaction($movementId);

    audit_action('hareket', $movementId, 'kartla_odeme', null, [
        'type'=>'odeme',
        'amount'=>$amount,
        'currency'=>'TL',
        'date'=>$date,
        'cari_id'=>$cariId,
        'card_key'=>$cardKey,
        'report_excluded'=>1,
        'account_id'=>null,
    ], 'Kredi kartı ile cari ödeme');
    log_action('Kredi kartı ile cari ödeme', (string)$cari['name'] . ' / ' . (string)$card['name'] . ' / ' . money($amount));

    echo json_encode([
        'ok'=>true,
        'message'=>'Kartlı ödeme kaydedildi. Cari borcu azaldı; kasa/banka ve rapor toplamlarına ikinci kez yazılmadı.',
        'movement_id'=>$movementId,
        'redirect'=>'hareketler.php?edit=' . $movementId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
