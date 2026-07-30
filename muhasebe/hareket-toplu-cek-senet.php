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

$persons = $_POST['item_person'] ?? [];
$banks = $_POST['item_bank'] ?? [];
$checkNos = $_POST['item_check_no'] ?? [];
$amounts = $_POST['item_amount'] ?? [];
$cities = $_POST['item_city'] ?? [];
$dues = $_POST['item_due_date'] ?? [];
if (!is_array($persons) || !is_array($banks) || !is_array($checkNos) || !is_array($amounts) || !is_array($cities) || !is_array($dues)) {
    flash('error', 'Toplu giriş satırları okunamadı.');
    redirect('hareketler.php');
}

$count = min(24, max(count($persons), count($banks), count($checkNos), count($amounts), count($cities), count($dues)));
if ($count < 1) {
    flash('error', 'En az bir çek veya senet satırı girmelisin.');
    redirect('hareketler.php');
}

$label = $instrument === 'senet' ? 'Senet' : 'Çek';
$docType = $instrument === 'senet' ? 'senet_gorseli' : 'cek_gorseli';
$method = $instrument === 'senet' ? 'SENET' : 'ÇEK';
$direction = $movementType === 'odeme' ? 'verilecek' : 'alinacak';
$categoryName = $instrument === 'senet' ? 'Senet' : 'Çek';
$defaultDrawer = 'Dumanlar Konfeksiyon Beyaz Eşya Ticaret Sanayi A.Ş.';
$pdo = db();

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT OR IGNORE INTO categories (name, type, created_at) VALUES (?, ?, ?)')
        ->execute([$categoryName, 'genel', now()]);
    $catStmt = $pdo->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
    $catStmt->execute([$categoryName]);
    $categoryId = (int)($catStmt->fetchColumn() ?: 0);

    $movementStmt = $pdo->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, check_id, created_by, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?)');
    $checkStmt = $pdo->prepare('INSERT INTO checks (cari_id, movement_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)');
    $linkStmt = $pdo->prepare('UPDATE movements SET check_id=?, updated_at=? WHERE id=?');

    $created = 0;
    $total = 0.0;
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $person = trim((string)($persons[$i] ?? ''));
        $bank = trim((string)($banks[$i] ?? ''));
        $checkNo = trim((string)($checkNos[$i] ?? ''));
        $amount = decimal_from_input($amounts[$i] ?? '0');
        $city = trim((string)($cities[$i] ?? ''));
        $due = trim((string)($dues[$i] ?? ''));

        if ($instrument === 'cek' && $movementType === 'odeme' && $person === '') {
            $person = $defaultDrawer;
        }

        $hasAnyValue = $person !== '' || $bank !== '' || $checkNo !== '' || $amount > 0 || $city !== '' || $due !== '';
        if (!$hasAnyValue) continue;

        if ($person === '' || $amount <= 0 || $city === '' || $due === '') {
            throw new RuntimeException(($i + 1) . '. satırda kişi/keşideci adı, tutar, il ve vade tarihi zorunludur.');
        }
        if ($instrument === 'cek' && ($bank === '' || $checkNo === '')) {
            throw new RuntimeException(($i + 1) . '. satırda banka bilgisi ve çek numarası zorunludur.');
        }

        $lineParts = [$label, 'Kişi: ' . $person];
        if ($instrument === 'cek') {
            $lineParts[] = 'Banka: ' . $bank;
            $lineParts[] = 'Çek No: ' . $checkNo;
        }
        $lineParts[] = 'İl: ' . $city;
        if ($description !== '') $lineParts[] = $description;
        $lineDesc = implode(' / ', $lineParts);
        $now = now();
        $userId = current_user()['id'] ?? null;
        $bankName = $instrument === 'senet' ? 'Senet' : $bank;
        $storedCheckNo = $instrument === 'senet' ? null : $checkNo;

        $movementStmt->execute([$cariId, $categoryId ?: null, $movementType, $amount, 'TL', $movementDate, $due, $method, $lineDesc, $docType, $userId, $now, $now]);
        $movementId = (int)$pdo->lastInsertId();

        $checkStmt->execute([$cariId, $movementId, $direction, 'bekliyor', $amount, $movementDate, $due, $bankName, $city, $storedCheckNo, $person, $lineDesc, $userId, $now, $now]);
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
