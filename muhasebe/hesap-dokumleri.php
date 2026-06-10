<?php
require_once __DIR__ . '/layout.php';
require_login();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
$start = $monthDate->format('Y-m-01');
$end = $monthDate->format('Y-m-t');
$prevMonth = $monthDate->modify('-1 month')->format('Y-m');
$nextMonth = $monthDate->modify('+1 month')->format('Y-m');
$print = isset($_GET['print']);

function hesap_dokum_real_cash_sql(): string
{
    return "
      AND COALESCE(m.is_check_adjustment,0)=0
      AND (m.check_id IS NULL OR m.check_id=0)
      AND UPPER(COALESCE(m.payment_method,'')) NOT LIKE '%ÇEK%'
      AND UPPER(COALESCE(m.payment_method,'')) NOT LIKE '%CEK%'";
}

function hesap_dokum_source_label(string $source): string
{
    return [
        'manual' => 'Manuel',
        'movement' => 'Cari hareket',
        'check' => 'Çek',
        'transfer' => 'Virman',
    ][$source] ?? $source;
}

$cashStmt = db()->prepare("SELECT m.movement_type, SUM(m.amount) AS total
    FROM movements m
    WHERE COALESCE(m.is_cancelled,0)=0
      AND m.movement_date BETWEEN ? AND ?
      AND m.movement_type IN ('tahsilat','gelir','odeme','gider')
      " . hesap_dokum_real_cash_sql() . "
    GROUP BY m.movement_type");
$cashStmt->execute([$start, $end]);
$cashTotals = ['tahsilat'=>0,'gelir'=>0,'odeme'=>0,'gider'=>0];
foreach ($cashStmt->fetchAll() as $row) {
    $type = $row['movement_type'] ?? '';
    if (isset($cashTotals[$type])) $cashTotals[$type] = (float)$row['total'];
}
$cashIn = $cashTotals['tahsilat'] + $cashTotals['gelir'];
$cashOut = $cashTotals['odeme'] + $cashTotals['gider'];
$cashNet = $cashIn - $cashOut;

$accountStmt = db()->prepare("SELECT a.id, a.name, a.account_type, a.bank_name,
      SUM(CASE WHEN at.direction='in' THEN at.amount ELSE 0 END) AS total_in,
      SUM(CASE WHEN at.direction='out' THEN at.amount ELSE 0 END) AS total_out,
      COUNT(at.id) AS row_count
    FROM accounts a
    LEFT JOIN account_transactions at ON at.account_id=a.id AND at.transaction_date BETWEEN ? AND ?
    GROUP BY a.id
    ORDER BY a.account_type ASC, a.name ASC");
$accountStmt->execute([$start, $end]);
$accountRows = $accountStmt->fetchAll();

$transactionStmt = db()->prepare("SELECT at.*, a.name AS account_name, a.account_type
    FROM account_transactions at
    JOIN accounts a ON a.id=at.account_id
    WHERE at.transaction_date BETWEEN ? AND ?
    ORDER BY at.transaction_date ASC, at.id ASC");
$transactionStmt->execute([$start, $end]);
$accountTransactions = $transactionStmt->fetchAll();

$movementStmt = db()->prepare("SELECT m.*, c.name AS cari_name, cat.name AS category_name
    FROM movements m
    LEFT JOIN cariler c ON c.id=m.cari_id
    LEFT JOIN categories cat ON cat.id=m.category_id
    WHERE COALESCE(m.is_cancelled,0)=0
      AND m.movement_date BETWEEN ? AND ?
      AND m.movement_type IN ('alacak','tahsilat','verecek','odeme','gelir','gider')
    ORDER BY m.movement_date ASC, m.id ASC");
$movementStmt->execute([$start, $end]);
$movements = $movementStmt->fetchAll();

$checkStmt = db()->prepare("SELECT ch.*, c.name AS cari_name
    FROM checks ch
    LEFT JOIN cariler c ON c.id=ch.cari_id
    WHERE COALESCE(ch.is_cancelled,0)=0
      AND ch.due_date BETWEEN ? AND ?
    ORDER BY ch.due_date ASC, ch.id ASC");
$checkStmt->execute([$start, $end]);
$checks = $checkStmt->fetchAll();

$checkTotals = ['alinacak'=>0,'verilecek'=>0,'alinacak_count'=>0,'verilecek_count'=>0];
foreach ($checks as $ch) {
    $dir = $ch['direction'] === 'verilecek' ? 'verilecek' : 'alinacak';
    $checkTotals[$dir] += (float)$ch['amount'];
    $checkTotals[$dir . '_count']++;
}

$accountSummary = account_summary();

page_header('Hesap Dökümleri', 'hesap_dokumleri');
?>

<?php if ($print): ?>
<script>
  window.addEventListener('load', function () { window.print(); });
</script>
<style>
  @media print {
    .sidebar, .topbar, .print-hide { display:none !important; }
    .main { padding:0 !important; }
    .app-shell { display:block !important; }
    .panel-card, .stat-card { break-inside: avoid; box-shadow:none !important; border:1px solid #ddd !important; }
    body { background:#fff !important; }
  }
</style>
<?php endif; ?>

<section class="panel-card report-controls print-hide">
  <form class="filterbar" method="get">
    <a class="btn btn-secondary" href="hesap-dokumleri.php?month=<?php echo e($prevMonth); ?>">‹ Önceki ay</a>
    <label>Ay seç<input type="month" name="month" value="<?php echo e($month); ?>"></label>
    <button class="btn btn-primary" type="submit">Göster</button>
    <a class="btn btn-secondary" href="hesap-dokumleri.php?month=<?php echo e($nextMonth); ?>">Sonraki ay ›</a>
    <a class="btn btn-secondary" target="_blank" href="hesap-dokumleri.php?month=<?php echo e($month); ?>&print=1">PDF / Yazdır</a>
  </form>
  <div class="report-period-note">Seçili dönem: <strong><?php echo e(month_label($month)); ?></strong> · <?php echo e(tr_date($start)); ?> - <?php echo e(tr_date($end)); ?></div>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card cash"><span>Bu ay giren</span><strong><?php echo e(money($cashIn)); ?></strong><small>Tahsilat + gelir, çek hariç</small></article>
  <article class="stat-card"><span>Bu ay çıkan</span><strong><?php echo e(money($cashOut)); ?></strong><small>Ödeme + gider, çek hariç</small></article>
  <article class="stat-card status"><span>Net nakit</span><strong class="<?php echo $cashNet>=0?'text-success':'text-danger'; ?>"><?php echo e(money($cashNet)); ?></strong><small>Giren - çıkan</small></article>
  <article class="stat-card soft"><span>Hesap bakiyesi</span><strong class="<?php echo $accountSummary['total']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($accountSummary['total'])); ?></strong><small>Kasa + banka + POS güncel</small></article>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card soft"><span>Tahsilat</span><strong><?php echo e(money($cashTotals['tahsilat'])); ?></strong><small>Nakit/hesaba giren tahsilat</small></article>
  <article class="stat-card soft"><span>Gelir</span><strong><?php echo e(money($cashTotals['gelir'])); ?></strong><small>Cari dışı gelir</small></article>
  <article class="stat-card soft"><span>Ödeme</span><strong><?php echo e(money($cashTotals['odeme'])); ?></strong><small>Nakit/hesaptan çıkan ödeme</small></article>
  <article class="stat-card soft"><span>Gider</span><strong><?php echo e(money($cashTotals['gider'])); ?></strong><small>Cari dışı gider</small></article>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Hesap bazlı aylık döküm</h3><span>Kasa / banka / POS kırılımı</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Hesap</th><th>Tip</th><th class="right">Giriş</th><th class="right">Çıkış</th><th class="right">Net</th><th class="right">Güncel bakiye</th></tr></thead>
    <tbody>
      <?php if(!$accountRows): ?><tr><td colspan="6" class="empty">Hesap bulunamadı.</td></tr><?php endif; ?>
      <?php foreach($accountRows as $a): $in=(float)$a['total_in']; $out=(float)$a['total_out']; $net=$in-$out; $bal=account_balance((int)$a['id']); ?>
      <tr>
        <td><strong><?php echo e($a['name']); ?></strong><small><?php echo e($a['bank_name'] ?: ''); ?></small></td>
        <td><?php echo badge(account_type_label($a['account_type']), account_type_tone($a['account_type'])); ?></td>
        <td class="right"><?php echo e(money($in)); ?></td>
        <td class="right"><?php echo e(money($out)); ?></td>
        <td class="right"><strong class="<?php echo $net>=0?'text-success':'text-danger'; ?>"><?php echo e(money($net)); ?></strong></td>
        <td class="right"><strong><?php echo e(money($bal)); ?></strong></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Aylık kasa/banka hareketleri</h3><span><?php echo count($accountTransactions); ?> kayıt</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Tarih</th><th>Hesap</th><th>Kaynak</th><th>Açıklama</th><th class="right">Giriş</th><th class="right">Çıkış</th></tr></thead>
    <tbody>
      <?php if(!$accountTransactions): ?><tr><td colspan="6" class="empty">Bu ay kasa/banka hareketi yok.</td></tr><?php endif; ?>
      <?php foreach($accountTransactions as $tr): ?>
      <tr>
        <td><?php echo e(tr_date($tr['transaction_date'])); ?></td>
        <td><strong><?php echo e($tr['account_name']); ?></strong><small><?php echo e(account_type_label($tr['account_type'])); ?></small></td>
        <td><?php echo badge(hesap_dokum_source_label($tr['source_type']), $tr['direction']==='in'?'success':'danger'); ?></td>
        <td><?php echo e($tr['description'] ?: '-'); ?></td>
        <td class="right"><?php echo $tr['direction']==='in' ? e(money($tr['amount'])) : '-'; ?></td>
        <td class="right"><?php echo $tr['direction']==='out' ? e(money($tr['amount'])) : '-'; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Cari hareket dökümü</h3><span>Alacak / tahsilat / verecek / ödeme</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Tarih</th><th>Cari</th><th>Tip</th><th>Kategori</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead>
    <tbody>
      <?php if(!$movements): ?><tr><td colspan="6" class="empty">Bu ay cari hareket yok.</td></tr><?php endif; ?>
      <?php foreach($movements as $m): ?>
      <tr>
        <td><?php echo e(tr_date($m['movement_date'])); ?></td>
        <td><?php echo $m['cari_id'] ? '<a href="cari-detay.php?id=' . e($m['cari_id']) . '">' . e($m['cari_name'] ?: '-') . '</a>' : e($m['cari_name'] ?: '-'); ?></td>
        <td><?php echo badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
        <td><?php echo e($m['category_name'] ?: '-'); ?></td>
        <td><?php echo e($m['description'] ?: '-'); ?></td>
        <td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="stats-grid two report-block">
  <article class="stat-card soft"><span>Bu ay vadesi gelen alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small><?php echo e((string)$checkTotals['alinacak_count']); ?> adet</small></article>
  <article class="stat-card soft"><span>Bu ay vadesi gelen verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small><?php echo e((string)$checkTotals['verilecek_count']); ?> adet</small></article>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Çek vade dökümü</h3><span>Çekler nakit toplamına dahil edilmez</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Vade</th><th>Yön</th><th>Cari</th><th>Banka</th><th>Çek No</th><th class="right">Tutar</th></tr></thead>
    <tbody>
      <?php if(!$checks): ?><tr><td colspan="6" class="empty">Bu ay vadesi gelen çek yok.</td></tr><?php endif; ?>
      <?php foreach($checks as $ch): ?>
      <tr>
        <td><?php echo e(tr_date($ch['due_date'])); ?></td>
        <td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td>
        <td><?php echo e($ch['cari_name'] ?: '-'); ?></td>
        <td><?php echo e($ch['bank_name'] ?: '-'); ?></td>
        <td><?php echo e($ch['check_no'] ?: '-'); ?></td>
        <td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<p class="calc-note report-calc-note"><strong>Not:</strong> Bu sayfada nakit giriş/çıkış hesabı çekleri hariç tutar. Çekler vade takibi için ayrı bölümde gösterilir.</p>

<?php page_footer(); ?>
