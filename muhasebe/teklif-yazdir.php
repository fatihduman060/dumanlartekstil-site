<?php
require_once __DIR__ . '/teklif-db.php';
require_once __DIR__ . '/layout.php';
require_login();

$offerId = (int)($_GET['id'] ?? 0);
$offer = teklif_load($offerId);
if (!$offer) {
    flash('error', 'PDF alınacak teklif bulunamadı.');
    redirect('teklif-ver.php');
}

$title = $offer['document_title'] ?: 'SİPARİŞ FİŞİ';
$offerNo = $offer['offer_no'] ?: '';
$offerDate = $offer['offer_date'] ?: date('Y-m-d');
$customer = $offer['customer_name'] ?: '';
$city = $offer['customer_city'] ?: '';
$customerAddress = trim((string)($offer['customer_address'] ?? ''));
$customerTaxOffice = trim((string)($offer['customer_tax_office'] ?? ''));
$customerTaxNo = trim((string)($offer['customer_tax_no'] ?? ''));
$customerPhone = trim((string)($offer['customer_phone'] ?? ''));
$currency = $offer['currency'] ?: 'TL';
$quantityLabel = $offer['quantity_label'] ?: 'DZ';
$note = $offer['note'] ?: '';
$footerText = $offer['footer_text'] ?: 'MALIMIZDAN HAYIR GÖRÜN.';
$termText = $offer['term_text'] ?: '';

function teklif_print_money($value): string
{
    return number_format((float)$value, 2, ',', '.');
}

$logoSrc = 'assets/dumanlar-logo-arkaplansiz.png?v=20';
$items = $offer['items'] ?? [];
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($title . ' ' . $offerNo); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eee;font-family:Arial,Helvetica,sans-serif;color:#111}.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;gap:10px;padding:12px;background:#102818}.toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 18px;background:#fff;color:#102818;font-weight:800;text-decoration:none;cursor:pointer}.sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:12mm 12mm 10mm;box-shadow:0 8px 30px rgba(0,0,0,.12)}.top{display:grid;grid-template-columns:1fr 1fr;gap:10mm;align-items:start}.company{display:flex;gap:10px;align-items:center}.company img{width:60px;height:60px;object-fit:contain}.company h1{font-size:18px;margin:0 0 4px}.company p,.customer p{margin:2px 0;font-size:11px}.doc{text-align:right}.doc h2{font-size:22px;margin:0 0 8px}.doc table{margin-left:auto;border-collapse:collapse;font-size:11px}.doc td{padding:3px 0 3px 12px}.customer{margin:10mm 0 5mm;padding:5mm;border:1px solid #222}.customer h3{margin:0 0 5px;font-size:13px}.items{width:100%;border-collapse:collapse;font-size:10px}.items th,.items td{border:1px solid #222;padding:5px}.items th{background:#e8efe9}.right{text-align:right}.center{text-align:center}.totals{width:75mm;margin:5mm 0 0 auto;border-collapse:collapse;font-size:11px}.totals td{border:1px solid #222;padding:5px}.totals strong{font-size:13px}.notes{margin-top:8mm;font-size:10px;line-height:1.5}.footer{margin-top:15mm;text-align:center;font-weight:800;font-size:12px}.signs{margin-top:18mm;display:grid;grid-template-columns:1fr 1fr;gap:25mm;text-align:center;font-size:11px}.sign-line{border-top:1px solid #222;padding-top:5px}@media print{body{background:#fff}.toolbar{display:none}.sheet{margin:0;box-shadow:none;width:210mm;min-height:297mm;page-break-after:always}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Yazdır / PDF</button><a href="teklif-ver.php?edit=<?php echo e($offer['id']); ?>">Düzenle</a></div>
<section class="sheet">
  <div class="top">
    <div class="company"><img src="<?php echo e($logoSrc); ?>" alt="Dumanlar"><div><h1>DUMANLAR A.Ş.</h1><p>Tokat / Erbaa</p><p>Çorap üretimi ve toptan satış</p></div></div>
    <div class="doc"><h2><?php echo e($title); ?></h2><table><tr><td>No</td><td><strong><?php echo e($offerNo); ?></strong></td></tr><tr><td>Tarih</td><td><?php echo e(tr_date($offerDate)); ?></td></tr></table></div>
  </div>
  <div class="customer"><h3><?php echo e($customer); ?></h3><?php if($city): ?><p><?php echo e($city); ?></p><?php endif; ?><?php if($customerAddress): ?><p><?php echo nl2br(e($customerAddress)); ?></p><?php endif; ?><?php if($customerTaxOffice||$customerTaxNo): ?><p>Vergi: <?php echo e(trim($customerTaxOffice . ' / ' . $customerTaxNo, ' /')); ?></p><?php endif; ?><?php if($customerPhone): ?><p>Telefon: <?php echo e($customerPhone); ?></p><?php endif; ?></div>
  <table class="items"><thead><tr><th>#</th><th>Barkod</th><th>Ürün adı</th><th>Ürün cinsi / açıklama</th><th><?php echo e($quantityLabel); ?></th><th>Birim fiyat</th><th>Tutar</th></tr></thead><tbody><?php foreach($items as $i=>$item): ?><tr><td class="center"><?php echo e($i+1); ?></td><td><?php echo e($item['product_barcode'] ?? ''); ?></td><td><?php echo e($item['product_name'] ?? ''); ?></td><td><?php echo e($item['product_type'] ?? ''); ?></td><td class="right"><?php echo e((string)($item['quantity'] ?? '')); ?></td><td class="right"><?php echo e(teklif_print_money($item['unit_price'] ?? 0) . ' ' . $currency); ?></td><td class="right"><strong><?php echo e(teklif_print_money($item['line_total'] ?? 0) . ' ' . $currency); ?></strong></td></tr><?php endforeach; ?></tbody></table>
  <table class="totals"><tr><td>Ara toplam</td><td class="right"><?php echo e(teklif_print_money($offer['subtotal'] ?? 0) . ' ' . $currency); ?></td></tr><?php if((int)($offer['discount_enabled'] ?? 0)===1): ?><tr><td>İskonto %<?php echo e((string)($offer['discount_rate'] ?? 0)); ?></td><td class="right">-<?php echo e(teklif_print_money($offer['discount_amount'] ?? 0) . ' ' . $currency); ?></td></tr><?php endif; ?><?php if((int)($offer['vat_enabled'] ?? 0)===1): ?><tr><td>KDV %<?php echo e((string)($offer['vat_rate'] ?? 0)); ?></td><td class="right"><?php echo e(teklif_print_money($offer['vat_amount'] ?? 0) . ' ' . $currency); ?></td></tr><?php endif; ?><tr><td><strong>Genel toplam</strong></td><td class="right"><strong><?php echo e(teklif_print_money($offer['grand_total'] ?? 0) . ' ' . $currency); ?></strong></td></tr></table>
  <div class="notes"><?php if($note): ?><p><strong>Açıklama:</strong> <?php echo nl2br(e($note)); ?></p><?php endif; ?><?php if($termText): ?><p><strong>Teklif notu:</strong> <?php echo nl2br(e($termText)); ?></p><?php endif; ?></div>
  <div class="footer"><?php echo e($footerText); ?></div>
  <div class="signs"><div class="sign-line">DUMANLAR A.Ş.</div><div class="sign-line">Müşteri onayı</div></div>
</section>
</body>
</html>
