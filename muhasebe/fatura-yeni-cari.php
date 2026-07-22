<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function fatura_yeni_cari_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
    ];
    $value = strtoupper(strtr(trim($value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function fatura_yeni_cari_digits(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?: '';
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function fatura_yeni_cari_name_valid(string $name): bool
{
    $length = function_exists('mb_strlen') ? mb_strlen(trim($name), 'UTF-8') : strlen(trim($name));
    $key = fatura_yeni_cari_norm($name);
    if ($length < 3) return false;
    if (preg_match('/\b(MAH|MAHALLESI|CAD|CADDESI|SOK|SOKAK|BULVAR|BLV|KAT|DAIRE|MEVKII|KOYU|ILCE|POSTA KODU|NO|NUMARA)\b/', $key)) return false;
    if (strpos($key, 'ORGANIZE SANAYI BOLGESI') !== false || preg_match('/\bOSB\b/', $key)) return false;
    if (preg_match('/DUMANLAR|BITKE|MOFIY|BAFIY/', $key)) return false;
    if (preg_match('/FATURA|ETTN|UUID|VERGI|VKN|TCKN|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM|KDV|MATRAH/', $key)) return false;
    return true;
}

function fatura_yeni_cari_find_existing(string $name, string $taxNo): ?array
{
    $taxDigits = fatura_yeni_cari_digits($taxNo);
    $nameKey = fatura_yeni_cari_norm($name);
    $rows = db()->query("SELECT id, name, cari_type, tax_no, tax_office, city, address, phone, email FROM cariler ORDER BY id ASC")->fetchAll();

    if ($taxDigits !== '') {
        foreach ($rows as $row) {
            if (fatura_yeni_cari_digits((string)($row['tax_no'] ?? '')) === $taxDigits) return $row;
        }
    }

    if ($nameKey !== '') {
        foreach ($rows as $row) {
            if (fatura_yeni_cari_norm((string)($row['name'] ?? '')) === $nameKey) return $row;
        }
    }

    return null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Geçersiz istek.');
    }

    require_write();
    require_csrf();

    $name = trim((string)($_POST['name'] ?? ''));
    $taxNo = fatura_yeni_cari_digits((string)($_POST['tax_no'] ?? ''));
    $taxOffice = trim((string)($_POST['tax_office'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('Ad / Ünvan alanını doldurmalısın.');
    }
    if (!fatura_yeni_cari_name_valid($name)) {
        throw new RuntimeException('Ad / Ünvan alanına karşı firmanın gerçek ünvanını yazmalısın.');
    }

    $companyTaxNo = fatura_yeni_cari_digits((string)setting_get('company_tax_no', '3140036788'));
    if ($taxNo !== '' && $taxNo === $companyTaxNo) {
        throw new RuntimeException('Girilen vergi numarası Dumanlar’a ait. Karşı firmanın vergi numarasını kontrol et.');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('E-posta adresini kontrol et.');
    }

    $phoneDigits = fatura_yeni_cari_digits($phone);
    if ($phoneDigits !== '' && ($phoneDigits === $taxNo || $phoneDigits === $companyTaxNo)) {
        $phone = '';
    }

    $cari = fatura_yeni_cari_find_existing($name, $taxNo);
    $created = false;
    $reused = false;

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
            'notes'=>'Fatura giriş ekranından oluşturuldu.',
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
        if (!$cari) throw new RuntimeException('Cari oluşturuldu ancak kayıt tekrar okunamadı.');
        $created = true;
    }

    $logLabel = $created ? 'Fatura girişinden cari oluşturuldu' : 'Fatura girişinde mevcut cari bulundu';
    log_action($logLabel, (string)$cari['name']);
    if ($created) {
        audit_action('cari', (int)$cari['id'], 'fatura_girisinden_eklendi', null, $cari, (string)$cari['name']);
    }

    echo json_encode([
        'ok'=>true,
        'created'=>$created,
        'reused'=>$reused,
        'cari'=>[
            'id'=>(int)$cari['id'],
            'name'=>(string)$cari['name'],
            'cari_type'=>(string)$cari['cari_type'],
            'tax_no'=>(string)($cari['tax_no'] ?? ''),
        ],
        'message'=>$created
            ? 'Yeni cari oluşturuldu ve bu faturada seçildi.'
            : 'Aynı vergi numarası veya ünvanla mevcut cari bulundu ve bu faturada seçildi.',
        'csrf_token'=>csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
