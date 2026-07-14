<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function toplu_yon_ozeti(?string $batch = null): array
{
    $tableExists = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='invoices'")->fetchColumn() > 0;
    if (!$tableExists) {
        return ['batch'=>'','count'=>0,'incoming'=>0,'outgoing'=>0,'posted'=>0];
    }

    if (!$batch) {
        $stmt = db()->query("SELECT import_batch FROM invoices
            WHERE COALESCE(is_cancelled,0)=0 AND COALESCE(import_batch,'')!=''
            ORDER BY id DESC LIMIT 1");
        $batch = (string)($stmt->fetchColumn() ?: '');
    }

    if ($batch === '') {
        return ['batch'=>'','count'=>0,'incoming'=>0,'outgoing'=>0,'posted'=>0];
    }

    $stmt = db()->prepare("SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(CASE WHEN direction='gelen' THEN 1 ELSE 0 END),0) AS incoming_count,
        COALESCE(SUM(CASE WHEN direction='giden' THEN 1 ELSE 0 END),0) AS outgoing_count,
        COALESCE(SUM(CASE WHEN cari_movement_id IS NOT NULL AND posted_to_cari=1 THEN 1 ELSE 0 END),0) AS posted_count,
        MIN(invoice_date) AS first_date,
        MAX(invoice_date) AS last_date
        FROM invoices
        WHERE COALESCE(is_cancelled,0)=0 AND import_batch=?");
    $stmt->execute([$batch]);
    $row = $stmt->fetch() ?: [];

    return [
        'batch'=>$batch,
        'count'=>(int)($row['total_count'] ?? 0),
        'incoming'=>(int)($row['incoming_count'] ?? 0),
        'outgoing'=>(int)($row['outgoing_count'] ?? 0),
        'posted'=>(int)($row['posted_count'] ?? 0),
        'first_date'=>(string)($row['first_date'] ?? ''),
        'last_date'=>(string)($row['last_date'] ?? ''),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $batch = trim((string)($_POST['batch'] ?? ''));
        $direction = trim((string)($_POST['direction'] ?? ''));
        if ($batch === '' || !in_array($direction, ['gelen','giden'], true)) {
            throw new RuntimeException('Toplu yükleme veya fatura yönü geçersiz.');
        }

        $stmt = db()->prepare("SELECT i.*, m.id AS movement_exists, COALESCE(m.is_cancelled,0) AS movement_cancelled
            FROM invoices i
            LEFT JOIN movements m ON m.id=i.cari_movement_id
            WHERE COALESCE(i.is_cancelled,0)=0 AND i.import_batch=?");
        $stmt->execute([$batch]);
        $rows = $stmt->fetchAll();
        if (!$rows) throw new RuntimeException('Düzeltilecek toplu fatura bulunamadı.');

        $newMovementType = $direction === 'giden' ? 'alacak' : 'verecek';
        $prefix = $direction === 'giden' ? 'Giden fatura' : 'Gelen fatura';
        $purpose = $direction === 'giden' ? 'Ürün/hizmet satışı' : 'Mal/hizmet alımı';

        db()->beginTransaction();
        try {
            db()->prepare('UPDATE invoices SET direction=?, updated_at=? WHERE import_batch=? AND COALESCE(is_cancelled,0)=0')
                ->execute([$direction, now(), $batch]);

            foreach ($rows as $row) {
                $movementId = (int)($row['cari_movement_id'] ?? 0);
                if ($movementId <= 0 || empty($row['movement_exists']) || (int)$row['movement_cancelled'] === 1) continue;

                $no = trim((string)($row['invoice_no'] ?? ''));
                $description = $prefix . ($no !== '' ? ' no: ' . $no : ' #' . (int)$row['id']) . ' / ' . $purpose;
                db()->prepare('UPDATE movements SET movement_type=?, description=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0')
                    ->execute([$newMovementType, $description, now(), $movementId]);
                sync_movement_account_transaction($movementId);
            }

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        $detail = $batch . ' · ' . count($rows) . ' fatura · ' . ($direction === 'giden' ? 'Giden' : 'Gelen');
        log_action('Toplu fatura yönü düzeltildi', $detail);
        audit_action('fatura', null, 'toplu_yon_duzeltildi', null, ['batch'=>$batch,'direction'=>$direction,'count'=>count($rows)], $detail);
    }

    $summary = toplu_yon_ozeti(trim((string)($_REQUEST['batch'] ?? '')) ?: null);
    $summary['ok'] = true;
    $summary['can_write'] = can_write();
    $summary['csrf_token'] = csrf_token();
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
