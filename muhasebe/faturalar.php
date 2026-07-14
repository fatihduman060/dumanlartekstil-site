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
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_date ON invoices(invoice_date)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_cari ON invoices(cari_id)");
db()->exec("CREATE INDEX IF NOT EXISTS idx_invoices_movement ON invoices(cari_movement_id)");

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

function fatura_para_birimi($value): string
{
    $value = strtoupper(trim((string)$value));
    return isset(fatura_para_birimleri()[$value]) ? $value : 'TL';
}

function fatura_para($amount, string $currency = 'TL'): string
{
    return number_format((float)$amount, 2, ',', '.') . ' ' . fatura_para_birimi($currency);
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

        if ($invalidInvoiceNo || $invoiceDate === '' || $totalAmount <= 0 || $subtotal < 0 || $vatAmount < 0) {
            flash('error', 'Fatura numarası, tarihi ve tutarları kontrol etmelisin.');
            redirect('faturalar.php' . ($id > 0 ? '?edit=' . $id : ''));
        }

        $oldRow = $id > 0 ? fatura_getir($id) : null;
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
                total_amount=?, currency=?, description=?, document_path=?, document_name=?, document_mime=?, updated_at=?
                WHERE id=?")
                ->execute([
                    $direction, $cariId, $invoiceNo, $invoiceDate, $dueDate, $subtotal, $vatAmount,
                    $totalAmount, $currency, $description, $doc['path'], $doc['name'], $doc['mime'], now(), $id
                ]);
            delete_replaced_upload($oldDoc, $doc);
            $saved = fatura_getir($id);
            if ($saved && !empty($saved['cari_movement_id']) && fatura_hareket_acik_mi((int)$saved['cari_movement_id'])) {
                fatura_hareket_guncelle((int)$saved['cari_movement_id'], $saved);
            }
            log_action('Fatura güncellendi', ($invoiceNo ?: '#' . $id) . ' ' . fatura_para($totalAmount, $currency));
            audit_action('fatura', $id, 'guncellendi', $oldRow, $saved, $invoiceNo ?: '#' . $id);
            flash('success', 'Fatura güncellendi. Cariye işlenmişse bağlı hareket de güncellendi.');
        } else {
            db()->prepare("INSERT INTO invoices (
                direction, cari_id, invoice_no, invoice_date, due_date, subtotal, vat_amount, total_amount,
                currency, description, document_path, document_name, document_mime,
                created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $direction, $cariId, $invoiceNo, $invoiceDate, $dueDate, $subtotal, $vatAmount, $totalAmount,
                    $currency, $description, $doc['path'], $doc['name'], $doc['mime'],
                    current_user()['id'] ?? null, now(), now()
                ]);
            $newId = (int)db()->lastInsertId();
            $saved = fatura_getir($newId);
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

$period = trim((string)($_GET['period'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
$periodStart = $period . '-01';
$periodEnd = date('Y-m-t', strtotime($periodStart));

$summaryStmt = db()->prepare("SELECT
    COALESCE(SUM(CASE WHEN direction='gelen' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS incoming_vat,
    COALESCE(SUM(CASE WHEN direction='giden' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS outgoing_vat,
    COALESCE(SUM(CASE WHEN currency='TL' THEN total_amount ELSE 0 END),0) AS total_tl,
    COUNT(*) AS invoice_count
    FROM invoices
    WHERE COALESCE(is_cancelled,0)=0 AND invoice_date BETWEEN ? AND ?");
$summaryStmt->execute([$periodStart, $periodEnd]);
$summary = $summaryStmt->fetch() ?: ['incoming_vat'=>0,'outgoing_vat'=>0,'total_tl'=>0,'invoice_count'=>0];
$incomingVat = (float)$summary['incoming_vat'];
$outgoingVat = (float)$summary['outgoing_vat'];
$vatNet = $outgoingVat - $incomingVat;
$vatNetLabel = $vatNet > 0.009 ? 'Tahmini ödenecek KDV' : ($vatNet < -0.009 ? 'Tahmini devreden KDV' : 'KDV dengede');
$vatNetTone = $vatNet > 0.009 ? 'text-danger' : 'text-success';

$q = trim((string)($_GET['q'] ?? ''));
$directionFilter = trim((string)($_GET['direction'] ?? ''));
$includeCancelled = isset($_GET['include_cancelled']);
$where = ['i.invoice_date BETWEEN ? AND ?'];
$params = [$periodStart, $periodEnd];
if (!$includeCancelled) $where[] = 'COALESCE(i.is_cancelled,0)=0';
if ($directionFilter !== '' && isset(fatura_yonleri()[$directionFilter])) {
    $where[] = 'i.direction=?';
    $params[] = $directionFilter;
}
if ($q !== '') {
    $where[] = '(i.invoice_no LIKE ? OR i.description LIKE ? OR i.document_name LIKE ? OR c.name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$sql = "SELECT i.*, c.name AS cari_name, m.is_cancelled AS movement_cancelled
    FROM invoices i
    LEFT JOIN cariler c ON c.id=i.cari_id
    LEFT JOIN movements m ON m.id=i.cari_movement_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.invoice_date DESC, i.id DESC
    LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

page_header('Faturalar', 'faturalar');
?>
<section class="dashboard-section">
  <div class="dashboard-section-head">
    <div><span>Fatura ve KDV Takibi</span><h3><?php echo e(date('m.Y', strtotime($periodStart))); ?> dönemi</h3></div>
    <p>Gelen ve giden faturaların basit KDV görünümü. Resmî KDV beyannamesi yerine geçmez.</p>
  </div>
  <form class="filterbar" method="get">
    <input type="month" name="period" value="<?php echo e($period); ?>">
    <button class="btn btn-secondary" type="submit">Dönemi göster</button>
  </form>
  <div class="stats-grid four section-stats">
    <article class="stat-card soft"><span>İndirilecek KDV</span><strong class="text-success"><?php echo e(fatura_para($incomingVat)); ?></strong><small>Gelen faturaların KDV'si</small></article>
    <article class="stat-card soft"><span>Hesaplanan KDV</span><strong class="text-danger"><?php echo e(fatura_para($outgoingVat)); ?></strong><small>Giden faturaların KDV'si</small></article>
    <article class="stat-card status"><span>KDV durumu</span><strong class="<?php echo e($vatNetTone); ?>"><?php echo e(fatura_para(abs($vatNet))); ?></strong><small><?php echo e($vatNetLabel); ?></small></article>
    <article class="stat-card soft"><span>Fatura toplamı</span><strong><?php echo e(fatura_para($summary['total_tl'])); ?></strong><small><?php echo e((string)$summary['invoice_count']); ?> kayıt · yalnızca TL</small></article>
  </div>
  <p class="calc-note"><strong>KDV durumu</strong> = hesaplanan KDV - indirilecek KDV. Tevkifat, istisna, iade ve önceki dönem devri bu ilk taslakta hesaba katılmaz.</p>
</section>

<section class="form-grid">
  <article class="panel-card form-card">
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
    <div class="card-head"><h3>Fatura listesi</h3><span><?php echo e(count($rows)); ?> kayıt</span></div>
    <form class="filterbar multi" method="get">
      <input type="hidden" name="period" value="<?php echo e($period); ?>">
      <input name="q" placeholder="Fatura no, cari veya açıklama ara" value="<?php echo e($q); ?>">
      <select name="direction">
        <option value="">Gelen + giden</option>
        <?php foreach(fatura_yonleri() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $directionFilter===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?>
      </select>
      <label class="check tiny"><input type="checkbox" name="include_cancelled" value="1" <?php echo $includeCancelled?'checked':''; ?>> İptalleri göster</label>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih / No</th><th>Yön</th><th>Cari</th><th>Matrah / KDV</th><th class="right">Toplam</th><th>Dosya</th><th>Cari durumu</th><th></th></tr></thead>
        <tbody>
          <?php if(!$rows): ?><tr><td colspan="8" class="empty">Bu dönemde fatura bulunamadı.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): $cancelled=(int)($r['is_cancelled'] ?? 0)===1; $meta=fatura_yonleri()[$r['direction']] ?? fatura_yonleri()['gelen']; ?>
          <tr class="<?php echo $cancelled?'row-cancelled':''; ?>">
            <td><strong><?php echo e(tr_date($r['invoice_date'])); ?></strong><small><?php echo e($r['invoice_no'] ?: 'Fatura #' . $r['id']); ?><?php echo $r['due_date'] ? ' · Vade: ' . e(tr_date($r['due_date'])) : ''; ?></small></td>
            <td><?php echo $cancelled ? badge('İptal','neutral') : badge($meta['label'], $meta['tone']); ?></td>
            <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id='.e($r['cari_id']).'">'.e($r['cari_name']).'</a>' : '<span class="muted">Cari yok</span>'; ?></td>
            <td><?php echo e(fatura_para($r['subtotal'], $r['currency'])); ?><small>KDV: <?php echo e(fatura_para($r['vat_amount'], $r['currency'])); ?></small></td>
            <td class="right"><strong><?php echo e(fatura_para($r['total_amount'], $r['currency'])); ?></strong></td>
            <td><?php if($r['document_path']): ?><a href="fatura-indir.php?id=<?php echo e($r['id']); ?>" target="_blank"><?php echo e($r['document_name'] ?: 'Faturayı aç'); ?></a><?php else: ?>-<?php endif; ?></td>
            <td>
              <?php if($cancelled): ?>
                <?php echo badge('İptal','neutral'); ?>
              <?php elseif(!empty($r['cari_movement_id']) && (int)($r['movement_cancelled'] ?? 0)===0): ?>
                <?php echo badge('Cariye işlendi','success'); ?><small>Hareket #<?php echo e($r['cari_movement_id']); ?></small>
              <?php else: ?>
                <?php echo badge('Bekliyor','warning'); ?>
              <?php endif; ?>
            </td>
            <td class="row-actions">
              <?php if(!$cancelled && can_write()): ?>
                <a href="faturalar.php?period=<?php echo e($period); ?>&edit=<?php echo e($r['id']); ?>">Düzenle</a>
                <form method="post">
                  <?php echo csrf_field(); ?><input type="hidden" name="action" value="post_cari"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
                  <button><?php echo !empty($r['cari_movement_id']) && (int)($r['movement_cancelled'] ?? 0)===0 ? 'Cariyi güncelle' : 'Cariye işle'; ?></button>
                </form>
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
