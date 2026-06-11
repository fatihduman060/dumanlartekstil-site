<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (session_status() === PHP_SESSION_NONE) {
    session_name('bitke_muhasebe_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH,
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function go(string $url): void { header('Location: ' . $url); exit; }
function flash_msg(string $type, string $msg): void { $_SESSION['safe_restore_flash'][] = ['type'=>$type, 'msg'=>$msg]; }
function take_flashes(): array { $f = $_SESSION['safe_restore_flash'] ?? []; unset($_SESSION['safe_restore_flash']); return $f; }
function csrf(): string { if (empty($_SESSION['safe_restore_csrf'])) $_SESSION['safe_restore_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['safe_restore_csrf']; }
function csrf_ok(?string $token): bool { return !empty($token) && !empty($_SESSION['safe_restore_csrf']) && hash_equals($_SESSION['safe_restore_csrf'], $token); }
function money_int($value): string { return number_format((float)$value, 0, ',', '.'); }
function now_local(): string { return date('Y-m-d H:i:s'); }

function lite_pdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function require_admin_light(): array
{
    if (empty($_SESSION['user_id'])) go('index.php');
    if (!is_file(DB_PATH)) go('index.php');
    try {
        $pdo = lite_pdo(DB_PATH);
        $stmt = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $u = $stmt->fetch();
        $pdo = null;
        if (!$u || (int)$u['is_active'] !== 1 || $u['role'] !== 'admin') go('dashboard.php');
        return $u;
    } catch (Throwable $e) {
        go('index.php');
    }
}

function upload_error_text(int $code): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'Dosya sunucu yükleme limitinden büyük.',
        UPLOAD_ERR_FORM_SIZE => 'Dosya form limitinden büyük.',
        UPLOAD_ERR_PARTIAL => 'Dosya yarım yüklendi. İnternet kesilmiş olabilir.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör yok.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayı diske yazamadı.',
        UPLOAD_ERR_EXTENSION => 'PHP dosya yüklemeyi durdurdu.',
    ];
    return $map[$code] ?? ('Bilinmeyen yükleme hatası: ' . $code);
}

function db_summary(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('SQLite dosyası bulunamadı.');
    $pdo = lite_pdo($path);
    $required = ['users','cariler','movements','checks'];
    foreach ($required as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Bu dosya Bitke muhasebe yedeği gibi görünmüyor: ' . $table . ' tablosu yok.');
    }
    $summary = [
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'cariler' => (int)$pdo->query('SELECT COUNT(*) FROM cariler')->fetchColumn(),
        'movements' => (int)$pdo->query('SELECT COUNT(*) FROM movements')->fetchColumn(),
        'checks' => (int)$pdo->query('SELECT COUNT(*) FROM checks')->fetchColumn(),
        'cancelled_checks' => (int)$pdo->query('SELECT COUNT(*) FROM checks WHERE COALESCE(is_cancelled,0)=1')->fetchColumn(),
        'active_3655992' => 0,
        'size' => filesize($path) ?: 0,
    ];
    try {
        $summary['active_3655992'] = (int)$pdo->query("SELECT COUNT(*) FROM checks WHERE COALESCE(is_cancelled,0)=0 AND REPLACE(UPPER(COALESCE(check_no,'')),'Z','')='3655992'")->fetchColumn();
    } catch (Throwable $e) {}
    $pdo = null;
    return $summary;
}

function extract_sqlite_from_zip(string $zipPath): string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZIP açma kapalı. SQLite dosyası yükleyin.');
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('ZIP dosyası açılamadı.');
    $stream = $zip->getStream('bitke_muhasebe.sqlite');
    if (!$stream) { $zip->close(); throw new RuntimeException('ZIP içinde bitke_muhasebe.sqlite bulunamadı.'); }
    $tmp = sys_get_temp_dir() . '/bitke_restore_' . bin2hex(random_bytes(6)) . '.sqlite';
    file_put_contents($tmp, stream_get_contents($stream));
    fclose($stream);
    $zip->close();
    return $tmp;
}

function atomic_restore_sqlite(string $source): array
{
    $sourceSummary = db_summary($source);
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (!is_writable($dir)) throw new RuntimeException('Storage klasörü yazılabilir değil: ' . $dir);
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

    $stamp = date('Ymd-His');
    $preBackup = BACKUP_DIR . '/bitke-muhasebe-geri-yukleme-oncesi-' . $stamp . '.sqlite';
    if (is_file(DB_PATH)) copy(DB_PATH, $preBackup);

    $newDb = $dir . '/bitke_restore_new_' . bin2hex(random_bytes(4)) . '.sqlite';
    if (!copy($source, $newDb)) throw new RuntimeException('Yedek geçici veritabanına kopyalanamadı.');
    @chmod($newDb, 0644);
    db_summary($newDb);

    $oldDb = $dir . '/bitke_restore_old_' . $stamp . '_' . bin2hex(random_bytes(4)) . '.sqlite';
    if (is_file(DB_PATH) && !rename(DB_PATH, $oldDb)) {
        @unlink($newDb);
        throw new RuntimeException('Mevcut veritabanı kenara alınamadı. Dosya izinlerini kontrol edin.');
    }
    if (!rename($newDb, DB_PATH)) {
        if (is_file($oldDb)) @rename($oldDb, DB_PATH);
        @unlink($newDb);
        throw new RuntimeException('Yeni veritabanı canlı konuma alınamadı.');
    }
    @chmod(DB_PATH, 0644);

    $after = db_summary(DB_PATH);
    if ($after['checks'] !== $sourceSummary['checks'] || $after['movements'] !== $sourceSummary['movements']) {
        if (is_file($oldDb)) {
            @unlink(DB_PATH);
            @rename($oldDb, DB_PATH);
        }
        throw new RuntimeException('Doğrulama başarısız oldu; eski veritabanı geri alındı.');
    }
    if (is_file($oldDb)) @unlink($oldDb);
    $after['pre_backup'] = basename($preBackup);
    return $after;
}

function insert_restore_log(array $user, string $fileName, array $summary): void
{
    try {
        $pdo = lite_pdo(DB_PATH);
        $detail = $fileName . ' / cari:' . $summary['cariler'] . ' / hareket:' . $summary['movements'] . ' / çek:' . $summary['checks'];
        $pdo->prepare('INSERT INTO logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$user['id'], $user['username'] ?? '', 'Yedekten geri yüklendi', $detail, $_SERVER['REMOTE_ADDR'] ?? '', now_local()]);
        $pdo = null;
    } catch (Throwable $e) {}
}

$user = require_admin_light();
$before = null;
try { $before = db_summary(DB_PATH); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf_token'] ?? null)) {
        flash_msg('error', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        go('yedek-yukle-guvenli.php');
    }
    if (trim($_POST['confirm_phrase'] ?? '') !== 'GERI YUKLE') {
        flash_msg('error', 'Onay alanına GERI YUKLE yazılmalı.');
        go('yedek-yukle-guvenli.php');
    }
    $file = $_FILES['backup_file'] ?? null;
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (!$file || $err !== UPLOAD_ERR_OK) {
        flash_msg('error', 'Dosya yüklenemedi: ' . upload_error_text($err));
        go('yedek-yukle-guvenli.php');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip','sqlite','db'], true)) {
        flash_msg('error', 'Sadece .zip, .sqlite veya .db yedek dosyası yüklenebilir.');
        go('yedek-yukle-guvenli.php');
    }
    $tmpSqlite = null;
    try {
        $source = $file['tmp_name'];
        if ($ext === 'zip') {
            $tmpSqlite = extract_sqlite_from_zip($source);
            $source = $tmpSqlite;
        }
        $result = atomic_restore_sqlite($source);
        insert_restore_log($user, (string)$file['name'], $result);
        $_SESSION['safe_restore_result'] = $result + ['file' => (string)$file['name']];
        flash_msg('success', 'Yedek geri yüklendi ve doğrulandı. Çıkış yapıp tekrar giriş yapmanız önerilir.');
    } catch (Throwable $e) {
        flash_msg('error', 'Geri yükleme başarısız: ' . $e->getMessage());
    }
    if ($tmpSqlite && is_file($tmpSqlite)) @unlink($tmpSqlite);
    go('yedek-yukle-guvenli.php');
}

$result = $_SESSION['safe_restore_result'] ?? null;
unset($_SESSION['safe_restore_result']);
$flashes = take_flashes();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Güvenli Yedek Yükle | Bitke Muhasebe</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f5f1e8;margin:0;color:#1f1f1f}.wrap{max-width:920px;margin:24px auto;padding:16px}.card{background:#fff;border:1px solid #ded6c8;border-radius:22px;padding:22px;box-shadow:0 12px 32px rgba(0,0,0,.08)}h1{margin:0 0 8px}.muted{color:#6f6a60}.alert{padding:14px 16px;border-radius:14px;margin:12px 0}.success{background:#e9f6ee;color:#217444}.error{background:#fff0ef;color:#a03b34}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:18px 0}.stat{background:#faf7ef;border:1px solid #e8dfd2;border-radius:14px;padding:14px}.stat span{display:block;color:#777;font-size:12px}.stat strong{font-size:22px}label{display:block;margin:14px 0;font-weight:700}input{width:100%;box-sizing:border-box;border:1px solid #ded6c8;border-radius:14px;padding:15px;font-size:16px;margin-top:8px}button,.btn{display:inline-block;border:0;border-radius:14px;padding:15px 20px;background:#a03b34;color:white;font-weight:800;text-decoration:none;font-size:16px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:18px}.btn2{background:#eee7dc;color:#2b2925}@media(max-width:720px){.grid{grid-template-columns:repeat(2,1fr)}.top{display:block}.btn{margin-top:10px}}
  </style>
</head>
<body>
<div class="wrap">
  <div class="top"><div><h1>Güvenli yedek yükle</h1><p class="muted">Veritabanı bağlantısı açılmadan restore yapar, sonra sayıları doğrular.</p></div><a class="btn btn2" href="yedekler.php">Yedeklemeye dön</a></div>
  <?php foreach ($flashes as $f): ?><div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div><?php endforeach; ?>
  <?php if ($result): ?><div class="alert success">Doğrulandı: <?php echo h($result['file']); ?> · Cari <?php echo h($result['cariler']); ?> · Hareket <?php echo h($result['movements']); ?> · Çek <?php echo h($result['checks']); ?> · İptal çek <?php echo h($result['cancelled_checks']); ?> · 3655992 aktif <?php echo h($result['active_3655992']); ?></div><?php endif; ?>
  <div class="card">
    <h2>Mevcut canlı veritabanı</h2>
    <?php if ($before): ?><div class="grid"><div class="stat"><span>Cari</span><strong><?php echo h($before['cariler']); ?></strong></div><div class="stat"><span>Hareket</span><strong><?php echo h($before['movements']); ?></strong></div><div class="stat"><span>Çek</span><strong><?php echo h($before['checks']); ?></strong></div><div class="stat"><span>3655992 aktif</span><strong><?php echo h($before['active_3655992']); ?></strong></div></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Mevcut veriler seçilen yedekle değiştirilecek. Devam edilsin mi?');">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf()); ?>">
      <label>ZIP / SQLite / DB yedek dosyası<input type="file" name="backup_file" required></label>
      <label>Onay için yazın: <strong>GERI YUKLE</strong><input name="confirm_phrase" placeholder="GERI YUKLE" required></label>
      <button type="submit">Yedeği doğrula ve geri yükle</button>
    </form>
  </div>
</div>
</body>
</html>
