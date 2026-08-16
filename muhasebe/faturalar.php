<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/stok-lib.php';
require_login();
stok_db_ensure();

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
    issuer_name TEXT,
    issuer_source TEXT,
    issuer_confidence INTEGER NOT NULL DEFAULT 0,
    issuer_parser_version TEXT,
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
ensure_column(db(), 'invoices', 'issuer_name', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_source', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_confidence', 'INTEGER NOT NULL DEFAULT 0');
ensure_column(db(), 'invoices', 'issuer_parser_version', 'TEXT');
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_date ON invoices(invoice_date)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_cari ON invoices(cari_id)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_movement ON invoices(cari_movement_id)");
db()->exec("CREATE TABLE IF NOT EXISTS invoice_expense_types (
    invoice_id INTEGER PRIMARY KEY,
    category TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    created_by INTEGER,
    created_at TEXT,
    updated_by INTEGER,
    updated_at TEXT
)");

function fatura_yonleri(): array
{
    return [
        'gelen' => ['label'=>'Gelen fatura', 'tone'=>'danger', 'movement_type'=>'verecek'],
        'giden' => ['label'=>'Giden fatura', 'tone'=>'success', 'movement_type'=>'alacak'],
    ];
}

function fatura_para_birimleri(): array
{
    return ['TL'=>'TL', 'USD'=>'USD', 'EUR'=>'EUR'];
}

function fatura_tur_etiketleri(): array
{
    return [
        'iplik'=>'İplik / Hammadde', 'iade'=>'İade Faturası', 'telefon'=>'Telefon / İnternet',
        'elektrik'=>'Elektrik', 'dogalgaz'=>'Doğalgaz', 'kargo'=>'Kargo / Nakliye',
        'akaryakit'=>'Akaryakıt', 'bakim'=>'Makine / Bakım', 'ambalaj'=>'Ambalaj',
        'personel'=>'Personel Gideri', 'ofis'=>'Ofis / Genel Gider', 'diger'=>'Diğer',
    ];
}

function fatura_para_birimi($value): string
{
    $value = strtoupper(trim((string)$value));
    return isset(fatura_para_birimleri()[$value]) ? $value : 'TL';
}

function fatura_para($amount, string $currency = 'TL'): string
{
    return number_format((float)$amount, 2, ',', '.') . ' ' . fatura_para_birimi($currency);
}

function fatura_muhtelif_cari_mi($value): bool
{
    $map = ['Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U','ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U'];
    $value = strtoupper(strtr(trim((string)$value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: $value;
    return strpos(trim($value), 'MUHTELIF FATURA GIRISI') !== false;
}

function fatura_kategori_id(): ?int
{
    $stmt = db()->prepare("SELECT id FROM categories WHERE LOWER(name)=LOWER(?) LIMIT 1");
    $stmt->execute(['Fatura']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    try {
        db()->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)')
            ->execute(['Fatura', 'genel', now()]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        $stmt->execute(['Fatura']);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }
}

function fatura_hareket_acik_mi(int $movementId): bool
{
    if ($movementId <= 0) return false;
    $stmt = db()->prepare('SELECT COUNT(*) FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $stmt->execute([$movementId]);
    return (int)$stmt->fetchColumn() > 0;
}

function fatura_hareket_aciklamasi(array $invoice): string
{
    $direction = (string)($invoice['direction'] ?? 'gelen');
    $no = trim((string)($invoice['invoice_no'] ?? ''));
    $prefix = $direction === 'giden' ? 'Giden fatura' : 'Gelen fatura';
    $purpose = $direction === 'giden' ? 'Ürün/hizmet satışı' : 'Mal/hizmet alımı';
    return $prefix . ($no !== '' ? ' no: ' . $no : ' #' . (int)$invoice['id']) . ' / ' . $purpose;
}

function fatura_hareket_payload(array $invoice): array
{
    $direction = (string)($invoice['direction'] ?? 'gelen');
    $meta = fatura_yonleri()[$direction] ?? fatura_yonleri()['gelen'];
    return [
        'cari_id' => !empty($invoice['cari_id']) ? (int)$invoice['cari_id'] : null,
        'category_id' => fatura_kategori_id(),
        'movement_type' => $meta['movement_type'],
        'amount' => (float)$invoice['total_amount'],
        'currency' => fatura_para_birimi($invoice['currency'] ?? 'TL'),
        'movement_date' => (string)$invoice['invoice_date'],
        'due_date' => !empty($invoice['due_date']) ? (string)$invoice['due_date'] : null,
        'payment_method' => 'Fatura',
        'description' => fatura_hareket_aciklamasi($invoice),
        'document_type' => 'fatura',
    ];
}

function fatura_hareket_guncelle(int $movementId, array $invoice): void
{
    $payload = fatura_hareket_payload($invoice);
    db()->prepare("UPDATE movements SET
        cari_id=?, category_id=?, account_id=NULL, movement_type=?, amount=?, currency=?,
        movement_date=?, due_date=?, payment_method=?, description=?, document_type=?, updated_at=?
        WHERE id=? AND COALESCE(is_cancelled,0)=0")
        ->execute([
            $payload['cari_id'], $payload['category_id'], $payload['movement_type'], $payload['amount'],
            $payload['currency'], $payload['movement_date'], $payload['due_date'], $payload['payment_method'],
            $payload['description'], $payload['document_type'], now(), $movementId
        ]);
    sync_movement_account_transaction($movementId);
}

function fatura_hareket_olustur(array $invoice): int
{
    $payload = fatura_hareket_payload($invoice);
    db()->prepare("INSERT INTO movements (
        cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
        payment_method, description, document_type, document_path, document_name, document_mime,
        created_by, created_at, updated_at
    ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)")
        ->execute([
            $payload['cari_id'], $payload['category_id'], $payload['movement_type'], $payload['amount'],
            $payload['currency'], $payload['movement_date'], $payload['due_date'], $payload['payment_method'],
            $payload['description'], $payload['document_type'], current_user()['id'] ?? null, now(), now()
        ]);
    $movementId = (int)db()->lastInsertId();
    sync_movement_account_transaction($movementId);
    return $movementId;
}

function fatura_getir(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM invoices WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? 'gelen');
        if (!isset(fatura_yonleri()[$direction])) $direction = 'gelen';

        $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
        $invoiceNoKey = preg_replace('/[^A-Z0-9]/', '', strtoupper($invoiceNo));
        $invalidInvoiceNo = $invoiceNoKey === ''
            || (bool)preg_match('/^(TICARETSICILNO|TICARETSICILNUMARASI|ETTN|UUID|VKN|TCKN|MERSISNO|\d{10,11})$/', $invoiceNoKey);
        $invoiceDate = (string)($_POST['invoice_date'] ?? date('Y-m-d'));
        $dueDate = !empty($_POST['due_date']) ? (string)$_POST['due_date'] : null;
        $cariId = ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null;
        $subtotal = decimal_from_input($_POST['subtotal'] ?? '0');
        $vatAmount = decimal_from_input($_POST['vat_amount'] ?? '0');
        $totalAmount = decimal_from_input($_POST['total_amount'] ?? '0');
        $currency = fatura_para_birimi($_POST['currency'] ?? 'TL');
        $description = trim((string)($_POST['description'] ?? ''));
        $issuerName = trim((string)($_POST['issuer_name'] ?? ''));
        $stockLines = stok_fatura_post_satirlari($_POST);
        try {
            stok_fatura_dogrula($id, $direction, $stockLines);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('faturalar.php' . ($id > 0 ? '?edit=' . $id : ''));
        }
        if (function_exists('mb_substr')) $issuerName = mb_substr($issuerName, 0, 180, 'UTF-8');
        else $issuerName = substr($issuerName, 0, 180);

        if ($invalidInvoiceNo || $invoiceDate === '' || $totalAmount <= 0 || $subtotal < 0 || $vatAmount < 0) {
            flash('error', 'Fatura numarası, tarihi ve tutarları kontrol etmelisin.');
            redirect('faturalar.php' . ($id > 0 ? '?edit=' . $id : ''));
        }

        $oldRow = $id > 0 ? fatura_getir($id) : null;
        $issuerUnchanged = $oldRow && trim((string)($oldRow['issuer_name'] ?? '')) === $issuerName;
        $issuerSource = $issuerUnchanged ? (string)($oldRow['issuer_source'] ?? '') : ($issuerName !== '' ? 'manual' : '');
        $issuerConfidence = $issuerUnchanged ? (int)($oldRow['issuer_confidence'] ?? 0) : ($issuerName !== '' ? 100 : 0);
        $issuerParserVersion = $issuerUnchanged ? (string)($oldRow['issuer_parser_version'] ?? '') : '';
        $oldDoc = $oldRow ? [
            'path'=>$oldRow['document_path'] ?? null,
            'name'=>$oldRow['document_name'] ?? null,
            'mime'=>$oldRow['document_mime'] ?? null,
        ] : null;

        try {
            $doc = handle_upload('document', $oldDoc);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('faturalar.php' . ($id > 0 ? '?edit=' . $id : ''));
        }

        if (!$doc['path'] && !$oldDoc) {
            flash('error', 'Fatura dosyasını seçmelisin.');
            redirect('faturalar.php');
        }

        if ($id > 0 && $oldRow) {
            db()->prepare("UPDATE invoices SET
                direction=?, cari_id=?, invoice_no=?, invoice_date=?, due_date=?, subtotal=?, vat_amount=?,
                total_amount=?, currency=?, description=?, document_path=?, document_name=?, document_mime=?,
                issuer_name=?, issuer_source=?, issuer_confidence=?, issuer_parser_version=?, updated_at=?
                WHERE id=?")
                ->execute([
                    $direction, $cariId, $invoiceNo, $invoiceDate, $dueDate, $subtotal, $vatAmount,
                    $totalAmount, $currency, $description, $doc['path'], $doc['name'], $doc['mime'],
                    $issuerName, $issuerSource, $issuerConfidence, $issuerParserVersion, now(), $id
                ]);
            delete_replaced_upload($oldDoc, $doc);
            $saved = fatura_getir($id);
            if ($saved && !empty($saved['cari_movement_id']) && fatura_hareket_acik_mi((int)$saved['cari_movement_id'])) {
                fatura_hareket_guncelle((int)$saved['cari_movement_id'], $saved);
            }
            log_action('Fatura güncellendi', ($invoiceNo ?: '#' . $id) . ' ' . fatura_para($totalAmount, $currency));
            stok_fatura_senkronla($id, $direction, $invoiceDate, $stockLines, false);
            audit_action('fatura', $id, 'guncellendi', $oldRow, $saved, $invoiceNo ?: '#' . $id);
            flash('success', 'Fatura güncellendi. Cariye işlenmişse bağlı hareket ve satış stokları da güncellendi.');
        } else {
            db()->prepare("INSERT INTO invoices (
                direction, cari_id, invoice_no, invoice_date, due_date, subtotal, vat_amount, total_amount,
                currency, description, document_path, document_name, document_mime, issuer_name, issuer_source, issuer_confidence, issuer_parser_version,
                created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $direction, $cariId, $invoiceNo, $invoiceDate, $dueDate, $subtotal, $vatAmount, $totalAmount,
                    $currency, $description, $doc['path'], $doc['name'], $doc['mime'], $issuerName,
                    $issuerName !== '' ? 'manual' : '', $issuerName !== '' ? 100 : 0, '',
                    current_user()['id'] ?? null, now(), now()
                ]);
            $newId = (int)db()->lastInsertId();
            $saved = fatura_getir($newId);
            stok_fatura_senkronla($newId, $direction, $invoiceDate, $stockLines, false);
            log_action('Fatura eklendi', ($invoiceNo ?: '#' . $newId) . ' ' . fatura_para($totalAmount, $currency));
            audit_action('fatura', $newId, 'eklendi', null, $saved, $invoiceNo ?: '#' . $newId);
            flash('success', 'Fatura arşive eklendi.');
        }
        redirect('faturalar.php');
    }

    if ($action === 'post_cari') {
        $id = (int)($_POST['id'] ?? 0);
        $invoice = fatura_getir($id);
        if (!$invoice || (int)($invoice['is_cancelled'] ?? 0) === 1) {
            flash('error', 'Fatura bulunamadı veya iptal edilmiş.');
            redirect('faturalar.php');
        }
        if (empty($invoice['cari_id'])) {
            flash('error', 'Cariye işlemek için faturada cari seçmelisin.');
            redirect('faturalar.php?edit=' . $id);
        }
        $invoiceCariStmt = db()->prepare('SELECT name FROM cariler WHERE id=? LIMIT 1');
        $invoiceCariStmt->execute([(int)$invoice['cari_id']]);
        if (fatura_muhtelif_cari_mi((string)($invoiceCariStmt->fetchColumn() ?: ''))) {
            flash('success', 'Muhtelif fatura arşivde tutulur; cari borç/alacak hareketi oluşturulmaz. Ödeme banka/kasa hesabından Gider olarak girilmelidir.');
            redirect('faturalar.php');
        }
        if ((float)$invoice['total_amount'] <= 0) {
            flash('error', 'Fatura toplamı sıfır olamaz.');
            redirect('faturalar.php?edit=' . $id);
        }

        $oldMovementId = (int)($invoice['cari_movement_id'] ?? 0);
        if ($oldMovementId > 0 && fatura_hareket_acik_mi($oldMovementId)) {
            fatura_hareket_guncelle($oldMovementId, $invoice);
            $movementId = $oldMovementId;
            $message = 'Mevcut cari hareketi faturaya göre güncellendi.';
        } else {
            $movementId = fatura_hareket_olustur($invoice);
            $message = 'Fatura cariye işlendi.';
        }

        db()->prepare('UPDATE invoices SET cari_movement_id=?, posted_to_cari=1, posted_at=?, posted_by=?, updated_at=? WHERE id=?')
            ->execute([$movementId, now(), current_user()['id'] ?? null, now(), $id]);

        log_action('Fatura cariye işlendi', '#' . $id . ' → hareket #' . $movementId);
        audit_action('fatura', $id, 'cariye_islendi', $invoice, ['cari_movement_id'=>$movementId,'posted_to_cari'=>1], $invoice['invoice_no'] ?: '#' . $id);
        flash('success', $message);
        redirect('faturalar.php');
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $invoice = fatura_getir($id);
        if ($invoice && (int)($invoice['is_cancelled'] ?? 0) === 0) {
            $reason = trim((string)($_POST['cancel_reason'] ?? 'Fatura iptal edildi'));
            db()->prepare('UPDATE invoices SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                ->execute([now(), current_user()['id'] ?? null, $reason, now(), $id]);

            $movementId = (int)($invoice['cari_movement_id'] ?? 0);
            if ($movementId > 0 && fatura_hareket_acik_mi($movementId)) {
                db()->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                    ->execute([now(), current_user()['id'] ?? null, 'Bağlı fatura iptal edildi', now(), $movementId]);
                sync_movement_account_transaction($movementId);
            }

            stok_fatura_senkronla($id, (string)$invoice['direction'], (string)$invoice['invoice_date'], [], true);
            log_action('Fatura iptal edildi', '#' . $id);
            audit_action('fatura', $id, 'iptal', $invoice, ['is_cancelled'=>1,'cancel_reason'=>$reason], $invoice['invoice_no'] ?: '#' . $id);
            flash('success', 'Fatura ve varsa bağlı cari hareket iptal edildi.');
        }
        redirect('faturalar.php');
    }
}

$cariler = cariler_for_select();
$edit = null;
if (!empty($_GET['edit'])) {
    $edit = fatura_getir((int)$_GET['edit']);
    if ($edit && (int)($edit['is_cancelled'] ?? 0) === 1) {
        flash('error', 'İptal edilmiş fatura düzenlenemez.');
        redirect('faturalar.php?include_cancelled=1');
    }
}

$stockProducts = stok_urunler(true);
$editStockLines = $edit ? stok_fatura_satirlari((int)$edit['id']) : [];

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
$periodStart = $period . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));

$summaryStmt = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN direction='gelen' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS incoming_vat,
    COALESCE(SUM(CASE WHEN direction='giden' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS outgoing_vat,
    COALESCE(SUM(CASE WHEN direction='giden' AND currency='TL' THEN total_amount ELSE 0 END),0) AS outgoing_total_tl,
    COALESCE(SUM(CASE WHEN direction='gelen' THEN 1 ELSE 0 END),0) AS incoming_count,
    COALESCE(SUM(CASE WHEN direction='giden' THEN 1 ELSE 0 END),0) AS outgoing_count,
    COALESCE(SUM(CASE WHEN direction='giden' AND currency='TL' THEN 1 ELSE 0 END),0) AS outgoing_tl_count
    FROM invoices
    WHERE COALESCE(is_cancelled,0)=0 AND invoice_date BETWEEN ? AND ?");
$summaryStmt->execute([$periodStart, $periodEnd]);
$summary = $summaryStmt->fetch() ?: ['incoming_vat'=>0,'outgoing_vat'=>0,'outgoing_total_tl'=>0,'incoming_count'=>0,'outgoing_count'=>0,'outgoing_tl_count'=>0];

$periodYear = substr($period, 0, 4);
$yearStart = $periodYear . '-01-01';
$yearEnd = $periodYear . '-12-31';
$yearOutgoingStmt = db()->prepare("SELECT COALESCE(SUM(total_amount),0) AS total_tl, COUNT(*) AS invoice_count
    FROM invoices
    WHERE COALESCE(is_cancelled,0)=0 AND direction='giden' AND currency='TL' AND invoice_date BETWEEN ? AND ?");
$yearOutgoingStmt->execute([$yearStart, $yearEnd]);
$yearOutgoing = $yearOutgoingStmt->fetch() ?: ['total_tl'=>0,'invoice_count'=>0];
$invoiceSeriesYear = (int)date('Y');
$invoiceSeriesPrefix = 'DMN' . $invoiceSeriesYear;
$invoiceSeriesStmt = db()->prepare("SELECT invoice_no FROM invoices WHERE direction='giden' AND invoice_no IS NOT NULL AND UPPER(invoice_no) LIKE ?");
$invoiceSeriesStmt->execute([$invoiceSeriesPrefix . '%']);
$lastInvoiceSequence = 0;
foreach ($invoiceSeriesStmt->fetchAll(PDO::FETCH_COLUMN) as $invoiceNumber) {
    $invoiceNumberKey = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$invoiceNumber));
    if (preg_match('/^' . preg_quote($invoiceSeriesPrefix, '/') . '(\d{1,9})$/', $invoiceNumberKey, $matches)) {
        $lastInvoiceSequence = max($lastInvoiceSequence, (int)$matches[1]);
    }
}
$lastOutgoingInvoiceNo = $lastInvoiceSequence > 0 ? $invoiceSeriesPrefix . str_pad((string)$lastInvoiceSequence, 9, '0', STR_PAD_LEFT) : '';
$nextOutgoingInvoiceNo = $invoiceSeriesPrefix . str_pad((string)($lastInvoiceSequence + 1), 9, '0', STR_PAD_LEFT);
$incomingVat = (float)$summary['incoming_vat'];
$outgoingVat = (float)$summary['outgoing_vat'];
$vatNet = $outgoingVat - $incomingVat;
$vatNetLabel = $vatNet > 0.009 ? 'Tahmini ödenecek KDV' : ($vatNet < -0.009 ? 'Tahmini devreden KDV' : 'KDV dengede');
$vatNetTone = $vatNet > 0.009 ? 'text-danger' : 'text-success';

$q = trim((string)($_GET['q'] ?? ''));
$directionFilter = trim((string)($_GET['direction'] ?? 'giden'));
if (!isset(fatura_yonleri()[$directionFilter])) $directionFilter = 'giden';
$includeCancelled = isset($_GET['include_cancelled']);
$where = ['i.invoice_date BETWEEN ? AND ?'];
$params = [$periodStart, $periodEnd];
if (!$includeCancelled) $where[] = 'COALESCE(i.is_cancelled,0)=0';
if ($directionFilter !== '' && isset(fatura_yonleri()[$directionFilter])) {
    $where[] = 'i.direction=?';
    $params[] = $directionFilter;
}
if ($q !== '') {
    $where[] = '(i.invoice_no LIKE ? OR i.description LIKE ? OR i.document_name LIKE ? OR i.issuer_name LIKE ? OR c.name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql = "SELECT i.*, c.name AS cari_name, m.is_cancelled AS movement_cancelled,
        COALESCE(t.category,'') AS expense_category, COALESCE(t.source,'') AS expense_category_source
    FROM invoices i
    LEFT JOIN cariler c ON c.id=i.cari_id
    LEFT JOIN movements m ON m.id=i.cari_movement_id
    LEFT JOIN invoice_expense_types t ON t.invoice_id=i.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.invoice_date DESC, i.id DESC
    LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$invoiceTypeLabels = fatura_tur_etiketleri();
$directionUrl = function (string $target) use ($period, $q, $includeCancelled): string {
    $query = ['period'=>$period, 'direction'=>$target];
    if ($q !== '') $query['q'] = $q;
    if ($includeCancelled) $query['include_cancelled'] = '1';
    return 'faturalar.php?' . http_build_query($query);
};
$listOnly = empty($edit);

page_header('Faturalar', 'faturalar');
?>
<style>
.form-grid.fatura-list-only{display:block!important;grid-template-columns:minmax(0,1fr)!important;width:100%}
.fatura-list-only>.panel-card{width:100%;max-width:none;margin:0}.fatura-entry-source[hidden]{display:none!important}
.fatura-list-only .table-wrap{width:100%;overflow:auto}.fatura-list-only table{width:100%;min-width:1120px}
.fatura-direction-tabs{display:flex;gap:8px;margin:0 0 12px;padding:7px;border:1px solid #e5dccf;border-radius:16px;background:#fff}
.fatura-direction-tabs a{flex:1;display:grid;gap:3px;text-align:center;text-decoration:none;border-radius:12px;padding:11px 14px;background:#fbf6ed;color:#16482e}
.fatura-direction-tabs a.active{background:#c49a4f;color:#102818}.fatura-direction-tabs strong{font-size:13px}.fatura-direction-tabs small{font-size:9px;font-weight:700;opacity:.72}
.fatura-entry-open{margin-left:auto;white-space:nowrap}
.kdv-devir-panel{display:grid;grid-template-columns:minmax(220px,.8fr) minmax(420px,1.6fr);gap:14px;align-items:end;margin:14px 0 16px;padding:14px 16px;border:1px solid #d8c6a5;background:linear-gradient(135deg,#fff7e8,#fff);border-radius:16px}.kdv-devir-copy{display:grid;gap:4px}.kdv-devir-copy small{font-size:11px;color:var(--muted)}.kdv-devir-form{display:grid;grid-template-columns:150px minmax(220px,1fr) auto;gap:9px;align-items:end}.kdv-devir-form label{display:grid;gap:5px;font-size:11px;font-weight:800}.kdv-devir-form input{width:100%;border:1px solid var(--border);background:#fff;border-radius:11px;padding:10px 11px}.kdv-devir-status{grid-column:1/-1;margin:0;font-size:11px;color:var(--muted)}
.fatura-tur-auto-status{display:flex;gap:10px;align-items:center;flex-wrap:wrap;min-height:38px;margin:10px 0 12px;padding:10px 12px;border:1px solid #c8d8ea;background:#f1f7ff;border-radius:12px;font-size:10px}.fatura-tur-summary{min-height:58px;margin:12px 0 14px;padding:13px 14px;border:1px solid #d9e3dc;background:#f7fbf8;border-radius:15px;display:grid;gap:10px}.fatura-tur-summary-head{display:grid;gap:3px}.fatura-tur-summary-head small{font-size:10px;color:var(--muted)}
.fatura-alt-kontroller{display:grid;gap:12px;margin-top:18px;padding:16px}.fatura-alt-kontrol-body{display:grid;gap:12px}
.fatura-yon-cell{display:grid;gap:4px;justify-items:start}.fatura-yon-cell button{border:0;background:transparent;padding:0;color:#7b6745;font-size:9px;font-weight:850;text-decoration:underline;cursor:pointer}.fatura-cari-sec-btn{border:1px dashed #c9a96e;background:#fff9ee;color:#51452f;border-radius:11px;padding:8px 10px;display:grid;gap:2px;text-align:left;cursor:pointer;min-width:120px}
.fatura-next-number-card strong{font-size:18px!important;letter-spacing:0!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fatura-alt-kontroller .masraf-fisi-form>.btn{grid-column:1/-1;width:100%;min-width:0;min-height:46px;white-space:normal!important;line-height:1.25}
@media(max-width:980px){.kdv-devir-panel{grid-template-columns:1fr}.kdv-devir-form{grid-template-columns:1fr 1fr}}
@media(max-width:720px){.fatura-direction-tabs{display:grid}.fatura-list-only table{min-width:980px}}
@media(max-width:640px){.kdv-devir-form{grid-template-columns:1fr}}
</style>
<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Fatura ve KDV Takibi</span><h3><?php echo e(date('m.Y', strtotime($periodStart))); ?> dönemi</h3></div>
    <p>Gelen ve giden faturaların basit KDV görünümü. Resmî KDV beyannamesi yerine geçmez.</p>
  </div>
  <form class="filterbar" method="get">
    <input type="month" name="period" value="<?php echo e($period); ?>">
    <input type="hidden" name="direction" value="<?php echo e($directionFilter); ?>">
    <button class="btn btn-secondary" type="submit">Dönemi göster</button>
    <a class="btn btn-primary" href="fatura-toplu-yukle.php" data-toplu-fatura-link>Toplu PDF yükle</a>
  </form>
  <div id="kdvDevirPanel" class="kdv-devir-panel">
    <div class="kdv-devir-copy"><strong>Önceki dönemden devreden KDV</strong><small>Önceki dönemden devreden tutarı bu dönem için kaydet.</small></div>
    <form class="kdv-devir-form" data-kdv-devir-form><input type="hidden" name="period" value="<?php echo e($period); ?>"><label>Tutar<input type="text" inputmode="decimal" name="amount" placeholder="0,00"></label><label>Not<input type="text" name="note" placeholder="Örn: Önceki ay beyannamesinden devir"></label><button type="submit" class="btn btn-secondary">KDV devrini kaydet</button></form>
    <p class="kdv-devir-status" data-kdv-devir-status>Bilgiler hazırlanıyor…</p>
  </div>
  <div class="stats-grid four section-stats">
    <article class="stat-card soft"><span>İndirilecek KDV</span><strong class="text-success"><?php echo e(fatura_para($incomingVat)); ?></strong><small>Gelen faturaların KDV'si</small></article>
    <article class="stat-card soft"><span>Hesaplanan KDV</span><strong class="text-danger"><?php echo e(fatura_para($outgoingVat)); ?></strong><small>Giden faturaların KDV'si</small></article>
    <article id="kdvDevirCard" class="stat-card soft"><span>Önceki dönemden devir</span><strong>0,00 TL</strong><small>Manuel girilen KDV devri</small></article>
    <article class="stat-card status"><span>KDV durumu</span><strong class="<?php echo e($vatNetTone); ?>"><?php echo e(fatura_para(abs($vatNet))); ?></strong><small><?php echo e($vatNetLabel); ?></small></article>
    <article id="magazaGunlukRaporCard" class="stat-card soft"><span>Mağaza günlük rapor</span><strong>0,00 TL</strong><small>Z raporu KDV toplamı hazırlanıyor</small></article>
    <article class="stat-card soft fatura-next-number-card"><span>Sıradaki giden fatura</span><strong><?php echo e($nextOutgoingInvoiceNo); ?></strong><small><?php echo $lastOutgoingInvoiceNo !== '' ? 'Son fatura: ' . e($lastOutgoingInvoiceNo) : e((string)$invoiceSeriesYear) . ' serisinde kayıt yok'; ?></small></article>
    <article class="stat-card soft"><span>Aylık giden fatura toplamı</span><strong><?php echo e(fatura_para($summary['outgoing_total_tl'])); ?></strong><small><?php echo e(date('m.Y', strtotime($periodStart))); ?> · <?php echo e((string)$summary['outgoing_tl_count']); ?> giden fatura</small></article>
    <article class="stat-card soft"><span>Yıllık giden fatura toplamı</span><strong><?php echo e(fatura_para($yearOutgoing['total_tl'])); ?></strong><small><?php echo e($periodYear); ?> yılı · <?php echo e((string)$yearOutgoing['invoice_count']); ?> giden fatura</small></article>
  </div>
  <p class="calc-note"><strong>KDV durumu</strong> = hesaplanan KDV - indirilecek KDV. Tevkifat, istisna, iade ve önceki dönem devri bu ilk taslakta hesaba katılmaz.</p>
</section>

<section class="form-grid<?php echo $listOnly ? ' fatura-list-only' : ''; ?>">
  <article class="panel-card form-card<?php echo $listOnly ? ' fatura-entry-source' : ''; ?>"<?php echo $listOnly ? ' hidden aria-hidden="true"' : ''; ?>>
    <div class="card-head"><h3><?php echo $edit ? 'Fatura düzenle' : 'Yeni fatura'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form" id="invoiceForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">

      <div class="two-col">
        <label>Fatura yönü
          <select name="direction" required>
            <?php foreach(fatura_yonleri() as $key=>$meta): ?>
              <option value="<?php echo e($key); ?>" <?php echo (($edit['direction'] ?? 'gelen')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Fatura no<input name="invoice_no" value="<?php echo e($edit['invoice_no'] ?? ''); ?>" placeholder="Örn: GIB202600123"></label>
      </div>

      <div class="two-col">
        <label>Fatura tarihi<input type="date" name="invoice_date" required value="<?php echo e($edit['invoice_date'] ?? date('Y-m-d')); ?>"></label>
        <label>Vade tarihi<input type="date" name="due_date" value="<?php echo e($edit['due_date'] ?? ''); ?>"></label>
      </div>

      <label>İlgili cari
        <select name="cari_id">
          <option value="">Cari seçilmedi</option>
          <?php foreach($cariler as $c): ?>
            <option value="<?php echo e($c['id']); ?>" <?php echo ((string)($edit['cari_id'] ?? '')===(string)$c['id'])?'selected':''; ?>><?php echo e($c['name']); ?> — <?php echo e($c['cari_type']); ?></option>
          <?php endforeach; ?>
        </select>
        <small>Cariye İşle düğmesi için cari seçilmiş olmalı.</small>
      </label>

      <label>Gönderen firma
        <input name="issuer_name" maxlength="180" value="<?php echo e($edit['issuer_name'] ?? ''); ?>" placeholder="PDF'den otomatik okunur; gerekirse düzelt">
        <small>Bu bilgi cariden ayrıdır. MUHTELİF FATURA GİRİŞİ carisi değişmeden kalır.</small>
      </label>

      <div class="two-col">
        <label>Matrah<input type="text" inputmode="decimal" name="subtotal" data-invoice-subtotal value="<?php echo e($edit['subtotal'] ?? ''); ?>" placeholder="0,00"></label>
        <label>KDV<input type="text" inputmode="decimal" name="vat_amount" data-invoice-vat value="<?php echo e($edit['vat_amount'] ?? ''); ?>" placeholder="0,00"></label>
      </div>

      <div class="two-col">
        <label>Genel toplam<input type="text" inputmode="decimal" name="total_amount" data-invoice-total required value="<?php echo e($edit['total_amount'] ?? ''); ?>" placeholder="0,00"></label>
        <label>Para birimi
          <select name="currency">
            <?php foreach(fatura_para_birimleri() as $key=>$label): ?>
              <option value="<?php echo e($key); ?>" <?php echo fatura_para_birimi($edit['currency'] ?? 'TL')===$key?'selected':''; ?>><?php echo e($label); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <section class="invoice-stock-box" data-invoice-stock-box>
        <div class="invoice-stock-head"><div><strong>Satılan ürünler ve stok düşümü</strong><small>Yalnızca giden/satış faturalarında ürün ve DZ miktarı stoktan otomatik düşer.</small></div><a href="stok-takibi.php" target="_blank">Stok ürünlerini aç</a></div>
        <?php if(!$stockProducts): ?><p class="muted">Henüz stok ürün kartı yok. Önce Stok Takibi bölümünden ürünleri ekle veya Excel listesini aktar.</p><?php else: ?>
        <div data-invoice-stock-lines>
          <?php $stockRenderLines=$editStockLines ?: [['product_id'=>'','quantity_dozen'=>'']]; foreach($stockRenderLines as $stockLine): ?>
          <div class="invoice-stock-line">
            <select name="stock_product_id[]"><option value="">Ürün seç</option><?php foreach($stockProducts as $stockProduct): ?><option value="<?php echo e($stockProduct['id']); ?>" <?php echo (string)($stockLine['product_id'] ?? '')===(string)$stockProduct['id']?'selected':''; ?>><?php echo e($stockProduct['article_code'].($stockProduct['barcode'] ? ' · '.$stockProduct['barcode'] : '').' · '.$stockProduct['product_name'].' · '.number_format((float)$stockProduct['stock_dozen'],2,',','.').' DZ'); ?></option><?php endforeach; ?></select>
            <input name="stock_quantity_dozen[]" inputmode="decimal" placeholder="Satılan DZ" value="<?php echo e(isset($stockLine['quantity_dozen']) ? str_replace('.', ',', (string)$stockLine['quantity_dozen']) : ''); ?>">
            <button type="button" data-stock-line-remove>×</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary" data-stock-line-add>+ Ürün satırı ekle</button>
        <?php endif; ?>
      </section>

      <label>Açıklama<textarea name="description" rows="3" placeholder="Faturaya ilişkin kısa not..."><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Fatura dosyası <small>PDF veya görsel; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut dosya: <a href="fatura-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Faturayı aç'); ?></a></p><?php endif; ?>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?php echo $edit ? 'Faturayı güncelle' : 'Fatura ekle'; ?></button>
        <?php if ($edit): ?><a class="btn btn-secondary" href="faturalar.php?period=<?php echo e($period); ?>">Vazgeç</a><?php endif; ?>
      </div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Fatura ekleme ve düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Fatura listesi</h3><span><?php echo e(count($rows)); ?> kayıt</span><?php if ($listOnly && can_write()): ?><button type="button" class="btn btn-primary fatura-entry-open" data-fatura-entry-open>+ Fatura Girişi</button><?php endif; ?></div>
    <nav class="fatura-direction-tabs" data-fatura-direction-tabs aria-label="Fatura yönü">
      <a href="<?php echo e($directionUrl('giden')); ?>" class="<?php echo $directionFilter === 'giden' ? 'active' : ''; ?>"><strong>Giden Fatura</strong><small>Bizim kestiğimiz faturalar · <?php echo e((string)$summary['outgoing_count']); ?> kayıt</small></a>
      <a href="<?php echo e($directionUrl('gelen')); ?>" class="<?php echo $directionFilter === 'gelen' ? 'active' : ''; ?>"><strong>Gelen Fatura</strong><small>Bize kesilen faturalar · <?php echo e((string)$summary['incoming_count']); ?> kayıt</small></a>
    </nav>
    <form class="filterbar multi" method="get">
      <input type="hidden" name="period" value="<?php echo e($period); ?>">
      <input name="q" placeholder="Fatura no, gönderen, cari veya açıklama ara" value="<?php echo e($q); ?>">
      <select name="direction">
        <?php foreach(fatura_yonleri() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $directionFilter===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?>
      </select>
      <select name="invoice_type" data-fatura-tur-filter>
        <option value="">Tüm fatura türleri</option>
        <?php foreach($invoiceTypeLabels as $key=>$label): ?><option value="<?php echo e($key); ?>"><?php echo e($label); ?></option><?php endforeach; ?>
        <option value="satis">Satış faturası</option><option value="belirsiz">Türü belirlenmemiş</option>
      </select>
      <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptalleri göster</label>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="fatura-tur-auto-status is-loading" data-fatura-tur-auto-status><strong>Otomatik fatura bilgisi</strong><span>Fatura türü ve gönderen firma bilgileri hazırlanıyor…</span></div>
    <section class="fatura-tur-summary" data-fatura-tur-summary><div class="fatura-tur-summary-head"><strong>Aylık gelen fatura dağılımı</strong><small>Fatura türleri hazırlanıyor…</small></div></section>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih / No</th><th>Yön</th><th>Cari</th><th data-fatura-tur-head>Fatura türü</th><th>Matrah / KDV</th><th class="right">Toplam</th><th>Dosya</th><th>Cari durumu</th><th></th></tr></thead>
        <tbody>
          <?php if(!$rows): ?><tr><td colspan="9" class="empty">Bu dönemde <?php echo $directionFilter === 'gelen' ? 'gelen' : 'giden'; ?> fatura bulunamadı.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): $cancelled=(int)($r['is_cancelled'] ?? 0)===1; $meta=fatura_yonleri()[$r['direction']] ?? fatura_yonleri()['gelen']; ?>
          <tr class="<?php echo $cancelled?'row-cancelled':''; ?>">
            <td><strong><?php echo e(tr_date($r['invoice_date'])); ?></strong><small><?php echo e($r['invoice_no'] ?: 'Fatura #' . $r['id']); ?><?php echo $r['due_date'] ? ' · Vade: ' . e(tr_date($r['due_date'])) : ''; ?></small></td>
            <td><?php if ($cancelled || !can_write()): ?><?php echo $cancelled ? badge('İptal','neutral') : badge($meta['label'], $meta['tone']); ?><?php else: $targetDirection=$r['direction']==='giden'?'gelen':'giden'; ?><div class="fatura-yon-cell"><?php echo badge($meta['label'], $meta['tone']); ?><button type="button" data-fatura-yon-sec="<?php echo e($r['id']); ?>" data-current="<?php echo e($r['direction']); ?>" data-target="<?php echo e($targetDirection); ?>"><?php echo $targetDirection === 'giden' ? 'Giden yap' : 'Gelen yap'; ?></button></div><?php endif; ?></td>
            <td>
              <?php if ($r['cari_id']): ?><a href="cari-detay.php?id=<?php echo e($r['cari_id']); ?>"><?php echo e($r['cari_name']); ?></a><?php elseif (!$cancelled && can_write()): ?><button type="button" class="fatura-cari-sec-btn" data-fatura-cari-sec="<?php echo e($r['id']); ?>"><strong>Cari yok</strong><small>Seç veya otomatik oluştur</small></button><?php else: ?><span class="muted">Cari yok</span><?php endif; ?>
              <?php if ($r['direction'] === 'gelen' && trim((string)($r['issuer_name'] ?? '')) !== ''): ?>
                <small class="fatura-issuer-line"><strong>Gönderen:</strong> <?php echo e($r['issuer_name']); ?></small>
              <?php elseif ($r['direction'] === 'gelen' && fatura_muhtelif_cari_mi($r['cari_name'] ?? '')): ?>
                <small class="fatura-issuer-line muted">Gönderen henüz okunmadı</small>
              <?php endif; ?>
            </td>
            <td data-fatura-tur-cell="<?php echo e($r['id']); ?>"><?php if ($r['direction'] === 'giden'): ?><span class="fatura-tur-static">Satış</span><small>Giden fatura</small><?php elseif (!empty($r['expense_category']) && isset($invoiceTypeLabels[$r['expense_category']])): ?><span class="fatura-tur-auto-loading"><?php echo e($invoiceTypeLabels[$r['expense_category']]); ?></span><?php else: ?><span class="fatura-tur-auto-loading">Hazırlanıyor…</span><?php endif; ?></td>
            <td><?php echo e(fatura_para($r['subtotal'], $r['currency'])); ?><small>KDV: <?php echo e(fatura_para($r['vat_amount'], $r['currency'])); ?></small></td>
            <td class="right"><strong><?php echo e(fatura_para($r['total_amount'], $r['currency'])); ?></strong></td>
            <td><?php if($r['document_path']): ?><a href="fatura-indir.php?id=<?php echo e($r['id']); ?>" target="_blank"><?php echo e($r['document_name'] ?: 'Faturayı aç'); ?></a><?php else: ?>-<?php endif; ?></td>
            <td>
              <?php if($cancelled): ?>
                <?php echo badge('İptal','neutral'); ?>
              <?php elseif(fatura_muhtelif_cari_mi($r['cari_name'] ?? '')): ?>
                <?php echo badge('Cari bakiyesi yok','neutral'); ?><small>Muhtelif kayıt</small>
              <?php elseif(!empty($r['cari_movement_id']) && (int)($r['movement_cancelled'] ?? 0)===0): ?>
                <?php echo badge('Cariye işlendi','success'); ?><small>Hareket #<?php echo e($r['cari_movement_id']); ?></small>
              <?php else: ?>
                <?php echo badge('Bekliyor','warning'); ?>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if(!$cancelled && can_write()): ?>
                <a href="faturalar.php?period=<?php echo e($period); ?>&direction=<?php echo e($directionFilter); ?>&edit=<?php echo e($r['id']); ?>">Düzenle</a>
                <?php if (!fatura_muhtelif_cari_mi($r['cari_name'] ?? '')): ?><form method="post">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="post_cari"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
                  <button><?php echo !empty($r['cari_movement_id']) && (int)($r['movement_cancelled'] ?? 0)===0 ? 'Cariyi güncelle' : 'Cariye işle'; ?></button>
                </form><?php endif; ?>
                <form method="post" onsubmit="return confirm('Fatura ve varsa bağlı cari hareket iptal edilsin mi?');">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal">
                  <button>İptal</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<section class="panel-card fatura-alt-kontroller" data-fatura-alt-kontroller>
  <div class="card-head"><div><h3>Fatura araçları</h3><small>Masraf fişleri ve seyrek kullanılan yardımcı işlemler</small></div></div>
  <div class="fatura-alt-kontrol-body" data-fatura-alt-kontrol-body><div class="toplu-yon-duzelt-panel" data-toplu-yon-panel hidden><div><strong>Son toplu yükleme yön kontrolü</strong><small data-toplu-yon-ozet>Kontrol ediliyor…</small></div><div class="toplu-yon-actions"><button type="button" class="btn btn-secondary" data-toplu-yon="giden">Tamamını giden yap</button><button type="button" class="btn btn-secondary" data-toplu-yon="gelen">Tamamını gelen yap</button></div></div></div>
</section>

<style>
.invoice-stock-box{display:grid;gap:10px;padding:13px;border:1px solid #c9ddcf;border-radius:14px;background:#f5fbf7}.invoice-stock-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.invoice-stock-head div{display:grid;gap:3px}.invoice-stock-head small{color:var(--muted)}.invoice-stock-line{display:grid;grid-template-columns:minmax(220px,1fr) 130px 36px;gap:8px;margin-bottom:8px}.invoice-stock-line select,.invoice-stock-line input{width:100%;min-height:40px;border:1px solid var(--border);border-radius:10px;padding:8px}.invoice-stock-line button{border:0;border-radius:10px;background:#f8eaea;color:#a33f35;font-weight:900}@media(max-width:600px){.invoice-stock-head{align-items:flex-start;flex-direction:column}.invoice-stock-line{grid-template-columns:1fr 110px 36px}}
</style>
<script>
(function(){
  var stockBox=document.querySelector('[data-invoice-stock-box]');
  var direction=document.querySelector('select[name="direction"]');
  if(stockBox&&direction){
    function stockVisibility(){stockBox.hidden=direction.value!=='giden';}
    direction.addEventListener('change',stockVisibility);stockVisibility();
    var lines=stockBox.querySelector('[data-invoice-stock-lines]');
    var add=stockBox.querySelector('[data-stock-line-add]');
    if(add&&lines){
      add.addEventListener('click',function(){var first=lines.querySelector('.invoice-stock-line');if(!first)return;var row=first.cloneNode(true);row.querySelector('select').value='';row.querySelector('input').value='';lines.appendChild(row);});
      lines.addEventListener('click',function(event){var button=event.target.closest('[data-stock-line-remove]');if(!button)return;var rows=lines.querySelectorAll('.invoice-stock-line');if(rows.length>1)button.closest('.invoice-stock-line').remove();else{rows[0].querySelector('select').value='';rows[0].querySelector('input').value='';}});
    }
  }
})();
</script>

<script>
(function(){
  function numberValue(value){
    var text=String(value||'').trim().replace(/\s/g,'');
    if(text.indexOf(',')!==-1) text=text.replace(/\./g,'').replace(',','.');
    var n=parseFloat(text);
    return Number.isFinite(n)?n:0;
  }
  function format(value){
    return Number(value||0).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  }
  var subtotal=document.querySelector('[data-invoice-subtotal]');
  var vat=document.querySelector('[data-invoice-vat]');
  var total=document.querySelector('[data-invoice-total]');
  if(!subtotal||!vat||!total) return;
  function sync(){
    if(total.dataset.preserveTotal==='1'&&total.value.trim()!=='') return;
    if(document.activeElement===total&&total.value.trim()!=='') return;
    var sum=numberValue(subtotal.value)+numberValue(vat.value);
    if(sum>0) total.value=format(sum);
  }
  subtotal.addEventListener('input',sync);
  vat.addEventListener('input',sync);
  total.addEventListener('input',function(event){
    if(!event.isTrusted) return;
    if(total.value.trim()!=='') total.dataset.preserveTotal='1';
    else delete total.dataset.preserveTotal;
  });
})();
</script>
<?php page_footer(); ?>
