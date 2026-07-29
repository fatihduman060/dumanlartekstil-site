<?php
require_once __DIR__ . '/hareket-satis-db.php';
require_login();
require_write();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('hareketler.php');
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$cariId = (int)($_POST['cari_id'] ?? 0);
$categoryId = (int)($_POST['category_id'] ?? 0);
$movementType = trim((string)($_POST['movement_type'] ?? ''));
$currency = strtoupper(trim((string)($_POST['currency'] ?? 'TL')));
$movementDate = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
$dueDate = trim((string)($_POST['due_date'] ?? '')) ?: null;
$paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$documentType = trim((string)($_POST['document_type'] ?? '')) ?: null;
$back = 'hareketler.php' . ($cariId > 0 ? '?cari_id=' . $cariId : '');

try {
    if ($movementType !== 'alacak') throw new RuntimeException('Detaylı satış yalnızca Alacak hareketi olarak kaydedilebilir.');
    if ($cariId <= 0) throw new RuntimeException('Detaylı satış için cari seçmelisin.');
    if (!hareket_satis_category_is_sales($categoryId)) throw new RuntimeException('Detaylı satış için kategori Satış olmalı.');
    if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';
    if ($movementDate === '') $movementDate = date('Y-m-d');

    $sale = hareket_satis_parse_payload($_POST['sale_detail_json'] ?? '');
    $amount = (float)$sale['grand_total'];
    if ($description === '') $description = 'Detaylı satış · ' . (int)$sale['item_count'] . ' kalem';

    hareket_satis_db_ensure();
    $pdo = db();
    $oldMovement = null;
    $oldDoc = null;

    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $oldMovement = $stmt->fetch() ?: null;
        if (!$oldMovement) throw new RuntimeException('Düzenlenecek hareket bulunamadı.');
        if ((int)($oldMovement['is_cancelled'] ?? 0) === 1) throw new RuntimeException('İptal edilmiş hareket düzenlenemez.');
        if (!empty($oldMovement['check_id'])) throw new RuntimeException('Çeke bağlı hareket detaylı satışa dönüştürülemez.');
        $oldDoc = [
            'path' => $oldMovement['document_path'] ?? null,
            'name' => $oldMovement['document_name'] ?? null,
            'mime' => $oldMovement['document_mime'] ?? null,
        ];
    }

    $doc = handle_upload('document', $oldDoc);
    $now = now();
    $userId = current_user()['id'] ?? null;

    $pdo->beginTransaction();
    if ($id > 0) {
        $pdo->prepare('UPDATE movements SET cari_id=?, category_id=?, account_id=NULL, movement_type=?, amount=?, currency=?, movement_date=?, due_date=?, payment_method=?, description=?, document_type=?, document_path=?, document_name=?, document_mime=?, updated_at=? WHERE id=?')
            ->execute([$cariId, $categoryId, 'alacak', $amount, $currency, $movementDate, $dueDate, $paymentMethod, $description, $documentType, $doc['path'], $doc['name'], $doc['mime'], $now, $id]);
        $movementId = $id;
    } else {
        $pdo->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cariId, $categoryId, 'alacak', $amount, $currency, $movementDate, $dueDate, $paymentMethod, $description, $documentType, $doc['path'], $doc['name'], $doc['mime'], $userId, $now, $now]);
        $movementId = (int)$pdo->lastInsertId();
    }

    hareket_satis_save($movementId, $sale);
    $pdo->commit();

    if ($id > 0) delete_replaced_upload($oldDoc, $doc);
    sync_movement_account_transaction($movementId);

    $auditValue = [
        'cari_id' => $cariId,
        'category_id' => $categoryId,
        'type' => 'alacak',
        'amount' => $amount,
        'currency' => $currency,
        'date' => $movementDate,
        'item_count' => $sale['item_count'],
        'subtotal' => $sale['subtotal'],
        'discount_rate' => $sale['discount_rate'],
        'vat_rate' => $sale['vat_rate'],
        'grand_total' => $sale['grand_total'],
    ];
    audit_action('hareket', $movementId, $id > 0 ? 'guncellendi' : 'eklendi', $oldMovement, $auditValue, 'Detaylı satış');
    log_action($id > 0 ? 'Detaylı satış güncellendi' : 'Detaylı satış eklendi', '#' . $movementId . ' · ' . $sale['item_count'] . ' kalem · ' . number_format($amount, 2, ',', '.') . ' ' . $currency);
    flash('success', 'Detaylı satış kaydedildi. ' . $sale['item_count'] . ' kalem · Genel toplam: ' . number_format($amount, 2, ',', '.') . ' ' . $currency);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}

redirect($back);
