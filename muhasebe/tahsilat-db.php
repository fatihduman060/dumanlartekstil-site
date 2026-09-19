<?php
require_once __DIR__ . '/bootstrap.php';

function tahsilat_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS collection_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        receipt_no TEXT NOT NULL,
        receipt_date TEXT NOT NULL,
        cari_id INTEGER,
        customer_name TEXT NOT NULL,
        customer_city TEXT,
        customer_address TEXT,
        customer_tax_office TEXT,
        customer_tax_no TEXT,
        customer_phone TEXT,
        payment_type TEXT NOT NULL DEFAULT 'nakit',
        currency TEXT NOT NULL DEFAULT 'TL',
        amount REAL NOT NULL DEFAULT 0,
        amount_text TEXT,
        description TEXT,
        bank_name TEXT,
        document_no TEXT,
        due_date TEXT,
        debtor_name TEXT,
        collected_by TEXT,
        paid_by TEXT,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    ensure_column($pdo, 'collection_receipts', 'check_document_path', 'TEXT');
    ensure_column($pdo, 'collection_receipts', 'check_document_name', 'TEXT');
    ensure_column($pdo, 'collection_receipts', 'check_document_mime', 'TEXT');
    ensure_column($pdo, 'collection_receipts', 'check_record_id', 'INTEGER');
    ensure_column($pdo, 'collection_receipts', 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'collection_receipts', 'cari_movement_id', 'INTEGER');
    ensure_column($pdo, 'collection_receipts', 'posted_at', 'TEXT');
    ensure_column($pdo, 'collection_receipts', 'posted_by', 'INTEGER');
    ensure_column($pdo, 'collection_receipts', 'deleted_at', 'TEXT');
    ensure_column($pdo, 'collection_receipts', 'deleted_by', 'INTEGER');
    ensure_column($pdo, 'collection_receipts', 'delete_reason', 'TEXT');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collection_receipts_date ON collection_receipts(receipt_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collection_receipts_check_record ON collection_receipts(check_record_id)");
}

function tahsilat_decimal($value): float
{
    if (function_exists('decimal_from_input')) return decimal_from_input($value);
    $v = trim(str_replace(' ', '', (string)$value));
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

function tahsilat_money(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function tahsilat_tr_date(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

function tahsilat_payment_label(string $type): string
{
    return [
        'nakit' => 'Nakit',
        'cek' => 'Çek',
        'senet' => 'Senet',
        'havale_eft' => 'Havale / EFT',
        'kredi_karti' => 'Kredi Kartı',
    ][$type] ?? $type;
}

function tahsilat_next_no(): string
{
    tahsilat_db_ensure();
    $max = 0;
    try {
        $rows = db()->query('SELECT receipt_no FROM collection_receipts')->fetchAll();
        foreach ($rows as $row) {
            $digits = preg_replace('/\D+/', '', (string)($row['receipt_no'] ?? ''));
            if ($digits !== '') $max = max($max, (int)$digits);
        }
    } catch (Throwable $e) {}
    return 'TM-' . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function tahsilat_amount_text(float $amount, string $currency = 'TL'): string
{
    $ones = ['', 'BİR', 'İKİ', 'ÜÇ', 'DÖRT', 'BEŞ', 'ALTI', 'YEDİ', 'SEKİZ', 'DOKUZ'];
    $tens = ['', 'ON', 'YİRMİ', 'OTUZ', 'KIRK', 'ELLİ', 'ALTMIŞ', 'YETMİŞ', 'SEKSEN', 'DOKSAN'];
    $groups = ['', 'BİN', 'MİLYON', 'MİLYAR'];
    $n = (int)floor(abs($amount));
    if ($n === 0) return 'SIFIR ' . $currency;
    $parts = [];
    $g = 0;
    while ($n > 0) {
        $chunk = $n % 1000;
        if ($chunk > 0) {
            $h = intdiv($chunk, 100);
            $r = $chunk % 100;
            $txt = '';
            if ($h > 0) $txt .= ($h === 1 ? 'YÜZ' : $ones[$h] . ' YÜZ');
            $ten = intdiv($r, 10);
            $one = $r % 10;
            if ($ten > 0) $txt .= ($txt ? ' ' : '') . $tens[$ten];
            if ($one > 0) $txt .= ($txt ? ' ' : '') . $ones[$one];
            if ($g === 1 && $chunk === 1) $txt = 'BİN';
            elseif ($groups[$g] ?? '') $txt .= ' ' . $groups[$g];
            array_unshift($parts, trim($txt));
        }
        $n = intdiv($n, 1000);
        $g++;
    }
    return trim(implode(' ', $parts)) . ' ' . $currency;
}

function tahsilat_load(int $id): ?array
{
    tahsilat_db_ensure();
    if ($id <= 0) return null;
    $stmt = db()->prepare('SELECT r.*, c.name AS cari_name, c.city AS cari_city, c.address AS cari_address, c.tax_no AS cari_tax_no, c.tax_office AS cari_tax_office, c.phone AS cari_phone FROM collection_receipts r LEFT JOIN cariler c ON c.id=r.cari_id WHERE r.id=? AND COALESCE(r.is_deleted,0)=0');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    foreach ([
        'customer_city' => 'cari_city',
        'customer_address' => 'cari_address',
        'customer_tax_no' => 'cari_tax_no',
        'customer_tax_office' => 'cari_tax_office',
        'customer_phone' => 'cari_phone',
    ] as $receiptKey => $cariKey) {
        if (trim((string)($row[$receiptKey] ?? '')) === '' && trim((string)($row[$cariKey] ?? '')) !== '') {
            $row[$receiptKey] = $row[$cariKey];
        }
    }
    return $row;
}

function tahsilat_save_from_post(int $id = 0): int
{
    tahsilat_db_ensure();
    $amount = tahsilat_decimal($_POST['amount'] ?? '0');
    if ($amount <= 0) throw new RuntimeException('Tahsilat tutarı sıfırdan büyük olmalı.');
    $currency = trim((string)($_POST['currency'] ?? 'TL')) ?: 'TL';
    $amountText = trim((string)($_POST['amount_text'] ?? ''));
    if ($amountText === '') $amountText = tahsilat_amount_text($amount, $currency);

    $oldDoc = null;
    if ($id > 0) {
        $oldForDoc = tahsilat_load($id);
        if ($oldForDoc) {
            $oldDoc = ['path'=>$oldForDoc['check_document_path'] ?? null, 'name'=>$oldForDoc['check_document_name'] ?? null, 'mime'=>$oldForDoc['check_document_mime'] ?? null];
        }
    }
    try { $checkDoc = handle_upload('check_document', $oldDoc); }
    catch (Throwable $e) { throw new RuntimeException('Çek/senet görseli yüklenemedi: ' . $e->getMessage()); }

    $payload = [
        'receipt_no' => trim((string)($_POST['receipt_no'] ?? '')) ?: tahsilat_next_no(),
        'receipt_date' => trim((string)($_POST['receipt_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'cari_id' => ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'customer_city' => trim((string)($_POST['customer_city'] ?? '')),
        'customer_address' => trim((string)($_POST['customer_address'] ?? '')),
        'customer_tax_office' => trim((string)($_POST['customer_tax_office'] ?? '')),
        'customer_tax_no' => trim((string)($_POST['customer_tax_no'] ?? '')),
        'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
        'payment_type' => trim((string)($_POST['payment_type'] ?? 'nakit')) ?: 'nakit',
        'currency' => $currency,
        'amount' => $amount,
        'amount_text' => $amountText,
        'description' => trim((string)($_POST['description'] ?? '')),
        'bank_name' => trim((string)($_POST['bank_name'] ?? '')),
        'document_no' => trim((string)($_POST['document_no'] ?? '')),
        'due_date' => trim((string)($_POST['due_date'] ?? '')),
        'debtor_name' => trim((string)($_POST['debtor_name'] ?? '')),
        'collected_by' => trim((string)($_POST['collected_by'] ?? '')),
        'paid_by' => trim((string)($_POST['paid_by'] ?? '')),
        'check_document_path' => $checkDoc['path'] ?? null,
        'check_document_name' => $checkDoc['name'] ?? null,
        'check_document_mime' => $checkDoc['mime'] ?? null,
    ];
    if ($payload['customer_name'] === '') throw new RuntimeException('Firma / müşteri adı boş olamaz.');

    $pdo = db();

    $dupStmt = $pdo->prepare("SELECT id FROM collection_receipts
        WHERE id<>? AND COALESCE(is_deleted,0)=0 AND UPPER(TRIM(receipt_no))=UPPER(TRIM(?)) LIMIT 1");
    $dupStmt->execute([$id, $payload['receipt_no']]);
    $duplicateReceiptId = (int)($dupStmt->fetchColumn() ?: 0);
    if ($duplicateReceiptId > 0) {
        throw new RuntimeException('Aynı makbuz numarası aktif kayıtlarda zaten var (Makbuz #' . $duplicateReceiptId . '). İkinci kayıt oluşturulmadı.');
    }
    if ($id > 0) {
        $old = tahsilat_load($id);
        if (!$old) throw new RuntimeException('Düzenlenecek makbuz bulunamadı.');

        if ((int)($old['posted_to_cari'] ?? 0) === 1) {
            $financialFields = [
                'receipt_date','cari_id','payment_type','currency','amount',
                'bank_name','document_no','due_date'
            ];
            foreach ($financialFields as $field) {
                $oldValue = $field === 'amount'
                    ? round((float)($old[$field] ?? 0), 2)
                    : trim((string)($old[$field] ?? ''));
                $newValue = $field === 'amount'
                    ? round((float)($payload[$field] ?? 0), 2)
                    : trim((string)($payload[$field] ?? ''));
                if ($oldValue !== $newValue) {
                    throw new RuntimeException('Bu makbuz cariye işlendiği için tutar/tarih/cari/ödeme bilgileri doğrudan değiştirilemez. Yanlışsa makbuzu iptal edip doğru kayıt oluşturun.');
                }
            }
        }

        $payload['updated_at'] = now();
        $payload['id'] = $id;
        $stmt = $pdo->prepare('UPDATE collection_receipts SET receipt_no=:receipt_no, receipt_date=:receipt_date, cari_id=:cari_id, customer_name=:customer_name, customer_city=:customer_city, customer_address=:customer_address, customer_tax_office=:customer_tax_office, customer_tax_no=:customer_tax_no, customer_phone=:customer_phone, payment_type=:payment_type, currency=:currency, amount=:amount, amount_text=:amount_text, description=:description, bank_name=:bank_name, document_no=:document_no, due_date=:due_date, debtor_name=:debtor_name, collected_by=:collected_by, paid_by=:paid_by, check_document_path=:check_document_path, check_document_name=:check_document_name, check_document_mime=:check_document_mime, updated_at=:updated_at WHERE id=:id');
        $stmt->execute($payload);
        delete_replaced_upload($oldDoc, $checkDoc);
        log_action('Tahsilat makbuzu güncellendi', $payload['receipt_no'] . ' ' . $payload['customer_name']);
        audit_action('tahsilat_makbuzu', $id, 'guncellendi', $old, $payload, $payload['receipt_no']);
        return $id;
    }

    $payload['created_by'] = current_user()['id'] ?? null;
    $payload['created_at'] = now();
    $payload['updated_at'] = now();
    $stmt = $pdo->prepare('INSERT INTO collection_receipts (receipt_no, receipt_date, cari_id, customer_name, customer_city, customer_address, customer_tax_office, customer_tax_no, customer_phone, payment_type, currency, amount, amount_text, description, bank_name, document_no, due_date, debtor_name, collected_by, paid_by, check_document_path, check_document_name, check_document_mime, created_by, created_at, updated_at) VALUES (:receipt_no, :receipt_date, :cari_id, :customer_name, :customer_city, :customer_address, :customer_tax_office, :customer_tax_no, :customer_phone, :payment_type, :currency, :amount, :amount_text, :description, :bank_name, :document_no, :due_date, :debtor_name, :collected_by, :paid_by, :check_document_path, :check_document_name, :check_document_mime, :created_by, :created_at, :updated_at)');
    $stmt->execute($payload);
    $receiptId = (int)$pdo->lastInsertId();
    log_action('Tahsilat makbuzu eklendi', $payload['receipt_no'] . ' ' . $payload['customer_name']);
    audit_action('tahsilat_makbuzu', $receiptId, 'eklendi', null, $payload, $payload['receipt_no']);
    return $receiptId;
}

function tahsilat_delete(int $id, string $reason = ''): bool
{
    tahsilat_db_ensure();
    $old = tahsilat_load($id);
    if (!$old) return false;

    $reason = trim($reason);
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($reasonLength < 6) throw new RuntimeException('Makbuz iptal nedeni en az 6 karakter olmalıdır.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId = current_user()['id'] ?? null;
        $now = now();
        $movementId = (int)($old['cari_movement_id'] ?? 0);
        if ($movementId > 0) {
            $mStmt = $pdo->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
            $mStmt->execute([$movementId]);
            $movement = $mStmt->fetch() ?: null;
            if ($movement && (int)($movement['is_cancelled'] ?? 0) === 0) {
                $movementReason = 'Tahsilat makbuzu iptal edildi: ' . $reason;
                $pdo->prepare('UPDATE movements SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=?')
                    ->execute([$now,$userId,$movementReason,$now,$movementId]);
                sync_movement_account_transaction($movementId);
                sync_movement_to_check($movementId, false);
                audit_action('hareket', $movementId, 'tahsilat_makbuzu_iptal', $movement, [
                    'is_cancelled'=>1,
                    'cancel_reason'=>$movementReason,
                    'receipt_id'=>$id,
                ], (string)($old['receipt_no'] ?? ''));
            }
        }

        $checkId = (int)($old['check_record_id'] ?? 0);
        if ($checkId > 0) {
            $cStmt = $pdo->prepare('SELECT * FROM checks WHERE id=? LIMIT 1');
            $cStmt->execute([$checkId]);
            $check = $cStmt->fetch() ?: null;
            if ($check && (int)($check['is_cancelled'] ?? 0) === 0) {
                $pdo->prepare("UPDATE checks SET is_cancelled=1,status='iptal',cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=?")
                    ->execute([$now,$userId,'Tahsilat makbuzu iptal edildi: '.$reason,$now,$checkId]);
                sync_check_to_movement($checkId, false);
                audit_action('cek', $checkId, 'tahsilat_makbuzu_iptal', $check, [
                    'is_cancelled'=>1,
                    'receipt_id'=>$id,
                    'cancel_reason'=>'Tahsilat makbuzu iptal edildi: '.$reason,
                ], (string)($old['receipt_no'] ?? ''));
            }
        }

        $pdo->prepare('UPDATE collection_receipts SET is_deleted=1,deleted_at=?,deleted_by=?,delete_reason=?,updated_at=? WHERE id=?')
            ->execute([$now,$userId,$reason,$now,$id]);
        audit_action('tahsilat_makbuzu', $id, 'iptal', $old, [
            'is_deleted'=>1,
            'delete_reason'=>$reason,
            'linked_movement_id'=>$movementId ?: null,
            'linked_check_id'=>$checkId ?: null,
        ], $old['receipt_no'] ?? '');
        $pdo->commit();
        log_action('Tahsilat makbuzu iptal edildi', ($old['receipt_no'] ?? '') . ' ' . ($old['customer_name'] ?? ''));
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tahsilatlar_list(int $limit = 120): array
{
    tahsilat_db_ensure();
    $limit = max(1, min(500, $limit));
    return db()->query('SELECT * FROM collection_receipts WHERE COALESCE(is_deleted,0)=0 ORDER BY receipt_date DESC, id DESC LIMIT ' . $limit)->fetchAll();
}
