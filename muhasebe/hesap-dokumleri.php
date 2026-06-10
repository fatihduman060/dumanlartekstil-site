<?php
require_once __DIR__ . '/layout.php';
require_login();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
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
    return ['manual'=>'Manuel','movement'=>'Cari hareket','check'=>'Çek','transfer'=>'Virman'][$source] ?? $source;
}
function short_text($text, int $max = 55): string
{
    $text = trim((string)$text);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    if (!function_exists('mb_strlen') && strlen($text) > $max) return substr($text, 0, $max - 1) . '…';
    return $text;
}

$cashStmt = db()->prepare("SELECT m.movement_type, SUM(m.amount) AS total FROM movements m WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_date BETWEEN ? AND ? AND m.movement_type IN ('tahsilat','gelir','odeme','gider') " . hesap_dokum_real_cash_sql() . " GROUP BY m.movement_type");
$cashStmt->execute([$start, $end]);
$cashTotals = ['tahsilat'=>0,'gelir'=>0,'odeme'=>0,'gider'=>0];
foreach ($cashStmt->fetchAll() as $row) if (isset($cashTotals[$row['movement_type']])) $cashTotals[$row['movement_type']] = (float)$row['total'];
$cashIn = $cashTotals['tahsilat'] + $cashTotals['gelir'];
$cashOut = $cashTotals['odeme'] + $cashTotals['gider'];
$cashNet = $cashIn - $cashOut;

$accountStmt = db()->prepare("SELECT a.id, a.name, a.account_type, a.bank_name, SUM(CASE WHEN at.direction='in' THEN at.amount ELSE 0 END) AS total_in, SUM(CASE WHEN at.direction='out' THEN at.amount ELSE 0 END) AS total_out, COUNT(at.id) AS row_count FROM accounts a LEFT JOIN account_transactions at ON at.account_id=a.id AND at.transaction_date BETWEEN ? AND ? GROUP BY a.id ORDER BY a.account_type ASC, a.name ASC");
$accountStmt->execute([$start, $end]);
$accountRows = $accountStmt->fetchAll();

$transactionStmt = db()->prepare("SELECT at.*, a.name AS account_name, a.account_type FROM account_transactions at JOIN accounts a ON a.id=at.account_id WHERE at.transaction_date BETWEEN ? AND ? ORDER BY at.transaction_date ASC, at.id ASC");
$transactionStmt->execute([$start, $end]);
$accountTransactions = $transactionStmt->fetchAll();

$movementStmt = db()->prepare("SELECT m.*, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_date BETWEEN ? AND ? AND m.movement_type IN ('alacak','tahsilat','verecek','odeme','gelir','gider') ORDER BY m.movement_date ASC, m.id ASC");
$movementStmt->execute([$start, $end]);
$movements = $movementStmt->fetchAll();

$checkStmt = db()->prepare("SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.due_date BETWEEN ? AND ? ORDER BY ch.due_date ASC, ch.id ASC");
$checkStmt->execute([$start, $end]);
$checks = $checkStmt->fetchAll();
$checkTotals = ['alinacak'=>0,'verilecek'=>0,'alinacak_count'=>0,'verilecek_count'=>0];
foreach ($checks as $ch) { $dir = $ch['direction'] === 'verilecek' ? 'verilecek' : 'alinacak'; $checkTotals[$dir] += (float)$ch['amount']; $checkTotals[$dir . '_count']++; }
$accountSummary = account_summary();

page_header('Hesap Dökümleri', 'hesap_dokumleri');
?>
<?php if ($print): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<style>
@media print{
  @page{size:A4 landscape;margin:8mm}
  .sidebar,.topbar,.print-hide{display:none!important}.app-shell{display:block!important}.main{padding:0!important}body{background:#fff!important;color:#111!important}
  .panel-card,.stat-card{box-shadow:none!important;border:1px solid #d8d2c5!important;border-radius:8px!important}.report-block{margin-bottom:8px!important}
  .stats-grid{gap:6px!important}.stat-card{padding:8px 10px!important}.stat-card span{font-size:9px!important}.stat-card strong{font-size:14px!important}.stat-card small{font-size:8px!important}
  .panel-card{padding:10px!important}.card-head{margin-bottom:6px!important}.card-head h3{font-size:12px!important}.card-head span{font-size:9px!important}
  table{font-size:8px!important;table-layout:fixed!important;width:100%!important}th,td{padding:4px 5px!important;vertical-align:top!important;word-break:break-word!important}td small{display:none!important}.badge{font-size:7px!important;padding:2px 5px!important}.table-wrap{overflow:visible!important}
  a{text-decoration:none!important;color:#111!important}.screen-only{display:none!important}
}
</style>
<?php endif; ?>

<style>
.statement-compact .panel-card{padding:16px}.statement-compact .report-block{margin-top:14px}.statement-compact table{font-size:13px}.statement-compact th,.statement-compact td{padding:8px 9px}.statement-compact .stat-card{padding:16px}.statement-compact .stat-card strong{font-size:22px}.statement-compact .mini-note{color:var(--muted);font-size:12px;margin:8px 0 0}.statement-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.statement-actions label{min-width:170px}.statement-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px}.statement-section-title small{color:var(--muted);font-weight:800}.screen-only{display:inline-flex}
</style>

<div class="statement-compact">
<section class="panel-card report-controls print-hide">
  <form class="filterbar statement-actions" method="get">
    <a class="btn btn-secondary" href="hesap-dokumleri.php?month=<?php echo e($prevMonth); ?>">‹ Önceki</a>
    <label>Ay seç<input type="month" name="month" value="<?php echo e($month); ?>"></label>
    <button class="btn btn-primary" type="submit">Göster</button>
    <a class="btn btn-secondary" href="hesap-dokumleri.php?month=<?php echo e($nextMonth); ?>">Sonraki ›</a>
    <a class="btn btn-primary" target="_blank" href="hesap-dokumleri-pdf.php?month=<?php echo e($month); ?>">PDF Aç</a>
    <a class="btn btn-secondary" target="_blank" href="hesap-dokumleri.php?month=<?php echo e($month); ?>&print=1">Yazdır</a>
  </form>
  <div class="report-period-note">Dönem: <strong><?php echo e(month_label($month)); ?></strong> · <?php echo e(tr_date($start)); ?> - <?php echo e(tr_date($end)); ?>. PDF ve Yazdır ayrı çalışır.</div>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card cash"><span>Giren</span><strong><?php echo e(money($cashIn)); ?></strong><small>Tahsilat + gelir, çek hariç</small></article>
  <article class="stat-card"><span>Çıkan</span><strong><?php echo e(money($cashOut)); ?></strong><small>Ödeme + gider, çek hariç</small></article>
  <article class="stat-card status"><span>Net nakit</span><strong class="<?php echo $cashNet>=0?'text-success':'text-danger'; ?>"><?php echo e(money($cashNet)); ?></strong><small>Giren - çıkan</small></article>
  <article class="stat-card soft"><span>Hesap bakiyesi</span><strong class="<?php echo $accountSummary['total']>=0?'text-success':'text-danger'; ?>"><?php echo e(money($accountSummary['total'])); ?></strong><small>Güncel kasa/banka/POS</small></article>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card soft"><span>Tahsilat</span><strong><?php echo e(money($cashTotals['tahsilat'])); ?></strong><small>Nakit/hesap tahsilatı</small></article>
  <article class="stat-card soft"><span>Gelir</span><strong><?php echo e(money($cashTotals['gelir'])); ?></strong><small>Cari dışı gelir</small></article>
  <article class="stat-card soft"><span>Alınacak çek</span><strong><?php echo e(money($checkTotals['alinacak'])); ?></strong><small><?php echo e((string)$checkTotals['alinacak_count']); ?> adet</small></article>
  <article class="stat-card soft"><span>Verilecek çek</span><strong><?php echo e(money($checkTotals['verilecek'])); ?></strong><small><?php echo e((string)$checkTotals['verilecek_count']); ?> adet</small></article>
</section>

<section class="panel-card report-block">
  <div class="card-head statement-section-title"><h3>1. Hesap bazlı özet</h3><span>Kasa / banka / POS</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Hesap</th><th>Tip</th><th class="right">Giriş</th><th class="right">Çıkış</th><th class="right">Net</th><th class="right">Güncel bakiye</th></tr></thead><tbody>
    <?php if(!$accountRows): ?><tr><td colspan="6" class="empty">Hesap bulunamadı.</td></tr><?php endif; ?>
    <?php foreach($accountRows as $a): $in=(float)$a['total_in']; $out=(float)$a['total_out']; $net=$in-$out; $bal=account_balance((int)$a['id']); ?>
      <tr><td><strong><?php echo e($a['name']); ?></strong><small><?php echo e($a['bank_name'] ?: ''); ?></small></td><td><?php echo badge(account_type_label($a['account_type']), account_type_tone($a['account_type'])); ?></td><td class="right"><?php echo e(money($in)); ?></td><td class="right"><?php echo e(money($out)); ?></td><td class="right"><strong class="<?php echo $net>=0?'text-success':'text-danger'; ?>"><?php echo e(money($net)); ?></strong></td><td class="right"><strong><?php echo e(money($bal)); ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel-card report-block">
  <div class="card-head statement-section-title"><h3>2. Kasa/banka hareketleri</h3><small><?php echo count($accountTransactions); ?> kayıt</small></div>
  <div class="table-wrap"><table>
    <thead><tr><th style="width:90px">Tarih</th><th>Hesap</th><th>Kaynak</th><th>Açıklama</th><th class="right">Giriş</th><th class="right">Çıkış</th></tr></thead><tbody>
    <?php if(!$accountTransactions): ?><tr><td colspan="6" class="empty">Bu ay kasa/banka hareketi yok.</td></tr><?php endif; ?>
    <?php foreach($accountTransactions as $tr): ?>
      <tr><td><?php echo e(tr_date($tr['transaction_date'])); ?></td><td><strong><?php echo e(short_text($tr['account_name'], 28)); ?></strong><small><?php echo e(account_type_label($tr['account_type'])); ?></small></td><td><?php echo badge(hesap_dokum_source_label($tr['source_type']), $tr['direction']==='in'?'success':'danger'); ?></td><td><?php echo e(short_text($tr['description'] ?: '-', 70)); ?></td><td class="right"><?php echo $tr['direction']==='in' ? e(money($tr['amount'])) : '-'; ?></td><td class="right"><?php echo $tr['direction']==='out' ? e(money($tr['amount'])) : '-'; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel-card report-block">
  <div class="card-head statement-section-title"><h3>3. Cari hareketler</h3><small><?php echo count($movements); ?> kayıt</small></div>
  <div class="table-wrap"><table>
    <thead><tr><th style="width:90px">Tarih</th><th>Cari</th><th>Tip</th><th>Açıklama</th><th class="right">Tutar</th></tr></thead><tbody>
    <?php if(!$movements): ?><tr><td colspan="5" class="empty">Bu ay cari hareket yok.</td></tr><?php endif; ?>
    <?php foreach($movements as $m): ?>
      <tr><td><?php echo e(tr_date($m['movement_date'])); ?></td><td><?php echo $m['cari_id'] ? '<a href="cari-detay.php?id=' . e($m['cari_id']) . '">' . e(short_text($m['cari_name'] ?: '-', 38)) . '</a>' : e(short_text($m['cari_name'] ?: '-', 38)); ?></td><td><?php echo badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td><td><?php echo e(short_text($m['description'] ?: '-', 75)); ?></td><td class="right"><strong><?php echo e(money($m['amount'])); ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel-card report-block">
  <div class="card-head statement-section-title"><h3>4. Çek vade dökümü</h3><small><?php echo count($checks); ?> kayıt · nakit toplamına dahil değil</small></div>
  <div class="table-wrap"><table>
    <thead><tr><th style="width:90px">Vade</th><th>Yön</th><th>Cari</th><th>Banka / Çek No</th><th class="right">Tutar</th></tr></thead><tbody>
    <?php if(!$checks): ?><tr><td colspan="5" class="empty">Bu ay vadesi gelen çek yok.</td></tr><?php endif; ?>
    <?php foreach($checks as $ch): ?>
      <tr><td><?php echo e(tr_date($ch['due_date'])); ?></td><td><?php echo badge(check_direction_label($ch['direction']), check_direction_tone($ch['direction'])); ?></td><td><?php echo e(short_text($ch['cari_name'] ?: '-', 38)); ?></td><td><?php echo e(short_text(trim(($ch['bank_name'] ?: '-') . ' / ' . ($ch['check_no'] ?: '-')), 55)); ?></td><td class="right"><strong><?php echo e(money($ch['amount'])); ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<p class="calc-note report-calc-note"><strong>Not:</strong> Nakit giriş/çıkış hesaplarında çekler hariç tutulur. Çekler vade takibi için ayrı listelenir.</p>
</div>
<?php page_footer(); ?>
