<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_write();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('dashboard.php');
require_csrf();

function ci_add_cols(string $table): void
{
    $pdo = db();
    ensure_column($pdo, $table, 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, $table, 'cari_movement_id', 'INTEGER');
    ensure_column($pdo, $table, 'posted_at', 'TEXT');
    ensure_column($pdo, $table, 'posted_by', 'INTEGER');
    if ($table === 'collection_receipts') {
        ensure_column($pdo, $table, 'check_record_id', 'INTEGER');
        ensure_column($pdo, $table, 'check_document_path', 'TEXT');
        ensure_column($pdo, $table, 'check_document_name', 'TEXT');
        ensure_column($pdo, $table, 'check_document_mime', 'TEXT');
    }
}

function ci_category(?string $name): ?int
{
    if (!$name) return null;
    try {
        $s = db()->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $s->execute([$name]);
        $id = (int)($s->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) { return null; }
}

function ci_active_movement(int $id): bool
{
    if ($id <= 0) return false;
    $s = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

function ci_active_check(int $id): bool
{
    if ($id <= 0) return false;
    $s = db()->prepare('SELECT id FROM checks WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

function ci_is_check_receipt(array $receipt): bool
{
    return in_array((string)($receipt['payment_type'] ?? ''), ['cek','senet'], true);
}

function ci_find_account_id_for_receipt(array $receipt): ?int
{
    $bankName = trim((string)($receipt['bank_name'] ?? ''));
    if ($bankName === '') return null;
    $stmt = db()->prepare("SELECT id FROM accounts WHERE COALESCE(is_active,1)=1 AND account_type='banka' AND (bank_name=? OR name=?) ORDER BY CASE WHEN bank_name=? THEN 0 ELSE 1 END, id ASC LIMIT 1");
    $stmt->execute([$bankName, $bankName, $bankName]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
}

function ci_check_description_from_receipt(array $receipt): string
{
    $type = (string)($receipt['payment_type'] ?? 'cek');
    $label = $type === 'senet' ? 'Senet' : 'Çek';
    $parts = [$label, 'Tahsilat makbuzu no: ' . trim((string)($receipt['receipt_no'] ?? ''))];
    if (trim((string)($receipt['description'] ?? '')) !== '') $parts[] = trim((string)$receipt['description']);
    return implode(' / ', array_filter($parts));
}

function ci_create_or_update_receipt_check(array $receipt, int $movementId): ?int
{
    if (!ci_is_check_receipt($receipt)) return null;
    $paymentType = (string)($receipt['payment_type'] ?? 'cek');
    $isSenet = $paymentType === 'senet';
    $due = trim((string)($receipt['due_date'] ?? ''));
    if ($due === '') throw new RuntimeException(($isSenet ? 'Senet' : 'Çek') . ' için vade tarihi girilmeli.');

    $existingId = (int)($receipt['check_record_id'] ?? 0);
    if ($existingId > 0 && !ci_active_check($existingId)) $existingId = 0;

    $docPath = $receipt['check_document_path'] ?? null;
    $docName = $receipt['check_document_name'] ?? null;
    $docMime = $receipt['check_document_mime'] ?? null;
    $bankName = $isSenet ? 'Senet' : trim((string)($receipt['bank_name'] ?? ''));
    $checkNo = trim((string)($receipt['document_no'] ?? ''));
    $drawer = trim((string)($receipt['debtor_name'] ?? '')) ?: trim((string)($receipt['customer_name'] ?? ''));
    $description = ci_check_description_from_receipt($receipt);
    $now = now();

    if ($existingId > 0) {
        db()->prepare('UPDATE checks SET cari_id=?, movement_id=?, direction=?, status=?, amount=?, issue_date=?, due_date=?, bank_name=?, check_no=?, drawer=?, description=?, document_path=COALESCE(?, document_path), document_name=COALESCE(?, document_name), document_mime=COALESCE(?, document_mime), updated_at=? WHERE id=?')
            ->execute([(int)$receipt['cari_id'] ?: null, $movementId, 'alinacak', 'bekliyor', (float)$receipt['amount'], $receipt['receipt_date'] ?: date('Y-m-d'), $due, $bankName, $checkNo, $drawer, $description, $docPath, $docName, $docMime, $now, $existingId]);
        $checkId = $existingId;
    } else {
        db()->prepare('INSERT INTO checks (cari_id, movement_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int)$receipt['cari_id'] ?: null, $movementId, 'alinacak', 'bekliyor', (float)$receipt['amount'], $receipt['receipt_date'] ?: date('Y-m-d'), $due, $bankName, $checkNo, $drawer, $description, $docPath, $docName, $docMime, current_user()['id'] ?? null, $now, $now]);
        $checkId = (int)db()->lastInsertId();
    }

    db()->prepare('UPDATE movements SET check_id=?, account_id=NULL, due_date=?, payment_method=?, document_type=?, document_path=COALESCE(?, document_path), document_name=COALESCE(?, document_name), document_mime=COALESCE(?, document_mime), updated_at=? WHERE id=?')
        ->execute([$checkId, $due, $isSenet ? 'Senet' : 'ÇEK', 'cek_gorseli', $docPath, $docName, $docMime, $now, $movementId]);
    db()->prepare('UPDATE collection_receipts SET check_record_id=?, updated_at=? WHERE id=?')->execute([$checkId, $now, (int)$receipt['id']]);
    sync_movement_account_transaction($movementId);
    return $checkId;
}

function ci_sync_receipt_existing_movement(array $receipt): ?int
{
    $movementId = (int)($receipt['cari_movement_id'] ?? 0);
    if (!ci_active_movement($movementId)) return null;

    if (ci_is_check_receipt($receipt)) {
        ci_create_or_update_receipt_check($receipt, $movementId);
        return $movementId;
    }

    $accountId = ci_find_account_id_for_receipt($receipt);
    if (!$accountId) return $movementId;
    $stmt = db()->prepare('SELECT account_id FROM movements WHERE id=?');
    $stmt->execute([$movementId]);
    $currentAccountId = (int)($stmt->fetchColumn() ?: 0);
    if ($currentAccountId !== $accountId) {
        db()->prepare('UPDATE movements SET account_id=?, updated_at=? WHERE id=?')->execute([$accountId, now(), $movementId]);
    }
    sync_movement_account_transaction($movementId);
    return $movementId;
}

function ci_make_movement(int $cariId, string $type, float $amount, string $date, string $method, string $desc, ?int $categoryId, ?string $docType, ?int $accountId = null): int
{
    $s = db()->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)');
    $now = now();
    $s->execute([$cariId, $categoryId, $accountId, $type, $amount, $date, $method, $desc, $docType, current_user()['id'] ?? null, $now, $now]);
    $movementId = (int)db()->lastInsertId();
    sync_movement_account_transaction($movementId);
    return $movementId;
}

function ci_post_offer(int $id): int
{
    ci_add_cols('offers');
    $s = db()->prepare('SELECT * FROM offers WHERE id=? AND COALESCE(is_deleted,0)=0');
    $s->execute([$id]);
    $o = $s->fetch();
    if (!$o) throw new RuntimeException('Sipariş fişi bulunamadı.');
    if (ci_active_movement((int)($o['cari_movement_id'] ?? 0))) throw new RuntimeException('Bu sipariş fişi zaten cariye işlenmiş.');
    $cariId = (int)($o['cari_id'] ?? 0);
    if ($cariId <= 0) throw new RuntimeException('Cariye işlemek için sipariş fişinde cari seçilmiş olmalı.');
    $amount = (float)($o['grand_total'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Cariye işlenecek tutar bulunamadı.');
    if ((string)($o['currency'] ?? 'TL') !== 'TL') throw new RuntimeException('Cari hareketler TL çalışıyor. Cariye işlemek için para birimi TL olmalı.');
    $no = trim((string)($o['offer_no'] ?? ''));
    $title = trim((string)($o['document_title'] ?? 'SİPARİŞ FİŞİ')) ?: 'SİPARİŞ FİŞİ';
    $mid = ci_make_movement($cariId, 'alacak', $amount, $o['offer_date'] ?: date('Y-m-d'), 'Sipariş fişi', $title . ' no: ' . $no . ' / Ürün satışı', ci_category('Satış'), 'siparis_fisi');
    db()->prepare('UPDATE offers SET posted_to_cari=1, cari_movement_id=?, posted_at=?, posted_by=?, updated_at=? WHERE id=?')->execute([$mid, now(), current_user()['id'] ?? null, now(), $id]);
    log_action('Sipariş fişi cariye işlendi', $no . ' - ' . money($amount));
    audit_action('teklif', $id, 'cariye_islendi', $o, ['movement_id'=>$mid,'amount'=>$amount], $no);
    return $mid;
}

function ci_receipt_label(string $type): string
{
    return ['nakit'=>'Nakit','cek'=>'Çek','senet'=>'Senet','havale_eft'=>'Havale / EFT','kredi_karti'=>'Kredi Kartı'][$type] ?? $type;
}

function ci_post_receipt(int $id): int
{
    ci_add_cols('collection_receipts');
    $s = db()->prepare('SELECT * FROM collection_receipts WHERE id=? AND COALESCE(is_deleted,0)=0');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) throw new RuntimeException('Tahsilat makbuzu bulunamadı.');
    if (ci_active_movement((int)($r['cari_movement_id'] ?? 0))) {
        $mid = ci_sync_receipt_existing_movement($r);
        if ($mid) return $mid;
        throw new RuntimeException('Bu tahsilat makbuzu zaten cariye işlenmiş.');
    }
    $cariId = (int)($r['cari_id'] ?? 0);
    if ($cariId <= 0) throw new RuntimeException('Cariye işlemek için makbuzda cari seçilmiş olmalı.');
    $amount = (float)($r['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Cariye işlenecek tahsilat tutarı bulunamadı.');
    if ((string)($r['currency'] ?? 'TL') !== 'TL') throw new RuntimeException('Cari hareketler TL çalışıyor. Cariye işlemek için para birimi TL olmalı.');
    $no = trim((string)($r['receipt_no'] ?? ''));
    $paymentType = (string)($r['payment_type'] ?? 'nakit');
    $label = ci_receipt_label($paymentType);
    $desc = 'Tahsilat makbuzu no: ' . $no . ' / ' . $label . ' tahsilat';
    if (trim((string)($r['description'] ?? '')) !== '') $desc .= ' - ' . trim((string)$r['description']);

    $accountId = null;
    if (!ci_is_check_receipt($r)) {
        $accountId = ci_find_account_id_for_receipt($r);
        if (in_array($paymentType, ['havale_eft','kredi_karti'], true) && !$accountId) {
            throw new RuntimeException('Banka bakiyesine işlemek için makbuzda Kasa/Banka bölümünden kayıtlı bir banka seçilmeli.');
        }
    }

    $mid = ci_make_movement($cariId, 'tahsilat', $amount, $r['receipt_date'] ?: date('Y-m-d'), $label, $desc, ci_category('Tahsilat'), 'tahsilat_makbuzu', $accountId);
    if (ci_is_check_receipt($r)) ci_create_or_update_receipt_check($r + ['id'=>$id], $mid);
    db()->prepare('UPDATE collection_receipts SET posted_to_cari=1, cari_movement_id=?, posted_at=?, posted_by=?, updated_at=? WHERE id=?')->execute([$mid, now(), current_user()['id'] ?? null, now(), $id]);
    log_action('Tahsilat makbuzu cariye işlendi', $no . ' - ' . money($amount));
    audit_action('tahsilat_makbuzu', $id, 'cariye_islendi', $r, ['movement_id'=>$mid,'amount'=>$amount,'account_id'=>$accountId,'payment_type'=>$paymentType], $no);
    return $mid;
}

$source = (string)($_POST['source_type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$back = safe_back_url($source === 'tahsilat' ? 'tahsilat-makbuzu.php' : 'teklif-ver.php');
try {
    if ($source === 'offer') {
        $mid = ci_post_offer($id);
        flash('success', 'Sipariş fişi cariye alacak olarak işlendi. Cari hareket no: #' . $mid);
    } elseif ($source === 'tahsilat') {
        $mid = ci_post_receipt($id);
        flash('success', 'Tahsilat makbuzu cariye işlendi. Çek/senet ise Çekler bölümüne de aktarıldı. Cari hareket no: #' . $mid);
    } else {
        throw new RuntimeException('Belge türü bulunamadı.');
    }
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}
redirect($back);
