<?php
require_once __DIR__ . '/layout.php';
require_admin();

function backup_check_sqlite(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Dosya bulunamadı.');
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $required = ['users','cariler','movements','checks'];
    foreach ($required as $table) {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Bu dosya muhasebe yedeği gibi görünmüyor: ' . $table . ' tablosu yok.');
    }
    $summary = [
        'cariler' => (int)$pdo->query('SELECT COUNT(*) FROM cariler')->fetchColumn(),
        'movements' => (int)$pdo->query('SELECT COUNT(*) FROM movements')->fetchColumn(),
        'checks' => (int)$pdo->query('SELECT COUNT(*) FROM checks')->fetchColumn(),
        'cancelled_checks' => (int)$pdo->query('SELECT COUNT(*) FROM checks WHERE COALESCE(is_cancelled,0)=1')->fetchColumn(),
    ];
    $pdo = null;
    return $summary;
}

function backup_upload_error(int $code): string
{
    return [
        UPLOAD_ERR_INI_SIZE => 'Dosya sunucu yükleme limitinden büyük.',
        UPLOAD_ERR_FORM_SIZE => 'Dosya form limitinden büyük.',
        UPLOAD_ERR_PARTIAL => 'Dosya yarım yüklendi.',
        UPLOAD_ERR_NO_FILE => 'Dosya seçilmedi.',
        UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör yok.',
        UPLOAD_ERR_CANT_WRITE => 'Sunucu dosyayı yazamadı.',
        UPLOAD_ERR_EXTENSION => 'PHP dosya yüklemeyi durdurdu.',
    ][$code] ?? ('Bilinmeyen hata: ' . $code);
}

function backup_restore_sqlite(string $source): array
{
    $summary = backup_check_sqlite($source);
    $targetDir = dirname(DB_PATH);
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    if (!is_writable($targetDir)) throw new RuntimeException('Storage klasörü yazılabilir değil.');

    $target = $targetDir . '/restore-' . date('Ymd-His') . '.sqlite';
    if (!copy($source, $target)) throw new RuntimeException('Yedek geçici dosyaya kopyalanamadı.');
    backup_check_sqlite($target);
    if (!copy($target, DB_PATH)) throw new RuntimeException('Yedek canlı veritabanına kopyalanamadı.');
    @unlink($target);

    $after = backup_check_sqlite(DB_PATH);
    if (($after['checks'] ?? -1) !== ($summary['checks'] ?? -2)) throw new RuntimeException('Doğrulama başarısız: çek sayısı uyuşmadı.');
    return $after;
}

function backup_restore_zip(string $source): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZIP açma kapalı. SQLite dosyası yükleyin.');
    $zip = new ZipArchive();
    if ($zip->open($source) !== true) throw new RuntimeException('ZIP açılamadı.');
    $stream = $zip->getStream('bitke_muhasebe.sqlite');
    if (!$stream) { $zip->close(); throw new RuntimeException('ZIP içinde bitke_muhasebe.sqlite yok.'); }
    $tmp = sys_get_temp_dir() . '/bitke_restore_' . bin2hex(random_bytes(5)) . '.sqlite';
    file_put_contents($tmp, stream_get_contents($stream));
    fclose($stream);
    $summary = backup_restore_sqlite($tmp);
    @unlink($tmp);
    $zip->close();
    return $summary;
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (trim($_POST['confirm_phrase'] ?? '') !== 'GERI YUKLE') {
        flash('error', 'Onay alanına GERI YUKLE yazılmalı.');
        redirect('yedek-yukle-guvenli.php');
    }
    $file = $_FILES['backup_file'] ?? null;
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash('error', 'Dosya yüklenemedi: ' . backup_upload_error((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        redirect('yedek-yukle-guvenli.php');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip','sqlite','db'], true)) {
        flash('error', 'Sadece .zip, .sqlite veya .db yüklenebilir.');
        redirect('yedek-yukle-guvenli.php');
    }
    $pre = system_create_backup_file('bitke-muhasebe-geri-yukleme-oncesi');
    try {
        $result = ($ext === 'zip') ? backup_restore_zip($file['tmp_name']) : backup_restore_sqlite($file['tmp_name']);
        $_SESSION['safe_restore_result'] = $result + ['file' => (string)$file['name'], 'pre' => $pre ? basename($pre) : 'yok'];
        flash('success', 'Yedek geri yüklendi ve doğrulandı. Çıkış yapıp tekrar giriş yapmanız önerilir.');
        redirect('yedek-yukle-guvenli.php');
    } catch (Throwable $e) {
        flash('error', 'Geri yükleme başarısız: ' . $e->getMessage() . ($pre ? ' İşlem öncesi yedek: ' . basename($pre) : ''));
        redirect('yedek-yukle-guvenli.php');
    }
}

$result = $_SESSION['safe_restore_result'] ?? null;
unset($_SESSION['safe_restore_result']);

page_header('Güvenli Yedek Yükle', 'yedekler');
?>
<section class="panel-card danger-zone">
  <div class="card-head"><h3>Güvenli yedek geri yükleme</h3><a href="yedekler.php">Yedekleme sayfasına dön</a></div>
  <div class="security-note"><strong>Bu sayfa doğrulamalıdır:</strong> dosyayı yükler, veritabanı tablolarını kontrol eder, sonra canlı veritabanına kopyalar ve çek/cari/hareket sayılarını tekrar doğrular.</div>
  <?php if ($result): ?>
    <div class="alert alert-success">
      Doğrulandı: <?php echo e($result['file'] ?? 'yedek'); ?> · Cari: <?php echo e($result['cariler'] ?? 0); ?> · Hareket: <?php echo e($result['movements'] ?? 0); ?> · Çek: <?php echo e($result['checks'] ?? 0); ?> · İptal çek: <?php echo e($result['cancelled_checks'] ?? 0); ?>
    </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="stack-form" onsubmit="return confirm('Mevcut veriler seçilen yedekle değiştirilecek. Devam edilsin mi?');">
    <?php echo csrf_field(); ?>
    <label>ZIP / SQLite / DB yedek dosyası<input type="file" name="backup_file" required><small>Telefonda dosya gri olursa ZIP yerine SQLite seçmeyi deneyin; bu alan tüm dosyaları gösterir.</small></label>
    <label>Onay için yazın: <strong>GERI YUKLE</strong><input name="confirm_phrase" placeholder="GERI YUKLE" required></label>
    <button class="btn btn-danger" type="submit">Yedeği doğrula ve geri yükle</button>
  </form>
</section>
<?php page_footer(); ?>
