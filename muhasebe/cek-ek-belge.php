<?php
require_once __DIR__ . '/layout.php';
require_login();

function cek_ek_belge_tipleri(): array
{
    return [
        'cek_on_gorseli' => 'Çek ön görseli',
        'cek_arka_gorseli' => 'Çek arka görseli',
        'tahsil_dekontu' => 'Tahsil dekontu',
        'odeme_dekontu' => 'Ödeme dekontu',
        'ciro_belgesi' => 'Ciro belgesi',
        'iade_belgesi' => 'İade belgesi',
        'protesto_belgesi' => 'Protesto belgesi',
        'diger' => 'Diğer çek belgesi',
    ];
}
function cek_ek_belge_label(?string $key): string
{
    $types = cek_ek_belge_tipleri();
    return $types[$key ?: ''] ?? ($key ?: '-');
}
function cek_ek_belge_prefix(int $checkId): string
{
    return 'Çek #' . $checkId . ' | ';
}

$checkId = (int)($_GET['id'] ?? $_POST['check_id'] ?? 0);
$stmt = db()->prepare('SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=?');
$stmt->execute([$checkId]);
$check = $stmt->fetch();
if (!$check) { flash('error', 'Çek bulunamadı.'); redirect('cekler.php'); }
$prefix = cek_ek_belge_prefix($checkId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $docType = $_POST['document_type'] ?? 'diger';
        if (!isset(cek_ek_belge_tipleri()[$docType])) $docType = 'diger';
        try {
            $doc = handle_upload('document');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('cek-ek-belge.php?id=' . $checkId);
        }
        if (!$doc['path']) {
            flash('error', 'Dosya seçmelisin.');
            redirect('cek-ek-belge.php?id=' . $checkId);
        }
        $desc = $prefix . trim($_POST['description'] ?? cek_ek_belge_label($docType));
        db()->prepare('INSERT INTO standalone_documents (cari_id, document_date, document_type, document_path, document_name, document_mime, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$check['cari_id'] ?: null, date('Y-m-d'), $docType, $doc['path'], $doc['name'], $doc['mime'], $desc, current_user()['id'] ?? null, now(), now()]);
        $newId = (int)db()->lastInsertId();
        log_action('Çek ek belgesi eklendi', '#' . $checkId . ' ' . cek_ek_belge_label($docType));
        audit_action('belge', $newId, 'eklendi', null, ['check_id'=>$checkId,'document_type'=>$docType], 'Çek ek belgesi');
        flash('success', 'Çek ek belgesi eklendi.');
        redirect('cek-ek-belge.php?id=' . $checkId);
    }

    if ($action === 'delete') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM standalone_documents WHERE id=? AND description LIKE ?');
        $stmt->execute([$docId, $prefix . '%']);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['document_path'])) {
                $path = UPLOAD_DIR . '/' . $row['document_path'];
                if (is_file($path)) @unlink($path);
            }
            db()->prepare('DELETE FROM standalone_documents WHERE id=?')->execute([$docId]);
            log_action('Çek ek belgesi silindi', '#' . $checkId);
            audit_action('belge', $docId, 'silindi', $row, null, 'Çek ek belgesi');
            flash('success', 'Çek ek belgesi silindi.');
        }
        redirect('cek-ek-belge.php?id=' . $checkId);
    }
}

$stmt = db()->prepare('SELECT * FROM standalone_documents WHERE description LIKE ? ORDER BY document_date DESC, id DESC');
$stmt->execute([$prefix . '%']);
$docs = $stmt->fetchAll();
page_header('Çek Ek Belgeleri', 'cekler');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Çek dosya kartı</span>
    <h2><?php echo e(($check['bank_name'] ?: 'Banka yok') . ' / ' . ($check['check_no'] ?: ('Çek #' . $checkId))); ?></h2>
    <p><?php echo e($check['cari_name'] ?: 'Cari seçilmedi'); ?> · Vade: <?php echo e(tr_date($check['due_date'])); ?> · Tutar: <?php echo e(money($check['amount'])); ?></p>
  </div>
  <div class="hero-actions"><a class="btn btn-secondary" href="cekler.php">Çeklere dön</a><a class="btn btn-secondary" href="cekler.php?edit=<?php echo e($checkId); ?>">Çeki düzenle</a></div>
</section>

<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3>Ek belge yükle</h3><span>Ön/arka görsel, dekont, protesto...</span></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="add"><input type="hidden" name="check_id" value="<?php echo e($checkId); ?>">
      <label>Belge tipi<select name="document_type"><?php foreach(cek_ek_belge_tipleri() as $key=>$label): ?><option value="<?php echo e($key); ?>"><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      <label>Açıklama<textarea name="description" rows="2" placeholder="Örn: Arka ciro görseli, banka tahsil dekontu..."></textarea></label>
      <label>Dosya <small>JPG, PNG, WEBP, HEIC veya PDF; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf" required></label>
      <button class="btn btn-primary" type="submit">Belge ekle</button>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Belge ekleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Bu çeke ait ek belgeler</h3><span><?php echo e(count($docs)); ?> dosya</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih</th><th>Belge tipi</th><th>Açıklama</th><th>Dosya</th><th></th></tr></thead>
        <tbody>
          <?php if(!$docs): ?><tr><td colspan="5" class="empty">Bu çek için ek belge yok.</td></tr><?php endif; ?>
          <?php foreach($docs as $d): ?>
          <tr>
            <td><?php echo e(tr_date($d['document_date'])); ?></td>
            <td><?php echo badge(cek_ek_belge_label($d['document_type']), 'neutral'); ?></td>
            <td><?php echo e(str_replace($prefix, '', $d['description'] ?: '-')); ?></td>
            <td><a href="serbest-belge-indir.php?id=<?php echo e($d['id']); ?>" target="_blank"><?php echo e($d['document_name'] ?: 'Belgeyi aç'); ?></a><small><?php echo e($d['document_mime'] ?: ''); ?></small></td>
            <td class="row-actions"><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Bu ek belge silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="check_id" value="<?php echo e($checkId); ?>"><input type="hidden" name="doc_id" value="<?php echo e($d['id']); ?>"><button>Sil</button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
