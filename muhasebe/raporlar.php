<?php
require_once __DIR__ . '/layout.php';
require_login();

/*
 * Dumanlar A.Ş. Yönetim Merkezi
 * Bu sayfa yalnızca mevcut SQLite tablolarını okur. INSERT/UPDATE/DELETE/DDL içermez.
 * Farklı kurulum sürümlerinde tablo veya alan bulunmadığında kart hata vermek yerine
 * veri kaynağını ve eksik olan parçayı açıkça gösterir.
 */

function ym_ident($name)
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$name) ? '"' . $name . '"' : null;
}

function ym_tables(PDO $pdo)
{
    static $tables = null;
    if ($tables !== null) return $tables;
    $tables = array();
    try {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
        foreach ($rows as $row) $tables[(string)$row['name']] = true;
    } catch (Throwable $e) {
        $tables = array();
    }
    return $tables;
}

function ym_columns(PDO $pdo, $table)
{
    static $cache = array();
    if (isset($cache[$table])) return $cache[$table];
    $cache[$table] = array();
    $ident = ym_ident($table);
    if ($ident === null || !isset(ym_tables($pdo)[$table])) return $cache[$table];
    try {
        $rows = $pdo->query('PRAGMA table_info(' . $ident . ')')->fetchAll();
        foreach ($rows as $row) $cache[$table][(string)$row['name']] = true;
    } catch (Throwable $e) {
        $cache[$table] = array();
    }
    return $cache[$table];
}

function ym_first_table(PDO $pdo, $candidates)
{
    $tables = ym_tables($pdo);
    foreach ($candidates as $candidate) if (isset($tables[$candidate])) return $candidate;
    return null;
}

function ym_first_column($columns, $candidates)
{
    foreach ($candidates as $candidate) if (isset($columns[$candidate])) return $candidate;
    return null;
}

function ym_safe_sum(PDO $pdo, $table, $amountColumn, $dateColumn, $start, $end, $whereSql, $params)
{
    $tableIdent = ym_ident($table);
    $amountIdent = ym_ident($amountColumn);
    $dateIdent = $dateColumn ? ym_ident($dateColumn) : null;
    if ($tableIdent === null || $amountIdent === null || ($dateColumn && $dateIdent === null)) return null;
    $sql = 'SELECT COALESCE(SUM(CAST(' . $amountIdent . ' AS REAL)),0) FROM ' . $tableIdent;
    $parts = array();
    $queryParams = array();
    if ($dateColumn) {
        $parts[] = $dateIdent . ' BETWEEN ? AND ?';
        $queryParams[] = $start;
        $queryParams[] = $end;
    }
    if ($whereSql !== '') {
        $parts[] = '(' . $whereSql . ')';
        foreach ($params as $param) $queryParams[] = $param;
    }
    if ($parts) $sql .= ' WHERE ' . implode(' AND ', $parts);
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($queryParams);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function ym_metric($label, $value, $source, $missing, $tone)
{
    return array('label'=>$label, 'value'=>$value, 'source'=>$source, 'missing'=>$missing, 'tone'=>$tone);
}

function ym_money_or_missing($value)
{
    return $value === null ? 'Eksik veri' : money((float)$value);
}

function ym_render_card($metric)
{
    $ready = $metric['value'] !== null;
    $tone = $ready ? $metric['tone'] : 'missing';
    ?>
    <article class="ym-card ym-<?php echo e($tone); ?>">
      <span class="ym-card-label"><?php echo e($metric['label']); ?></span>
      <strong><?php echo e(ym_money_or_missing($metric['value'])); ?></strong>
      <small><b>Bağlı veri:</b> <?php echo e($metric['source']); ?></small>
      <small class="ym-state"><b><?php echo $ready ? 'Durum:' : 'Eksik:'; ?></b> <?php echo e($ready ? 'Veri güvenle okunuyor' : $metric['missing']); ?></small>
    </article>
    <?php
}

function ym_render_section($title, $description, $metrics, $tag)
{
    ?>
    <section class="panel-card ym-section">
      <div class="card-head ym-section-head"><div><h3><?php echo e($title); ?></h3><p><?php echo e($description); ?></p></div><span><?php echo e($tag); ?></span></div>
      <div class="ym-card-grid"><?php foreach ($metrics as $metric) ym_render_card($metric); ?></div>
    </section>
    <?php
}

$pdo = db();
$currentYear = (int)date('Y');
$year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
if ($year < 2000 || $year > 2100) $year = $currentYear;
$yearStart = sprintf('%04d-01-01', $year);
$yearEnd = sprintf('%04d-12-31', $year);

$missingItems = array();
$dataSets = array();

/* Cari ve temel finans hareketleri */
$movementTable = ym_first_table($pdo, array('movements', 'cari_hareketleri', 'cari_movements'));
$movementColumns = $movementTable ? ym_columns($pdo, $movementTable) : array();
$movementAmount = ym_first_column($movementColumns, array('amount', 'tutar', 'total'));
$movementDate = ym_first_column($movementColumns, array('movement_date', 'tarih', 'date', 'created_at'));
$movementType = ym_first_column($movementColumns, array('movement_type', 'hareket_turu', 'type'));
$movementReady = $movementTable && $movementAmount && $movementDate && $movementType;
$dataSets['Cari hareketleri'] = $movementReady;
$receivable = $payable = $collection = $payment = null;
if ($movementReady) {
    $typeIdent = ym_ident($movementType);
    $cancelSql = isset($movementColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0 AND ' : '';
    $receivable = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('alacak'));
    $payable = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('verecek'));
    $collection = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('tahsilat'));
    $payment = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('odeme'));
} else {
    $missingItems[] = 'Cari Analizi: hareket tablosu ile tutar, tarih ve hareket türü alanları birlikte bulunamadı.';
}
$netReceivable = ($receivable === null || $collection === null) ? null : max(0, $receivable - $collection);
$netPayable = ($payable === null || $payment === null) ? null : max(0, $payable - $payment);

/* Kasa ve banka */
$accountTable = ym_first_table($pdo, array('accounts', 'hesaplar'));
$transactionTable = ym_first_table($pdo, array('account_transactions', 'hesap_hareketleri'));
$accountColumns = $accountTable ? ym_columns($pdo, $accountTable) : array();
$transactionColumns = $transactionTable ? ym_columns($pdo, $transactionTable) : array();
$accountsReady = $accountTable && isset($accountColumns['id']) && isset($accountColumns['account_type']) && isset($accountColumns['opening_balance']);
$transactionsReady = $transactionTable && isset($transactionColumns['account_id']) && isset($transactionColumns['direction']) && isset($transactionColumns['amount']);
$cashTotal = $bankTotal = null;
if ($accountsReady) {
    try {
        $activeSql = isset($accountColumns['is_active']) ? ' AND COALESCE(a."is_active",1)=1' : '';
        $join = '';
        $balance = 'CAST(a."opening_balance" AS REAL)';
        if ($transactionsReady) {
            $join = ' LEFT JOIN ' . ym_ident($transactionTable) . ' t ON t."account_id"=a."id"';
            $balance .= '+COALESCE(SUM(CASE WHEN t."direction"=\'in\' THEN CAST(t."amount" AS REAL) WHEN t."direction"=\'out\' THEN -CAST(t."amount" AS REAL) ELSE 0 END),0)';
        }
        $sql = 'SELECT a."account_type" AS type, COALESCE(SUM(balance),0) total FROM (SELECT a."id",a."account_type",(' . $balance . ') balance FROM ' . ym_ident($accountTable) . ' a' . $join . ' WHERE 1=1' . $activeSql . ' GROUP BY a."id") a GROUP BY a."account_type"';
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            if ($row['type'] === 'kasa') $cashTotal = (float)$row['total'];
            if ($row['type'] === 'banka') $bankTotal = (float)$row['total'];
        }
        if ($cashTotal === null) $cashTotal = 0.0;
        if ($bankTotal === null) $bankTotal = 0.0;
    } catch (Throwable $e) {
        $cashTotal = $bankTotal = null;
    }
}
$dataSets['Kasa / banka'] = $cashTotal !== null && $bankTotal !== null;
if (!$dataSets['Kasa / banka']) $missingItems[] = 'Nakit/Banka Analizi: hesap türü, açılış bakiyesi veya hesap hareketi alanları eksik.';

/* Çekler */
$checkTable = ym_first_table($pdo, array('checks', 'cekler'));
$checkColumns = $checkTable ? ym_columns($pdo, $checkTable) : array();
$checkAmount = ym_first_column($checkColumns, array('amount', 'tutar'));
$checkDate = ym_first_column($checkColumns, array('due_date', 'issue_date', 'tarih'));
$checkDirection = ym_first_column($checkColumns, array('direction', 'yon', 'type'));
$checksReady = $checkTable && $checkAmount && $checkDate && $checkDirection;
$incomingChecks = $outgoingChecks = null;
if ($checksReady) {
    $dirIdent = ym_ident($checkDirection);
    $cancelSql = isset($checkColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0 AND ' : '';
    $incomingChecks = ym_safe_sum($pdo, $checkTable, $checkAmount, $checkDate, $yearStart, $yearEnd, $cancelSql . $dirIdent . '=?', array('alinacak'));
    $outgoingChecks = ym_safe_sum($pdo, $checkTable, $checkAmount, $checkDate, $yearStart, $yearEnd, $cancelSql . $dirIdent . '=?', array('verilecek'));
}
$dataSets['Çekler'] = $checksReady;
if (!$checksReady) $missingItems[] = 'Finansal Durum: çek tablosu veya tutar/vade/yön alanı eksik.';

/* Faturalar, satış/alış ve KDV */
$invoiceTable = ym_first_table($pdo, array('invoices', 'faturalar', 'invoice_records', 'fatura_kayitlari'));
$invoiceColumns = $invoiceTable ? ym_columns($pdo, $invoiceTable) : array();
$invoiceAmount = ym_first_column($invoiceColumns, array('grand_total', 'general_total', 'total_amount', 'total', 'toplam', 'genel_toplam', 'tutar'));
$invoiceDate = ym_first_column($invoiceColumns, array('invoice_date', 'fatura_tarihi', 'date', 'tarih', 'created_at'));
$invoiceDirection = ym_first_column($invoiceColumns, array('direction', 'invoice_type', 'type', 'yon', 'fatura_turu'));
$invoiceVat = ym_first_column($invoiceColumns, array('vat_total', 'kdv_total', 'kdv_toplam', 'total_vat', 'kdv'));
$invoiceReady = $invoiceTable && $invoiceAmount && $invoiceDate && $invoiceDirection;
$salesTotal = $purchaseTotal = $salesVat = $purchaseVat = null;
if ($invoiceReady) {
    $dir = ym_ident($invoiceDirection);
    $salesWhere = 'LOWER(COALESCE(CAST(' . $dir . ' AS TEXT),\'\')) IN (\'satis\',\'satış\',\'sales\',\'out\',\'giden\')';
    $purchaseWhere = 'LOWER(COALESCE(CAST(' . $dir . ' AS TEXT),\'\')) IN (\'alis\',\'alış\',\'purchase\',\'in\',\'gelen\')';
    $salesTotal = ym_safe_sum($pdo, $invoiceTable, $invoiceAmount, $invoiceDate, $yearStart, $yearEnd, $salesWhere, array());
    $purchaseTotal = ym_safe_sum($pdo, $invoiceTable, $invoiceAmount, $invoiceDate, $yearStart, $yearEnd, $purchaseWhere, array());
    if ($invoiceVat) {
        $salesVat = ym_safe_sum($pdo, $invoiceTable, $invoiceVat, $invoiceDate, $yearStart, $yearEnd, $salesWhere, array());
        $purchaseVat = ym_safe_sum($pdo, $invoiceTable, $invoiceVat, $invoiceDate, $yearStart, $yearEnd, $purchaseWhere, array());
    }
}
$dataSets['Fatura satış / alış'] = $invoiceReady;
$dataSets['KDV özeti'] = $invoiceReady && $invoiceVat;
if (!$invoiceReady) $missingItems[] = 'Satış Analizi: fatura tablosu ile toplam, tarih ve alış/satış yönü alanları bulunamadı.';
if (!($invoiceReady && $invoiceVat)) $missingItems[] = 'Mali Tablolar: faturalarda KDV toplam alanı bulunamadı.';

/* Üretim */
$productionTable = ym_first_table($pdo, array('production_records', 'uretim_kayitlari', 'production', 'uretim_takibi', 'uretim'));
$productionColumns = $productionTable ? ym_columns($pdo, $productionTable) : array();
$productionAmount = ym_first_column($productionColumns, array('quantity', 'miktar', 'total_quantity', 'uretim_miktari', 'adet', 'kg'));
$productionDate = ym_first_column($productionColumns, array('production_date', 'uretim_tarihi', 'date', 'tarih', 'created_at'));
$productionTotal = ($productionTable && $productionAmount && $productionDate) ? ym_safe_sum($pdo, $productionTable, $productionAmount, $productionDate, $yearStart, $yearEnd, '', array()) : null;
$dataSets['Üretim'] = $productionTotal !== null;
if ($productionTotal === null) $missingItems[] = 'Üretim Analizi: üretim tablosu veya miktar/tarih alanı eksik.';

/* Stok */
$stockTable = ym_first_table($pdo, array('stock_movements', 'stok_hareketleri', 'stocks', 'stoklar', 'products', 'urunler'));
$stockColumns = $stockTable ? ym_columns($pdo, $stockTable) : array();
$stockAmount = ym_first_column($stockColumns, array('current_stock', 'stock_quantity', 'quantity', 'miktar', 'stok', 'adet'));
$stockDate = ym_first_column($stockColumns, array('movement_date', 'date', 'tarih', 'created_at'));
$stockTotal = ($stockTable && $stockAmount) ? ym_safe_sum($pdo, $stockTable, $stockAmount, $stockDate, $yearStart, $yearEnd, '', array()) : null;
$dataSets['Stok'] = $stockTotal !== null;
if ($stockTotal === null) $missingItems[] = 'Stok Analizi: stok tablosu veya mevcut miktar alanı eksik.';

/* Kart ekstreleri */
$cardTable = ym_first_table($pdo, array('card_statements', 'credit_card_statements', 'kart_ekstreleri', 'kart_ekstre', 'kredi_karti_ekstreleri'));
$cardColumns = $cardTable ? ym_columns($pdo, $cardTable) : array();
$cardAmount = ym_first_column($cardColumns, array('remaining_amount', 'balance', 'amount', 'total_amount', 'tutar', 'toplam'));
$cardDate = ym_first_column($cardColumns, array('statement_date', 'due_date', 'date', 'tarih', 'created_at'));
$cardTotal = ($cardTable && $cardAmount && $cardDate) ? ym_safe_sum($pdo, $cardTable, $cardAmount, $cardDate, $yearStart, $yearEnd, '', array()) : null;
$dataSets['Kart ekstreleri'] = $cardTotal !== null;
if ($cardTotal === null) $missingItems[] = 'Kart Analizi: kart ekstresi tablosu veya tutar/tarih alanı eksik.';

/* SGK / SSK ve vergiler */
$taxTable = ym_first_table($pdo, array('tax_payments', 'vergi_odemeleri'));
$taxColumns = $taxTable ? ym_columns($pdo, $taxTable) : array();
$taxReady = $taxTable
    && isset($taxColumns['tax_type'])
    && isset($taxColumns['amount'])
    && isset($taxColumns['status'])
    && isset($taxColumns['due_date'])
    && isset($taxColumns['paid_date']);
$pendingSgk = $paidSgk = $pendingTaxes = $paidTaxes = null;
if ($taxReady) {
    $sgkWhere = "(LOWER(COALESCE(\"tax_type\",'')) LIKE '%sgk%' OR LOWER(COALESCE(\"tax_type\",'')) LIKE '%ssk%' OR LOWER(COALESCE(\"tax_type\",'')) LIKE '%sosyal güvenlik%')";
    $otherTaxWhere = 'NOT ' . $sgkWhere;
    $pendingSgk = ym_safe_sum($pdo, $taxTable, 'amount', 'due_date', $yearStart, $yearEnd, '"status"=? AND ' . $sgkWhere, array('bekliyor'));
    $paidSgk = ym_safe_sum($pdo, $taxTable, 'amount', 'paid_date', $yearStart, $yearEnd, '"status"=? AND ' . $sgkWhere, array('odendi'));
    $pendingTaxes = ym_safe_sum($pdo, $taxTable, 'amount', 'due_date', $yearStart, $yearEnd, '"status"=? AND ' . $otherTaxWhere, array('bekliyor'));
    $paidTaxes = ym_safe_sum($pdo, $taxTable, 'amount', 'paid_date', $yearStart, $yearEnd, '"status"=? AND ' . $otherTaxWhere, array('odendi'));
}
$dataSets['SGK / SSK ve vergiler'] = $taxReady;
if (!$taxReady) $missingItems[] = 'SGK / SSK ve Vergiler: vergi ödeme tablosu veya tür, tutar, durum ve tarih alanları eksik.';

$readyCount = 0;
foreach ($dataSets as $isReady) if ($isReady) $readyCount++;
$readiness = count($dataSets) ? (int)round(($readyCount / count($dataSets)) * 100) : 0;
$netCashFlow = ($collection === null || $payment === null) ? null : $collection - $payment;
$financialPosition = ($netReceivable === null || $netPayable === null) ? null : $netReceivable - $netPayable;
$grossProfit = ($salesTotal === null || $purchaseTotal === null) ? null : $salesTotal - $purchaseTotal;
$vatPosition = ($salesVat === null || $purchaseVat === null) ? null : $salesVat - $purchaseVat;

$srcMovement = $movementTable ?: 'hareket tablosu bulunamadı';
$srcAccounts = $accountTable ? $accountTable . ($transactionTable ? ' + ' . $transactionTable : '') : 'hesap tablosu bulunamadı';
$srcChecks = $checkTable ?: 'çek tablosu bulunamadı';
$srcInvoices = $invoiceTable ?: 'fatura tablosu bulunamadı';
$srcProduction = $productionTable ?: 'üretim tablosu bulunamadı';
$srcStock = $stockTable ?: 'stok tablosu bulunamadı';
$srcCards = $cardTable ?: 'kart ekstresi tablosu bulunamadı';
$srcTaxes = $taxTable ?: 'vergi ödeme tablosu bulunamadı';

page_header('Dumanlar A.Ş. Yönetim Merkezi', 'raporlar');
?>
<style>
.ym-hero{margin-bottom:18px;background:linear-gradient(135deg,#fff,#fff7ea);display:grid;grid-template-columns:1fr auto;gap:22px;align-items:center}.ym-hero h2{margin:0 0 8px;font-size:30px;letter-spacing:-.045em}.ym-hero p,.ym-section-head p{margin:0;color:var(--muted);font-size:13px;font-weight:750}.ym-tools{display:flex;align-items:end;gap:12px}.ym-tools label{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:900}.ym-progress{min-width:180px}.ym-progress-line{height:10px;border-radius:999px;background:#eeeae1;overflow:hidden;margin:7px 0}.ym-progress-line i{display:block;height:100%;background:linear-gradient(90deg,var(--warning),var(--success));border-radius:999px}.ym-section{margin-top:18px}.ym-section-head{align-items:flex-start}.ym-section-head h3{margin-bottom:5px}.ym-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ym-card{border:1px solid var(--border);border-left:4px solid var(--accent);border-radius:17px;padding:17px;background:#fff;min-width:0}.ym-card-label{display:block;color:var(--muted);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.ym-card strong{display:block;font-size:24px;margin:9px 0;letter-spacing:-.035em}.ym-card small{display:block;color:var(--muted);line-height:1.4;margin-top:5px;overflow-wrap:anywhere}.ym-card small b{color:var(--text)}.ym-success{border-left-color:var(--success)}.ym-danger{border-left-color:var(--danger)}.ym-info{border-left-color:var(--accent)}.ym-missing{border-left-color:var(--warning);background:#fffaf0}.ym-missing strong{font-size:20px;color:#835710}.ym-state{padding-top:6px;border-top:1px dashed var(--border)}.ym-missing-list,.ym-status-list{display:grid;gap:10px}.ym-list-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:14px;background:#fff}.ym-list-row span{font-weight:800}.ym-list-row small{color:var(--muted)}.ym-ok{color:var(--success);font-weight:900}.ym-warn{color:#835710;font-weight:900}.ym-empty-ok{padding:16px;border-radius:14px;background:#f3fbf5;color:var(--success);font-weight:900}.ym-footnote{margin:18px 2px;color:var(--muted);font-size:12px;font-weight:750}@media(max-width:1100px){.ym-hero{grid-template-columns:1fr}.ym-card-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.ym-tools{align-items:stretch;flex-direction:column}.ym-card-grid{grid-template-columns:1fr}.ym-list-row{flex-direction:column}.ym-hero h2{font-size:25px}}
</style>

<section class="panel-card ym-hero">
  <div><h2>Dumanlar A.Ş. Yönetim Merkezi</h2><p>Şirketin mevcut kayıtlarını değiştirmeden okuyan ilk aşama yönetim kokpiti. Her kart kendi veri kaynağını ve varsa eksiğini gösterir.</p></div>
  <div class="ym-tools">
    <form method="get"><label>Rapor yılı<select name="year" onchange="this.form.submit()"><?php for ($y=$currentYear+1; $y>=$currentYear-5; $y--): ?><option value="<?php echo e($y); ?>" <?php echo $y===$year?'selected':''; ?>><?php echo e($y); ?></option><?php endfor; ?></select></label></form>
    <div class="ym-progress"><strong>Veri hazırlığı: %<?php echo e($readiness); ?></strong><div class="ym-progress-line"><i style="width:<?php echo e($readiness); ?>%"></i></div><small><?php echo e($readyCount); ?>/<?php echo e(count($dataSets)); ?> veri grubu hazır</small></div>
  </div>
</section>

<?php
ym_render_section('Finansal Durum', $year . ' yılı cari ve çek pozisyonu.', array(
    ym_metric('Net cari alacağı', $netReceivable, $srcMovement, 'Cari hareket tablosu/alanları eksik.', 'success'),
    ym_metric('Net cari borcu', $netPayable, $srcMovement, 'Cari hareket tablosu/alanları eksik.', 'danger'),
    ym_metric('Finansal pozisyon', $financialPosition, $srcMovement, 'Alacak ve borç birlikte hesaplanamıyor.', $financialPosition !== null && $financialPosition < 0 ? 'danger' : 'info'),
    ym_metric('Alınacak çekler', $incomingChecks, $srcChecks, 'Çek tutarı, vadesi veya yönü eksik.', 'success'),
    ym_metric('Verilecek çekler', $outgoingChecks, $srcChecks, 'Çek tutarı, vadesi veya yönü eksik.', 'danger')
), 'Salt okunur');

ym_render_section('Nakit Durumu', 'Kasa, banka ve seçili yıl tahsilat/ödeme görünümü.', array(
    ym_metric('Kasa toplamı', $cashTotal, $srcAccounts, 'Hesap türü/açılış bakiyesi alanları eksik.', 'info'),
    ym_metric('Banka toplamı', $bankTotal, $srcAccounts, 'Hesap türü/açılış bakiyesi alanları eksik.', 'info'),
    ym_metric('Yıllık nakit neti', $netCashFlow, $srcMovement, 'Tahsilat ve ödeme hareketleri okunamıyor.', $netCashFlow !== null && $netCashFlow < 0 ? 'danger' : 'success')
), $year . ' dönemi');

ym_render_section('Cari Analizi', 'Cari hareketlerinin brüt ve net özeti.', array(
    ym_metric('Brüt alacak', $receivable, $srcMovement, 'Alacak hareketleri okunamıyor.', 'success'),
    ym_metric('Tahsilat', $collection, $srcMovement, 'Tahsilat hareketleri okunamıyor.', 'info'),
    ym_metric('Brüt borç', $payable, $srcMovement, 'Verecek hareketleri okunamıyor.', 'danger'),
    ym_metric('Ödeme', $payment, $srcMovement, 'Ödeme hareketleri okunamıyor.', 'info')
), 'Cari kayıtları');

ym_render_section('Satış Analizi', 'Fatura kayıtlarından yıllık satış ve alış toplamları.', array(
    ym_metric('Fatura satış toplamı', $salesTotal, $srcInvoices, 'Fatura toplamı, tarihi veya yön alanı eksik.', 'success'),
    ym_metric('Fatura alış toplamı', $purchaseTotal, $srcInvoices, 'Fatura toplamı, tarihi veya yön alanı eksik.', 'danger')
), 'Fatura verisi');

ym_render_section('Üretim Analizi', 'Mevcut üretim kayıtlarının seçili yıl toplamı.', array(
    ym_metric('Toplam üretim', $productionTotal, $srcProduction, 'Üretim miktarı veya tarih alanı eksik.', 'info')
), 'Üretim verisi');

ym_render_section('Stok Analizi', 'Mevcut stok veya stok hareketi kayıtlarının toplam miktarı.', array(
    ym_metric('Toplam stok miktarı', $stockTotal, $srcStock, 'Stok miktarı alanı veya stok tablosu eksik.', 'info')
), 'Stok verisi');

ym_render_section('Banka Analizi', 'Aktif banka hesaplarının açılış ve hareket bakiyeleri.', array(
    ym_metric('Banka bakiyesi', $bankTotal, $srcAccounts, 'Banka hesapları güvenle hesaplanamıyor.', 'info')
), 'Kasa / Banka');

ym_render_section('Kart Analizi', 'Kart ekstrelerinin seçili yıl toplam görünümü.', array(
    ym_metric('Kart ekstre toplamı', $cardTotal, $srcCards, 'Kart ekstresi tutar veya tarih alanı eksik.', 'danger')
), 'Kart ekstreleri');

ym_render_section('SGK / SSK ve Vergiler', 'Vergi Ödemeleri kayıtlarından seçili yılın bekleyen ve ödenen yükümlülükleri. SGK, SSK ve sosyal güvenlik kayıtları diğer vergilerden ayrı gösterilir.', array(
    ym_metric('Bekleyen SGK / SSK', $pendingSgk, $srcTaxes, 'Vergi türü, tutar, durum veya vade alanı eksik.', 'danger'),
    ym_metric('Ödenen SGK / SSK', $paidSgk, $srcTaxes, 'Vergi türü, tutar, durum veya ödeme tarihi alanı eksik.', 'success'),
    ym_metric('Bekleyen diğer vergiler', $pendingTaxes, $srcTaxes, 'Vergi türü, tutar, durum veya vade alanı eksik.', 'danger'),
    ym_metric('Ödenen diğer vergiler', $paidTaxes, $srcTaxes, 'Vergi türü, tutar, durum veya ödeme tarihi alanı eksik.', 'success')
), 'Vergi Ödemeleri');

ym_render_section('Karlılık Analizi', 'İlk aşamada fatura satış toplamı eksi fatura alış toplamı; genel giderler henüz dahil değildir.', array(
    ym_metric('Brüt ticari fark', $grossProfit, $srcInvoices, 'Satış ve alış faturaları birlikte okunamıyor.', $grossProfit !== null && $grossProfit < 0 ? 'danger' : 'success')
), 'Ön gösterge');

ym_render_section('Mali Tablolar', 'KDV pozisyonu hazır veriden okunur; gelir tablosu, bilanço ve nakit akışı için hesap planı gerekir.', array(
    ym_metric('Hesaplanan KDV', $salesVat, $srcInvoices, 'Faturalarda KDV toplam alanı eksik.', 'danger'),
    ym_metric('İndirilecek KDV', $purchaseVat, $srcInvoices, 'Faturalarda KDV toplam alanı eksik.', 'success'),
    ym_metric('KDV pozisyonu', $vatPosition, $srcInvoices, 'Satış ve alış KDV toplamları birlikte okunamıyor.', $vatPosition !== null && $vatPosition < 0 ? 'success' : 'danger'),
    ym_metric('Gelir tablosu', null, 'Tek düzen hesap planı', 'Hesap planı ve dönem kapanış eşlemesi henüz yok.', 'missing'),
    ym_metric('Bilanço', null, 'Tek düzen hesap planı', 'Varlık/kaynak hesap sınıfları henüz yok.', 'missing'),
    ym_metric('Nakit akış tablosu', null, 'Hareket sınıflandırması', 'İşletme/yatırım/finansman sınıfları henüz yok.', 'missing')
), 'Mali görünüm');
?>

<section class="panel-card ym-section">
  <div class="card-head ym-section-head"><div><h3>Eksik Veriler</h3><p>Kartların gerçek veriye bağlanması için gereken eksikler.</p></div><span><?php echo e(count($missingItems)); ?> bulgu</span></div>
  <?php if (!$missingItems): ?><div class="ym-empty-ok">Hazır veri gruplarında eksik bulunmadı.</div><?php else: ?><div class="ym-missing-list"><?php foreach ($missingItems as $item): ?><div class="ym-list-row"><span><?php echo e($item); ?></span><b class="ym-warn">Eksik veri</b></div><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="panel-card ym-section">
  <div class="card-head ym-section-head"><div><h3>Sistem Hazırlık Durumu</h3><p>Kontrol yalnızca mevcut tablo ve alanlara göre yapılır; hiçbir tablo oluşturulmaz veya değiştirilmez.</p></div><span>%<?php echo e($readiness); ?> hazır</span></div>
  <div class="ym-status-list"><?php foreach ($dataSets as $name=>$isReady): ?><div class="ym-list-row"><span><?php echo e($name); ?></span><b class="<?php echo $isReady?'ym-ok':'ym-warn'; ?>"><?php echo $isReady?'Hazır':'Eksik veri'; ?></b></div><?php endforeach; ?></div>
</section>

<p class="ym-footnote">Güvenlik notu: Bu Yönetim Merkezi yalnızca SELECT ve PRAGMA table_info sorguları çalıştırır. Finansal kayıt eklemez, güncellemez veya silmez; DDL çalıştırmaz. Gösterimler <?php echo e($yearStart); ?> – <?php echo e($yearEnd); ?> dönemine aittir.</p>
<?php page_footer(); ?>
