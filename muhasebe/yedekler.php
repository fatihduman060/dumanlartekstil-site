<?php
require_once __DIR__ . '/layout.php';
require_admin();

function create_backup_file(string $prefix = 'bitke-muhasebe-yedek'): ?string
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    $name = $prefix . '-' . date('Ymd-His');
    $zipPath = BACKUP_DIR . '/' . $name . '.zip';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            if (is_file(DB_PATH)) $zip->addFile(DB_PATH, 'bitke_muhasebe.sqlite');
            $uploadBase = realpath(UPLOAD_DIR);
            if ($uploadBase && is_dir($uploadBase)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadBase, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $rel = 'uploads/' . substr($file->getPathname(), strlen($uploadBase) + 1);
                        $zip->addFile($file->getPathname(), $rel);
                    }
                }
            }
            $zip->close();
            return $zipPath;
        }
    }
    $sqliteCopy = BACKUP_DIR . '/' . $name . '.sqlite';
    if (is_file(DB_PATH) && copy(DB_PATH, $sqliteCopy)) return $sqliteCopy;
    return null;
}

function restore_sqlite_file(string $source): void
{
    if (!is_file($source)) throw new RuntimeException('Geri yüklenecek veritabanı bulunamadı.');
    $test = new PDO('sqlite:' . $source);
    $test->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $required = ['users','cariler','movements','checks'];
    foreach ($required as $table) {
        $stmt = $test->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Bu SQLite dosyası Bitke muhasebe yedeği gibi görünmüyor: ' . $table . ' tablosu yok.');
    }
    $test = null;
    if (!copy($source, DB_PATH)) throw new RuntimeException('SQLite veritabanı storage klasörüne kopyalanamadı.');
}

function restore_zip_file(string $source): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZipArchive kapalı olduğu için ZIP geri yükleme yapılamıyor. SQLite yedek yükleyin.');
    $zip = new ZipArchive();
    if ($zip->open($source) !== true) throw new RuntimeException('ZIP yedeği açılamadı.');
    $tmpDb = sys_get_temp_dir() . '/bitke_restore_' . bin2hex(random_bytes(6)) . '.sqlite';
    $dbIndex = $zip->locateName('bitke_muhasebe.sqlite');
    if ($dbIndex === false) { $zip->close(); throw new RuntimeException('ZIP içinde bitke_muhasebe.sqlite bulunamadı.'); }
    $stream = $zip->getStream('bitke_muhasebe.sqlite');
    if (!$stream) { $zip->close(); throw new RuntimeException('ZIP içindeki SQLite dosyası okunamadı.'); }
    file_put_contents($tmpDb, stream_get_contents($stream));
    fclose($stream);
    restore_sqlite_file($tmpDb);
    @unlink($tmpDb);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    for ($i=0; $i<$zip->numFiles; $i++) {
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
    $zip->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $created = create_backup_file();
        if ($created) {
            setting_set('last_backup_date', date('Y-m-d'));
            setting_set('last_backup_file', basename($created));
            log_action('Yedek oluşturuldu', basename($created));
            redirect('yedekler.php?download=' . urlencode(basename($created)));
        }
        flash('error', 'Yedek oluşturulamadı. Storage klasörünün yazılabilir olduğundan emin olun.');
        redirect('yedekler.php');
    }
    if ($action === 'restore') {
        $phrase = trim($_POST['confirm_phrase'] ?? '');
        if ($phrase !== 'GERI YUKLE') {
            flash('error', 'Geri yükleme için onay alanına GERI YUKLE yazılmalı.');
            redirect('yedekler.php');
        }
        if (empty($_FILES['backup_file']) || ($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Geri yüklenecek ZIP veya SQLite yedek dosyasını seçin.');
            redirect('yedekler.php');
        }
        $file = $_FILES['backup_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip','sqlite','db'], true)) {
            flash('error', 'Sadece .zip, .sqlite veya .db yedek dosyası yüklenebilir.');
            redirect('yedekler.php');
        }
        $pre = create_backup_file('bitke-muhasebe-geri-yukleme-oncesi');
        try {
            if ($ext === 'zip') restore_zip_file($file['tmp_name']);
            else restore_sqlite_file($file['tmp_name']);
            log_action('Yedekten geri yüklendi', $file['name'] . ($pre ? ' / önceki yedek: ' . basename($pre) : ''));
            flash('success', 'Yedek geri yüklendi. Güvenlik için çıkış yapıp tekrar giriş yapmanız önerilir.');
        } catch (Throwable $e) {
            flash('error', 'Geri yükleme başarısız: ' . $e->getMessage() . ($pre ? ' Mevcut sistemin işlem öncesi yedeği oluşturuldu: ' . basename($pre) : ''));
        }
        redirect('yedekler.php');
    }
    if ($action === 'delete') {
        $file = basename($_POST['file'] ?? '');
        $path = BACKUP_DIR . '/' . $file;
        if ($file && is_file($path) && preg_match('/^bitke-muhasebe-(yedek|geri-yukleme-oncesi)-\d{8}-\d{6}\.(zip|sqlite)$/', $file)) {
            unlink($path); log_action('Yedek silindi', $file); flash('success','Yedek silindi.');
        }
        redirect('yedekler.php');
    }
}

if (!empty($_GET['download'])) {
    $file = basename($_GET['download']);
    $path = BACKUP_DIR . '/' . $file;
    if (!preg_match('/^bitke-muhasebe-(yedek|geri-yukleme-oncesi)-\d{8}-\d{6}\.(zip|sqlite)$/', $file)) { http_response_code(400); exit('Geçersiz dosya.'); }
    download_file($path, $file, substr($file, -4) === '.zip' ? 'application/zip' : 'application/octet-stream');
}

$files = [];
if (is_dir(BACKUP_DIR)) {
    foreach (glob(BACKUP_DIR . '/bitke-muhasebe-*.*') ?: [] as $f) {
        $bn = basename($f);
        if (preg_match('/^bitke-muhasebe-(yedek|geri-yukleme-oncesi)-\d{8}-\d{6}\.(zip|sqlite)$/', $bn)) {
            $files[] = ['name'=>$bn, 'size'=>filesize($f), 'time'=>filemtime($f)];
        }
    }
    usort($files, fn($a,$b)=>$b['time']<=>$a['time']);
}

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

<section class="stats-grid two" style="margin-top:0">
  <article class="stat-card"><span>Toplam yedek sayısı</span><strong><?php echo count($files); ?></strong><small><?php echo class_exists('ZipArchive') ? '✅ ZIP + belgeler aktif' : '⚠️ Sadece SQLite / ZIP geri yükleme kapalı'; ?></small></article>
  <article class="stat-card <?php echo (!$lastBackup || $daysSince >= 7) ? '' : 'soft'; ?>"><span>Son yedek tarihi</span><strong class="<?php echo (!$lastBackup || $daysSince >= 7) ? 'text-danger' : 'text-success'; ?>"><?php echo $lastBackup ? e(tr_date($lastBackup)) : 'Yok'; ?></strong><small><?php echo $daysSince !== null ? $daysSince . ' gün önce' : 'Henüz yedek alınmamış'; ?></small></article>
</section>

<section class="content-grid compact">
  <article class="panel-card">
    <div class="card-head"><h3>Mevcut yedekler</h3><span><?php echo count($files); ?> dosya</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Dosya</th><th>Tür</th><th>Oluşturma</th><th class="right">Boyut</th><th></th></tr></thead>
        <tbody>
          <?php if(!$files): ?><tr><td colspan="5" class="empty">Henüz yedek yok. Yukarıdan ilk yedeği oluşturun.</td></tr><?php endif; ?>
          <?php foreach($files as $f): $isZip = substr($f['name'], -4) === '.zip'; $isLatest = $f['name'] === $lastBackupFile; ?>
          <tr <?php echo $isLatest ? 'class="latest-backup-row"' : ''; ?>>
            <td><strong><?php echo e($f['name']); ?></strong><?php if ($isLatest): ?><small class="soon-tag">Son yedek</small><?php endif; ?></td>
            <td><?php echo $isZip ? '<span class="badge badge-success">ZIP</span>' : '<span class="badge badge-info">SQLite</span>'; ?></td>
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
    <div class="security-note"><strong>Önemli:</strong> Geri yükleme mevcut veritabanını seçtiğiniz yedekle değiştirir. İşlemden önce otomatik “geri-yükleme-öncesi” yedeği alınır.</div>
    <form method="post" enctype="multipart/form-data" class="stack-form" onsubmit="return confirm('Mevcut veriler yedek dosyasıyla değiştirilecek. Devam edilsin mi?');">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="restore">
      <label>ZIP / SQLite yedek dosyası<input type="file" name="backup_file" accept=".zip,.sqlite,.db" required></label>
      <label>Onay için yazın: <strong>GERI YUKLE</strong><input name="confirm_phrase" placeholder="GERI YUKLE" required></label>
      <button class="btn btn-danger" type="submit">Yedeği geri yükle</button>
    </form>
  </article>
</section>
<?php page_footer(); ?>
