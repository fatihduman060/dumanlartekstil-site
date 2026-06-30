<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$movementId = (int)($_GET['movement_id'] ?? 0);
$cariId = (int)($_GET['cari_id'] ?? 0);
$back = $cariId > 0 ? 'cari-detay.php?id=' . $cariId . '#hareketler' : 'hareketler.php';

if ($movementId <= 0) {
    flash('error', 'İncelenecek hareket bulunamadı.');
    redirect($back);
}

$stmt = db()->prepare('SELECT * FROM movements WHERE id=?');
$stmt->execute([$movementId]);
$movement = $stmt->fetch();
if (!$movement) {
    flash('error', 'Hareket kaydı bulunamadı.');
    redirect($back);
}

try {
    $stmt = db()->prepare('SELECT id FROM offers WHERE cari_movement_id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
    $stmt->execute([$movementId]);
    $offerId = (int)($stmt->fetchColumn() ?: 0);
    if ($offerId > 0) {
        redirect('teklif-ver.php?edit=' . $offerId);
    }
} catch (Throwable $e) {}

try {
    $stmt = db()->prepare('SELECT id FROM collection_receipts WHERE cari_movement_id=? AND COALESCE(is_deleted,0)=0 LIMIT 1');
    $stmt->execute([$movementId]);
    $receiptId = (int)($stmt->fetchColumn() ?: 0);
    if ($receiptId > 0) {
        redirect('tahsilat-makbuzu.php?edit=' . $receiptId);
    }
} catch (Throwable $e) {}

try {
    $checkId = (int)($movement['check_id'] ?? 0);
    if ($checkId <= 0) {
        $stmt = db()->prepare('SELECT id FROM checks WHERE movement_id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
        $stmt->execute([$movementId]);
        $checkId = (int)($stmt->fetchColumn() ?: 0);
    }
    if ($checkId > 0) {
        $stmt = db()->prepare('SELECT direction FROM checks WHERE id=? LIMIT 1');
        $stmt->execute([$checkId]);
        $direction = (string)($stmt->fetchColumn() ?: 'alinacak');
        redirect('cekler.php?direction=' . urlencode($direction) . '&edit=' . $checkId . '#cek-form');
    }
} catch (Throwable $e) {}

redirect('hareketler.php?edit=' . $movementId . ($cariId > 0 ? '&cari_id=' . $cariId : ''));
