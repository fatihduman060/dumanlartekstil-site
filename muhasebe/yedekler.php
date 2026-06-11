<?php
// Restore işlemi, layout/bootstrap veritabanı bağlantısını açmadan önce çalışır.
// Böylece canlı SQLite dosyası güvenli şekilde değiştirilebilir.
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

function yedek_pre_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function yedek_pre_redirect(string $url = 'yedekler.php'): void
{
    header('Location: ' . $url);
    exit;
}

function yedek_pre_pdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function yedek_pre_now(): string
{
    return date('Y-m-d H:i:s');
}

function yedek_pre_ip(): string
{
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return trim(explode(',', (string)$raw)[0]);
}

function yedek_pre_require_admin(): array
{
    if (empty($_SESSION['user_id']) || !is_file(DB_PATH)) yedek_pre_redirect('index.php');
    $pdo = yedek_pre_pdo(DB_PATH);
    $stmt = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $pdo = null;
    if (!$user || (int)$user['is_active'] !== 1 || ($user['role'] ?? '') !== 'admin') yedek_pre_redirect('dashboard.php');
    return $user;
}

function yedek_pre_upload_error(int $code): string
{
    return [
        UPLOAD_ERR_INI_SIZE => 'Dosya sunucunun upload_max_filesize limitinden büyük.',
        UPLOAD_ERR_FORM_SIZE => 'Dosya form limitinden büyük.',
        UPLOAD_ERR_PARTIAL => 'Dosya yarım yüklendi. İnternet kesilmiş olabilir.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici upload klasörü yok.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayı diske yazamadı.',
        UPLOAD_ERR_EXTENSION => 'PHP eklentisi yüklemeyi durdurdu.',
    ][$code] ?? ('Bilinmeyen upload hatası: ' . $code);
}

function yedek_pre_summary(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('SQLite dosyası bulunamadı.');
    $pdo = yedek_pre_pdo($path);
    $required = ['users','cariler','movements','checks'];
    foreach ($required as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Bu SQLite dosyası Bitke muhasebe yedeği gibi görünmüyor: ' . $table . ' tablosu yok.');
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

function yedek_pre_extract_zip_sqlite(string $source): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZipArchive kapalı olduğu için ZIP geri yükleme yapılamıyor. SQLite yedek yükleyin.');
    $zip = new ZipArchive();
    if ($zip->open($source) !== true) throw new RuntimeException('ZIP yedeği açılamadı.');
    $stream = $zip->getStream('bitke_muhasebe.sqlite');
    if (!$stream) { $zip->close(); throw new RuntimeException('ZIP içinde bitke_muhasebe.sqlite bulunamadı.'); }
    $tmpDb = sys_get_temp_dir() . '/bitke_restore_' . bin2hex(random_bytes(6)) . '.sqlite';
    file_put_contents($tmpDb, stream_get_contents($stream));
    fclose($stream);
    return [$zip, $tmpDb];
}

function yedek_pre_restore_uploads_from_zip(ZipArchive $zip): void
{
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (strpos($name, 'uploads/') !== 0 || substr($name, -1) === '/') continue;
        $rel = substr($name, strlen('uploads/'));
        if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, '/') === 0 || strpos($rel, '\\') === 0) continue;
        $target = UPLOAD_DIR . '/' . $rel;
        $dir = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $stream = $zip->getStream($name);
        if ($stream) { file_put_contents($target, stream_get_contents($stream)); fclose($stream); }
    }
}

function yedek_pre_restore_sqlite_atomic(string $source): array
{
    $sourceSummary = yedek_pre_summary($source);
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
    if (!is_writable($dbDir)) throw new RuntimeException('Storage klasörü yazılabilir değil: ' . $dbDir);
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

    $stamp = date('Ymd-His');
    $preBackup = BACKUP_DIR . '/bitke-muhasebe-geri-yukleme-oncesi-' . $stamp . '.sqlite';
    if (is_file(DB_PATH)) copy(DB_PATH, $preBackup);

    $newDb = $dbDir . '/restore-new-' . bin2hex(random_bytes(5)) . '.sqlite';
    if (!copy($source, $newDb)) throw new RuntimeException('Yedek geçici veritabanına kopyalanamadı.');
    @chmod($newDb, 0644);
    yedek_pre_summary($newDb);

    $oldDb = $dbDir . '/restore-old-' . $stamp . '-' . bin2hex(random_bytes(5)) . '.sqlite';
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

    $after = yedek_pre_summary(DB_PATH);
    if ($after['checks'] !== $sourceSummary['checks'] || $after['movements'] !== $sourceSummary['movements']) {
        if (is_file($oldDb)) { @unlink(DB_PATH); @rename($oldDb, DB_PATH); }
        throw new RuntimeException('Geri yükleme doğrulaması başarısız oldu; eski veritabanı geri alındı.');
    }
    if (is_file($oldDb)) @unlink($oldDb);
    $after['pre_backup'] = basename($preBackup);
    return $after;
}

function yedek_pre_log_restore(array $user, string $fileName, array $summary): void
{
    try {
        $pdo = yedek_pre_pdo(DB_PATH);
        $detail = $fileName . ' / cari:' . $summary['cariler'] . ' / hareket:' . $summary['movements'] . ' / çek:' . $summary['checks'];
        $pdo->prepare('INSERT INTO logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([(int)$user['id'], $user['username'] ?? '', 'Yedekten geri yüklendi', $detail, yedek_pre_ip(), yedek_pre_now()]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, username, entity_type, entity_id, action, old_value, new_value, detail, ip, created_at) VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?)')
            ->execute([(int)$user['id'], $user['username'] ?? '', 'yedek', 'geri_yukleme', json_encode(['dosya'=>$fileName,'ozet'=>$summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'Yedekten geri yükleme doğrulandı', yedek_pre_ip(), yedek_pre_now()]);
        $pdo = null;
    } catch (Throwable $e) {}
}

// Restore POST ise burada, layout/db açılmadan önce tamamla.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $user = yedek_pre_require_admin();
    $token = $_POST['csrf_token'] ?? null;
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        yedek_pre_flash('error', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        yedek_pre_redirect('yedekler.php');
    }
    if (trim($_POST['confirm_phrase'] ?? '') !== 'GERI YUKLE') {
        yedek_pre_flash('error', 'Geri yükleme için onay alanına GERI YUKLE yazılmalı.');
        yedek_pre_redirect('yedekler.php');
    }
    $file = $_FILES['backup_file'] ?? null;
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if (!$file || $uploadError !== UPLOAD_ERR_OK) {
        yedek_pre_flash('error', 'Dosya yüklenemedi: ' . yedek_pre_upload_error($uploadError));
        yedek_pre_redirect('yedekler.php');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip','sqlite','db'], true)) {
        yedek_pre_flash('error', 'Sadece .zip, .sqlite veya .db yedek dosyası yüklenebilir.');
        yedek_pre_redirect('yedekler.php');
    }

    $zip = null;
    $tmpDb = null;
    try {
        $source = $file['tmp_name'];
        if ($ext === 'zip') {
            [$zip, $tmpDb] = yedek_pre_extract_zip_sqlite($source);
            $source = $tmpDb;
        }
        $summary = yedek_pre_restore_sqlite_atomic($source);
        if ($zip) yedek_pre_restore_uploads_from_zip($zip);
        yedek_pre_log_restore($user, (string)$file['name'], $summary);
        $_SESSION['restore_summary'] = $summary + ['file' => (string)$file['name']];
        yedek_pre_flash('success', 'Yedek geri yüklendi ve doğrulandı. Güvenlik için çıkış yapıp tekrar giriş yapmanız önerilir.');
    } catch (Throwable $e) {
        yedek_pre_flash('error', 'Geri yükleme başarısız: ' . $e->getMessage());
    }
    if ($zip) $zip->close();
    if ($tmpDb && is_file($tmpDb)) @unlink($tmpDb);
    yedek_pre_redirect('yedekler.php');
}

require_once __DIR__ . '/layout.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $created = system_create_backup_file('bitke-muhasebe-yedek');
        if ($created) {
            setting_set('last_backup_date', date('Y-m-d'));
            setting_set('last_backup_file', basename($created));
            log_action('Yedek oluşturuldu', basename($created));
            redirect('yedekler.php?download=' . urlencode(basename($created)));
        }
        flash('error', 'Yedek oluşturulamadı. Storage klasörünün yazılabilir olduğundan emin olun.');
        redirect('yedekler.php');
    }
    if ($action === 'delete') {
        $file = basename($_POST['file'] ?? '');
        $path = BACKUP_DIR . '/' . $file;
        if ($file && is_file($path) && backup_file_is_allowed($file)) {
            unlink($path); log_action('Yedek silindi', $file); flash('success','Yedek silindi.');
        }
        redirect('yedekler.php');
    }
}

if (!empty($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = BACKUP_DIR . '/' . $file;
    if (!backup_file_is_allowed($file)) { http_response_code(400); exit('Geçersiz dosya.'); }
    download_file($path, $file, substr($file, -4) === '.zip' ? 'application/zip' : 'application/octet-stream');
}

$files = backup_file_list();
$autoLast = setting_get('auto_backup_last_date');
$autoLastFile = setting_get('auto_backup_last_file', '');
$restoreSummary = $_SESSION['restore_summary'] ?? null;
unset($_SESSION['restore_summary']);

$lastBackup = setting_get('last_backup_date');
$lastBackupFile = setting_get('last_backup_file', '');
$daysSince = null;
if ($lastBackup) $daysSince = (int)floor((time() - strtotime($lastBackup)) / 86400);

page_header('Yedekleme', 'yedekler');
?>

<?php if ($lastBackup): ?>
<div class="backup-status-bar <?php echo $daysSince >= 7 ? 'backup-old' : 'backup-ok'; ?>">
  <?php if ($daysSince === 0): ?>
    ✅ Bugün yedek alındı. Veriler güvende.
  <?php elseif ($daysSince < 7): ?>
    ✅ Son yedek <strong><?php echo $daysSince; ?> gün önce</strong> (<?php echo e(tr_date($lastBackup)); ?>) alındı.
  <?php else: ?>
    ⚠️ Son yedek <strong><?php echo $daysSince; ?> gün önce</strong> (<?php echo e(tr_date($lastBackup)); ?>) alındı. Yeni yedek almanızı öneririz.
  <?php endif; ?>
</div>
<?php else: ?>
<div class="backup-status-bar backup-old">⚠️ Henüz yedek alınmamış. Verilerinizi korumak için bir yedek oluşturun.</div>
<?php endif; ?>

<?php if ($restoreSummary): ?>
<div class="backup-status-bar backup-ok">
  ✅ Geri yükleme doğrulandı:
  <strong><?php echo e($restoreSummary['file'] ?? 'yedek'); ?></strong>
  · Cari: <strong><?php echo e($restoreSummary['cariler'] ?? 0); ?></strong>
  · Hareket: <strong><?php echo e($restoreSummary['movements'] ?? 0); ?></strong>
  · Çek: <strong><?php echo e($restoreSummary['checks'] ?? 0); ?></strong>
  · İptal çek: <strong><?php echo e($restoreSummary['cancelled_checks'] ?? 0); ?></strong>
  · 3655992 aktif: <strong><?php echo e($restoreSummary['active_3655992'] ?? '-'); ?></strong>
</div>
<?php endif; ?>

<div class="backup-status-bar backup-ok auto-backup-note">
  🤖 Otomatik yedekleme aktif: Sistem ilk panel kullanımında günde 1 yedek alır, son 5 otomatik yedeği saklar.
  <?php if ($autoLast): ?><span>Son otomatik: <strong><?php echo e(tr_date($autoLast)); ?></strong></span><?php endif; ?>
</div>

<section class="hero-card">
  <div>
    <span class="status-pill">Veri güvenliği</span>
    <h2>Yedekle, indir, gerektiğinde geri yükle.</h2>
    <p>ZIP yedek; SQLite veritabanını ve yüklenen belgeleri içerir. Geri yükleme öncesinde sistem otomatik olarak mevcut durumun ayrıca yedeğini alır.</p>
  </div>
  <div class="hero-actions">
    <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="create"><button class="btn btn-primary" type="submit">⇩ Yedek oluştur ve indir</button></form>
  </div>
</section>

<section class="stats-grid three" style="margin-top:0">
  <article class="stat-card"><span>Toplam yedek sayısı</span><strong><?php echo count($files); ?></strong><small><?php echo class_exists('ZipArchive') ? '✅ ZIP + belgeler aktif' : '⚠️ Sadece SQLite / ZIP geri yükleme kapalı'; ?></small></article>
  <article class="stat-card <?php echo (!$lastBackup || $daysSince >= 7) ? '' : 'soft'; ?>"><span>Son yedek tarihi</span><strong class="<?php echo (!$lastBackup || $daysSince >= 7) ? 'text-danger' : 'text-success'; ?>"><?php echo $lastBackup ? e(tr_date($lastBackup)) : 'Yok'; ?></strong><small><?php echo $daysSince !== null ? $daysSince . ' gün önce' : 'Henüz yedek alınmamış'; ?></small></article>
  <article class="stat-card soft"><span>Otomatik yedek</span><strong><?php echo $autoLast ? e(tr_date($autoLast)) : 'Hazır'; ?></strong><small><?php echo $autoLastFile ? e($autoLastFile) : 'İlk kullanımda oluşur'; ?></small></article>
</section>

<section class="content-grid compact">
  <article class="panel-card">
    <div class="card-head"><h3>Mevcut yedekler</h3><span><?php echo count($files); ?> dosya</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Dosya</th><th>Tür</th><th>Oluşturma</th><th class="right">Boyut</th><th></th></tr></thead>
        <tbody>
          <?php if(!$files): ?><tr><td colspan="5" class="empty">Henüz yedek yok. Yukarıdan ilk yedeği oluşturun.</td></tr><?php endif; ?>
          <?php foreach($files as $f): $isZip = substr($f['name'], -4) === '.zip'; $isAuto = strpos($f['name'], 'bitke-muhasebe-otomatik-') === 0; $isLatest = $f['name'] === $lastBackupFile; ?>
          <tr <?php echo $isLatest ? 'class="latest-backup-row"' : ''; ?>>
            <td><strong><?php echo e($f['name']); ?></strong><?php if ($isLatest): ?><small class="soon-tag">Son yedek</small><?php endif; ?></td>
            <td><?php echo $isAuto ? '<span class="badge badge-special">Otomatik</span>' : ($isZip ? '<span class="badge badge-success">ZIP</span>' : '<span class="badge badge-info">SQLite</span>'); ?></td>
            <td><?php echo e(date('d.m.Y H:i', $f['time'])); ?></td>
            <td class="right"><?php echo e(bytes_human($f['size'])); ?></td>
            <td class="row-actions"><a href="yedekler.php?download=<?php echo e(urlencode($f['name'])); ?>">⇩ İndir</a><form method="post" onsubmit="return confirm('Yedek silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?php echo e($f['name']); ?>"><button>Sil</button></form></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>

  <article class="panel-card danger-zone">
    <div class="card-head"><h3>Yedekten geri yükle</h3><span>Dikkatli kullan</span></div>
    <div class="security-note"><strong>Önemli:</strong> Geri yükleme mevcut veritabanını seçtiğiniz yedekle değiştirir. İşlemden önce sistem mevcut veritabanını ayrıca saklar ve işlem sonunda sayıları doğrular.</div>
    <form method="post" enctype="multipart/form-data" class="stack-form" onsubmit="return confirm('Mevcut veriler yedek dosyasıyla değiştirilecek. Devam edilsin mi?');">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="restore">
      <label>ZIP / SQLite yedek dosyası<input type="file" name="backup_file" required><small>Mobilde tüm dosyalar gösterilir; sunucu yine sadece .zip, .sqlite veya .db kabul eder. Başarılı yüklemede cari/hareket/çek sayısı ekranda doğrulanır.</small></label>
      <label>Onay için yazın: <strong>GERI YUKLE</strong><input name="confirm_phrase" placeholder="GERI YUKLE" required></label>
      <button class="btn btn-danger" type="submit">Yedeği geri yükle</button>
    </form>
  </article>
</section>
<?php page_footer(); ?>