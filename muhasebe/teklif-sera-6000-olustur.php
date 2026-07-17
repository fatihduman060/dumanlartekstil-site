<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/teklif-otomatik-sera-6000.php';

require_login();
require_write();

try {
    $result = teklif_seratekstil_requested_offer();
    $offerId = (int)($result['offer_id'] ?? 0);
    if ($offerId <= 0) {
        throw new RuntimeException((string)($result['error'] ?? 'Teklif oluşturulamadı.'));
    }

    if (!empty($result['created'])) {
        flash('success', 'Sera Tekstil teklifi oluşturuldu ve açıldı.');
    } else {
        flash('success', 'Sera Tekstil teklifi bulundu ve açıldı.');
    }
    redirect('teklif-ver.php?edit=' . $offerId . '&requested_offer=sera6000');
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect('teklif-ver.php');
}
