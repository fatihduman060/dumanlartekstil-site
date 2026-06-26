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
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> - <?php echo e($customer); ?></title>
<style>
  *{box-sizing:border-box}
  :root{--navy:#071b34;--gold:#c8a15a;--gold2:#e8c982;--line:#e8e3d8;--muted:#6b7280}
  html,body{margin:0;padding:0;background:#dfe3e7;font-family:Arial,Helvetica,sans-serif;color:#09192f}
  .toolbar{position:sticky;top:0;z-index:30;display:flex;gap:10px;justify-content:center;padding:12px;background:var(--navy)}
  .toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;cursor:pointer}
  .page{width:210mm;height:297mm;margin:14px auto;background:#fff;box-shadow:0 10px 28px rgba(0,0,0,.22);position:relative;overflow:hidden;border:1px solid #cfd5db}
  .topbar{height:3mm;background:var(--navy)}
  .hero{height:45mm;display:grid;grid-template-columns:58% 42%;border-bottom:1px solid #e8e2d7;position:relative;background:#fff;overflow:hidden}
  .hero-left{position:relative;padding:8mm 10mm 6mm 9mm;background:linear-gradient(108deg,#fff 0%,#fff 76%,transparent 76%);overflow:hidden}
  .hero-left:after{content:'';position:absolute;right:-6mm;top:0;width:13mm;height:100%;background:linear-gradient(135deg,var(--gold2),#a77b2e);transform:skewX(-18deg);z-index:3;box-shadow:-3px 0 0 var(--navy)}
  .mark{display:flex;align-items:center;gap:6mm;max-width:104mm;overflow:hidden}.dd{width:22mm;height:22mm;border:3.5px solid var(--navy);border-right-color:var(--gold);border-radius:3mm 50% 50% 3mm;position:relative;color:var(--gold);font-size:17mm;font-weight:900;line-height:19mm;text-align:center;font-family:Georgia,serif;flex:0 0 auto}.dd:before{content:'D';position:absolute;left:3.2mm;top:.5mm;color:var(--gold)}
  .brand-name{font-family:Georgia,'Times New Roman',serif;font-size:15.6mm;letter-spacing:.7mm;line-height:.9;color:var(--navy);font-weight:700;white-space:nowrap}.brand-sub{margin-top:3.6mm;font-size:3.6mm;letter-spacing:1.6mm;font-weight:800;color:var(--navy);white-space:nowrap}
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
