<?php

function fatura_red_uygula(int $id): array
{
    db()->beginTransaction();
    try {
        satis_bag_lock();
        $invoice = fatura_getir($id);
        if (!$invoice || $invoice['direction'] !== 'giden' || (int)($invoice['is_cancelled'] ?? 0) !== 0) {
            throw new RuntimeException('Aktif giden fatura bulunamadı.');
        }

        $link = satis_bag_get('invoice', $id);
        $dispatchId = $link ? (int)$link['dispatch_id'] : 0;
        $movementId = (int)($invoice['cari_movement_id'] ?? 0);
        if ($link) {
            $dispatch = satis_bag_row('warehouse_dispatches', $dispatchId);
            satis_bag_validate($invoice, $dispatch, true);
            $movementId = satis_bag_movement($invoice, $dispatch);
        }
        if ($movementId > 0) {
            $sharedInvoice = db()->prepare('SELECT COUNT(*) FROM invoices WHERE id<>? AND cari_movement_id=? AND COALESCE(is_cancelled,0)=0');
            $sharedInvoice->execute([$id, $movementId]);
            $sharedDispatch = db()->prepare('SELECT COUNT(*) FROM warehouse_dispatches WHERE id<>? AND cari_movement_id=? AND COALESCE(is_cancelled,0)=0');
            $sharedDispatch->execute([$dispatchId, $movementId]);
            if ((int)$sharedInvoice->fetchColumn() > 0 || (int)$sharedDispatch->fetchColumn() > 0) {
                throw new RuntimeException('Cari hareketi başka aktif belgeyle ortak. Bu fatura otomatik sıfırlanamaz.');
            }
        }

        $userId = current_user()['id'] ?? null;
        $stamp = now();
        $reason = 'Alıcıdan RED yanıtı geldi';
        if ($link) {
            db()->prepare('DELETE FROM sale_document_links WHERE invoice_id=?')->execute([$id]);
            satis_bag_audit('red_sonrasi_bag_kaldirildi', $link, null, $reason . '; ortak cari hareketi kapatıldı.');
            db()->prepare('UPDATE warehouse_dispatches SET cari_movement_id=NULL, posted_to_cari=0, updated_at=? WHERE id=?')
                ->execute([$stamp, $dispatchId]);
        }
        db()->prepare('UPDATE invoices SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, rejected_at=?, rejected_by=?, posted_to_cari=0, updated_at=? WHERE id=?')
            ->execute([$stamp, $userId, $reason, $stamp, $userId, $stamp, $id]);

        if ($movementId > 0 && fatura_hareket_acik_mi($movementId)) {
            db()->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                ->execute([$stamp, $userId, 'Bağlı giden faturaya RED geldi', $stamp, $movementId]);
            sync_movement_account_transaction($movementId);
        }

        stok_fatura_senkronla($id, 'giden', (string)$invoice['invoice_date'], [], true);
        log_action('Giden faturaya RED geldi', ($invoice['invoice_no'] ?: '#' . $id) . ' · fatura #' . $id);
        audit_action('fatura', $id, 'red_geldi', $invoice, [
            'is_cancelled'=>1, 'rejected_at'=>$stamp, 'cancel_reason'=>$reason,
            'cari_movement_id'=>$movementId, 'dispatch_id'=>$dispatchId,
        ], $invoice['invoice_no'] ?: '#' . $id);
        db()->commit();
        return $invoice;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
