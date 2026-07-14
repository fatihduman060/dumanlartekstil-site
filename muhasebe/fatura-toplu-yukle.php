<?php
require_once __DIR__ . '/layout.php';
require_login();

db()->exec("CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    direction TEXT NOT NULL DEFAULT 'gelen',
    cari_id INTEGER,
    invoice_no TEXT,
    invoice_date TEXT NOT NULL,
    due_date TEXT,
    subtotal REAL NOT NULL DEFAULT 0,
    vat_amount REAL NOT NULL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'TL',
    description TEXT,
    document_path TEXT,
    document_name TEXT,
    document_mime TEXT,
    cari_movement_id INTEGER,
    posted_to_cari INTEGER NOT NULL DEFAULT 0,
    posted_at TEXT,
    posted_by INTEGER,
    is_cancelled INTEGER NOT NULL DEFAULT 0,
    cancelled_at TEXT,
    cancelled_by INTEGER,
    cancel_reason TEXT,
    created_by INTEGER,
    created_at TEXT,
    updated_at TEXT
)");
ensure_column(db(), 'invoices', 'file_hash', 'TEXT');
ensure_column(db(), 'invoices', 'import_batch', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_name', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_source', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_confidence', 'INTEGER NOT NULL DEFAULT 0');
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_file_hash ON invoices(file_hash)");

function toplu_fatura_para_birimi($value): string
{
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['TL','USD','EUR'], true) ? $value : 'TL';
}

function toplu_fatura_gecerli_tarih(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function toplu_fatura_dosya(int $index): ?array
{
    if (!isset($_FILES['documents']['name'][$index])) return null;
    return [
        'name' => $_FILES['documents']['name'][$index],
        'type' => $_FILES['documents']['type'][$index] ?? '',
        'tmp_name' => $_FILES['documents']['tmp_name'][$index] ?? '',
        'error' => $_FILES['documents']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
        'size' => $_FILES['documents']['size'][$index] ?? 0,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();

    $items = json_decode((string)($_POST['items_json'] ?? '[]'), true);
    if (!is_array($items)) $items = [];
    $fileCount = isset($_FILES['documents']['name']) && is_array($_FILES['documents']['name'])
        ? count($_FILES['documents']['name'])
        : 0;

    if ($fileCount < 1 || !$items) {
        flash('error', 'Yüklenecek PDF faturaları seçmelisin.');
        redirect('fatura-toplu-yukle.php');
    }
    if ($fileCount > 50) {
        flash('error', 'Tek seferde en fazla 50 PDF yükleyebilirsin.');
        redirect('fatura-toplu-yukle.php');
    }

    $saved = 0;
    $duplicates = 0;
    $failed = 0;
    $errors = [];
    $batch = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $userId = current_user()['id'] ?? null;

    for ($i = 0; $i < $fileCount; $i++) {
        $file = toplu_fatura_dosya($i);
        $meta = is_array($items[$i] ?? null) ? $items[$i] : [];
        $fileName = trim((string)($file['name'] ?? ('Dosya ' . ($i + 1))));

        try {
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Dosya yükleme hatası.');
            }
            if (($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
                throw new RuntimeException('Dosya 10 MB sınırını aşıyor.');
            }

            $direction = (string)($meta['direction'] ?? 'gelen');
            if (!in_array($direction, ['gelen','giden'], true)) $direction = 'gelen';
            $invoiceNo = trim((string)($meta['invoice_no'] ?? ''));
            $invoiceNoKey = preg_replace('/[^A-Z0-9]/', '', strtoupper($invoiceNo));
            $invalidInvoiceNo = $invoiceNoKey === ''
                || (bool)preg_match('/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO|\d{10,11})$/', $invoiceNoKey);
            $invoiceDate = trim((string)($meta['invoice_date'] ?? ''));
            $dueDate = trim((string)($meta['due_date'] ?? ''));
            $dueDate = $dueDate !== '' && toplu_fatura_gecerli_tarih($dueDate) ? $dueDate : null;
            $subtotalRaw = trim((string)($meta['subtotal'] ?? ''));
            $vatRaw = trim((string)($meta['vat_amount'] ?? ''));
            $totalRaw = trim((string)($meta['total_amount'] ?? ''));
            $subtotal = decimal_from_input($subtotalRaw);
            $vatAmount = decimal_from_input($vatRaw);
            $totalAmount = decimal_from_input($totalRaw);
            $currency = toplu_fatura_para_birimi($meta['currency'] ?? 'TL');
            $cariId = (int)($meta['cari_id'] ?? 0);
            $description = trim((string)($meta['description'] ?? 'Toplu PDF fatura yüklemesi'));

            if ($invalidInvoiceNo) {
                throw new RuntimeException('Fatura numarası eksik veya geçersiz.');
            }
            if (!toplu_fatura_gecerli_tarih($invoiceDate)) {
                throw new RuntimeException('Fatura tarihi eksik veya geçersiz.');
            }
            if ($subtotalRaw === '' || $vatRaw === '' || $totalRaw === '') {
                throw new RuntimeException('Matrah, KDV ve genel toplam alanları eksik olamaz.');
            }
            if ($totalAmount <= 0) {
                throw new RuntimeException('Fatura toplamı sıfır olamaz.');
            }
            if ($subtotal < 0 || $vatAmount < 0) {
                throw new RuntimeException('Matrah ve KDV negatif olamaz.');
            }
            if ($cariId > 0) {
                $stmt = db()->prepare('SELECT COUNT(*) FROM cariler WHERE id=?');
                $stmt->execute([$cariId]);
                if ((int)$stmt->fetchColumn() < 1) $cariId = 0;
            }

            $hash = is_file((string)$file['tmp_name']) ? hash_file('sha256', (string)$file['tmp_name']) : '';
            if ($hash !== '') {
                $stmt = db()->prepare("SELECT id FROM invoices WHERE file_hash=? AND COALESCE(is_cancelled,0)=0 LIMIT 1");
                $stmt->execute([$hash]);
                if ($stmt->fetchColumn()) {
                    $duplicates++;
                    continue;
                }
            }
            if ($invoiceNo !== '') {
                $stmt = db()->prepare("SELECT id FROM invoices
                    WHERE COALESCE(is_cancelled,0)=0 AND direction=? AND invoice_no=? AND invoice_date=?
                      AND ABS(total_amount - ?) < 0.01
                    LIMIT 1");
                $stmt->execute([$direction, $invoiceNo, $invoiceDate, $totalAmount]);
                if ($stmt->fetchColumn()) {
                    $duplicates++;
                    continue;
                }
            }

            $_FILES['document'] = $file;
            $doc = handle_upload('document');
            unset($_FILES['document']);

            db()->prepare("INSERT INTO invoices (
                direction, cari_id, invoice_no, invoice_date, due_date, subtotal, vat_amount, total_amount,
                currency, description, document_path, document_name, document_mime, file_hash, import_batch,
                posted_to_cari, cari_movement_id, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)")
                ->execute([
                    $direction, $cariId > 0 ? $cariId : null, $invoiceNo, $invoiceDate, $dueDate,
                    $subtotal, $vatAmount, $totalAmount, $currency, $description,
                    $doc['path'], $doc['name'], $doc['mime'], $hash ?: null, $batch,
                    $userId, now(), now()
                ]);

            $invoiceId = (int)db()->lastInsertId();
            log_action('Toplu fatura arşive eklendi', '#' . $invoiceId . ' · ' . ($invoiceNo ?: $fileName));
            audit_action('fatura', $invoiceId, 'toplu_eklendi', null, [
                'direction'=>$direction,
                'cari_id'=>$cariId > 0 ? $cariId : null,
                'invoice_no'=>$invoiceNo,
                'invoice_date'=>$invoiceDate,
                'total_amount'=>$totalAmount,
                'posted_to_cari'=>0,
                'import_batch'=>$batch,
            ], $invoiceNo ?: $fileName);
            $saved++;
        } catch (Throwable $e) {
            unset($_FILES['document']);
            $failed++;
            if (count($errors) < 5) $errors[] = $fileName . ': ' . $e->getMessage();
        }
    }

    $message = $saved . ' fatura arşive kaydedildi. Cari hareket oluşturulmadı.';
    if ($duplicates > 0) $message .= ' ' . $duplicates . ' mükerrer dosya atlandı.';
    if ($failed > 0) $message .= ' ' . $failed . ' dosya kaydedilemedi.';
    flash($saved > 0 ? 'success' : 'error', $message);
    if ($errors) flash('error', implode(' | ', $errors));
    redirect('faturalar.php');
}

$cariler = db()->query("SELECT id, name, cari_type, COALESCE(tax_no,'') AS tax_no FROM cariler ORDER BY name ASC")->fetchAll();
$companyTaxNo = preg_replace('/\D+/', '', (string)setting_get('company_tax_no', '3140036788')) ?: '3140036788';

page_header('Toplu Fatura Yükle', 'faturalar');
?>
<section class="hero-card bulk-invoice-hero">
  <div>
    <span class="status-pill">Toplu PDF arşivi</span>
    <h2>PDF faturaları birlikte yükle, kontrol et ve cari cari ayır.</h2>
    <p>Bu ekran faturaları yalnızca arşive kaydeder. Otomatik cari hareket oluşturmaz; istediğin faturayı daha sonra listeden “Cariye işle” diyerek işlersin.</p>
  </div>
  <div class="hero-actions"><a class="btn btn-secondary" href="faturalar.php">Fatura listesine dön</a></div>
</section>

<section class="panel-card bulk-invoice-panel">
  <?php if (can_write()): ?>
  <form method="post" enctype="multipart/form-data" id="bulkInvoiceForm" class="stack-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="items_json" id="bulkInvoiceItems" value="[]">

    <label class="bulk-file-picker">
      <strong>PDF faturaları seç</strong>
      <small>Tek seferde en fazla 50 PDF, her biri en fazla 10 MB. Seçimden sonra bilgiler otomatik okunmaya çalışılır.</small>
      <input type="file" name="documents[]" id="bulkInvoiceFiles" accept="application/pdf,.pdf" multiple required>
    </label>

    <div class="bulk-upload-note">
      <strong>Önemli:</strong> Bu toplu yüklemede “Kaydet” dediğinde cariye borç veya alacak yazılmaz. Geçmiş faturaların cari hareketleri zaten girildiyse mükerrer oluşmaz.
    </div>

    <div id="bulkInvoiceSummary" class="bulk-summary">Henüz PDF seçilmedi.</div>
    <div id="bulkInvoiceRows" class="bulk-invoice-rows"></div>

    <div class="form-actions bulk-actions">
      <button class="btn btn-primary" type="submit" id="bulkInvoiceSave" disabled>Kontrol edilen faturaları arşive kaydet</button>
      <a class="btn btn-secondary" href="faturalar.php">Vazgeç</a>
    </div>
  </form>
  <?php else: ?>
    <p class="muted">Görüntüleme yetkisindesiniz. Toplu fatura yükleme kapalı.</p>
  <?php endif; ?>
</section>

<script>
window.BITKE_BULK_CARILER = <?php echo json_encode($cariler, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
window.BITKE_COMPANY_TAX_NO = <?php echo json_encode($companyTaxNo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php page_footer(); ?>
