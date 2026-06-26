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
while (count($rows) < 10) $rows[] = ['product_name'=>'', 'product_type'=>'', 'quantity'=>0, 'unit_price'=>0, 'line_total'=>0];
$docTypeLabel = (mb_stripos($title, 'SİPARİŞ') !== false || mb_stripos($title, 'SIPARIS') !== false) ? 'SİPARİŞ' : 'TEKLİF';
$logoSrc = 'data:image/jpeg;base64,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';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e($customer); ?></title>
<style>
  *{box-sizing:border-box}
  :root{--navy:#071b34;--gold:#c8a15a;--gold2:#e8c982;--line:#e8e3d8}
  html,body{margin:0;padding:0;background:#dfe3e7;font-family:Arial,Helvetica,sans-serif;color:#09192f}
  .toolbar{position:sticky;top:0;z-index:30;display:flex;gap:10px;justify-content:center;padding:12px;background:var(--navy)}
  .toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;cursor:pointer}
  .page{width:210mm;height:297mm;margin:14px auto;background:#fff;box-shadow:0 10px 28px rgba(0,0,0,.22);position:relative;overflow:hidden;border:1px solid #cfd5db}
  .topbar{height:3mm;background:var(--navy)}
  .hero{height:45mm;display:grid;grid-template-columns:58% 42%;border-bottom:1px solid #e8e2d7;background:#fff;overflow:hidden}
  .hero-left{position:relative;padding:7mm 8mm 6mm 7mm;background:linear-gradient(108deg,#fff 0%,#fff 77%,transparent 77%);overflow:hidden}
  .hero-left:after{content:'';position:absolute;right:-6mm;top:0;width:13mm;height:100%;background:linear-gradient(135deg,var(--gold2),#a77b2e);transform:skewX(-18deg);z-index:3;box-shadow:-3px 0 0 var(--navy)}
  .real-logo{display:block;width:108mm;max-height:31mm;object-fit:contain;object-position:left center;position:relative;z-index:2}
  .hero-right{position:relative;background:linear-gradient(rgba(7,27,52,.14),rgba(7,27,52,.42)),url('../assets/img/bitkekurumsal/corporate-production-line.png') center/cover no-repeat;overflow:hidden}
  .hero-right:before{content:'';position:absolute;left:-7mm;top:0;width:12mm;height:100%;background:linear-gradient(135deg,#f2d792,#a77b2e);transform:skewX(-18deg)}
  .hero-right:after{content:'Üretim Gücümüz\A MAKİNE PARKURUMUZ';white-space:pre;position:absolute;right:8mm;bottom:8mm;text-align:center;color:#fff;font-weight:700;letter-spacing:.45mm;font-size:3.45mm;line-height:1.65;text-shadow:0 2px 8px rgba(0,0,0,.45)}
  .contact{height:10mm;display:grid;grid-template-columns:33mm 43mm 31mm 32mm 1fr;align-items:center;border-bottom:1px solid #cfd6de;background:#fff;font-size:2.45mm;color:#0e1d31;font-weight:800}
  .contact div{height:100%;display:flex;align-items:center;justify-content:center;gap:1.4mm;border-right:1px solid #d8dee5;padding:0 2mm;text-align:center;overflow:hidden}.contact div:last-child{border-right:0;font-size:2.15mm}.ico{width:4.8mm;height:4.8mm;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--navy);color:#fff;font-size:2.4mm;flex:0 0 auto}
  .content{padding:9mm 10mm 46mm;position:relative}.doc-head{display:grid;grid-template-columns:1fr 52mm;gap:12mm;align-items:start;margin-bottom:6mm}.title-wrap h1{font-family:Georgia,'Times New Roman',serif;font-size:15.8mm;letter-spacing:.8mm;line-height:.95;margin:0;color:var(--navy);font-weight:700}.title-rule{display:flex;align-items:center;gap:4mm;width:74mm;margin:4.5mm 0 4.2mm}.title-rule:before,.title-rule:after{content:'';height:1px;background:var(--gold);flex:1}.title-rule span{width:1.8mm;height:1.8mm;border-radius:50%;background:var(--gold)}.customer-name{font-size:3.85mm;font-weight:800;color:#273249;margin-bottom:6mm;max-width:118mm}.city{display:flex;gap:2.4mm;align-items:center;font-size:4mm;font-weight:800;color:#273249}.pin{color:var(--gold);font-size:4.6mm}
  .date-box{border:1.3px solid var(--gold);border-radius:2mm;padding:3.5mm 4mm;background:#fff;box-shadow:0 4px 12px rgba(12,28,52,.05)}.date-row{display:grid;grid-template-columns:7mm 1fr auto;gap:2mm;align-items:center;padding:2mm 0;border-bottom:1px solid #eee}.date-row:last-child{border-bottom:0}.date-row .cal{width:5.5mm;height:5.5mm;border-radius:1mm;border:1px solid #d8dee5;display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:2.8mm}.date-row b{font-size:2.7mm;color:#425064}.date-row strong{font-size:2.85mm;color:var(--gold);word-break:break-word;text-align:right}
  table.items{width:100%;border-collapse:collapse;table-layout:fixed;font-size:3mm}.items th{background:var(--navy);color:#fff;text-align:center;padding:2.15mm 1.8mm;border-right:1px solid var(--gold);font-weight:800}.items th:last-child{border-right:0}.items thead tr.sub th{background:#f7f1e7;color:#1c2b42;padding:1.7mm 1.8mm;font-size:2.65mm;border-bottom:1px solid var(--line)}.items td{height:5.5mm;border:1px solid var(--line);padding:1.3mm 2mm;color:#263246;font-weight:700}.items td:nth-child(2),.items td:nth-child(3),.items td:nth-child(4){text-align:center}.items td.right{text-align:right}.items small{display:block;margin-top:.4mm;color:#667085;font-size:2.25mm;font-weight:600}.items .empty{color:transparent}.total-line td{height:6.6mm;background:#fff}.total-line .label{background:var(--navy);color:var(--gold2);text-align:center;font-weight:900}.total-line .amount{color:#9a702a;font-weight:900;text-align:center;font-size:3.25mm}
  .note{display:flex;align-items:flex-start;gap:3mm;margin:4.5mm 2mm 0;font-size:3.15mm;color:#333;font-weight:700}.boxicon{width:6mm;height:6mm;color:var(--gold);font-size:5mm;line-height:6mm}.term{margin:3mm 2mm 0;font-size:2.65mm;color:#4b5563;font-weight:600}
  .bottom{position:absolute;left:0;right:0;bottom:0}.features{height:31mm;background:var(--navy);display:grid;grid-template-columns:1fr 1fr 1fr 52mm;align-items:center;gap:3mm;padding:4.5mm 10mm;color:#fff}.feature{display:grid;grid-template-columns:10mm 1fr;gap:2.3mm;align-items:center}.ficon{width:9.5mm;height:9.5mm;border:1.2px solid var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:5mm}.feature b{display:block;color:var(--gold2);font-size:2.65mm;margin-bottom:.7mm}.feature span{display:block;font-size:2.25mm;line-height:1.18;color:#e7edf5}.thanks{border:1.1px solid var(--gold);border-radius:2mm;padding:4.5mm;text-align:center;color:var(--gold2);font-family:Georgia,serif;font-size:4mm;line-height:1.2;font-style:italic}.footer-band{height:7.5mm;background:linear-gradient(90deg,#f0c777,#f9e4a8,#f0c777);text-align:center;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-size:3.45mm;letter-spacing:.6mm;color:#513910}
  @page{size:A4;margin:0}
  @media print{html,body{width:210mm;height:297mm;background:#fff}.toolbar{display:none}.page{margin:0;border:0;box-shadow:none;width:210mm;height:297mm;page-break-after:auto}.hero-right,.features,.footer-band,.items th,.total-line .label{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Yazdır / PDF al</button><a href="teklif-ver.php?edit=<?php echo e($offerId); ?>">Düzenlemeye dön</a><a href="teklif-ver.php">Teklif listesi</a></div>
  <main class="page">
    <div class="topbar"></div>
    <section class="hero">
      <div class="hero-left"><img class="real-logo" src="<?php echo e($logoSrc); ?>" alt="Dumanlar Çorap & Tekstil Üretimi"></div>
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
