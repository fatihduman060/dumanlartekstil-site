<?php
require_once __DIR__ . '/teklif-db.php';
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
while (count($rows) < 14) $rows[] = ['product_name'=>'', 'product_type'=>'', 'quantity'=>0, 'unit_price'=>0, 'line_total'=>0];
$docTypeLabel = (mb_stripos($title, 'SİPARİŞ') !== false || mb_stripos($title, 'SIPARIS') !== false) ? 'SİPARİŞ' : 'TEKLİF';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e($customer); ?></title>
<style>
  *{box-sizing:border-box}
  :root{--navy:#071b34;--navy2:#0a243f;--gold:#c8a15a;--gold2:#e8c982;--line:#e8e3d8;--muted:#6b7280}
  body{margin:0;background:#dfe3e7;font-family:Arial,Helvetica,sans-serif;color:#09192f}
  .toolbar{position:sticky;top:0;z-index:30;display:flex;gap:10px;justify-content:center;padding:12px;background:var(--navy)}
  .toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;cursor:pointer}
  .page{width:210mm;min-height:297mm;margin:14px auto;background:#fff;box-shadow:0 10px 28px rgba(0,0,0,.22);position:relative;overflow:hidden;border:1px solid #cfd5db}
  .topbar{height:4.5mm;background:var(--navy)}
  .hero{height:60mm;display:grid;grid-template-columns:57% 43%;border-bottom:2px solid #e8e2d7;position:relative;background:#fff}
  .hero-left{position:relative;padding:15mm 11mm 9mm 10mm;background:linear-gradient(108deg,#fff 0%,#fff 76%,transparent 76%)}
  .hero-left:after{content:'';position:absolute;right:-7mm;top:0;width:15mm;height:100%;background:linear-gradient(135deg,var(--gold2),#a77b2e);transform:skewX(-18deg);z-index:3;box-shadow:-4px 0 0 var(--navy)}
  .mark{display:flex;align-items:center;gap:8mm}.dd{width:29mm;height:29mm;border:5px solid var(--navy);border-right-color:var(--gold);border-radius:3mm 50% 50% 3mm;position:relative;color:var(--gold);font-size:23mm;font-weight:900;line-height:25mm;text-align:center;font-family:Georgia,serif}.dd:before{content:'D';position:absolute;left:4.3mm;top:.4mm;color:var(--gold)}
  .brand-name{font-family:Georgia,'Times New Roman',serif;font-size:21mm;letter-spacing:1mm;line-height:.88;color:var(--navy);font-weight:700}.brand-sub{margin-top:5mm;font-size:5.1mm;letter-spacing:2.2mm;font-weight:800;color:var(--navy)}
  .hero-right{position:relative;background:linear-gradient(rgba(7,27,52,.08),rgba(7,27,52,.38)),url('../assets/img/bitkekurumsal/corporate-production-line.png') center/cover no-repeat;overflow:hidden}
  .hero-right:before{content:'';position:absolute;left:-8mm;top:0;width:14mm;height:100%;background:linear-gradient(135deg,#f2d792,#a77b2e);transform:skewX(-18deg)}
  .hero-right:after{content:'Üretim Gücümüz\A MAKİNE PARKURUMUZ';white-space:pre;position:absolute;right:10mm;bottom:12mm;text-align:center;color:#fff;font-weight:700;letter-spacing:.6mm;font-size:4.2mm;line-height:1.7;text-shadow:0 2px 8px rgba(0,0,0,.45)}
  .contact{height:14mm;display:grid;grid-template-columns:32mm 45mm 35mm 36mm 1fr;align-items:center;border-bottom:1px solid #cfd6de;background:#fff;font-size:3.05mm;color:#0e1d31;font-weight:700}
  .contact div{height:100%;display:flex;align-items:center;justify-content:center;gap:2mm;border-right:1px solid #d8dee5;padding:0 3mm;text-align:center}.contact div:last-child{border-right:0;font-size:2.65mm}.ico{width:6mm;height:6mm;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--navy);color:#fff;font-size:3mm;flex:0 0 auto}
  .content{padding:12mm 10mm 0;position:relative}.doc-head{display:grid;grid-template-columns:1fr 58mm;gap:16mm;align-items:start;margin-bottom:9mm}.title-wrap h1{font-family:Georgia,'Times New Roman',serif;font-size:20mm;letter-spacing:1.2mm;line-height:.95;margin:0;color:var(--navy);font-weight:700}.title-rule{display:flex;align-items:center;gap:5mm;width:88mm;margin:7mm 0 6mm}.title-rule:before,.title-rule:after{content:'';height:1px;background:var(--gold);flex:1}.title-rule span{width:2mm;height:2mm;border-radius:50%;background:var(--gold)}.customer-name{font-size:4.4mm;font-weight:800;color:#273249;margin-bottom:9mm}.city{display:flex;gap:3mm;align-items:center;font-size:4.6mm;font-weight:800;color:#273249}.pin{color:var(--gold);font-size:5.5mm}
  .date-box{border:1.5px solid var(--gold);border-radius:2mm;padding:5mm 6mm;background:#fff;box-shadow:0 5px 14px rgba(12,28,52,.06)}.date-row{display:grid;grid-template-columns:9mm 1fr auto;gap:3mm;align-items:center;padding:2.5mm 0;border-bottom:1px solid #eee}.date-row:last-child{border-bottom:0}.date-row .cal{width:6.8mm;height:6.8mm;border-radius:1.1mm;border:1px solid #d8dee5;display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:3.5mm}.date-row b{font-size:3.25mm;color:#425064}.date-row strong{font-size:3.5mm;color:var(--gold)}
  table.items{width:100%;border-collapse:collapse;table-layout:fixed;font-size:3.45mm}.items th{background:var(--navy);color:#fff;text-align:center;padding:3mm 2mm;border-right:1px solid var(--gold);font-weight:800}.items th:last-child{border-right:0}.items thead tr.sub th{background:#f7f1e7;color:#1c2b42;padding:2.3mm 2mm;font-size:3.05mm;border-bottom:1px solid var(--line)}.items td{height:7.3mm;border:1px solid var(--line);padding:2mm 3mm;color:#263246;font-weight:700}.items td:nth-child(2),.items td:nth-child(3),.items td:nth-child(4){text-align:center}.items td.right{text-align:right}.items small{display:block;margin-top:.8mm;color:#667085;font-size:2.65mm;font-weight:600}.items .empty{color:transparent}.total-line td{height:8.8mm;background:#fff}.total-line .label{background:var(--navy);color:var(--gold2);text-align:center;font-weight:900}.total-line .amount{color:var(--gold);font-weight:900;text-align:center;font-size:4mm}
  .note{display:flex;align-items:flex-start;gap:4mm;margin:6mm 3mm 0;font-size:3.6mm;color:#333;font-weight:700}.boxicon{width:7mm;height:7mm;color:var(--gold);font-size:6mm;line-height:7mm}.term{margin:4mm 3mm 0;font-size:3mm;color:#4b5563;font-weight:600}
  .bottom{position:absolute;left:0;right:0;bottom:0}.features{height:38mm;background:var(--navy);display:grid;grid-template-columns:1fr 1fr 1fr 58mm;align-items:center;gap:4mm;padding:6mm 11mm;color:#fff}.feature{display:grid;grid-template-columns:13mm 1fr;gap:3mm;align-items:center}.ficon{width:12mm;height:12mm;border:1.5px solid var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:7mm}.feature b{display:block;color:var(--gold2);font-size:3.2mm;margin-bottom:1mm}.feature span{display:block;font-size:2.7mm;line-height:1.25;color:#e7edf5}.thanks{border:1.3px solid var(--gold);border-radius:2mm;padding:6mm;text-align:center;color:var(--gold2);font-family:Georgia,serif;font-size:5mm;line-height:1.25;font-style:italic}.footer-band{height:9mm;background:linear-gradient(90deg,#f0c777,#f9e4a8,#f0c777);text-align:center;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-size:4.2mm;letter-spacing:.8mm;color:#513910}
  @page{size:A4;margin:0}
  @media print{body{background:#fff}.toolbar{display:none}.page{margin:0;border:0;box-shadow:none;width:210mm;min-height:297mm;page-break-after:always}.hero-right{-webkit-print-color-adjust:exact;print-color-adjust:exact}.features,.footer-band,.items th,.total-line .label{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Yazdır / PDF al</button><a href="teklif-ver.php?edit=<?php echo e($offerId); ?>">Düzenlemeye dön</a><a href="teklif-ver.php">Teklif listesi</a></div>
  <main class="page">
    <div class="topbar"></div>
    <section class="hero">
      <div class="hero-left">
        <div class="mark"><div class="dd"></div><div><div class="brand-name">DUMANLAR</div><div class="brand-sub">ÇORAP & TEKSTİL ÜRETİMİ</div></div></div>
      </div>
      <div class="hero-right"></div>
    </section>
    <section class="contact">
      <div><span class="ico">🌐</span>dumanlartekstil.com.tr</div>
      <div><span class="ico">✉</span>dumanlartekstil@yahoo.com</div>
      <div><span class="ico">☎</span>+90 356 715 82 83</div>
      <div><span class="ico">☏</span>+90 356 716 03 46</div>
      <div><span class="ico">●</span>Kayakaya Bulvarı Organize Sanayi Bölgesi Beylik Bükü Cad No:6 Erbaa-TOKAT / TÜRKİYE</div>
    </section>
    <section class="content">
      <div class="doc-head">
        <div class="title-wrap">
          <h1><?php echo e($title); ?></h1>
          <div class="title-rule"><span></span></div>
          <div class="customer-name"><?php echo e($customer); ?></div>
          <div class="city"><span class="pin">●</span><?php echo e($city ?: '-'); ?></div>
        </div>
        <div class="date-box">
          <div class="date-row"><span class="cal">▣</span><b><?php echo e($docTypeLabel); ?> TARİHİ</b><strong><?php echo e(tr_date($offerDate)); ?></strong></div>
          <div class="date-row"><span class="cal">▤</span><b><?php echo e($docTypeLabel); ?> NO</b><strong><?php echo e($offerNo); ?></strong></div>
        </div>
      </div>
      <table class="items">
        <thead>
          <tr><th style="width:44%">ÜRÜN ADI</th><th style="width:14%"><?php echo e($quantityLabel); ?></th><th style="width:19%">BİRİM FİYATI</th><th style="width:23%">TUTAR</th></tr>
          <tr class="sub"><th>ÜRÜN CİNSİ</th><th>MİKTAR</th><th>BİRİM FİYAT</th><th>TUTAR</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $qty=(float)($r['quantity'] ?? 0); $price=(float)($r['unit_price'] ?? 0); $line=(float)($r['line_total'] ?? 0); $has=!empty($r['product_name']) || !empty($r['product_type']) || $qty>0 || $price>0; ?>
          <tr>
            <td class="<?php echo $has ? '' : 'empty'; ?>"><?php echo e(($r['product_name'] ?? '') ?: ($r['product_type'] ?? '')); ?><?php echo (!empty($r['product_name']) && !empty($r['product_type'])) ? '<small>'.e($r['product_type']).'</small>' : ''; ?></td>
            <td><?php echo $qty > 0 ? e(number_format($qty, 0, ',', '.')) : ''; ?></td>
            <td><?php echo $price > 0 ? e(teklif_money($price)) : ''; ?></td>
            <td class="right"><?php echo $line > 0 ? e(teklif_money($line)) : ''; ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="total-line"><td colspan="2"></td><td class="label">ARA TOPLAM</td><td class="amount"><?php echo e(teklif_money($subtotal)); ?> <?php echo e($currency); ?></td></tr>
          <?php if ($vatEnabled): ?><tr class="total-line"><td colspan="2"></td><td class="label">KDV (%<?php echo e((string)$vatRate); ?>)</td><td class="amount"><?php echo e(teklif_money($vatAmount)); ?> <?php echo e($currency); ?></td></tr><?php endif; ?>
          <tr class="total-line"><td colspan="2"></td><td class="label">GENEL TOPLAM</td><td class="amount"><?php echo e(teklif_money($grandTotal)); ?> <?php echo e($currency); ?></td></tr>
        </tbody>
      </table>
      <?php if ($note !== ''): ?><div class="note"><span class="boxicon">▧</span><span><?php echo nl2br(e($note)); ?></span></div><?php endif; ?>
      <?php if ($termText !== ''): ?><div class="term"><?php echo e($termText); ?></div><?php endif; ?>
    </section>
    <section class="bottom">
      <div class="features">
        <div class="feature"><div class="ficon">★</div><div><b>KALİTELİ ÜRETİM</b><span>Yüksek kalite standartlarında üretim garantisi.</span></div></div>
        <div class="feature"><div class="ficon">▸</div><div><b>ZAMANINDA TESLİMAT</b><span>Siparişlerinizi zamanında teslim ediyoruz.</span></div></div>
        <div class="feature"><div class="ficon">♢</div><div><b>GÜVENİLİR HİZMET</b><span>Müşteri memnuniyetini önceliğimiz kabul ediyoruz.</span></div></div>
        <div class="thanks">Teşekkür eder,<br>iyi çalışmalar dileriz.</div>
      </div>
      <div class="footer-band"><?php echo e($footerText); ?></div>
    </section>
  </main>
</body>
</html>
