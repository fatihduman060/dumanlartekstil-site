<?php
ob_start();
require __DIR__ . '/teklif-yazdir.php';
$html = ob_get_clean();

$fix = <<<'HTML'
<style id="single-page-print-fix">
@media print{
  @page{size:A4;margin:0!important}
  html,body{
    width:210mm!important;
    height:285mm!important;
    max-height:285mm!important;
    overflow:hidden!important;
    margin:0!important;
    padding:0!important;
    background:#fff!important;
  }
  .toolbar{display:none!important}
  .page{
    width:210mm!important;
    height:285mm!important;
    max-height:285mm!important;
    overflow:hidden!important;
    margin:0 auto!important;
    border:0!important;
    box-shadow:none!important;
    break-after:avoid!important;
    page-break-after:avoid!important;
    break-inside:avoid!important;
    page-break-inside:avoid!important;
  }
  .header{height:46mm!important;padding-top:4.2mm!important;border-top-width:2mm!important}
  .logo{width:105mm!important;height:27mm!important;margin-bottom:1.8mm!important}
  .brand-line{font-size:2.85mm!important}
  .contact{height:8.5mm!important;font-size:1.95mm!important}
  .contact div{padding:0 .65mm!important;gap:.65mm!important}
  .contact div:last-child{font-size:1.75mm!important;line-height:1.08!important}
  .web-cell{font-size:1.78mm!important;padding:0 .35mm!important}
  .ico{width:4mm!important;height:4mm!important;font-size:1.85mm!important}
  .wa svg{width:2.55mm!important;height:2.55mm!important}
  .content{padding:5.8mm 9mm 31mm!important}
  .doc-head{margin-bottom:3.2mm!important}
  .customer-name{font-size:6mm!important;line-height:1.04!important;margin-bottom:1.6mm!important}
  .customer-info{font-size:2.25mm!important;padding:1.8mm 2.4mm!important;margin-bottom:2mm!important}
  .city{font-size:4mm!important}
  .date-row{min-height:8.8mm!important}
  table.items{font-size:2.85mm!important}
  .items th{padding:1.7mm 1.3mm!important}
  .items thead tr.sub th{padding:1.25mm 1.3mm!important;font-size:2.35mm!important}
  .items td{height:3.9mm!important;padding:.65mm 1.4mm!important}
  .total-line td{height:5mm!important}
  .note{margin:2.8mm 2mm 0!important;font-size:2.8mm!important}
  .note-mark{width:6.2mm!important;height:6.2mm!important;font-size:2.35mm!important}
  .bottom{
    height:31mm!important;
    max-height:31mm!important;
    bottom:0!important;
    overflow:hidden!important;
    background:linear-gradient(to bottom,var(--navy) 0,var(--navy) 24.8mm,#efc36d 24.8mm,#f9e4aa 28mm,#efc36d 31mm)!important;
  }
  .bottom:after{height:6.2mm!important;font-size:2.7mm!important}
  .features{height:24.8mm!important;padding:3mm 8.5mm!important;grid-template-columns:1fr 1fr 1fr 48mm!important}
  .feature{height:17mm!important;grid-template-columns:7.5mm 1fr!important;gap:1.8mm!important}
  .ficon{width:7.2mm!important;height:7.2mm!important;font-size:2.5mm!important}
  .feature b{font-size:2.2mm!important}
  .feature span{font-size:1.75mm!important}
  .thanks{font-size:3.25mm!important;padding:3.2mm!important}
}
</style>
HTML;

$html = str_replace('</head>', $fix . "\n</head>", $html);
$html = str_replace('www.dumanlartekstİl.com.tr', 'www.dumanlartekstil.com.tr', $html);
echo $html;
