<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/kredi-kartlari-lib.php';
require_login();
require_private_finance_modules();

$pdo = db();
ensure_column($pdo, 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
ensure_column($pdo, 'movements', 'card_key', 'TEXT');
ensure_column($pdo, 'movements', 'report_excluded', 'INTEGER NOT NULL DEFAULT 0');

$start = trim((string)($_GET['start'] ?? ''));
$end = trim((string)($_GET['end'] ?? ''));
$cariId = trim((string)($_GET['cari_id'] ?? ''));
$cardKey = trim((string)($_GET['card_key'] ?? ''));
$where = ["COALESCE(m.is_cancelled,0)=0", "m.movement_type='odeme'", "COALESCE(m.report_excluded,0)=1", "COALESCE(m.card_key,'')<>''"];
$params = [];
if ($start !== '') { $where[] = 'm.movement_date>=?'; $params[] = $start; }
if ($end !== '') { $where[] = 'm.movement_date<=?'; $params[] = $end; }
if ($cariId !== '') { $where[] = 'm.cari_id=?'; $params[] = (int)$cariId; }
if ($cardKey !== '') { $where[] = 'm.card_key=?'; $params[] = $cardKey; }

$sql = "SELECT m.*, c.name AS cari_name, c.city AS cari_city
    FROM movements m
    LEFT JOIN cariler c ON c.id=m.cari_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.movement_date DESC, m.id DESC LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];
$cards = muhasebe_kredi_kartlari();
$cariler = cariler_for_select();
$total = 0.0;
foreach ($rows as $row) $total += (float)$row['amount'];

page_header('Kart ile Yapılan Ödemeler', 'cekler');
?>
<style>
.cardpay-page{display:grid;gap:16px;max-width:1540px;margin:0 auto}.cardpay-hero{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:20px 22px;border-radius:22px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}.cardpay-hero h2{margin:4px 0;color:#fff}.cardpay-hero p{margin:0;color:#e9f5ed}.cardpay-actions{display:flex;gap:8px;flex-wrap:wrap}.cardpay-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 13px;border-radius:999px;background:#fff;color:#16482e;text-decoration:none;font-weight:900}.cardpay-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.cardpay-summary article{padding:14px 16px;border:1px solid #e5dccf;border-radius:16px;background:#fff}.cardpay-summary span{display:block;font-size:11px;color:#776b5c;font-weight:800}.cardpay-summary strong{display:block;margin-top:5px;font-size:22px;color:#102818}.cardpay-filter{display:grid;grid-template-columns:150px 150px minmax(180px,1fr) minmax(190px,1fr) auto;gap:9px;padding:13px;border:1px solid #e5dccf;border-radius:16px;background:#fff}.cardpay-filter input,.cardpay-filter select,.cardpay-filter button{min-height:40px;border:1px solid #d8cdbb;border-radius:11px;padding:8px 10px;background:#fff}.cardpay-table{background:#fff;border:1px solid #e5dccf;border-radius:18px;overflow:hidden}.cardpay-table .table-wrap{overflow:auto}.cardpay-table table{width:100%;min-width:980px;border-collapse:collapse}.cardpay-table th{background:#16482e;color:#fff;text-align:left;padding:10px 11px;font-size:11px}.cardpay-table td{padding:11px;border-bottom:1px solid #eee5d8;font-size:12px;vertical-align:top}.cardpay-table small{display:block;margin-top:3px;color:#776b5c}@media(max-width:800px){.cardpay-hero{display:block}.cardpay-actions{margin-top:10px}.cardpay-filter{grid-template-columns:1fr 1fr}.cardpay-summary{grid-template-columns:1fr}}@media(max-width:520px){.cardpay-filter{grid-template-columns:1fr}}
</style>
<div class="cardpay-page">
  <section class="cardpay-hero">
    <div><span>MUHASEBE DÖKÜMÜ</span><h2>Kart ile yapılan ödemeler</h2><p>Cari borcu kredi kartı ile kapatılan hareketler. Kasa/banka ve yönetim raporlarında ikinci gider oluşturmaz.</p></div>
    <div class="cardpay-actions"><a href="cekler.php">Çeklere dön</a><a href="kartli-odemeler-excel.php">Excel indir</a></div>
  </section>
  <section class="cardpay-summary"><article><span>Kayıt adedi</span><strong><?php echo e((string)count($rows)); ?></strong></article><article><span>Toplam kartlı cari ödeme</span><strong><?php echo e(money($total)); ?></strong></article></section>
  <form class="cardpay-filter" method="get">
    <input type="date" name="start" value="<?php echo e($start); ?>" aria-label="Başlangıç tarihi">
    <input type="date" name="end" value="<?php echo e($end); ?>" aria-label="Bitiş tarihi">
    <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!==''&&(int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
    <select name="card_key"><option value="">Tüm kredi kartları</option><?php foreach($cards as $key=>$card): ?><option value="<?php echo e($key); ?>" <?php echo $cardKey===$key?'selected':''; ?>><?php echo e($card['name']); ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary" type="submit">Filtrele</button>
  </form>
  <section class="cardpay-table"><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Cari</th><th>Kart / Banka</th><th>Açıklama</th><th>Hareket</th><th>Tutar</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="6">Kart ile yapılan ödeme kaydı bulunamadı.</td></tr><?php endif; ?>
    <?php foreach($rows as $row): $card=$cards[(string)$row['card_key']] ?? ['name'=>$row['payment_method'] ?: 'Kredi Kartı','bank_name'=>'','last4'=>'']; ?>
    <tr><td><?php echo e(tr_date($row['movement_date'])); ?></td><td><strong><?php echo e($row['cari_name'] ?: '-'); ?></strong><small><?php echo e($row['cari_city'] ?: ''); ?></small></td><td><strong><?php echo e($card['name']); ?></strong><small><?php echo e($card['bank_name']); ?></small></td><td><?php echo e($row['description'] ?: '-'); ?></td><td><a href="hareketler.php?edit=<?php echo e($row['id']); ?>">#<?php echo e($row['id']); ?></a></td><td><strong><?php echo e(money($row['amount'])); ?></strong></td></tr>
    <?php endforeach; ?>
  </tbody></table></div></section>
</div>
<?php page_footer(); ?>
