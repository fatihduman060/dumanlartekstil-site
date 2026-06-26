<?php
require_once __DIR__ . '/teklif-db.php';
require_login();

$offerId = (int)($_GET['id'] ?? 0);
$offer = teklif_load($offerId);
if (!$offer) {
    flash('error', 'PDF alınacak teklif bulunamadı.');
    redirect('teklif-ver.php');
}

$title = $offer['document_title'] ?: 'TEKLİF FORMU';
$offerNo = $offer['offer_no'] ?: '';
$offerDate = $offer['offer_date'] ?: date('Y-m-d');
$customer = $offer['customer_name'] ?: '';
$city = $offer['customer_city'] ?: '';
$currency = $offer['currency'] ?: 'TL';
$quantityLabel = $offer['quantity_label'] ?: 'DZ';
$note = $offer['note'] ?: '';
$footerText = $offer['footer_text'] ?: 'MALIMIZDAN HAYIR GÖRÜN.';
$termText = $offer['term_text'] ?: '';
$subtotal = (float)($offer['subtotal'] ?? 0);
$vatEnabled = (int)($offer['vat_enabled'] ?? 0) === 1;
$vatRate = (float)($offer['vat_rate'] ?? 10);
$vatAmount = (float)($offer['vat_amount'] ?? 0);
$grandTotal = (float)($offer['grand_total'] ?? ($subtotal + $vatAmount));
$rows = $offer['items'] ?? [];
while (count($rows) < 16) $rows[] = ['product_name'=>'', 'product_type'=>'', 'quantity'=>0, 'unit_price'=>0, 'line_total'=>0];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e($customer); ?></title>
<style>
  *{box-sizing:border-box} body{margin:0;background:#d9d9d9;font-family:Arial,Helvetica,sans-serif;color:#111}.toolbar{position:sticky;top:0;z-index:10;display:flex;gap:10px;justify-content:center;padding:12px;background:#102818}.toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:#102818;font-weight:800;text-decoration:none;cursor:pointer}.page{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:10mm 9mm 8mm;border:1px solid #b8b8b8;box-shadow:0 8px 25px rgba(0,0,0,.18);position:relative}.top-blue{height:13px;background:#11aee2;margin:-4mm -4mm 0}.brand{display:grid;grid-template-columns:1.15fr .85fr;gap:12px;align-items:center;padding:10px 8px 8px;border-bottom:7px solid #006695}.brand-left strong{display:block;font-size:42px;letter-spacing:2px;line-height:.9}.brand-left span{display:block;font-size:13px;font-weight:700;letter-spacing:.5px;margin-top:5px}.brand-right{text-align:right}.bitke{font-size:58px;font-weight:900;letter-spacing:-3px;line-height:.82}.corap{font-size:34px;font-family:Georgia,serif;color:#555;letter-spacing:1px}.contact-strip{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin:0 0 12px;color:#fff;font-size:10px;font-weight:700}.contact-strip div{background:#11aee2;padding:5px 8px}.contact-strip div:nth-child(2){background:#0876a5}.doc-title{text-align:center;margin:10px 0 8px}.doc-title h1{font-size:38px;margin:0;letter-spacing:1px}.doc-title p{margin:4px 0 0;font-weight:700;font-size:12px}.info{display:grid;grid-template-columns:1fr 180px;gap:20px;align-items:start;margin:12px 0 20px;min-height:70px}.customer{text-align:center;font-size:14px;font-weight:700;padding-top:20px}.customer strong{display:block;margin-bottom:28px}.info-box table{width:100%;border-collapse:collapse;font-size:12px;font-weight:700}.info-box td{padding:4px 6px;border-bottom:2px solid #bbb}.info-box td:last-child{text-align:right;color:#e02b2b}.items{width:100%;border-collapse:collapse;font-size:12px}.items thead th{background:#12aee3;color:#111;text-align:center;padding:5px;border-right:4px solid #fff}.items thead tr.sub th{background:#fff;color:#222;font-size:11px;padding:3px;border-bottom:2px solid #777}.items td{height:20px;padding:4px 5px;border-bottom:2px solid #9d9d9d}.items td:nth-child(2),.items td:nth-child(3),.items td:nth-child(4),.items td:nth-child(5){text-align:center}.items .right{text-align:right!important}.items small{font-size:10px;color:#444}.totals{width:55%;margin:8px 0 0 auto;border-collapse:collapse;font-size:12px;font-weight:900}.totals td{border-bottom:2px solid #777;padding:5px 7px}.totals td:last-child{text-align:right}.totals .grand td{border-bottom:3px double #333;font-size:14px}.note{text-align:center;margin:20px 30px 18px;font-size:13px}.term{text-align:left;margin:10px 30px 0;font-size:11px;color:#333}.footer-band{position:absolute;left:9mm;right:9mm;bottom:7mm;background:#11aee2;text-align:center;font-weight:900;padding:5px;font-size:13px}@page{size:A4;margin:0}@media print{body{background:#fff}.toolbar{display:none}.page{margin:0;border:0;box-shadow:none;width:210mm;min-height:297mm;page-break-after:always}}
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Yazdır / PDF al</button><a href="teklif-ver.php?edit=<?php echo e($offerId); ?>">Düzenlemeye dön</a><a href="teklif-ver.php">Teklif listesi</a></div>
  <main class="page">
    <div class="top-blue"></div>
    <section class="brand">
      <div class="brand-left"><strong>DUMANLAR</strong><span>KONFEKSİYON BEYAZ EŞYA TİC VE SAN A.Ş</span></div>
      <div class="brand-right"><div class="bitke">bitke</div><div class="corap">ÇORAP</div></div>
    </section>
    <section class="contact-strip">
      <div>www.dumanlartekstil.com.tr &nbsp;&nbsp; +90 356 715 82 83 &nbsp;&nbsp; +90 356 716 03 46</div>
      <div>Kayakaya Bulvarı Organize Sanayi Bölgesi Beylik Bükü Cad. No:6 Erbaa/TOKAT / TÜRKİYE</div>
    </section>
    <section class="doc-title"><h1><?php echo e($title); ?></h1><p><?php echo e($customer); ?></p></section>
    <section class="info">
      <div class="customer"><strong><?php echo e($customer); ?></strong><span><?php echo e($city); ?></span></div>
      <div class="info-box"><table><tr><td>TEKLİF TARİHİ :</td><td><?php echo e(tr_date($offerDate)); ?></td></tr><tr><td>TEKLİF NO :</td><td><?php echo e($offerNo); ?></td></tr></table></div>
    </section>
    <table class="items">
      <thead><tr><th>ÜRÜN ADI</th><th><?php echo e($quantityLabel); ?></th><th>BİRİM FİYATI</th><th>TUTAR</th></tr><tr class="sub"><th>ÜRÜN CİNSİ</th><th>MİKTAR</th><th>BİRİM FİYAT</th><th>TUTAR</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $qty=(float)($r['quantity'] ?? 0); $price=(float)($r['unit_price'] ?? 0); $line=(float)($r['line_total'] ?? 0); ?>
        <tr>
          <td><?php echo e(($r['product_name'] ?? '') ?: ($r['product_type'] ?? '')); ?><?php echo (!empty($r['product_name']) && !empty($r['product_type'])) ? '<br><small>'.e($r['product_type']).'</small>' : ''; ?></td>
          <td><?php echo $qty > 0 ? e(number_format($qty, 0, ',', '.')) : ''; ?></td>
          <td><?php echo $price > 0 ? e(teklif_money($price)) : ''; ?></td>
          <td class="right"><?php echo $line > 0 ? e(teklif_money($line)) : '0,00'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <table class="totals">
      <tr><td>ARA TOPLAM :</td><td><?php echo e(teklif_money($subtotal)); ?> <?php echo e($currency); ?></td></tr>
      <?php if ($vatEnabled): ?><tr><td>KDV (%<?php echo e((string)$vatRate); ?>) :</td><td><?php echo e(teklif_money($vatAmount)); ?> <?php echo e($currency); ?></td></tr><?php endif; ?>
      <tr class="grand"><td>GENEL TOPLAM :</td><td><?php echo e(teklif_money($grandTotal)); ?> <?php echo e($currency); ?></td></tr>
    </table>
    <?php if ($note !== ''): ?><div class="note"><?php echo nl2br(e($note)); ?></div><?php endif; ?>
    <?php if ($termText !== ''): ?><div class="term"><?php echo e($termText); ?></div><?php endif; ?>
    <div class="footer-band"><?php echo e($footerText); ?></div>
  </main>
</body>
</html>
