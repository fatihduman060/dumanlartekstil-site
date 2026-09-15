<?php
require_once __DIR__.'/depo-cikis-paylas-lib.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (isset($_GET['token'])) {
    $token=is_string($_GET['token'])?$_GET['token']:'';
    $row=depo_cikis_shared_row($token);
    $target='depo-cikis-yazdir.php?token='.rawurlencode($token).'&pdf=1';
} else {
    require_login();
    $id=(int)($_GET['id']??0);
    $row=depo_cikis_load($id);
    if ($row && !depo_cikis_can_view($row)) $row=null;
    $target='depo-cikis-yazdir.php?id='.(int)$id.'&pdf=1';
}
if (!$row) { http_response_code(404); exit('Fiş bulunamadı veya paylaşım bağlantısının süresi doldu.'); }
header('Location: '.$target,true,303);
exit;
