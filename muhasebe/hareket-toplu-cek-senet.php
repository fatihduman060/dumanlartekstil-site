<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_write();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') redirect('hareketler.php');
require_csrf();

$instrument = (string)($_POST['instrument'] ?? 'cek');
if (!in_array($instrument, ['cek','senet'], true)) $instrument = 'cek';
$movementType = (string)($_POST['movement_type'] ?? 'tahsilat');
if (!in_array($movementType, ['tahsilat','odeme'], true)) {
    flash('error', 'Toplu çek/senet girişi yalnızca Tahsilat veya Ödeme olarak yapılabilir.');
    redirect('hareketler.php');
}

$cariId = (int)($_POST['cari_id'] ?? 0);
$movementDate = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
$description = trim((string)($_POST['description'] ?? ''));
$currency = strtoupper(trim((string)($_POST['currency'] ?? 'TL')));
if ($currency !== 'TL') {
    flash('error', 'Toplu çek/senet girişi şimdilik yalnızca TL ile yapılabilir.');
    redirect('hareketler.php');
}
if ($cariId <= 0) {
    flash('error', 'Toplu çek/senet girişi için cari seçmelisin.');
    redirect('hareketler.php');
}

$numbers = $_POST['document_no'] ?? [];
$amounts = $_POST['item_amount'] ?? [];
$dues = $_POST['item_due_date'] ?? [];
if (!is_array($numbers) || !is_array($amounts) || !is_array($dues)) {
    flash('error', 'Toplu giriş satırları okunamadı.');
    redirect('hareketler.php');
}

$count = min(24, max(count($numbers), count($amounts), count($dues)));
if ($count < 1) {
    flash('error', 'En az bir çek veya senet satırı girmelisin.');
    redirect('hareketler.php');
}

$label = $instrument === 'senet' ? 'Senet' : 'Çek';
$docType = $instrument === 'senet' ? 'senet_gorseli' : 'cek_gorseli';
$method = $instrument === 'senet' ? 'SENET' : 'ÇEK';
$direction = $movementType === 'odeme' ? 'verilecek' : 'alinacak';
$categoryName = $instrument === 'senet' ? 'Senet' : 'Çek';
$pdo = db();

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT OR IGNORE INTO categories (name, type, created_at) VALUES (?, ?, ?)')
        ->execute([$categoryName, 'genel', now()]);
    $catStmt = $pdo->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
    $catStmt->execute([$categoryName]);
    $categoryId = (int)($catStmt->fetchColumn() ?: 0);

    $movementStmt = $pdo->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, check_id, created_by, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?)');
    $checkStmt = $pdo->prepare('INSERT INTO checks (cari_id, movement_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NULL, ?, NULL, NULL, NULL, ?, ?, ?)');
    $linkStmt = $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?');

    $created = 0;
    $total = 0.0;
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $amount = decimal_from_input($amounts[$i] ?? '0');
        $due = trim((string)($dues[$i] ?? ''));
        $no = trim((string)($numbers[$i] ?? ''));
        if ($amount <= 0 && $due === '' && $no === '') continue;
        if ($amount <= 0 || $due === '') {
            throw new RuntimeException(($i + 1) . '. satırda tutar ve vade tarihi zorunludur.');
        }

        $lineDesc = $label . ($no !== '' ? ' no: ' . $no : '') . ($description !== '' ? ' / ' . $description : '');
        $now = now();
        $userId = current_user()['id'] ?? null;
        $movementStmt->execute([$cariId, $categoryId ?: null, $movementType, $amount, 'TL', $movementDate, $due, $method, $lineDesc, $docType, $userId, $now, $now]);
        $movementId = (int)$pdo->lastInsertId();

        $checkStmt->execute([$cariId, $movementId, $direction, 'bekliyor', $amount, $movementDate, $due, $instrument === 'senet' ? 'Senet' : null, $no !== '' ? $no : null, $lineDesc, $userId, $now, $now]);
        $checkId = (int)$pdo->lastInsertId();
        $linkStmt->execute([$checkId, $now, $movementId]);

        sync_movement_account_transaction($movementId);
        sync_check_account_transaction($checkId);
        $created++;
        $total += $amount;
        $ids[] = $movementId;
    }

    if ($created < 1) throw new RuntimeException('Kaydedilecek dolu satır bulunamadı.');
    $pdo->commit();

    log_action('Toplu ' . strtolower($label) . ' girişi', $created . ' adet · ' . money($total));
    audit_action('hareket', null, 'toplu_eklendi', null, ['instrument'=>$instrument,'count'=>$created,'total'=>$total,'movement_ids'=>$ids,'cari_id'=>$cariId], $label);
    flash('success', $created . ' adet ' . strtolower($label) . ' tek seferde kaydedildi. Toplam: ' . money($total));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}

redirect('hareketler.php?cari_id=' . $cariId);
