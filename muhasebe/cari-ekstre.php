<?php
require_once __DIR__ . '/layout.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$stmt = db()->prepare('SELECT * FROM cariler WHERE id=?'); $stmt->execute([$id]); $cari = $stmt->fetch();
if (!$cari) { flash('error','Cari bulunamadı.'); redirect('cariler.php'); }
$where=['m.cari_id=?','COALESCE(m.is_cancelled,0)=0']; $params=[$id];
if ($start !== '') { $where[]='m.movement_date>=?'; $params[]=$start; }
if ($end !== '') { $where[]='m.movement_date<=?'; $params[]=$end; }
$sql="SELECT m.*, cat.name AS category_name, a.name AS account_name FROM movements m LEFT JOIN categories cat ON cat.id=m.category_id LEFT JOIN accounts a ON a.id=m.account_id WHERE ".implode(' AND ',$where)." ORDER BY m.movement_date ASC, m.id ASC";
$stmt=db()->prepare($sql); $stmt->execute($params); $movements=$stmt->fetchAll();
$balance=cari_balance($id);
$running = 0;
// Toplam borç / alacak satırları
$totalDebt = 0; $totalCredit = 0;
foreach ($movements as $m) {
    if (in_array($m['movement_type'], ['alacak', 'gelir'], true)) $totalCredit += (float)$m['amount'];
    elseif (in_array($m['movement_type'], ['tahsilat'], true)) $totalDebt += (float)$m['amount']; // reduces our receivable
    elseif (in_array($m['movement_type'], ['verecek', 'gider'], true)) $totalDebt += (float)$m['amount'];
    elseif ($m['movement_type'] === 'odeme') $totalCredit += (float)$m['amount']; // reduces payable
}
page_header('Cari Ekstresi', 'raporlar');
?>

<section class="panel-card report-controls print-hide">
  <form class="filterbar" method="get">
    <input type="hidden" name="id" value="<?php echo e($id); ?>">
    <input type="date" name="start" value="<?php echo e($start); ?>">
    <input type="date" name="end" value="<?php echo e($end); ?>">
    <button class="btn btn-secondary">Filtrele</button>
    <a class="btn btn-secondary" href="export.php?type=movements&cari_id=<?php echo e($id); ?>&start=<?php echo e($start); ?>&end=<?php echo e($end); ?>">Excel CSV</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">🖨 Yazdır / PDF</button>
  </form>
</section>

<!-- Sadece baskıda görünecek başlık -->
<div class="print-header print-only">
  <div class="print-header-inner">
    <div>
      <strong class="print-firma">Bitke / Dumanlar</strong>
      <span>Cari Ekstre Raporu</span>
    </div>
    <div class="print-date">Tarih: <?php echo date('d.m.Y'); ?></div>
  </div>
  <?php if ($start || $end): ?>
  <p class="print-range">Dönem: <?php echo ($start ? tr_date($start) : '—') . ' / ' . ($end ? tr_date($end) : '—'); ?></p>
  <?php endif; ?>
</div>

<section class="hero-card detail-hero report-block">
  <div>
    <span class="status-pill">Cari ekstresi</span>
    <h2><?php echo e($cari['name']); ?></h2>
    <p>
      <?php
      $parts = [];
      if ($cari['authorized_person']) $parts[] = $cari['authorized_person'];
      if ($cari['phone']) $parts[] = $cari['phone'];
      if ($cari['tax_no']) $parts[] = 'V.N: ' . $cari['tax_no'];
      if ($cari['tax_office']) $parts[] = $cari['tax_office'];
      if ($cari['city']) $parts[] = $cari['city'];
      echo e(implode(' · ', $parts) ?: 'Hareket ekstresi');
      ?>
    </p>
  </div>
  <div class="hero-actions print-hide">
    <a class="btn btn-secondary" href="cari-detay.php?id=<?php echo e($id); ?>">Cari detayına dön</a>
  </div>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card"><span>Alacak</span><strong><?php echo e(money($balance['alacak'])); ?></strong><small>Tahsilat: <?php echo e(money($balance['tahsilat'])); ?></small></article>
  <article class="stat-card"><span>Kalan alacak</span><strong><?php echo e(money($balance['net_alacak'])); ?></strong><small>Genel toplam</small></article>
  <article class="stat-card"><span>Verecek</span><strong><?php echo e(money($balance['verecek'])); ?></strong><small>Ödeme: <?php echo e(money($balance['odeme'])); ?></small></article>
  <article class="stat-card"><span>Net bakiye</span><strong class="<?php echo $balance['net'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($balance['net'])); ?></strong><small><?php echo $balance['net'] >= 0 ? 'Alacaklı' : 'Borçlu'; ?></small></article>
</section>

<section class="panel-card report-block">
  <div class="card-head">
    <h3>Ekstre hareketleri</h3>
    <span><?php echo $start || $end ? e(tr_date($start) . ' — ' . tr_date($end)) : 'Tüm kayıtlar (' . count($movements) . ' hareket)'; ?></span>
  </div>
  <div class="table-wrap">
    <table class="ekstre-table">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Vade</th>
          <th>Tip</th>
          <th>Açıklama / Hesap</th>
          <th class="right">Borç</th>
          <th class="right">Alacak</th>
          <th class="right">Bakiye</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$movements): ?><tr><td colspan="7" class="empty">Bu dönemde hareket yok.</td></tr><?php endif; ?>
        <?php
        $running = 0;
        foreach ($movements as $m):
            // Bakiye hesabı: alacak ve odeme pozitif (bize gelecek/borç azaldı), tahsilat ve verecek negatif
            if (in_array($m['movement_type'], ['alacak', 'gelir', 'odeme'], true)) {
                $sign = 1;
            } else {
                $sign = -1;
            }
            $running += $sign * (float)$m['amount'];
            $isDebit = in_array($m['movement_type'], ['verecek', 'gider', 'tahsilat'], true);
            $isCredit = !$isDebit;
        ?>
        <tr>
          <td><?php echo e(tr_date($m['movement_date'])); ?></td>
          <td><?php echo e(tr_date($m['due_date'])); ?></td>
          <td><?php echo badge(movement_label($m['movement_type']), movement_tone($m['movement_type'])); ?></td>
          <td><?php echo e($m['description'] ?: $m['category_name'] ?: '-'); ?><small><?php echo e($m['account_name'] ?: ''); ?></small></td>
          <td class="right"><?php echo $isDebit ? '<strong>' . e(money($m['amount'])) . '</strong>' : '-'; ?></td>
          <td class="right"><?php echo $isCredit ? '<strong>' . e(money($m['amount'])) . '</strong>' : '-'; ?></td>
          <td class="right"><strong class="<?php echo $running >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($running)); ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($movements): ?>
      <tfoot>
        <tr class="ekstre-total">
          <td colspan="4"><strong>TOPLAM</strong></td>
          <td class="right"><strong><?php
            $totalD = 0;
            foreach ($movements as $m) if (in_array($m['movement_type'], ['verecek','gider','tahsilat'], true)) $totalD += (float)$m['amount'];
            echo e(money($totalD));
          ?></strong></td>
          <td class="right"><strong><?php
            $totalC = 0;
            foreach ($movements as $m) if (in_array($m['movement_type'], ['alacak','gelir','odeme'], true)) $totalC += (float)$m['amount'];
            echo e(money($totalC));
          ?></strong></td>
          <td class="right"><strong class="<?php echo $running >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($running)); ?></strong></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>

<script>window.addEventListener('load',()=>{ if(new URLSearchParams(location.search).get('print')==='1') setTimeout(()=>window.print(),350); });</script>
<?php page_footer(); ?>
