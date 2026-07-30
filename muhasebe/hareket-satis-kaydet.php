<?php
require_once __DIR__ . '/hareket-satis-db.php';
require_login();
require_write();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('hareketler.php');
require_csrf();

function hareket_satis_ean13_check_digit(string $first12): string
{
    $digits = preg_replace('/\D+/', '', $first12) ?: '';
    if (strlen($digits) !== 12) return '';
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$digits[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (string)((10 - ($sum % 10)) % 10);
}

function hareket_satis_article_from_text(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (preg_match('/86992348\s*([0-9]{2})[\s\-\/.]*([0-9]{2})\s*[0-9]/u', $text, $m)) return $m[1] . $m[2];
    if (preg_match('/(?:^|[^0-9])([0-9]{2})\s*[\-\/.]\s*([0-9]{2})(?:[^0-9]|$)/u', $text, $m)) return $m[1] . $m[2];
    if (preg_match('/^\s*([0-9]{4})(?:[^0-9]|$)/u', $text, $m)) return $m[1];
    return '';
}

function hareket_satis_auto_barcode(string $barcode, string $productName): string
{
    $prefix = '86992348';
    $raw = trim($barcode);
    $digits = preg_replace('/\D+/', '', $raw) ?: '';

    if (strlen($digits) === 13) return $digits;
    if (strlen($digits) === 12 && strpos($digits, $prefix) === 0) {
        return $digits . hareket_satis_ean13_check_digit($digits);
    }
    if (strlen($digits) === 4) {
        $first12 = $prefix . $digits;
        return $first12 . hareket_satis_ean13_check_digit($first12);
    }

    $article = hareket_satis_article_from_text($raw);
    if ($article === '') $article = hareket_satis_article_from_text($productName);
    if ($article !== '') {
        $first12 = $prefix . $article;
        return $first12 . hareket_satis_ean13_check_digit($first12);
    }
    return $raw;
}

function hareket_satis_normalize_payload_barcodes($raw)
{
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) return $raw;
    if (!isset($decoded['items']) || !is_array($decoded['items'])) return $decoded;

    foreach ($decoded['items'] as &$item) {
        if (!is_array($item)) continue;
        $item['barcode'] = hareket_satis_auto_barcode(
            (string)($item['barcode'] ?? ''),
            (string)($item['name'] ?? '')
        );
    }
    unset($item);
    return $decoded;
}

function hareket_satis_persist_product_barcodes(PDO $pdo, array $sale, string $now): void
{
    teklif_db_ensure();
    $select = $pdo->prepare('SELECT id, barcode FROM offer_products WHERE name=? LIMIT 1');
    $update = $pdo->prepare("UPDATE offer_products SET barcode=?, updated_at=? WHERE id=? AND TRIM(COALESCE(barcode,''))=''");
    $insert = $pdo->prepare('INSERT INTO offer_products (barcode, name, product_type, default_unit_price, is_active, created_at, updated_at) VALUES (?, ?, NULL, ?, 1, ?, ?)');

    foreach ($sale['items'] as $item) {
        $name = trim((string)($item['name'] ?? ''));
        $barcode = trim((string)($item['barcode'] ?? ''));
        if ($name === '' || $barcode === '') continue;

        $select->execute([$name]);
        $existing = $select->fetch();
        if ($existing) {
            if (trim((string)($existing['barcode'] ?? '')) === '') {
                $update->execute([$barcode, $now, (int)$existing['id']]);
            }
            continue;
        }

        try {
            $insert->execute([$barcode, $name, (float)($item['unit_price'] ?? 0), $now, $now]);
        } catch (Throwable $e) {
            // Aynı ürün eş zamanlı eklendiyse yalnızca boş barkodunu tamamla.
            $select->execute([$name]);
            $existing = $select->fetch();
            if ($existing && trim((string)($existing['barcode'] ?? '')) === '') {
                $update->execute([$barcode, $now, (int)$existing['id']]);
            }
        }
    }
}

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

    $normalizedPayload = hareket_satis_normalize_payload_barcodes($_POST['sale_detail_json'] ?? '');
    $sale = hareket_satis_parse_payload($normalizedPayload);
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
    hareket_satis_persist_product_barcodes($pdo, $sale, $now);
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