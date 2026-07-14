<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $cariId = (int)($_POST['cari_id'] ?? 0);
        if ($invoiceId <= 0 || $cariId <= 0) {
            throw new RuntimeException('Fatura veya cari seçimi geçersiz.');
        }

        $stmt = db()->prepare("SELECT * FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Fatura bulunamadı veya iptal edilmiş.');

        $stmt = db()->prepare('SELECT id, name, cari_type FROM cariler WHERE id=?');
        $stmt->execute([$cariId]);
        $cari = $stmt->fetch();
        if (!$cari) throw new RuntimeException('Seçilen cari bulunamadı.');

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE invoices SET cari_id=?, updated_at=? WHERE id=?')
                ->execute([$cariId, now(), $invoiceId]);

            $movementId = (int)($invoice['cari_movement_id'] ?? 0);
            if ($movementId > 0) {
                $stmt = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
                $stmt->execute([$movementId]);
                if ($stmt->fetchColumn()) {
                    db()->prepare('UPDATE movements SET cari_id=?, updated_at=? WHERE id=?')
                        ->execute([$cariId, now(), $movementId]);
                    sync_movement_account_transaction($movementId);
                }
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        log_action('Faturaya cari seçildi', '#' . $invoiceId . ' → ' . $cari['name']);
        audit_action('fatura', $invoiceId, 'cari_secildi', ['cari_id'=>$invoice['cari_id'] ?? null], ['cari_id'=>$cariId], $cari['name']);

        echo json_encode([
            'ok'=>true,
            'invoice_id'=>$invoiceId,
            'cari'=>[
                'id'=>(int)$cari['id'],
                'name'=>(string)$cari['name'],
                'cari_type'=>(string)$cari['cari_type'],
            ],
            'csrf_token'=>csrf_token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cariler = db()->query('SELECT id, name, cari_type FROM cariler ORDER BY name ASC')->fetchAll();
    echo json_encode([
        'ok'=>true,
        'cariler'=>$cariler,
        'can_write'=>can_write(),
        'csrf_token'=>csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
