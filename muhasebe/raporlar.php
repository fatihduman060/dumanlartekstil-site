<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/dashboard-cari-aggregate.php';
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

function ym_metric($label, $value, $source, $missing, $tone, $format = 'money', $detail = null)
{
    return array('label'=>$label, 'value'=>$value, 'source'=>$source, 'missing'=>$missing, 'tone'=>$tone, 'format'=>$format, 'detail'=>$detail);
}

function ym_value_or_missing($metric)
{
    if ($metric['value'] === null) return 'Eksik veri';
    if ($metric['format'] === 'dozen') return number_format((float)$metric['value'], 2, ',', '.') . ' düzine';
    if ($metric['format'] === 'quantity') return number_format((float)$metric['value'], 0, ',', '.') . ' adet';
    return money((float)$metric['value']);
}

function ym_render_card($metric)
{
    $ready = $metric['value'] !== null;
    $tone = $ready ? $metric['tone'] : 'missing';
    ?>
    <article class="ym-card ym-<?php echo e($tone); ?><?php echo !empty($metric['detail']) && $ready ? ' ym-clickable' : ''; ?>"<?php if (!empty($metric['detail']) && $ready): ?> role="button" tabindex="0" data-report-detail="<?php echo e($metric['detail']); ?>" aria-label="<?php echo e($metric['label'] . ' detaylarını aç'); ?>"<?php endif; ?>>
      <span class="ym-card-label"><?php echo e($metric['label']); ?></span>
      <strong><?php echo e(ym_value_or_missing($metric)); ?></strong>
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
$receivable = $payable = $collection = $payment = $otherIncome = null;
if ($movementReady) {
    $typeIdent = ym_ident($movementType);
    $cancelSql = isset($movementColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0 AND ' : '';
    $receivable = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('alacak'));
    $payable = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('verecek'));
    $collection = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('tahsilat'));
    $payment = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('odeme'));
    $otherIncome = ym_safe_sum($pdo, $movementTable, $movementAmount, $movementDate, $yearStart, $yearEnd, $cancelSql . $typeIdent . '=?', array('gelir'));
} else {
    $missingItems[] = 'Cari Analizi: hareket tablosu ile tutar, tarih ve hareket türü alanları birlikte bulunamadı.';
}
$netReceivable = $netPayable = null;
if ($movementReady) {
    try {
        /* Açık pozisyonlar toplu hareket farkından değil, her cari kendi içinde
         * mahsup edildikten sonra hesaplanır. Böylece aynı carinin alış/borcu
         * açık alacak toplamını yapay olarak büyütmez. */
        $positionTotals = dashboard_cari_aggregate($yearStart, $yearEnd);
        $netReceivable = 0.0;
        $netPayable = 0.0;
        foreach ($positionTotals['positions'] as $positionRow) {
            $positionNet = (float)$positionRow['alacak'] - (float)$positionRow['tahsilat']
                - (float)$positionRow['verecek'] + (float)$positionRow['odeme'];
            if ($positionNet > 0.005) $netReceivable += $positionNet;
            elseif ($positionNet < -0.005) $netPayable += abs($positionNet);
        }
    } catch (Throwable $e) {
        $netReceivable = $netPayable = null;
    }
}

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
$invoiceVat = ym_first_column($invoiceColumns, array('vat_amount', 'vat_total', 'kdv_total', 'kdv_toplam', 'total_vat', 'kdv'));
$invoiceReady = $invoiceTable && $invoiceAmount && $invoiceDate && $invoiceDirection;
$salesTotal = $purchaseTotal = $salesVat = $purchaseVat = null;
if ($invoiceReady) {
    $dir = ym_ident($invoiceDirection);
    $activeInvoiceWhere = isset($invoiceColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0 AND ' : '';
    $salesWhere = $activeInvoiceWhere . 'LOWER(COALESCE(CAST(' . $dir . ' AS TEXT),\'\')) IN (\'satis\',\'satış\',\'sales\',\'out\',\'giden\')';
    $purchaseWhere = $activeInvoiceWhere . 'LOWER(COALESCE(CAST(' . $dir . ' AS TEXT),\'\')) IN (\'alis\',\'alış\',\'purchase\',\'in\',\'gelen\')';
    $salesTotal = ym_safe_sum($pdo, $invoiceTable, $invoiceAmount, $invoiceDate, $yearStart, $yearEnd, $salesWhere, array());
    $purchaseTotal = ym_safe_sum($pdo, $invoiceTable, $invoiceAmount, $invoiceDate, $yearStart, $yearEnd, $purchaseWhere, array());
    if ($invoiceVat) {
        $tlWhere = isset($invoiceColumns['currency']) ? ' AND UPPER(COALESCE("currency",\'TL\'))=\'TL\'' : '';
        $salesVat = ym_safe_sum($pdo, $invoiceTable, $invoiceVat, $invoiceDate, $yearStart, $yearEnd, $salesWhere . $tlWhere, array());
        $purchaseVat = ym_safe_sum($pdo, $invoiceTable, $invoiceVat, $invoiceDate, $yearStart, $yearEnd, $purchaseWhere . $tlWhere, array());
    }
}
$dataSets['Fatura satış / alış'] = $invoiceReady;
$dataSets['KDV özeti'] = $invoiceReady && $invoiceVat;
if (!$invoiceReady) $missingItems[] = 'Satış Analizi: fatura tablosu ile toplam, tarih ve alış/satış yönü alanları bulunamadı.';
if (!($invoiceReady && $invoiceVat)) $missingItems[] = 'Mali Tablolar: faturalarda KDV toplam alanı bulunamadı.';

/* Mağaza satışları
 * Günlük mağaza kayıtlarının seçili yıldaki brüt toplamı okunur. Kullanıcının
 * belirlediği raporlama kuralına göre bu toplamın %20'si tahmini kâr gösterilir.
 */
$storeSalesTable = ym_first_table($pdo, array('store_daily_sales'));
$storeSalesColumns = $storeSalesTable ? ym_columns($pdo, $storeSalesTable) : array();
$storeSalesReady = $storeSalesTable
    && isset($storeSalesColumns['gross_amount'])
    && isset($storeSalesColumns['sale_date']);
$storeSalesTotal = null;
$storeEstimatedProfit = null;
if ($storeSalesReady) {
    $storeSalesTotal = ym_safe_sum($pdo, $storeSalesTable, 'gross_amount', 'sale_date', $yearStart, $yearEnd, '', array());
    if ($storeSalesTotal !== null) $storeEstimatedProfit = $storeSalesTotal * 0.20;
}
$dataSets['Mağaza satışları'] = $storeSalesReady;
if (!$storeSalesReady) $missingItems[] = 'Mağaza Raporu: günlük mağaza satış tablosu veya toplam/tarih alanı bulunamadı.';

/* Üretim */
$productionTable = ym_first_table($pdo, array('production_group_shift_entries', 'production_daily_entries', 'production_records', 'uretim_kayitlari', 'production', 'uretim_takibi', 'uretim'));
$productionColumns = $productionTable ? ym_columns($pdo, $productionTable) : array();
$productionAmount = ym_first_column($productionColumns, array('produced_dozen', 'quantity', 'miktar', 'total_quantity', 'uretim_miktari', 'adet', 'kg'));
$productionDate = ym_first_column($productionColumns, array('production_date', 'uretim_tarihi', 'date', 'tarih', 'created_at'));
$productionTotal = ($productionTable && $productionAmount && $productionDate) ? ym_safe_sum($pdo, $productionTable, $productionAmount, $productionDate, $yearStart, $yearEnd, '', array()) : null;
$productionDefective = ($productionTable && isset($productionColumns['defective_qty']) && $productionDate) ? ym_safe_sum($pdo, $productionTable, 'defective_qty', $productionDate, $yearStart, $yearEnd, '', array()) : null;
$productionMonthly = array();
if ($productionTable && $productionAmount && $productionDate) {
    for ($productionMonthNo=1; $productionMonthNo<=12; $productionMonthNo++) {
        $productionMonthly[sprintf('%04d-%02d', $year, $productionMonthNo)] = 0.0;
    }
    try {
        $productionSql = 'SELECT substr(' . ym_ident($productionDate) . ',1,7) AS month_key, '
            . 'COALESCE(SUM(CAST(' . ym_ident($productionAmount) . ' AS REAL)),0) AS total '
            . 'FROM ' . ym_ident($productionTable) . ' WHERE ' . ym_ident($productionDate) . ' BETWEEN ? AND ? '
            . 'GROUP BY substr(' . ym_ident($productionDate) . ',1,7)';
        $productionStmt = $pdo->prepare($productionSql);
        $productionStmt->execute(array($yearStart, $yearEnd));
        foreach ($productionStmt->fetchAll() as $productionRow) {
            $productionKey = (string)$productionRow['month_key'];
            if (isset($productionMonthly[$productionKey])) $productionMonthly[$productionKey] = (float)$productionRow['total'];
        }
    } catch (Throwable $e) {
        $productionMonthly = array();
    }
}
$dataSets['Üretim'] = $productionTotal !== null;
if ($productionTotal === null) $missingItems[] = 'Üretim Analizi: üretim tablosu veya miktar/tarih alanı eksik.';

/* Stok
 * Mevcut stok bir dönem toplamı değildir. Tüm aktif girişler eksi tüm aktif
 * çıkışlar alınır; iptal edilen satış veya manuel hareketler bakiyeye katılmaz.
 */
$stockTable = ym_first_table($pdo, array('stock_movements', 'stok_hareketleri', 'stocks', 'stoklar', 'products', 'urunler'));
$stockColumns = $stockTable ? ym_columns($pdo, $stockTable) : array();
$stockAmount = ym_first_column($stockColumns, array('quantity_dozen', 'current_stock', 'stock_quantity', 'quantity', 'miktar', 'stok', 'adet'));
$stockDate = ym_first_column($stockColumns, array('movement_date', 'date', 'tarih', 'created_at'));
$stockTotal = null;
if ($stockTable === 'stock_movements' && $stockAmount && isset($stockColumns['direction'])) {
    try {
        $stockCancelSql = isset($stockColumns['is_cancelled']) ? ' WHERE COALESCE("is_cancelled",0)=0' : '';
        $stockSql = 'SELECT COALESCE(SUM(CASE WHEN "direction"=\'in\' THEN CAST(' . ym_ident($stockAmount) . ' AS REAL) WHEN "direction"=\'out\' THEN -CAST(' . ym_ident($stockAmount) . ' AS REAL) ELSE 0 END),0) FROM ' . ym_ident($stockTable) . $stockCancelSql;
        $stockTotal = (float)$pdo->query($stockSql)->fetchColumn();
    } catch (Throwable $e) {
        $stockTotal = null;
    }
} elseif ($stockTable && $stockAmount) {
    $stockTotal = ym_safe_sum($pdo, $stockTable, $stockAmount, null, $yearStart, $yearEnd, '', array());
}
$dataSets['Stok'] = $stockTotal !== null;
if ($stockTotal === null) $missingItems[] = 'Stok Analizi: stok tablosu veya giriş/çıkış miktar alanları eksik.';

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

/* Maaş, avans ve tazminat ödemeleri
 * Rapor kaynak kayıtları toplar; bunlara bağlı account_transactions satırlarını
 * ayrıca toplamaz. Böylece banka çıkışı ikinci kez sayılmaz.
 */
$salaryTable = ym_first_table($pdo, array('salary_records'));
$salaryColumns = $salaryTable ? ym_columns($pdo, $salaryTable) : array();
$salaryReady = $salaryTable
    && isset($salaryColumns['period'])
    && isset($salaryColumns['salary_amount'])
    && isset($salaryColumns['paid_amount'])
    && isset($salaryColumns['remaining_amount']);
$salaryPeriodStart = sprintf('%04d-01', $year);
$salaryPeriodEnd = sprintf('%04d-12', $year);
$salaryAccrued = $salaryPaid = $salaryPending = $salaryAdvances = $salaryGarnishment = $salaryCompensation = null;
if ($salaryReady) {
    $salaryAccrued = ym_safe_sum($pdo, $salaryTable, 'salary_amount', 'period', $salaryPeriodStart, $salaryPeriodEnd, '', array());
    $salaryPending = ym_safe_sum($pdo, $salaryTable, 'remaining_amount', 'period', $salaryPeriodStart, $salaryPeriodEnd, '', array());

    try {
        $manualSalaryTable = isset(ym_tables($pdo)['salary_manual_monthly_totals']) ? 'salary_manual_monthly_totals' : null;
        if ($manualSalaryTable) {
            $salaryPaidSql = 'SELECT COALESCE(SUM(x.amount),0) FROM ('
                . 'SELECT m."period", CAST(m."amount" AS REAL) AS amount FROM "salary_manual_monthly_totals" m WHERE m."period" BETWEEN ? AND ? '
                . 'UNION ALL '
                . 'SELECT sr."period", COALESCE(SUM(CAST(sr."paid_amount" AS REAL)),0) AS amount FROM "salary_records" sr '
                . 'WHERE sr."period" BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM "salary_manual_monthly_totals" m WHERE m."period"=sr."period") '
                . 'GROUP BY sr."period") x';
            $salaryPaidStmt = $pdo->prepare($salaryPaidSql);
            $salaryPaidStmt->execute(array($salaryPeriodStart, $salaryPeriodEnd, $salaryPeriodStart, $salaryPeriodEnd));
            $salaryPaid = (float)$salaryPaidStmt->fetchColumn();
        } else {
            $salaryPaid = ym_safe_sum($pdo, $salaryTable, 'paid_amount', 'period', $salaryPeriodStart, $salaryPeriodEnd, '', array());
        }
    } catch (Throwable $e) {
        $salaryPaid = null;
    }

    $salaryAdvanceTable = isset(ym_tables($pdo)['salary_advances']) ? 'salary_advances' : null;
    if ($salaryAdvanceTable) {
        $salaryAdvanceColumns = ym_columns($pdo, $salaryAdvanceTable);
        if (isset($salaryAdvanceColumns['amount']) && isset($salaryAdvanceColumns['advance_date'])) {
            $salaryAdvances = ym_safe_sum($pdo, $salaryAdvanceTable, 'amount', 'advance_date', $yearStart, $yearEnd, '', array());
        }
    }

    $salaryGarnishmentTable = isset(ym_tables($pdo)['salary_garnishment_payments']) ? 'salary_garnishment_payments' : null;
    if ($salaryGarnishmentTable) {
        $salaryGarnishmentColumns = ym_columns($pdo, $salaryGarnishmentTable);
        if (isset($salaryGarnishmentColumns['amount']) && isset($salaryGarnishmentColumns['payment_date'])) {
            $salaryGarnishmentWhere = isset($salaryGarnishmentColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0' : '';
            $salaryGarnishment = ym_safe_sum($pdo, $salaryGarnishmentTable, 'amount', 'payment_date', $yearStart, $yearEnd, $salaryGarnishmentWhere, array());
        }
    }

    $salaryCompensationTable = isset(ym_tables($pdo)['salary_compensation_payments']) ? 'salary_compensation_payments' : null;
    if ($salaryCompensationTable) {
        $salaryCompensationColumns = ym_columns($pdo, $salaryCompensationTable);
        if (isset($salaryCompensationColumns['amount']) && isset($salaryCompensationColumns['payment_date'])) {
            $salaryCompensationWhere = isset($salaryCompensationColumns['is_cancelled']) ? 'COALESCE("is_cancelled",0)=0' : '';
            $salaryCompensation = ym_safe_sum($pdo, $salaryCompensationTable, 'amount', 'payment_date', $yearStart, $yearEnd, $salaryCompensationWhere, array());
        }
    }
}
$dataSets['Maaş ödemeleri'] = $salaryReady;
if (!$salaryReady) $missingItems[] = 'Maaş Ödemeleri: maaş kayıt tablosu veya dönem, tahakkuk, ödenen ve kalan tutar alanları eksik.';

$readyCount = 0;
foreach ($dataSets as $isReady) if ($isReady) $readyCount++;
$readiness = count($dataSets) ? (int)round(($readyCount / count($dataSets)) * 100) : 0;
$netCashFlow = ($collection === null || $payment === null) ? null : $collection - $payment;
$financialPosition = ($netReceivable === null || $netPayable === null) ? null : $netReceivable - $netPayable;
$grossProfit = ($salesTotal === null || $purchaseTotal === null) ? null : $salesTotal - $purchaseTotal;
$vatPosition = ($salesVat === null || $purchaseVat === null) ? null : $salesVat - $purchaseVat;

/* Yönetim amaçlı mali tablo özetleri
 * Resmî tek düzen bilanço yerine, panelde hâlihazırda tutulan ve doğrulanabilen
 * kaynaklardan seçili yılın gelir-gideri, mevcut varlık/yükümlülük pozisyonu
 * ve nakit akışı oluşturulur. Aynı kayıtlar ikinci kez toplanmaz.
 */
$managementRevenue = ($salesTotal === null || $storeSalesTotal === null)
    ? null
    : $salesTotal + $storeSalesTotal;
$managementExpense = ($purchaseTotal === null)
    ? null
    : $purchaseTotal
        + (float)($paidSgk ?? 0)
        + (float)($paidTaxes ?? 0)
        + (float)($salaryPaid ?? 0)
        + (float)($salaryAdvances ?? 0)
        + (float)($salaryGarnishment ?? 0)
        + (float)($salaryCompensation ?? 0);
$managementPeriodResult = ($managementRevenue === null || $managementExpense === null)
    ? null
    : $managementRevenue - $managementExpense;

$managementAssets = ($cashTotal === null || $bankTotal === null || $netReceivable === null || $incomingChecks === null)
    ? null
    : $cashTotal + $bankTotal + $netReceivable + $incomingChecks;
$managementLiabilities = ($netPayable === null || $outgoingChecks === null || $cardTotal === null)
    ? null
    : $netPayable + $outgoingChecks + $cardTotal
        + (float)($pendingSgk ?? 0)
        + (float)($pendingTaxes ?? 0)
        + (float)($salaryPending ?? 0);
$managementBalancePosition = ($managementAssets === null || $managementLiabilities === null)
    ? null
    : $managementAssets - $managementLiabilities;

$srcMovement = $movementTable ?: 'hareket tablosu bulunamadı';
$srcAccounts = $accountTable ? $accountTable . ($transactionTable ? ' + ' . $transactionTable : '') : 'hesap tablosu bulunamadı';
$srcChecks = $checkTable ?: 'çek tablosu bulunamadı';
$srcInvoices = $invoiceTable ?: 'fatura tablosu bulunamadı';
$srcStoreSales = $storeSalesTable ?: 'mağaza satış tablosu bulunamadı';
$srcProduction = $productionTable ?: 'üretim tablosu bulunamadı';
$srcStock = $stockTable ?: 'stok tablosu bulunamadı';
$srcCards = $cardTable ?: 'kart ekstresi tablosu bulunamadı';
$srcTaxes = $taxTable ?: 'vergi ödeme tablosu bulunamadı';
$srcSalaries = $salaryTable ?: 'maaş kayıt tablosu bulunamadı';
$srcSalaryAdvances = !empty($salaryAdvanceTable) ? $salaryAdvanceTable : 'maaş avans tablosu bulunamadı';
$srcSalaryGarnishment = !empty($salaryGarnishmentTable) ? $salaryGarnishmentTable : 'maaş haczi ödeme tablosu bulunamadı';
$srcSalaryCompensation = !empty($salaryCompensationTable) ? $salaryCompensationTable : 'tazminat ödeme tablosu bulunamadı';

/* Kullanıcının istediği yıllık kaynak özeti.
 * Gelen/verilen çekler ile açık cari pozisyonlar bilinçli olarak ayrı kaynaklar
 * halinde gösterilir. Vergi ve personel ödemelerine bağlı kasa/banka satırları
 * ayrıca toplanmaz; böylece aynı gider iki kez düşülmez.
 */
$annualIncomeSources = ($incomingChecks === null || $netReceivable === null || $collection === null || $otherIncome === null || $storeSalesTotal === null)
    ? null
    : $incomingChecks + $netReceivable + $collection + $otherIncome + $storeSalesTotal;
$annualExpenseSources = ($outgoingChecks === null || $payment === null || $paidSgk === null || $paidTaxes === null || $salaryPaid === null)
    ? null
    : $outgoingChecks
        + $payment
        + $paidSgk
        + $paidTaxes
        + $salaryPaid
        + (float)($salaryAdvances ?? 0)
        + (float)($salaryGarnishment ?? 0)
        + (float)($salaryCompensation ?? 0);
$annualSourcesRemaining = ($annualIncomeSources === null || $annualExpenseSources === null)
    ? null
    : $annualIncomeSources - $annualExpenseSources;

$annualIncomeMetrics = array(
    ym_metric('Nakit / banka tahsilatları', $collection, $srcMovement, 'Tahsilat hareketleri okunamıyor.', 'success', 'money', 'collection'),
    ym_metric('Diğer gelir hareketleri', $otherIncome, $srcMovement, 'Diğer gelir hareketleri okunamıyor.', 'success', 'money', 'other_income'),
    ym_metric('Mağaza satışları', $storeSalesTotal, $srcStoreSales, 'Yıllık mağaza satış toplamı okunamıyor.', 'success', 'money', 'store_sales'),
    ym_metric('Yıllık gelen çekler', $incomingChecks, $srcChecks, 'Gelen çek tutarı, vadesi veya yönü okunamıyor.', 'success', 'money', 'incoming_checks'),
    ym_metric('Açıkta kalan alacak', $netReceivable, $srcMovement, 'Cari alacak ve tahsilat kayıtları birlikte okunamıyor.', 'success', 'money', 'open_receivables'),
    ym_metric('Toplam gelir kaynakları', $annualIncomeSources, $srcChecks . ' + ' . $srcMovement . ' + ' . $srcStoreSales, 'Gelir kaynaklarının tamamı birlikte hesaplanamıyor.', 'success')
);
$annualExpenseMetrics = array(
    ym_metric('Yıllık verilen çekler', $outgoingChecks, $srcChecks, 'Verilen çek tutarı, vadesi veya yönü okunamıyor.', 'danger', 'money', 'outgoing_checks'),
    ym_metric('Ödenen SSK / SGK', $paidSgk, $srcTaxes, 'Ödenen SSK / SGK tutarı okunamıyor.', 'danger', 'money', 'paid_sgk'),
    ym_metric('Ödenen diğer vergiler', $paidTaxes, $srcTaxes, 'Ödenen vergi tutarı okunamıyor.', 'danger', 'money', 'paid_taxes'),
    ym_metric('Maaş ve personel ödemeleri', $salaryPaid === null ? null : $salaryPaid + (float)($salaryAdvances ?? 0) + (float)($salaryGarnishment ?? 0) + (float)($salaryCompensation ?? 0), $srcSalaries, 'Ödenen maaş ve personel tutarları okunamıyor.', 'danger', 'money', 'personnel'),
    ym_metric('Diğer ödemeler', $payment, $srcMovement, 'Diğer cari ödeme hareketleri okunamıyor.', 'danger', 'money', 'other_payments'),
    ym_metric('Toplam gider kaynakları', $annualExpenseSources, $srcChecks . ' + ' . $srcTaxes . ' + ' . $srcSalaries . ' + ' . $srcMovement, 'Gider kaynakları birlikte hesaplanamıyor.', 'danger')
);

$productionMetrics = array(
    ym_metric($year . ' yıllık toplam üretim', $productionTotal, $srcProduction . '.' . ($productionAmount ?: 'produced_dozen'), 'Üretim düzine veya tarih alanı eksik.', 'info', 'dozen'),
    ym_metric($year . ' yıllık toplam defolu', $productionDefective, $srcProduction . '.defective_qty', 'Defolu miktar veya tarih alanı eksik.', 'danger', 'quantity')
);
$productionMonthNames = array(1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık');
for ($productionMonthNo=1; $productionMonthNo<=12; $productionMonthNo++) {
    $productionKey = sprintf('%04d-%02d', $year, $productionMonthNo);
    $productionMonthValue = isset($productionMonthly[$productionKey]) ? $productionMonthly[$productionKey] : ($productionTotal === null ? null : 0.0);
    $productionMetrics[] = ym_metric($productionMonthNames[$productionMonthNo] . ' üretimi', $productionMonthValue, $srcProduction . '.' . ($productionAmount ?: 'produced_dozen'), 'Aylık üretim kaydı okunamıyor.', 'info', 'dozen');
}

page_header('Dumanlar A.Ş. Yönetim Merkezi', 'raporlar');
?>
<style>
.ym-hero{margin-bottom:18px;background:linear-gradient(135deg,#fff,#fff7ea);display:grid;grid-template-columns:1fr auto;gap:22px;align-items:center}.ym-hero h2{margin:0 0 8px;font-size:30px;letter-spacing:-.045em}.ym-hero p,.ym-section-head p{margin:0;color:var(--muted);font-size:13px;font-weight:750}.ym-tools{display:flex;align-items:end;gap:12px}.ym-tools label{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:900}.ym-progress{min-width:180px}.ym-progress-line{height:10px;border-radius:999px;background:#eeeae1;overflow:hidden;margin:7px 0}.ym-progress-line i{display:block;height:100%;background:linear-gradient(90deg,var(--warning),var(--success));border-radius:999px}.ym-section{margin-top:18px}.ym-section-head{align-items:flex-start}.ym-section-head h3{margin-bottom:5px}.ym-card-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.ym-card{border:1px solid var(--border);border-left:4px solid var(--accent);border-radius:17px;padding:17px;background:#fff;min-width:0}.ym-card-label{display:block;color:var(--muted);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.ym-card strong{display:block;font-size:24px;margin:9px 0;letter-spacing:-.035em}.ym-card small{display:block;color:var(--muted);line-height:1.4;margin-top:5px;overflow-wrap:anywhere}.ym-card small b{color:var(--text)}.ym-success{border-left-color:var(--success)}.ym-danger{border-left-color:var(--danger)}.ym-info{border-left-color:var(--accent)}.ym-missing{border-left-color:var(--warning);background:#fffaf0}.ym-missing strong{font-size:20px;color:#835710}.ym-state{padding-top:6px;border-top:1px dashed var(--border)}.ym-clickable{cursor:pointer;transition:transform .15s ease,box-shadow .15s ease}.ym-clickable:hover,.ym-clickable:focus{transform:translateY(-2px);box-shadow:0 12px 28px rgba(27,49,36,.12);outline:2px solid rgba(31,107,73,.25);outline-offset:2px}.ym-clickable:after{content:'Detayı aç →';display:block;margin-top:9px;color:var(--accent);font-size:11px;font-weight:900}.ym-source-columns{display:grid;grid-template-columns:1fr 1fr;gap:16px}.ym-source-column{padding:15px;border:1px solid var(--border);border-radius:18px;background:#fafbf9}.ym-source-column h4{margin:0 0 12px;font-size:17px}.ym-source-column .ym-card-grid{grid-template-columns:1fr}.ym-remaining{margin-top:16px}.ym-remaining .ym-card{background:linear-gradient(135deg,#fff,#f1f8f3)}.ym-detail-backdrop{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(13,25,18,.58)}.ym-detail-backdrop.open{display:flex}.ym-detail-modal{width:min(980px,100%);max-height:min(86vh,820px);display:flex;flex-direction:column;border-radius:20px;background:#fff;box-shadow:0 30px 90px rgba(0,0,0,.3);overflow:hidden}.ym-detail-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 18px;background:#eef6f0}.ym-detail-head h3{margin:0}.ym-detail-head p{margin:4px 0 0;color:var(--muted);font-size:12px}.ym-detail-close{width:44px;height:44px;border:0;border-radius:12px;background:#fff;font-size:23px;cursor:pointer}.ym-detail-body{padding:15px;overflow:auto}.ym-detail-table{width:100%;border-collapse:collapse;min-width:680px}.ym-detail-table th,.ym-detail-table td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}.ym-detail-table th{position:sticky;top:0;background:#fff;font-size:11px;text-transform:uppercase;color:var(--muted)}.ym-detail-table td:last-child,.ym-detail-table th:last-child{text-align:right;white-space:nowrap}.ym-detail-empty{padding:28px;text-align:center;color:var(--muted);font-weight:800}.ym-missing-list,.ym-status-list{display:grid;gap:10px}.ym-list-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid var(--border);border-radius:14px;background:#fff}.ym-list-row span{font-weight:800}.ym-list-row small{color:var(--muted)}.ym-ok{color:var(--success);font-weight:900}.ym-warn{color:#835710;font-weight:900}.ym-empty-ok{padding:16px;border-radius:14px;background:#f3fbf5;color:var(--success);font-weight:900}.ym-footnote{margin:18px 2px;color:var(--muted);font-size:12px;font-weight:750}@media(max-width:1100px){.ym-hero{grid-template-columns:1fr}.ym-card-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.ym-tools{align-items:stretch;flex-direction:column}.ym-card-grid,.ym-source-columns{grid-template-columns:1fr}.ym-list-row{flex-direction:column}.ym-hero h2{font-size:25px}.ym-detail-backdrop{padding:0}.ym-detail-modal{height:100dvh;max-height:none;border-radius:0}.ym-detail-body{padding:10px}.ym-detail-table{min-width:600px}}
</style>

<section class="panel-card ym-hero">
  <div><h2>Dumanlar A.Ş. Yönetim Merkezi</h2><p>Şirketin mevcut kayıtlarını değiştirmeden okuyan ilk aşama yönetim kokpiti. Her kart kendi veri kaynağını ve varsa eksiğini gösterir.</p></div>
  <div class="ym-tools">
    <form method="get"><label>Rapor yılı<select name="year" onchange="this.form.submit()"><?php for ($y=$currentYear+1; $y>=$currentYear-5; $y--): ?><option value="<?php echo e($y); ?>" <?php echo $y===$year?'selected':''; ?>><?php echo e($y); ?></option><?php endfor; ?></select></label></form>
    <div class="ym-progress"><strong>Veri hazırlığı: %<?php echo e($readiness); ?></strong><div class="ym-progress-line"><i style="width:<?php echo e($readiness); ?>%"></i></div><small><?php echo e($readyCount); ?>/<?php echo e(count($dataSets)); ?> veri grubu hazır</small></div>
  </div>
</section>

<?php
?>
<section class="panel-card ym-section">
  <div class="card-head ym-section-head"><div><h3>Yıllık Gelir, Gider ve Kalan</h3><p><?php echo e($year); ?> yılı gelir kaynakları ile gider kaynaklarının ayrı toplamı.</p></div><span>Yıllık özet</span></div>
  <div class="ym-source-columns">
    <div class="ym-source-column"><h4>Gelir kaynakları</h4><div class="ym-card-grid"><?php foreach ($annualIncomeMetrics as $metric) ym_render_card($metric); ?></div></div>
    <div class="ym-source-column"><h4>Gider kaynakları</h4><div class="ym-card-grid"><?php foreach ($annualExpenseMetrics as $metric) ym_render_card($metric); ?></div></div>
  </div>
  <div class="ym-remaining"><?php ym_render_card(ym_metric('Kalan', $annualSourcesRemaining, 'Toplam gelir kaynakları − toplam gider kaynakları', 'Gelir ve gider kaynakları birlikte hesaplanamıyor.', $annualSourcesRemaining !== null && $annualSourcesRemaining < 0 ? 'danger' : 'success')); ?></div>
</section>
<div class="ym-detail-backdrop" data-report-modal aria-hidden="true">
  <section class="ym-detail-modal" role="dialog" aria-modal="true" aria-labelledby="ymDetailTitle">
    <header class="ym-detail-head"><div><h3 id="ymDetailTitle">Detay listesi</h3><p data-report-detail-summary></p></div><button type="button" class="ym-detail-close" data-report-close aria-label="Kapat">×</button></header>
    <div class="ym-detail-body" data-report-detail-body><div class="ym-detail-empty">Detaylar yükleniyor…</div></div>
  </section>
</div>
<script>
(function(){
  var modal=document.querySelector('[data-report-modal]'); if(!modal)return;
  var title=modal.querySelector('#ymDetailTitle'),summary=modal.querySelector('[data-report-detail-summary]'),body=modal.querySelector('[data-report-detail-body]');
  var money=new Intl.NumberFormat('tr-TR',{style:'currency',currency:'TRY'});
  function closeModal(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
  function cell(text){var td=document.createElement('td');td.textContent=text==null?'':String(text);return td;}
  function render(data){
    title.textContent=data.title||'Detay listesi';summary.textContent=(data.year||'')+' yılı · '+(data.rows||[]).length+' kayıt · '+money.format(Number(data.total||0));body.textContent='';
    if(!data.rows||!data.rows.length){body.innerHTML='<div class="ym-detail-empty">Bu dönem için kayıt bulunamadı.</div>';return;}
    var table=document.createElement('table');table.className='ym-detail-table';table.innerHTML='<thead><tr><th>Tarih</th><th>Kaynak</th><th>Açıklama</th><th>Tutar</th></tr></thead>';
    var tbody=document.createElement('tbody');data.rows.forEach(function(row){var tr=document.createElement('tr');tr.appendChild(cell(row.date));tr.appendChild(cell(row.source));tr.appendChild(cell(row.description));tr.appendChild(cell(money.format(Number(row.amount||0))));tbody.appendChild(tr);});table.appendChild(tbody);body.appendChild(table);
  }
  function openDetail(card){
    modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';title.textContent='Detay listesi';summary.textContent='';body.innerHTML='<div class="ym-detail-empty">Detaylar yükleniyor…</div>';
    fetch('rapor-detay.php?year=<?php echo (int)$year; ?>&type='+encodeURIComponent(card.getAttribute('data-report-detail')),{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){if(!data.ok)throw new Error(data.message||'Detay okunamadı.');render(data);}).catch(function(err){body.innerHTML='<div class="ym-detail-empty"></div>';body.firstChild.textContent=err.message||'Detay kayıtları okunamadı.';});
  }
  document.addEventListener('click',function(event){var card=event.target.closest('[data-report-detail]');if(card){openDetail(card);return;}if(event.target.closest('[data-report-close]')||event.target===modal)closeModal();});
  document.addEventListener('keydown',function(event){if(event.key==='Escape'&&modal.classList.contains('open'))closeModal();if((event.key==='Enter'||event.key===' ')&&event.target.matches('[data-report-detail]')){event.preventDefault();openDetail(event.target);}});
})();
</script>
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

ym_render_section('Mağaza Raporu', $year . ' yılı günlük mağaza satış kayıtlarının toplamı ve toplam tutar üzerinden %20 tahmini kârı.', array(
    ym_metric($year . ' mağaza toplam tutarı', $storeSalesTotal, $srcStoreSales . '.gross_amount', 'Mağaza satış toplamı veya tarihi okunamıyor.', 'success'),
    ym_metric($year . ' tahmini mağaza kârı (%20)', $storeEstimatedProfit, $srcStoreSales . '.gross_amount × %20', 'Mağaza yıllık toplamı hesaplanamıyor.', 'info')
), '%20 kâr oranı');

ym_render_section('Üretim Analizi', 'Üretim Takibi bölümündeki günlük düzine kayıtlarının seçilen yıl için aylık kırılımı ve yıllık toplamı.', $productionMetrics, 'Üretim Takibi');

ym_render_section('Stok Analizi', 'Mevcut stok veya stok hareketi kayıtlarının toplam miktarı.', array(
    ym_metric('Toplam stok miktarı', $stockTotal, $srcStock, 'Stok miktarı alanı veya stok tablosu eksik.', 'info', 'dozen')
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

ym_render_section('Maaş Ödemeleri', 'Seçili yılın bordro, avans ve tazminat ödemeleri. Bağlı kasa/banka hareketleri ayrıca toplanmaz; böylece aynı ödeme iki kez sayılmaz.', array(
    ym_metric('Toplam maaş tahakkuku', $salaryAccrued, $srcSalaries, 'Maaş dönemi veya tahakkuk tutarı okunamıyor.', 'info'),
    ym_metric('Ödenen maaş', $salaryPaid, $srcSalaries . ' + manuel aylık toplamlar', 'Ödenen maaş tutarı okunamıyor.', 'success'),
    ym_metric('Bekleyen maaş', $salaryPending, $srcSalaries, 'Kalan maaş tutarı okunamıyor.', 'danger'),
    ym_metric('Maaş avansları', $salaryAdvances, $srcSalaryAdvances, 'Avans tutarı veya tarihi okunamıyor.', 'info'),
    ym_metric('Maaş haczi ödemesi', $salaryGarnishment, $srcSalaryGarnishment, 'Maaş haczi tutarı veya ödeme tarihi okunamıyor.', 'danger'),
    ym_metric('Tazminat / diğer personel ödemeleri', $salaryCompensation, $srcSalaryCompensation, 'Tazminat tutarı veya ödeme tarihi okunamıyor.', 'danger')
), 'Maaşlar');

ym_render_section('Karlılık Analizi', 'İlk aşamada fatura satış toplamı eksi fatura alış toplamı; genel giderler henüz dahil değildir.', array(
    ym_metric('Brüt ticari fark', $grossProfit, $srcInvoices, 'Satış ve alış faturaları birlikte okunamıyor.', $grossProfit !== null && $grossProfit < 0 ? 'danger' : 'success')
), 'Ön gösterge');

ym_render_section('Mali Tablolar', 'Seçili yılın mevcut fatura, mağaza, personel, vergi, cari, çek, kart ve kasa/banka kayıtlarından oluşturulan yönetim özeti.', array(
    ym_metric('Hesaplanan KDV', $salesVat, $srcInvoices, 'Faturalarda KDV toplam alanı eksik.', 'danger'),
    ym_metric('İndirilecek KDV', $purchaseVat, $srcInvoices, 'Faturalarda KDV toplam alanı eksik.', 'success'),
    ym_metric('KDV pozisyonu', $vatPosition, $srcInvoices, 'Satış ve alış KDV toplamları birlikte okunamıyor.', $vatPosition !== null && $vatPosition < 0 ? 'success' : 'danger'),
    ym_metric('Gelir tablosu · toplam gelir', $managementRevenue, $srcInvoices . ' + ' . $srcStoreSales, 'Fatura veya mağaza satış toplamı okunamıyor.', 'success'),
    ym_metric('Gelir tablosu · toplam gider', $managementExpense, $srcInvoices . ' + ' . $srcTaxes . ' + ' . $srcSalaries, 'Alış, vergi veya personel giderleri okunamıyor.', 'danger'),
    ym_metric('Gelir tablosu · dönem sonucu', $managementPeriodResult, 'Toplam gelir − toplam gider', 'Gelir ve gider toplamları birlikte hesaplanamıyor.', $managementPeriodResult !== null && $managementPeriodResult < 0 ? 'danger' : 'success'),
    ym_metric('Bilanço · toplam varlık', $managementAssets, $srcAccounts . ' + ' . $srcMovement . ' + ' . $srcChecks, 'Kasa, banka, alacak veya alınacak çekler okunamıyor.', 'success'),
    ym_metric('Bilanço · toplam yükümlülük', $managementLiabilities, $srcMovement . ' + ' . $srcChecks . ' + ' . $srcCards, 'Borç, verilecek çek, kart veya bekleyen yükümlülükler okunamıyor.', 'danger'),
    ym_metric('Bilanço · net pozisyon', $managementBalancePosition, 'Toplam varlık − toplam yükümlülük', 'Varlık ve yükümlülükler birlikte hesaplanamıyor.', $managementBalancePosition !== null && $managementBalancePosition < 0 ? 'danger' : 'info'),
    ym_metric('Nakit akış tablosu · net akış', $netCashFlow, $srcMovement, 'Tahsilat ve ödeme hareketleri birlikte okunamıyor.', $netCashFlow !== null && $netCashFlow < 0 ? 'danger' : 'success')
), 'Yönetim özeti');
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
