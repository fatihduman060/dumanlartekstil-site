<?php
require_once __DIR__.'/depo-cikis-paylas-lib.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (isset($_GET['token'])) {
    $row=depo_cikis_shared_row(is_string($_GET['token'])?$_GET['token']:'');
} else {
    require_login();
    $row=depo_cikis_load((int)($_GET['id']??0));
    if ($row && !depo_cikis_can_view($row)) $row=null;
}
if (!$row) { http_response_code(404); exit('Fiş bulunamadı veya paylaşım bağlantısının süresi doldu.'); }
if (!extension_loaded('mbstring')) {
    http_response_code(503); exit('PDF için sunucuda mbstring etkinleştirilmeli. Yazdır görünümünden PDF olarak kaydedebilirsiniz.');
}
require_once __DIR__.'/depo-cikis-pdf-lib.php';
try {
    $pdf=depo_cikis_pdf($row);
    $bytes=$pdf->Output('S');
} catch (Throwable $e) {
    error_log('Depo çıkış PDF oluşturulamadı: '.$e->getMessage());
    http_response_code(500); exit('PDF oluşturulamadı. Lütfen tekrar deneyin.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="depo-cikis-'.(int)$row['id'].'.pdf"');
header('Content-Length: '.strlen($bytes));
echo $bytes;
