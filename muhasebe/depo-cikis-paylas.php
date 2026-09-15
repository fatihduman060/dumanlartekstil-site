<?php
require_once __DIR__.'/depo-cikis-paylas-lib.php';
require_login();
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405); header('Allow: POST'); exit('Paylaşım için fişteki WhatsApp düğmesini kullanın.');
}
require_csrf();
$row=depo_cikis_load((int)($_POST['id']??0));
if (!$row || !depo_cikis_can_view($row)) { http_response_code(404); exit('Fiş bulunamadı.'); }
$token=depo_cikis_create_share($row);
// Canonical deployment origin; never trust Host/X-Forwarded-Host for shared URLs.
$url='https://bitke.com.tr'.APP_BASE_PATH.'/depo-cikis-yazdir.php?token='.$token.'&pdf=1';
$text='Dumanlar Tekstil - Depo çıkış fişi #'.$row['dispatch_no']."\n"
    .'Tarih: '.tr_date($row['dispatch_date'])."\n"
    .'PDF al / görüntüle (7 gün geçerli): '.$url;
header('Location: https://wa.me/?text='.rawurlencode($text),true,303);
exit;
