<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $direction = trim((string)($_POST['direction'] ?? ''));
        if ($invoiceId <= 0 || !in_array($direction, ['gelen','giden'], true)) {
            throw new RuntimeException('Fatura veya yön seçimi geçersiz.');
        }

        $stmt = db()->prepare("SELECT * FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Fatura bulunamadı veya iptal edilmiş.');

        $movementType = $direction === 'giden' ? 'alacak' : 'verecek';
        $prefix = $direction === 'giden' ? 'Giden fatura' : 'Gelen fatura';
        $purpose = $direction === 'giden' ? 'Ürün/hizmet satışı' : 'Mal/hizmet alımı';
        $no = trim((string)($invoice['invoice_no'] ?? ''));
        $description = $prefix . ($no !== '' ? ' no: ' . $no : ' #' . $invoiceId) . ' / ' . $purpose;

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE invoices SET direction=?, updated_at=? WHERE id=?')
                ->execute([$direction, now(), $invoiceId]);

            $movementId = (int)($invoice['cari_movement_id'] ?? 0);
            if ($movementId > 0) {
                $stmt = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
                $stmt->execute([$movementId]);
                if ($stmt->fetchColumn()) {
                    db()->prepare('UPDATE movements SET movement_type=?, description=?, updated_at=? WHERE id=?')
                        ->execute([$movementType, $description, now(), $movementId]);
                    sync_movement_account_transaction($movementId);
                }
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        $label = $direction === 'giden' ? 'Giden fatura' : 'Gelen fatura';
        log_action('Fatura yönü değiştirildi', '#' . $invoiceId . ' → ' . $label);
        audit_action('fatura', $invoiceId, 'yon_degistirildi', ['direction'=>$invoice['direction'] ?? null], ['direction'=>$direction], $label);

        echo json_encode([
            'ok'=>true,
            'invoice_id'=>$invoiceId,
            'direction'=>$direction,
            'label'=>$label,
            'tone'=>$direction === 'giden' ? 'success' : 'danger',
            'csrf_token'=>csrf_token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'=>true,
        'can_write'=>can_write(),
        'csrf_token'=>csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
