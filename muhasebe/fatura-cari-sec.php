<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function fatura_cari_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
    ];
    $value = strtr(trim($value), $map);
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function fatura_cari_tax_digits(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?: '';
    // Bazı PDF'ler 10 haneli VKN'nin başına yanlışlıkla 0 ekleyerek 11 hane döndürüyor.
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function fatura_cari_name_valid(string $name): bool
{
    $key = fatura_cari_norm($name);
    if (mb_strlen(trim($name), 'UTF-8') < 3) return false;
    if (preg_match('/\b(MAH|MAHALLESI|CAD|CADDESI|SOK|SOKAK|BULVAR|BLV|KAT|DAIRE|MEVKII|KOYU|ILCE|POSTA KODU|NO|NUMARA)\b/', $key)) return false;
    if (str_contains($key, 'ORGANIZE SANAYI BOLGESI') || preg_match('/\bOSB\b/', $key)) return false;
    if (preg_match('/DUMANLAR|BITKE|MOFIY/', $key)) return false;
    if (preg_match('/FATURA|ETTN|UUID|VERGI|VKN|TCKN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV|MATRAH/', $key)) return false;
    return true;
}

function fatura_cari_attach(array $invoice, array $cari): void
{
    $invoiceId = (int)$invoice['id'];
    $cariId = (int)$cari['id'];

    db()->prepare('UPDATE invoices SET cari_id=?, updated_at=? WHERE id=?')
        ->execute([$cariId, now(), $invoiceId]);

    $movementId = (int)($invoice['cari_movement_id'] ?? 0);
    if ($movementId > 0) {
        $stmt = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
        $stmt->execute([$movementId]);
        if ($stmt->fetchColumn()) {
            db()->prepare('UPDATE movements SET cari_id=?, updated_at=? WHERE id=?')
                ->execute([$cariId, now(), $movementId]);
            sync_movement_account_transaction($movementId);
        }
    }
}

function fatura_cari_find_existing(string $name, string $taxNo): ?array
{
    $taxDigits = fatura_cari_tax_digits($taxNo);
    $nameKey = fatura_cari_norm($name);
    $rows = db()->query("SELECT id, name, cari_type, tax_no, tax_office, city, address, phone, email FROM cariler ORDER BY id ASC")->fetchAll();

    if ($taxDigits !== '') {
        foreach ($rows as $row) {
            if (fatura_cari_tax_digits((string)($row['tax_no'] ?? '')) === $taxDigits) return $row;
        }
    }

    if ($nameKey !== '') {
        foreach ($rows as $row) {
            if (fatura_cari_norm((string)($row['name'] ?? '')) === $nameKey) return $row;
        }
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $action = trim((string)($_POST['action'] ?? 'select_existing'));
        if ($invoiceId <= 0) throw new RuntimeException('Fatura seçimi geçersiz.');

        $stmt = db()->prepare("SELECT * FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Fatura bulunamadı veya iptal edilmiş.');

        $created = false;
        $reused = false;

        if ($action === 'create_auto') {
            $name = trim((string)($_POST['name'] ?? ''));
            $taxNo = fatura_cari_tax_digits((string)($_POST['tax_no'] ?? ''));
            $taxOffice = trim((string)($_POST['tax_office'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));

            if ($name === '') throw new RuntimeException('Faturadan firma adı bulunamadı. Ad / ünvan alanını kontrol et.');
            if (!fatura_cari_name_valid($name)) {
                throw new RuntimeException('Ad / ünvan alanı firma adı yerine adres veya Dumanlar bilgisi içeriyor. Faturadaki karşı firma ünvanını kontrol et.');
            }

            $companyTaxNo = fatura_cari_tax_digits((string)setting_get('company_tax_no', '3140036788'));
            if ($taxNo !== '' && $taxNo === $companyTaxNo) {
                throw new RuntimeException('Bulunan vergi numarası Dumanlar’a ait. Karşı firmanın bilgilerini kontrol et.');
            }

            $phoneDigits = fatura_cari_tax_digits($phone);
            if ($phoneDigits !== '' && ($phoneDigits === $taxNo || $phoneDigits === $companyTaxNo)) {
                $phone = '';
            }
            if (preg_match('/DUMANLAR|BITKE|MOFIY/i', $email)) {
                $email = '';
            }
            if (preg_match('/DUMANLAR|BITKE|MOFIY/', fatura_cari_norm($address))) {
                $address = '';
            }

            $cari = fatura_cari_find_existing($name, $taxNo);
            if ($cari) {
                $reused = true;
            } else {
                $payload = [
                    'cari_type'=>'Firma',
                    'name'=>$name,
                    'tax_no'=>$taxNo,
                    'tax_office'=>$taxOffice,
                    'authorized_person'=>'',
                    'phone'=>$phone,
                    'email'=>$email,
                    'city'=>$city,
                    'address'=>$address,
                    'iban'=>'',
                    'notes'=>'Fatura PDF’sinden otomatik oluşturuldu.',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ];
                db()->prepare("INSERT INTO cariler (
                    cari_type, name, tax_no, tax_office, authorized_person, phone, email, city, address, iban, notes, created_at, updated_at
                ) VALUES (
                    :cari_type, :name, :tax_no, :tax_office, :authorized_person, :phone, :email, :city, :address, :iban, :notes, :created_at, :updated_at
                )")->execute($payload);
                $cariId = (int)db()->lastInsertId();
                $stmt = db()->prepare('SELECT id, name, cari_type, tax_no, tax_office, city, address, phone, email FROM cariler WHERE id=?');
                $stmt->execute([$cariId]);
                $cari = $stmt->fetch();
                $created = true;
            }
        } else {
            $cariId = (int)($_POST['cari_id'] ?? 0);
            if ($cariId <= 0) throw new RuntimeException('Cari seçimi geçersiz.');
            $stmt = db()->prepare('SELECT id, name, cari_type, tax_no, tax_office, city, address, phone, email FROM cariler WHERE id=?');
            $stmt->execute([$cariId]);
            $cari = $stmt->fetch();
            if (!$cari) throw new RuntimeException('Seçilen cari bulunamadı.');
        }

        db()->beginTransaction();
        try {
            fatura_cari_attach($invoice, $cari);
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }

        $event = $created ? 'otomatik_cari_olusturuldu' : ($reused ? 'mevcut_cari_eslesti' : 'cari_secildi');
        $logText = '#' . $invoiceId . ' → ' . $cari['name'];
        log_action($created ? 'Faturadan cari oluşturuldu' : 'Faturaya cari seçildi', $logText);
        if ($created) audit_action('cari', (int)$cari['id'], 'faturadan_eklendi', null, $cari, (string)$cari['name']);
        audit_action('fatura', $invoiceId, $event, ['cari_id'=>$invoice['cari_id'] ?? null], ['cari_id'=>(int)$cari['id']], (string)$cari['name']);

        echo json_encode([
            'ok'=>true,
            'invoice_id'=>$invoiceId,
            'created'=>$created,
            'reused'=>$reused,
            'cari'=>[
                'id'=>(int)$cari['id'],
                'name'=>(string)$cari['name'],
                'cari_type'=>(string)$cari['cari_type'],
                'tax_no'=>(string)($cari['tax_no'] ?? ''),
            ],
            'message'=>$created
                ? 'Cari otomatik oluşturuldu ve faturaya bağlandı.'
                : ($reused ? 'Aynı vergi numarası veya ünvanla mevcut cari bulundu ve faturaya bağlandı.' : 'Cari faturaya bağlandı.'),
            'csrf_token'=>csrf_token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cariler = db()->query('SELECT id, name, cari_type, tax_no FROM cariler ORDER BY name ASC')->fetchAll();
    $invoiceInfo = null;
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if ($invoiceId > 0) {
        $stmt = db()->prepare("SELECT id, direction, document_name, document_path FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch();
        if ($row) {
            $invoiceInfo = [
                'id'=>(int)$row['id'],
                'direction'=>(string)$row['direction'],
                'document_name'=>(string)($row['document_name'] ?? ''),
                'document_url'=>'fatura-indir.php?id=' . (int)$row['id'],
                'has_document'=>!empty($row['document_path']),
            ];
        }
    }

    echo json_encode([
        'ok'=>true,
        'cariler'=>$cariler,
        'invoice'=>$invoiceInfo,
        'can_write'=>can_write(),
        'csrf_token'=>csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
