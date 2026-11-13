<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$sale = pos_sale($id);
if (!$sale) { http_response_code(404); exit('Fiş bulunamadı.'); }
$isExchange = (string)($sale['sale_type'] ?? 'sale') === 'exchange';
$paymentLabels = ['cash'=>'Nakit','card'=>'Kredi Kartı','mixed'=>'Nakit + Kredi Kartı','credit'=>'Veresiye','none'=>'Fark yok'];
$paymentAmounts = pos_sale_payment_amounts($sale);
$autoPrint = (string)($_GET['print'] ?? '') === '1';
$returnedTotal = 0.0;
$issuedTotal = 0.0;
if ($isExchange) {
    foreach ($sale['items'] as $item) {
        if (($item['exchange_role'] ?? '') === 'returned' || (float)$item['quantity'] < 0) $returnedTotal += abs((float)$item['line_total']);
        else $issuedTotal += abs((float)$item['line_total']);
    }
}
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo e($sale['receipt_no']); ?> | <?php echo $isExchange ? 'Değişim Fişi' : 'Satış Fişi'; ?></title>
<style>
@page{size:80mm auto;margin:0}*{box-sizing:border-box}body{margin:0;background:#eee;color:#111;font-family:Arial,"Helvetica Neue",sans-serif}.receipt{width:80mm;min-height:120mm;margin:12px auto;padding:5mm 4mm;background:#fff;font-size:11px}.center{text-align:center}.brand{font-size:18px;font-weight:900;letter-spacing:.04em}.muted{color:#555}.meta{display:grid;grid-template-columns:1fr auto;gap:4px 10px;margin:12px 0;border-bottom:1px dashed #333;padding-bottom:9px}.items{width:100%;border-collapse:collapse}.items td{padding:5px 0;vertical-align:top}.items .name{font-weight:800}.items .detail{font-size:10px;color:#444}.right{text-align:right}.group-title{padding:9px 0 4px;font-weight:900;border-bottom:1px dashed #333}.totals{margin-top:9px;padding-top:7px;border-top:1px dashed #333}.total-row{display:flex;justify-content:space-between;gap:10px;padding:3px 0}.grand{font-size:16px;font-weight:900;border-top:1px solid #111;margin-top:4px;padding-top:7px}.footer{margin-top:15px;padding-top:9px;border-top:1px dashed #333;text-align:center}.actions{width:80mm;margin:12px auto;display:flex;gap:8px}.actions button,.actions a{flex:1;padding:12px;border:0;border-radius:10px;background:#18472e;color:#fff;text-decoration:none;text-align:center;font-weight:800}.actions a{background:#555}.auto-print .actions{display:none}@media print{body{background:#fff}.receipt{margin:0}.actions{display:none}}
</style></head><body class="<?php echo $autoPrint ? 'auto-print' : ''; ?>">
<main class="receipt">
  <div class="center"><div class="brand">DUMANLAR A.Ş.</div><div class="muted"><?php echo $isExchange ? 'DEĞİŞİM FİŞİ' : 'SATIŞ FİŞİ'; ?></div></div>
  <div class="meta"><span>Müşteri</span><strong><?php echo e($sale['customer_name'] ?: 'Perakende Müşteri'); ?></strong><span>Tarih</span><strong><?php echo e(tr_date($sale['sale_date'])); ?> <?php echo e(substr($sale['sale_time'],0,5)); ?></strong><span>Fiş No</span><strong><?php echo e($sale['receipt_no']); ?></strong></div>
  <table class="items"><tbody>
  <?php $lastRole = ''; foreach ($sale['items'] as $item): $role = (($item['exchange_role'] ?? '') === 'returned' || (float)$item['quantity'] < 0) ? 'returned' : 'issued'; ?>
    <?php if ($isExchange && $role !== $lastRole): ?><tr><td colspan="2" class="group-title"><?php echo $role === 'returned' ? 'İADE EDİLEN ÜRÜNLER' : 'YENİ ALINAN ÜRÜNLER'; ?></td></tr><?php endif; $lastRole = $role; ?>
    <tr><td><div class="name"><?php echo e($item['product_name']); ?></div><div class="detail"><?php echo e($item['barcode']); ?><br><?php echo e(number_format(abs((float)$item['quantity']), 0, ',', '.')); ?> × <?php echo e(money((float)$item['unit_price'])); ?></div></td><td class="right"><strong><?php echo e(money(abs((float)$item['line_total']))); ?></strong></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="totals">
    <?php if ($isExchange): ?>
      <div class="total-row"><span>İade edilen</span><strong><?php echo e(money($returnedTotal)); ?></strong></div>
      <div class="total-row"><span>Yeni alınan</span><strong><?php echo e(money($issuedTotal)); ?></strong></div>
      <div class="total-row grand"><span>ÖDENEN FARK</span><strong><?php echo e(money((float)$sale['grand_total'])); ?></strong></div>
    <?php else: ?>
      <?php if ((float)$sale['discount_amount'] > 0): ?><div class="total-row"><span>Ara Toplam</span><strong><?php echo e(money((float)$sale['subtotal'])); ?></strong></div><div class="total-row"><span>İndirim</span><strong>-<?php echo e(money((float)$sale['discount_amount'])); ?></strong></div><?php endif; ?>
      <div class="total-row grand"><span>TOPLAM</span><strong><?php echo e(money((float)$sale['grand_total'])); ?></strong></div>
    <?php endif; ?>
    <div class="total-row"><span>Ödeme</span><strong><?php echo e($paymentLabels[$sale['payment_method']] ?? $sale['payment_method']); ?></strong></div>
    <?php if ((float)$paymentAmounts['cash'] > 0 && (float)$paymentAmounts['card'] > 0): ?><div class="total-row"><span>Nakit</span><strong><?php echo e(money((float)$paymentAmounts['cash'])); ?></strong></div><div class="total-row"><span>Kredi Kartı</span><strong><?php echo e(money((float)$paymentAmounts['card'])); ?></strong></div><?php endif; ?>
  </div>
  <div class="footer">Bizi tercih ettiğiniz için teşekkür ederiz.<br><span class="muted">Değişim için bu fişi saklayınız.</span><br><strong>Mali değeri yoktur.</strong></div>
</main>
<div class="actions"><button type="button" onclick="window.print()">Fişi Yazdır</button><a href="<?php echo $isExchange ? 'barkod-degisim.php' : 'barkod-satis.php'; ?>"><?php echo $isExchange ? 'Değişime Dön' : 'Satışa Dön'; ?></a></div>
<script>(function(){var params=new URLSearchParams(location.search);if(params.get('print')!=='1')return;var closeAfter=params.get('autoclose')==='1';window.addEventListener('afterprint',function(){if(closeAfter)setTimeout(function(){window.close();},40);});var run=function(){window.print();};if(typeof requestAnimationFrame==='function'){requestAnimationFrame(function(){requestAnimationFrame(run);});}else{setTimeout(run,50);}})();</script>
</body></html>
