<?php
require_once __DIR__.'/depo-cikis-lib.php';
require_once __DIR__.'/magaza-kullanici.php';
require_login();
if(!can_access_warehouse_dispatch()) redirect('dashboard.php');
$row=depo_cikis_load((int)($_GET['id']??0));
if(!$row) redirect('depo-cikis.php');
if(is_warehouse_user() && (int)($row['created_by']??0)!==(int)(current_user()['id']??0)) redirect('depo-cikis.php');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sipariş Fişi #<?php echo e($row['dispatch_no']); ?></title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:#e8e8e8;font:14px Arial,sans-serif;color:#102818}
.bar{padding:12px;text-align:center;background:#102818}
.bar button,.bar a{padding:9px 14px;border:0;border-radius:20px;background:#fff;color:#102818;text-decoration:none;font-weight:bold;cursor:pointer}
.page{width:210mm;min-height:297mm;margin:12px auto;padding:10mm 12mm;background:#fff}
.head{text-align:center;border-bottom:2px solid #b99245;padding-bottom:5mm;margin-bottom:5mm}
.logo{width:94mm;height:23mm;object-fit:contain}
.head h1{margin:3mm 0 0;font-size:18px;letter-spacing:1.4px}
.info{display:grid;grid-template-columns:minmax(0,1fr) 62mm;gap:10mm;margin:5mm 0 6mm}
.info h2{margin:0 0 3px;font-size:16px;line-height:1.1}
.info p{margin:3px 0 0;line-height:1.25;font-size:12px}
.box{border:1px solid #102818;border-radius:6px;padding:7px}
.box div{display:flex;justify-content:space-between;gap:8px;padding:3px;font-size:11px}
table{width:100%;border-collapse:collapse;table-layout:fixed;border:1px solid #9f9f9f}
th{background:#102818;color:#f2d18b;padding:6px 5px;font-size:10px;line-height:1.15;border:1px solid #6f756f;font-weight:700}
td{padding:5px;border:1px solid #b8b8b8;font-size:10px;line-height:1.15;vertical-align:middle;word-break:break-word}
tbody tr:nth-child(odd){background:#ffffff}
tbody tr:nth-child(even){background:#f2efe9}
th:nth-child(1),td:nth-child(1){width:24%}
th:nth-child(2),td:nth-child(2){width:38%}
th:nth-child(3),td:nth-child(3){width:10%}
th:nth-child(4),td:nth-child(4){width:14%}
th:nth-child(5),td:nth-child(5){width:14%}
.num{text-align:right;white-space:nowrap}
.totals{display:grid;justify-content:end;gap:3px;margin-top:5mm}
.total-row{display:grid;grid-template-columns:42mm 38mm;gap:6px;text-align:right;font-size:11px}
.total-row.discount strong{color:#a13c3c}
.total-row.grand{font-size:15px;border-top:2px solid #6f6f6f;padding-top:4px;margin-top:2px}
.note{margin-top:5mm;border-top:1px solid #aaa;padding-top:3mm;font-size:10px;line-height:1.25}

/* İlk sıkıştırma: görünümü bozmadan orta uzunlukta fişleri tek sayfaya alır. */
body.fit-1 .page{padding:8mm 10mm}
body.fit-1 .head{padding-bottom:3.5mm;margin-bottom:3.5mm}
body.fit-1 .logo{width:86mm;height:20mm}
body.fit-1 .head h1{margin-top:2mm;font-size:16px}
body.fit-1 .info{gap:8mm;margin:3.5mm 0 4mm}
body.fit-1 .info h2{font-size:14px}
body.fit-1 .info p{font-size:10.5px;line-height:1.15}
body.fit-1 .box{padding:5px}
body.fit-1 .box div{padding:2px;font-size:10px}
body.fit-1 th{padding:5px 4px;font-size:9px}
body.fit-1 td{padding:4px;font-size:9px;line-height:1.08}
body.fit-1 .totals{margin-top:3mm;gap:2px}
body.fit-1 .total-row{font-size:10px}
body.fit-1 .total-row.grand{font-size:13px;padding-top:3px}
body.fit-1 .note{margin-top:3mm;padding-top:2mm;font-size:9px}

/* İkinci sıkıştırma: hâlâ okunabilir kalarak tek A4 için son kademe. */
body.fit-2 .page{padding:6mm 8mm}
body.fit-2 .head{padding-bottom:2mm;margin-bottom:2mm}
body.fit-2 .logo{width:78mm;height:17mm}
body.fit-2 .head h1{margin-top:1.5mm;font-size:14px}
body.fit-2 .info{grid-template-columns:minmax(0,1fr) 55mm;gap:6mm;margin:2.5mm 0 3mm}
body.fit-2 .info h2{font-size:12px}
body.fit-2 .info p{font-size:9px;line-height:1.08}
body.fit-2 .box{padding:4px}
body.fit-2 .box div{padding:1.5px;font-size:9px}
body.fit-2 th{padding:4px 3px;font-size:8px}
body.fit-2 td{padding:3px;font-size:8px;line-height:1.02}
body.fit-2 .totals{margin-top:2.5mm;gap:1px}
body.fit-2 .total-row{grid-template-columns:38mm 34mm;font-size:9px}
body.fit-2 .total-row.grand{font-size:12px;padding-top:2px}
body.fit-2 .note{margin-top:2mm;padding-top:1.5mm;font-size:8px}

thead{display:table-header-group}
tr{break-inside:avoid;page-break-inside:avoid}
@page{size:A4;margin:0}
@media print{
  body{background:#fff}
  .bar{display:none!important}
  .page{margin:0;width:210mm;box-shadow:none}
  table,thead,tbody,tr,th,td{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  body:not(.multi-page) .page{height:297mm;min-height:297mm;overflow:hidden}
  body.multi-page .page{height:auto;min-height:297mm;overflow:visible}
}
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Yazdır / PDF</button> <a href="depo-cikis.php?edit=<?php echo e($row['id']); ?>">Düzenlemeye dön</a></div>
<main class="page" id="printPage">
  <header class="head">
    <img class="logo" src="assets/dumanlar-logo-arkaplansiz.png?v=20" alt="Dumanlar">
    <h1>SİPARİŞ FİŞİ</h1>
  </header>
  <section class="info">
    <div>
      <h2><?php echo e($row['customer_name']); ?></h2>
      <div><?php echo e($row['customer_city']); ?></div>
      <p><?php echo nl2br(e($row['customer_address'])); ?></p>
    </div>
    <div class="box">
      <div><b>Tarih</b><span><?php echo e(tr_date($row['dispatch_date'])); ?></span></div>
      <div><b>Fiş No</b><span><?php echo e($row['dispatch_no']); ?></span></div>
    </div>
  </section>
  <table>
    <thead><tr><th>Barkod</th><th>Ürün</th><th>Miktar</th><th>Birim Fiyat</th><th>Tutar</th></tr></thead>
    <tbody>
    <?php foreach($row['items'] as $i): ?>
      <tr>
        <td><?php echo e($i['product_barcode']); ?></td>
        <td><?php echo e($i['product_name']); ?></td>
        <td class="num"><?php echo e(number_format((float)$i['quantity'],0,',','.')); ?></td>
        <td class="num"><?php echo e(money((float)$i['unit_price'])); ?></td>
        <td class="num"><?php echo e(money((float)$i['line_total'])); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="totals">
    <div class="total-row"><span>Ara Toplam:</span><strong><?php echo e(money((float)($row['subtotal']??$row['total']))); ?> TL</strong></div>
    <?php if((int)($row['discount_enabled']??0)===1): ?><div class="total-row discount"><span>İskonto (%<?php echo e((string)($row['discount_rate']??0)); ?>):</span><strong>-<?php echo e(money((float)($row['discount_amount']??0))); ?> TL</strong></div><?php endif; ?>
    <?php if((int)($row['vat_enabled']??0)===1): ?><div class="total-row"><span>KDV (%<?php echo e((string)($row['vat_rate']??0)); ?>):</span><strong><?php echo e(money((float)($row['vat_amount']??0))); ?> TL</strong></div><?php endif; ?>
    <div class="total-row grand"><span>GENEL TOPLAM:</span><strong><?php echo e(money((float)$row['total'])); ?> TL</strong></div>
  </div>
  <?php if(trim((string)$row['note'])!==''): ?><div class="note"><b>NOT:</b> <?php echo nl2br(e($row['note'])); ?></div><?php endif; ?>
</main>
<script>
(function(){
  var page=document.getElementById('printPage');
  if(!page) return;

  function overflowsA4(){
    var oldHeight=page.style.height;
    var oldMinHeight=page.style.minHeight;
    var oldOverflow=page.style.overflow;
    page.style.height='297mm';
    page.style.minHeight='297mm';
    page.style.overflow='hidden';
    var overflow=page.scrollHeight>page.clientHeight+2;
    page.style.height=oldHeight;
    page.style.minHeight=oldMinHeight;
    page.style.overflow=oldOverflow;
    return overflow;
  }

  function fitForPrint(){
    document.body.classList.remove('fit-1','fit-2','multi-page');

    if(!overflowsA4()) return;
    document.body.classList.add('fit-1');
    if(!overflowsA4()) return;

    document.body.classList.remove('fit-1');
    document.body.classList.add('fit-2');
    if(!overflowsA4()) return;

    // Bu noktada liste gerçekten uzun: okunabilirliği koru ve ikinci sayfaya izin ver.
    document.body.classList.add('multi-page');
  }

  window.addEventListener('beforeprint',fitForPrint);
  window.addEventListener('resize',function(){window.clearTimeout(window.__wdFitTimer);window.__wdFitTimer=window.setTimeout(fitForPrint,120)});
  window.addEventListener('load',function(){setTimeout(fitForPrint,60);setTimeout(fitForPrint,350)});
  if(document.fonts&&document.fonts.ready) document.fonts.ready.then(fitForPrint);
  fitForPrint();
})();
</script>
</body>
</html>
