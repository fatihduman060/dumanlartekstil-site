<?php
require_once __DIR__ . '/tahsilat-db.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$r = tahsilat_load($id);
if (!$r) {
    flash('error', 'PDF alınacak tahsilat makbuzu bulunamadı.');
    redirect('tahsilat-makbuzu.php');
}

$logoSrc = 'assets/dumanlar-logo-arkaplansiz.png?v=20';
$receiptNo = $r['receipt_no'] ?: '';
$receiptDate = $r['receipt_date'] ?: date('Y-m-d');
$customer = $r['customer_name'] ?: '';
$city = $r['customer_city'] ?: '';
$address = trim((string)($r['customer_address'] ?? ''));
$taxOffice = trim((string)($r['customer_tax_office'] ?? ''));
$taxNo = trim((string)($r['customer_tax_no'] ?? ''));
$phone = trim((string)($r['customer_phone'] ?? ''));
$paymentType = (string)($r['payment_type'] ?? 'nakit');
$amount = (float)($r['amount'] ?? 0);
$currency = $r['currency'] ?: 'TL';
$amountText = trim((string)($r['amount_text'] ?? '')) ?: tahsilat_amount_text($amount, $currency);
$description = trim((string)($r['description'] ?? '')) ?: 'Cari hesabına mahsuben tahsil edilmiştir.';
$hasExtra = trim((string)($r['bank_name'] ?? '')) !== '' || trim((string)($r['document_no'] ?? '')) !== '' || trim((string)($r['due_date'] ?? '')) !== '' || trim((string)($r['debtor_name'] ?? '')) !== '';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tahsilat Makbuzu - <?php echo e($customer); ?></title>
<style>
*{box-sizing:border-box}:root{--navy:#061a33;--gold:#c49a4f;--gold2:#efd28a;--line:#e6dfd2;--cream:#fbf7ef;--ink:#071a33}html,body{margin:0;padding:0;background:#dfe3e7;font-family:Arial,Helvetica,sans-serif;color:var(--ink)}.toolbar{position:sticky;top:0;z-index:30;display:flex;gap:10px;justify-content:center;padding:12px;background:var(--navy)}.toolbar button,.toolbar a{border:0;border-radius:999px;padding:10px 16px;background:#fff;color:var(--navy);font-weight:800;text-decoration:none;cursor:pointer}.page{width:210mm;height:297mm;margin:14px auto;background:#fff;box-shadow:0 10px 28px rgba(0,0,0,.20);position:relative;overflow:hidden;border:1px solid #d7dce2}.header{height:58mm;padding:7mm 10mm 0;background:linear-gradient(180deg,#fff 0,#fff 80%,#fbf7ef 100%);border-top:3mm solid var(--navy);position:relative;text-align:center;overflow:hidden}.header:before{content:'';position:absolute;left:11mm;right:11mm;bottom:0;height:1px;background:linear-gradient(90deg,transparent,var(--gold),transparent)}.header:after{content:'';position:absolute;left:50%;bottom:0;width:2.3mm;height:2.3mm;background:var(--gold);transform:translate(-50%,50%) rotate(45deg)}.logo{display:block;width:118mm;height:34mm;object-fit:contain;margin:0 auto 4mm}.brand-line{display:flex;align-items:center;justify-content:center;gap:7mm;color:var(--gold);font-size:3.55mm;letter-spacing:.75mm;font-weight:950;text-transform:uppercase}.brand-line:before,.brand-line:after{content:'';width:30mm;height:.35mm;background:linear-gradient(90deg,transparent,var(--gold),transparent)}.contact{height:11mm;display:grid;grid-template-columns:31mm 43mm 49mm 31mm 1fr;align-items:center;border-top:.45mm solid var(--gold);border-bottom:.45mm solid var(--gold);background:#fff;font-size:2.35mm;color:#0e1d31;font-weight:800}.contact div{height:100%;display:flex;align-items:center;justify-content:center;gap:1.15mm;border-right:1px solid #d8dee5;padding:0 1.2mm;text-align:center;overflow:hidden;white-space:nowrap}.contact div:last-child{border-right:0;font-size:1.9mm;white-space:normal;line-height:1.12}.web-cell{font-size:2.12mm!important;letter-spacing:-.03mm!important;padding:0 .85mm!important}.ico{width:5.1mm;height:5.1mm;border:1px solid var(--gold);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:var(--gold);font-size:2.2mm;flex:0 0 auto}.wa{border-color:#25d366;color:#25d366;font-weight:900}.wa svg{width:3.25mm;height:3.25mm;display:block;fill:currentColor}.address-cell{font-size:2.15mm!important;line-height:1.22!important;font-weight:900!important;text-align:left!important;justify-content:flex-start!important;gap:1.2mm!important;padding-left:1.6mm!important}.address-text{display:block}.address-text span,.address-text strong{display:block}.address-text strong{margin-top:.25mm;color:var(--navy);font-weight:950;letter-spacing:.08mm}.content{padding:10mm 11mm 42mm}.doc-head{display:grid;grid-template-columns:1fr 70mm;gap:12mm;align-items:start;margin-bottom:7mm}.customer-name{font-size:7.3mm;line-height:1.12;font-weight:950;color:var(--navy);margin-bottom:2.5mm;text-transform:uppercase;letter-spacing:.08mm}.customer-info{display:grid;gap:1.2mm;max-width:120mm;padding:3mm 3.5mm;border:1px solid var(--line);border-left:1.2mm solid var(--gold);border-radius:2mm;background:var(--cream);font-size:2.75mm;line-height:1.25;font-weight:700}.customer-info div{display:grid;grid-template-columns:20mm 1fr;gap:2mm}.customer-info b{color:var(--navy);font-size:2.5mm;text-transform:uppercase}.date-box{border:1.25px solid var(--navy);border-radius:2mm;background:#fff;overflow:hidden;margin-top:1mm}.date-row{display:grid;grid-template-columns:27mm 1fr;align-items:center;min-height:11.5mm;border-bottom:1px solid #d8dee5}.date-row:last-child{border-bottom:0}.date-row b{height:100%;display:flex;align-items:center;padding:0 3.5mm;border-right:1px solid #aab4c1;font-size:2.85mm;color:var(--navy);font-weight:900}.date-row strong{padding:0 4mm;font-size:3.2mm;text-align:right;font-weight:900}.amount-box{margin:8mm 0 7mm;border:1px solid var(--gold);border-radius:3mm;overflow:hidden}.amount-title{background:var(--navy);color:var(--gold2);font-size:3.2mm;font-weight:950;letter-spacing:.08em;padding:3mm 4mm;text-align:center}.amount-grid{display:grid;grid-template-columns:1fr 1fr}.amount-grid div{padding:5mm;border-right:1px solid var(--line);min-height:22mm}.amount-grid div:last-child{border-right:0}.amount-grid b{display:block;color:#6b7280;font-size:2.7mm;text-transform:uppercase;margin-bottom:2mm}.amount-grid strong{font-size:7mm;color:var(--navy)}.amount-grid p{margin:0;font-size:3.7mm;line-height:1.35;font-weight:900;color:#263246}.info-table{width:100%;border-collapse:collapse;border:1px solid var(--gold);border-radius:2mm;overflow:hidden;font-size:3mm}.info-table th{width:35mm;background:#fbf5e9;color:var(--navy);text-align:left;border-bottom:1px solid var(--line);padding:3mm;font-weight:950}.info-table td{border-bottom:1px solid var(--line);padding:3mm;font-weight:750}.info-table tr:last-child th,.info-table tr:last-child td{border-bottom:0}.notice{margin-top:7mm;padding:4mm;border-radius:2mm;background:#fbf7ef;border:1px solid var(--line);font-size:3.3mm;line-height:1.45;font-weight:800;color:#273249}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:15mm;margin-top:18mm}.sign{height:28mm;border-top:1px solid var(--navy);padding-top:3mm;text-align:center;font-size:3.1mm;font-weight:900;color:var(--navy)}.sign small{display:block;margin-top:2mm;color:#667085;font-weight:700}.bottom{position:absolute;left:0;right:0;bottom:0;height:32mm;background:linear-gradient(to bottom,var(--navy) 0,var(--navy) 24mm,#efc36d 24mm,#f9e4aa 28mm,#efc36d 32mm);border-top:1mm solid var(--gold)}.bottom:after{content:'MALIMIZDAN HAYIR GÖRÜN.';position:absolute;left:0;right:0;bottom:0;height:8mm;display:flex;align-items:center;justify-content:center;font-family:Georgia,serif;font-size:3.1mm;letter-spacing:.65mm;color:#513910;z-index:5;white-space:nowrap}@page{size:A4;margin:0}@media print{html,body{width:210mm!important;height:297mm!important;background:#fff!important;overflow:hidden!important;margin:0!important;padding:0!important}.toolbar{display:none!important}.page{margin:0!important;border:0!important;box-shadow:none!important;width:210mm!important;height:297mm!important}.header{height:49mm!important;padding-top:5mm!important;border-top-width:2.4mm!important}.logo{width:111mm!important;height:30mm!important;margin-bottom:2.5mm!important}.brand-line{font-size:3.05mm!important}.contact{height:9.5mm!important;font-size:2.18mm!important;grid-template-columns:30mm 41mm 49mm 30mm 1fr!important}.contact div{gap:.9mm!important;padding:0 1mm!important}.web-cell{font-size:2mm!important;padding:0 .55mm!important}.ico{width:4.5mm!important;height:4.5mm!important}.wa svg{width:2.9mm!important;height:2.9mm!important}.content{padding:9mm 10mm 36mm!important}.bottom{height:30mm!important}.header,.contact,.bottom,.amount-title{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Yazdır / PDF al</button><a href="tahsilat-makbuzu.php?edit=<?php echo e($id); ?>">Düzenlemeye dön</a><a href="tahsilat-makbuzu.php">Makbuz listesi</a></div>
<main class="page">
<section class="header"><img class="logo" src="<?php echo e($logoSrc); ?>" alt="Dumanlar"><div class="brand-line">TAHSİLAT MAKBUZU</div></section>
<section class="contact"><div><span class="ico">T</span>0 (356) 715 82 83</div><div><span class="ico">M</span>dumanlartekstil@yahoo.com</div><div class="web-cell"><span class="ico">W</span>www.dumanlartekstil.com.tr</div><div><span class="ico wa" aria-label="WhatsApp"><svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16.02 3.2A12.73 12.73 0 0 0 5.2 22.65L3.8 28.8l6.29-1.66A12.74 12.74 0 1 0 16.02 3.2Zm0 23.12c-2.05 0-4.06-.6-5.78-1.74l-.41-.27-3.74.99 1-3.64-.27-.42a10.35 10.35 0 1 1 9.2 5.08Zm5.96-7.74c-.33-.16-1.93-.95-2.23-1.06-.3-.11-.52-.16-.74.16-.22.33-.85 1.06-1.04 1.28-.19.22-.38.25-.7.08-.33-.16-1.38-.51-2.63-1.62-.97-.86-1.62-1.92-1.81-2.25-.19-.33-.02-.5.14-.66.15-.15.33-.38.49-.57.16-.19.22-.33.33-.55.11-.22.05-.41-.03-.57-.08-.16-.74-1.78-1.01-2.44-.27-.64-.54-.55-.74-.56h-.63c-.22 0-.57.08-.87.41-.3.33-1.14 1.11-1.14 2.71s1.17 3.15 1.33 3.37c.16.22 2.3 3.51 5.57 4.92.78.34 1.39.54 1.86.69.78.25 1.49.21 2.05.13.63-.09 1.93-.79 2.2-1.55.27-.76.27-1.42.19-1.55-.08-.14-.3-.22-.63-.38Z"/></svg></span>0 (356) 715 82 83</div><div class="address-cell"><span class="ico">A</span><span class="address-text"><span>Kelkit Osb Mah. Beyllikbükü Cad. No:8</span><strong>ERBAA/TOKAT</strong></span></div></section>
<section class="content">
  <div class="doc-head">
    <div>
      <div class="customer-name"><?php echo e($customer); ?></div>
      <div class="customer-info">
        <?php if ($address !== ''): ?><div><b>Adres</b><span><?php echo e($address); ?></span></div><?php endif; ?>
        <?php if ($taxOffice !== '' || $taxNo !== ''): ?><div><b>Vergi</b><span><?php echo e(trim($taxOffice . ($taxOffice !== '' && $taxNo !== '' ? ' / ' : '') . $taxNo)); ?></span></div><?php endif; ?>
        <?php if ($phone !== ''): ?><div><b>Telefon</b><span><?php echo e($phone); ?></span></div><?php endif; ?>
        <?php if ($city !== ''): ?><div><b>Şehir</b><span><?php echo e($city); ?></span></div><?php endif; ?>
      </div>
    </div>
    <div class="date-box"><div class="date-row"><b>MAKBUZ NO</b><strong><?php echo e($receiptNo); ?></strong></div><div class="date-row"><b>TARİH</b><strong><?php echo e(tahsilat_tr_date($receiptDate)); ?></strong></div></div>
  </div>
  <div class="amount-box"><div class="amount-title">TAHSİL EDİLEN TUTAR</div><div class="amount-grid"><div><b>Rakamla</b><strong><?php echo e(tahsilat_money($amount)); ?> <?php echo e($currency); ?></strong></div><div><b>Yazıyla</b><p><?php echo e($amountText); ?></p></div></div></div>
  <table class="info-table"><tr><th>Tahsilat Türü</th><td><?php echo e(tahsilat_payment_label($paymentType)); ?></td></tr><?php if($hasExtra): ?><?php if(!empty($r['bank_name'])): ?><tr><th>Banka</th><td><?php echo e($r['bank_name']); ?></td></tr><?php endif; ?><?php if(!empty($r['document_no'])): ?><tr><th>Belge / İşlem No</th><td><?php echo e($r['document_no']); ?></td></tr><?php endif; ?><?php if(!empty($r['due_date'])): ?><tr><th>Vade</th><td><?php echo e(tahsilat_tr_date($r['due_date'])); ?></td></tr><?php endif; ?><?php if(!empty($r['debtor_name'])): ?><tr><th>Keşideci / Borçlu</th><td><?php echo e($r['debtor_name']); ?></td></tr><?php endif; ?><?php endif; ?><tr><th>Açıklama</th><td><?php echo nl2br(e($description)); ?></td></tr></table>
  <div class="notice">Yukarıda bilgileri bulunan firmadan, cari hesabına mahsuben belirtilen tutar tahsil edilmiştir.</div>
  <div class="signatures"><div class="sign">Tahsil Eden<small><?php echo e($r['collected_by'] ?: 'Dumanlar A.Ş.'); ?></small></div><div class="sign">Ödeme Yapan<small><?php echo e($r['paid_by'] ?: $customer); ?></small></div></div>
</section>
<section class="bottom"></section>
</main>
</body>
</html>
