<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/depo-cikis-lib.php';
require_login();

if (!can_access_warehouse_dispatch()) {
    http_response_code(403);
    exit('Yetkiniz yok.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Geçersiz istek.');
}

require_csrf();
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Silinecek depo çıkış fişi bulunamadı.');
    redirect('depo-cikis.php');
}

$row = depo_cikis_load($id);
if (!$row) {
    flash('error', 'Depo çıkış fişi bulunamadı.');
    redirect('depo-cikis.php');
}
if (!depo_cikis_can_edit($row)) {
    flash('error', 'Bu fişi silme yetkiniz yok.');
    redirect('depo-cikis.php');
}

// Muhasebe hareketine dönüşmüş bir fişi buradan silmeyiz. Böylece cari geçmişi bozulmaz.
if ((int)($row['posted_to_cari'] ?? 0) === 1 || (int)($row['cari_movement_id'] ?? 0) > 0) {
    flash('error', 'Bu fiş cariye işlendiği için silinemez. Önce cari bağlantısının güvenli şekilde geri alınması gerekir.');
    redirect('depo-cikis.php?edit=' . $id);
}

$reason = trim((string)($_POST['cancel_reason'] ?? 'Hatalı depo çıkış fişi iptal edildi'));
$reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
if ($reasonLength < 6) {
    flash('error', 'İptal nedeni en az 6 karakter olmalıdır.');
    redirect('depo-cikis.php?edit=' . $id);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE warehouse_dispatches SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0')
        ->execute([now(), current_user()['id'] ?? null, $reason, now(), $id]);
    audit_action('depo_cikis', $id, 'iptal', $row, [
        'is_cancelled'=>1,
        'cancel_reason'=>$reason,
    ], trim((string)($row['dispatch_no'] ?? ('#' . $id))));
    $pdo->commit();
    log_action('Depo çıkış fişi iptal edildi', trim((string)($row['dispatch_no'] ?? ('#' . $id))) . ' - ' . trim((string)($row['customer_name'] ?? '')));
    flash('success', 'Depo çıkış fişi silinmedi; iptal edilerek geçmişte korundu.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', 'Fiş iptal edilemedi: ' . $e->getMessage());
}

redirect('depo-cikis.php');
