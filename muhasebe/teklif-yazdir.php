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
while (count($rows) < 15) $rows[] = ['product_name'=>'', 'product_type'=>'', 'quantity'=>0, 'unit_price'=>0, 'line_total'=>0];
$docTypeLabel = (mb_stripos($title, 'SİPARİŞ') !== false || mb_stripos($title, 'SIPARIS') !== false) ? 'SİPARİŞ' : 'TEKLİF';
$logoSrc = 'assets/dumanlar-logo-arkaplansiz.png?v=7';
$machineSrc = 'assets/07DA19C3-9C50-4D7F-A2FB-922CC15BC158.PNG?v=7';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e($customer); ?></title>
<style>
  *{box-sizing:border-box}
  :root{--navy:#061a33;--navy2:#071f3e;--gold:#c49a4f;--gold2:#efd28a;--line:#e5e0d7;--cream:#f7f1e6}
  html,body{margin:0;padding:0;background:#dfe3e7;font-family:Arial,Helvetica,sans-serif;color:#071a33}
  .toolbar{position:sticky;top:0;z-index:30;display:flex;gap:10px;justify-content:center;padding:12px;background:var(--navy)}
  .toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;cursor:pointer}
  .page{width:210mm;height:297mm;margin:14px auto;background:#fff;box-shadow:0 10px 28px rgba(0,0,0,.20);position:relative;overflow:hidden;border:1px solid #d7dce2;page-break-after:avoid;break-after:avoid}

  .hero{height:66mm;display:grid;grid-template-columns:58.5% 41.5%;position:relative;background:#fff;overflow:hidden;border-top:3.2mm solid var(--navy);border-bottom:1.2px solid #cdd3dc}
  .hero-left{position:relative;z-index:2;background:#fff;padding:13mm 8mm 8mm 9.5mm;overflow:visible}
  .hero-left:after{content:'';position:absolute;right:-8mm;top:-3.2mm;width:17mm;height:69.2mm;background:linear-gradient(135deg,#f3d78e 0%,#c99639 52%,#7d551d 100%);transform:skewX(-17deg);z-index:4;box-shadow:-3.2px 0 0 var(--navy)}
  .real-logo{display:block;width:111mm;height:37mm;object-fit:contain;object-position:left center;position:relative;z-index:5}
  .hero-right{position:relative;z-index:1;background:var(--navy);overflow:hidden}
  .hero-right img{width:100%;height:100%;object-fit:cover;object-position:center center;display:block;filter:saturate(1.08) contrast(1.04) brightness(.92)}
  .hero-right:before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,rgba(6,26,51,.20),rgba(6,26,51,.05) 35%,rgba(6,26,51,.45));z-index:2;pointer-events:none}
  .hero-right:after{content:'Üretim Gücümüz\A MAKİNE PARKURUMUZ';white-space:pre;position:absolute;right:8mm;bottom:8mm;text-align:center;color:#fff;font-weight:700;letter-spacing:.45mm;font-size:3.55mm;line-height:1.65;text-shadow:0 2px 8px rgba(0,0,0,.62);z-index:3}

  .contact{height:11mm;display:grid;grid-template-columns:34mm 47mm 34mm 35mm 1fr;align-items:center;border-bottom:1px solid #cfd6de;background:#fff;font-size:2.55mm;color:#0e1d31;font-weight:800;box-shadow:0 1px 4px rgba(9,25,47,.06)}
  .contact div{height:100%;display:flex;align-items:center;justify-content:center;gap:1.5mm;border-right:1px solid #d8dee5;padding:0 2mm;text-align:center;overflow:hidden;white-space:nowrap}.contact div:last-child{border-right:0;font-size:2.15mm;white-space:normal;line-height:1.15}.ico{width:5.2mm;height:5.2mm;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:var(--navy);color:#fff;font-size:2.55mm;flex:0 0 auto}

  .content{padding:9mm 10mm 44mm;position:relative}.doc-head{display:grid;grid-template-columns:1fr 52mm;gap:15mm;align-items:start;margin-bottom:6.2mm}.title-wrap h1{font-family:Georgia,'Times New Roman',serif;font-size:16.6mm;letter-spacing:.85mm;line-height:.95;margin:0;color:var(--navy);font-weight:700}.title-rule{display:flex;align-items:center;gap:4mm;width:78mm;margin:4.6mm 0 4mm}.title-rule:before,.title-rule:after{content:'';height:1px;background:var(--gold);flex:1}.title-rule span{width:1.8mm;height:1.8mm;border-radius:50%;background:var(--gold)}.customer-name{font-size:4mm;font-weight:800;color:#273249;margin-bottom:6.5mm;max-width:118mm}.city{display:flex;gap:2.5mm;align-items:center;font-size:4.15mm;font-weight:800;color:#273249}.pin{color:var(--gold);font-size:4.9mm}
  .date-box{border:1.25px solid var(--gold);border-radius:2mm;padding:4mm 4.2mm;background:#fff;box-shadow:0 5px 13px rgba(12,28,52,.045);margin-top:1.2mm}.date-row{display:grid;grid-template-columns:7mm 1fr auto;gap:2.2mm;align-items:center;padding:2.2mm 0;border-bottom:1px solid #ece6dc}.date-row:last-child{border-bottom:0}.date-row .cal{width:5.7mm;height:5.7mm;border-radius:1mm;border:1px solid #d8dee5;display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:2.85mm;background:#f8fafc}.date-row b{font-size:2.75mm;color:#425064}.date-row strong{font-size:2.95mm;color:var(--gold);word-break:break-word;text-align:right}

  table.items{width:100%;border-collapse:collapse;table-layout:fixed;font-size:3.05mm}.items th{background:var(--navy);color:#fff;text-align:center;padding:2.25mm 1.8mm;border-right:1.4px solid var(--gold);font-weight:900}.items th:last-child{border-right:0}.items thead tr.sub th{background:var(--cream);color:#1c2b42;padding:1.68mm 1.8mm;font-size:2.7mm;border-bottom:1px solid var(--line)}.items td{height:5.15mm;border:1px solid var(--line);padding:1.1mm 2mm;color:#263246;font-weight:700}.items td:nth-child(2),.items td:nth-child(3),.items td:nth-child(4){text-align:center}.items td.right{text-align:right}.items small{display:block;margin-top:.25mm;color:#667085;font-size:2.15mm;font-weight:600}.items .empty{color:transparent}.total-line td{height:6.05mm;background:#fff}.total-line .label{background:var(--navy);color:var(--gold2);text-align:center;font-weight:900}.total-line .amount{color:#9a702a;font-weight:900;text-align:center;font-size:3.1mm}
  .note{display:flex;align-items:flex-start;gap:3mm;margin:4mm 2.5mm 0;font-size:3.25mm;color:#333;font-weight:700}.boxicon{width:6mm;height:6mm;color:var(--gold);font-size:5mm;line-height:6mm}.term{margin:2.6mm 2.5mm 0;font-size:2.7mm;color:#4b5563;font-weight:600}

  .bottom{position:absolute;left:0;right:0;bottom:0;height:41mm;overflow:hidden;page-break-inside:avoid;break-inside:avoid;background:linear-gradient(to bottom,var(--navy) 0,var(--navy) 33.2mm,#efc36d 33.2mm,#f9e4aa 37mm,#efc36d 41mm);border-top:1.1mm solid var(--gold)}
  .bottom:after{content:attr(data-footer);position:absolute;left:0;right:0;bottom:0;height:7.8mm;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-size:3.45mm;letter-spacing:.7mm;color:#513910;z-index:5;white-space:nowrap}.bottom:before{content:'';position:absolute;left:38mm;right:38mm;bottom:3.9mm;height:.25mm;background:linear-gradient(90deg,transparent,var(--gold),transparent);z-index:6;opacity:.7}
  .features{position:absolute;left:0;right:0;top:0;height:33.2mm;background:transparent;display:grid;grid-template-columns:1fr 1fr 1fr 54mm;align-items:center;gap:3.4mm;padding:4.7mm 10.5mm;color:#fff;overflow:hidden}.feature{display:grid;grid-template-columns:10mm 1fr;gap:2.4mm;align-items:center}.ficon{width:9.8mm;height:9.8mm;border:1.2px solid var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--gold);font-size:5.1mm}.feature b{display:block;color:var(--gold2);font-size:2.6mm;margin-bottom:.7mm}.feature span{display:block;font-size:2.15mm;line-height:1.18;color:#e7edf5}.thanks{border:1.1px solid var(--gold);border-radius:2mm;padding:4.6mm;text-align:center;color:var(--gold2);font-family:Georgia,serif;font-size:3.95mm;line-height:1.2;font-style:italic}

  @page{size:A4;margin:0}
  @media print{
    html,body{width:210mm!important;height:auto!important;min-height:0!important;background:#fff!important;overflow:hidden!important;margin:0!important;padding:0!important}
    .toolbar{display:none!important}
    .page{margin:0 auto!important;border:0!important;box-shadow:none!important;width:209mm!important;height:286mm!important;overflow:hidden!important;page-break-before:avoid!important;page-break-after:auto!important;page-break-inside:avoid!important;break-before:avoid!important;break-after:auto!important;break-inside:avoid!important;zoom:1!important}
    .hero{height:62mm!important;border-top-width:2.8mm!important}
    .hero-left{padding:11.5mm 7mm 7mm 9mm!important}
    .hero-left:after{height:65mm!important;top:-2.8mm!important;width:15.8mm!important}
    .real-logo{width:108mm!important;height:35mm!important}
    .hero-right:after{font-size:3.25mm!important;right:7mm!important;bottom:6.5mm!important}
    .contact{height:10mm!important;font-size:2.35mm!important;grid-template-columns:33mm 45mm 33mm 34mm 1fr!important}
    .contact div{gap:1.2mm!important;padding:0 1.6mm!important}.contact div:last-child{font-size:2mm!important}
    .ico{width:4.8mm!important;height:4.8mm!important;font-size:2.3mm!important}
    .content{padding:7.5mm 10mm 39mm!important}
    .doc-head{margin-bottom:5mm!important;gap:13mm!important}.title-wrap h1{font-size:15.5mm!important}.title-rule{margin:4mm 0 3.7mm!important}.customer-name{margin-bottom:5.5mm!important}.date-box{padding:3.4mm 3.8mm!important}.date-row{padding:1.8mm 0!important}.date-row b{font-size:2.55mm!important}.date-row strong{font-size:2.65mm!important}
    .items th{padding:1.9mm 1.5mm!important}.items thead tr.sub th{padding:1.42mm 1.5mm!important}.items td{height:4.55mm!important;padding:.9mm 1.7mm!important}.total-line td{height:5.35mm!important}.note{margin-top:3.2mm!important;font-size:2.9mm!important}
    .bottom{height:37mm!important;background:linear-gradient(to bottom,var(--navy) 0,var(--navy) 29.8mm,#efc36d 29.8mm,#f9e4aa 33.3mm,#efc36d 37mm)!important;page-break-inside:avoid!important;break-inside:avoid!important}.bottom:after{height:7.2mm!important;font-size:3.05mm!important}.bottom:before{bottom:3.6mm!important}
    .features{height:29.8mm!important;padding:3.8mm 9mm!important;grid-template-columns:1fr 1fr 1fr 51mm!important;page-break-inside:avoid!important;break-inside:avoid!important}.feature{grid-template-columns:9mm 1fr!important;gap:2.1mm!important}.ficon{width:8.8mm!important;height:8.8mm!important;font-size:4.6mm!important}.feature b{font-size:2.35mm!important}.feature span{font-size:1.98mm!important}.thanks{font-size:3.45mm!important;padding:3.5mm!important}
    .hero,.hero-right,.bottom,.features,.items th,.total-line .label{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  }
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Yazdır / PDF al</button><a href="teklif-ver.php?edit=<?php echo e($offerId); ?>">Düzenlemeye dön</a><a href="teklif-ver.php">Teklif listesi</a></div>
  <main class="page">
    <section class="hero">
      <div class="hero-left"><img class="real-logo" src="<?php echo e($logoSrc); ?>" alt="Dumanlar Çorap & Tekstil Üretimi"></div>
      <div class="hero-right"><img src="<?php echo e($machineSrc); ?>" alt="Dumanlar makine parkuru"></div>
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
    <section class="bottom" data-footer="<?php echo e($footerText); ?>">
      <div class="features">
        <div class="feature"><div class="ficon">★</div><div><b>KALİTELİ ÜRETİM</b><span>Yüksek kalite standartlarında üretim garantisi.</span></div></div>
        <div class="feature"><div class="ficon">▸</div><div><b>ZAMANINDA TESLİMAT</b><span>Siparişlerinizi zamanında teslim ediyoruz.</span></div></div>
        <div class="feature"><div class="ficon">♢</div><div><b>GÜVENİLİR HİZMET</b><span>Müşteri memnuniyetini önceliğimiz kabul ediyoruz.</span></div></div>
        <div class="thanks">Teşekkür eder,<br>iyi çalışmalar dileriz.</div>
      </div>
    </section>
  </main>
</body>
</html>
