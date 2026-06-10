<?php
require_once __DIR__ . '/layout.php';
require_login();

$status = trim($_GET['status'] ?? '');
$cariId = trim($_GET['cari_id'] ?? '');
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$q = trim($_GET['q'] ?? '');
$print = isset($_GET['print']);

$where = [];
$params = [];
if ($status !== '' && isset(private_receivable_statuses()[$status])) { $where[] = 'pr.status=?'; $params[] = $status; }
if ($cariId !== '') { $where[] = 'pr.cari_id=?'; $params[] = (int)$cariId; }
if ($start !== '') { $where[] = 'pr.receivable_date>=?'; $params[] = $start; }
if ($end !== '') { $where[] = 'pr.receivable_date<=?'; $params[] = $end; }
if ($q !== '') { $where[] = '(pr.description LIKE ? OR c.name LIKE ? OR pr.document_name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }

$sql = "SELECT pr.*, c.name AS cari_name, u.display_name AS user_name
    FROM private_receivables pr
    JOIN cariler c ON c.id=pr.cari_id
    LEFT JOIN users u ON u.id=pr.created_by";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY pr.receivable_date DESC, pr.id DESC LIMIT 1000';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = private_receivable_totals(['status'=>$status, 'cari_id'=>$cariId, 'start'=>$start, 'end'=>$end, 'q'=>$q]);
$cariler = cariler_for_select();
$queryBase = http_build_query(['status'=>$status, 'cari_id'=>$cariId, 'start'=>$start, 'end'=>$end, 'q'=>$q]);

page_header('Özel Alacak Raporu', 'ozel_alacaklar');
?>
<section class="hero-card <?php echo $print ? 'print-hide' : ''; ?>">
  <div>
    <span class="status-pill">Genel bakiyeden izole takip</span>
    <h2>Özel alacakların toplu raporu.</h2>
    <p>Bu ekrandaki tutarlar normal cari bakiyeyi, kasa/bankayı ve dashboard ana toplamlarını etkilemez. Sadece özel takip kayıtlarını gösterir.</p>
  </div>
  <div class="hero-actions">
    <a class="btn btn-secondary" href="export.php?type=private_receivables&<?php echo e($queryBase); ?>">CSV indir</a>
    <a class="btn btn-secondary" href="ozel-alacaklar.php?<?php echo e($queryBase); ?>&print=1" target="_blank">PDF/Yazdır</a>
  </div>
</section>

<section class="panel-card report-controls <?php echo $print ? 'print-hide' : ''; ?>">
  <form class="filterbar multi ultra" method="get">
    <input name="q" placeholder="Cari, açıklama, belge ara" value="<?php echo e($q); ?>">
    <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
    <select name="status"><option value="">Tüm durumlar</option><?php foreach(private_receivable_statuses() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $status===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select>
    <input type="date" name="start" value="<?php echo e($start); ?>">
    <input type="date" name="end" value="<?php echo e($end); ?>">
    <button class="btn btn-secondary" type="submit">Filtrele</button>
  </form>
</section>

<section class="stats-grid four report-block">
  <article class="stat-card special"><span>Açık özel alacak</span><strong><?php echo e(money($summary['acik'])); ?></strong><small>Takip edilecek tutar</small></article>
  <article class="stat-card soft"><span>Kapanan özel alacak</span><strong><?php echo e(money($summary['kapandi'])); ?></strong><small>Tahsil/ödeme hesabına karışmaz</small></article>
  <article class="stat-card soft"><span>İptal özel alacak</span><strong><?php echo e(money($summary['iptal'])); ?></strong><small>Bilgi amaçlı</small></article>
  <article class="stat-card soft"><span>Kayıt sayısı</span><strong><?php echo e((string)$summary['count']); ?></strong><small>Filtreye göre</small></article>
</section>

<section class="panel-card report-block">
  <div class="card-head"><h3>Özel alacak listesi</h3><span><?php echo count($rows); ?> kayıt</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Cari</th><th>Durum</th><th>Açıklama</th><th>Belge</th><th>Ekleyen</th><th class="right">Tutar</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">Özel alacak kaydı bulunamadı.</td></tr><?php endif; ?>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo e(tr_date($r['receivable_date'])); ?><small><?php echo e(tr_datetime($r['created_at'])); ?></small></td>
            <td><a href="cari-detay.php?id=<?php echo e($r['cari_id']); ?>#ozel-alacak"><?php echo e($r['cari_name']); ?></a></td>
            <td><?php echo badge(private_receivable_status_label($r['status']), private_receivable_status_tone($r['status'])); ?></td>
            <td><?php echo e($r['description'] ?: '-'); ?></td>
            <td><?php echo !empty($r['document_path']) ? '<a href="ozel-belge-indir.php?id=' . e($r['id']) . '" target="_blank">' . e(document_type_label($r['document_type'])) . '</a>' : '-'; ?></td>
            <td><?php echo e($r['user_name'] ?: '-'); ?></td>
            <td class="right"><strong><?php echo e(money($r['amount'])); ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php if($print): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script><?php endif; ?>
<?php page_footer(); ?>
