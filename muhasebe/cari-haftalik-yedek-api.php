<?php
require_once __DIR__ . '/cari-haftalik-yedek-lib.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Yetkisiz erişim.'); }

$action = (string)($_REQUEST['action'] ?? 'status');

if ($action === 'download') {
    $file = basename((string)($_GET['file'] ?? ''));
    if (!preg_match('/^Cari_Bakiye_[0-9]{8}_[0-9]{8}_[0-9]{6}\.xlsx$/', $file)) { http_response_code(400); exit('Geçersiz dosya.'); }
    $path = cari_haftalik_yedek_dir() . '/' . $file;
    if (!is_file($path)) { http_response_code(404); exit('Dosya bulunamadı.'); }
    download_file($path, $file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function cari_haftalik_api_out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($action === 'status') {
        $files = array_map(function ($f) {
            return [
                'name'=>$f['name'],
                'time'=>date('d.m.Y H:i', (int)$f['time']),
                'size'=>bytes_human((int)$f['size']),
                'download'=>'cari-haftalik-yedek-api.php?action=download&file=' . rawurlencode($f['name']),
            ];
        }, cari_haftalik_list());
        $monday = strtotime('monday this week 08:00:00');
        cari_haftalik_api_out([
            'ok'=>true,
            'files'=>$files,
            'csrf_token'=>csrf_token(),
            'week_key'=>cari_haftalik_week_key(),
            'due'=>time() >= $monday,
            'schedule'=>'Her Pazartesi 08:00',
            'keep'=>52,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cari_haftalik_api_out(['ok'=>false,'error'=>'POST gerekli.'], 405);
    require_csrf();

    if ($action === 'create') {
        $path = cari_haftalik_create(true);
        log_action('Cari bakiye Excel yedeği oluşturuldu', basename((string)$path));
        cari_haftalik_api_out([
            'ok'=>true,
            'file'=>basename((string)$path),
            'download'=>'cari-haftalik-yedek-api.php?action=download&file=' . rawurlencode(basename((string)$path)),
        ]);
    }

    if ($action === 'auto') {
        $before = cari_haftalik_list();
        $path = cari_haftalik_auto_if_due();
        $after = cari_haftalik_list();
        $created = $path && count($after) > count($before);
        if ($created) log_action('Haftalık cari bakiye Excel yedeği otomatik oluşturuldu', basename((string)$path));
        cari_haftalik_api_out(['ok'=>true,'created'=>$created,'file'=>$path ? basename($path) : null]);
    }

    cari_haftalik_api_out(['ok'=>false,'error'=>'Geçersiz işlem.'], 400);
} catch (Throwable $e) {
    cari_haftalik_api_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
