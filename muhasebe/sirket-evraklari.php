<?php
require_once __DIR__ . '/layout.php';
require_login();

function sirket_evrak_tipleri(): array
{
    return [
        'vergi_levhasi' => 'Vergi Levhası',
        'imza_sirkusu' => 'İmza Sirküsü',
        'ticaret_sicil' => 'Ticaret Sicil Gazetesi',
        'faaliyet_belgesi' => 'Faaliyet Belgesi',
        'oda_kayit' => 'Oda Kayıt Belgesi',
        'sgk_belgesi' => 'SGK / Borcu Yoktur',
        'vergi_borcu_yoktur' => 'Vergi Borcu Yoktur',
        'sozlesme' => 'Sözleşme',
        'teminat' => 'Teminat / Banka Yazısı',
        'yetki_belgesi' => 'Yetki Belgesi',
        'ruhsat' => 'Ruhsat / İzin Belgesi',
        'kalite_belgesi' => 'Kalite Belgesi',
        'diger' => 'Diğer Şirket Evrakı',
    ];
}
function sirket_evrak_tip_label(?string $key): string
{
    $types = sirket_evrak_tipleri();
    return $types[$key ?: ''] ?? ($key ?: '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $docType = $_POST['document_type'] ?? 'diger';
        if (!isset(sirket_evrak_tipleri()[$docType])) $docType = 'diger';
        $docDate = $_POST['document_date'] ?: date('Y-m-d');
        $cariId = ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null;
        $desc = trim($_POST['description'] ?? '');
        $oldDoc = null;
        $oldRow = null;
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM standalone_documents WHERE id=?');
            $stmt->execute([$id]);
            $oldRow = $stmt->fetch() ?: null;
            $oldDoc = $oldRow ? ['path'=>$oldRow['document_path'] ?? null, 'name'=>$oldRow['document_name'] ?? null, 'mime'=>$oldRow['document_mime'] ?? null] : null;
        }
        try {
            $doc = handle_upload('document', $oldDoc);
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('sirket-evraklari.php');
        }
        if (!$doc['path'] && !$oldDoc) {
            flash('error', 'Evrak dosyası seçmelisin.');
            redirect('sirket-evraklari.php');
        }
        if ($id > 0 && $oldRow) {
            db()->prepare('UPDATE standalone_documents SET cari_id=?, document_date=?, document_type=?, document_path=?, document_name=?, document_mime=?, description=?, updated_at=? WHERE id=?')
                ->execute([$cariId, $docDate, $docType, $doc['path'], $doc['name'], $doc['mime'], $desc, now(), $id]);
            delete_replaced_upload($oldDoc, $doc);
            log_action('Şirket evrakı güncellendi', sirket_evrak_tip_label($docType));
            audit_action('belge', $id, 'guncellendi', $oldRow, ['document_type'=>$docType,'date'=>$docDate,'description'=>$desc], sirket_evrak_tip_label($docType));
            flash('success', 'Şirket evrakı güncellendi.');
        } else {
            db()->prepare('INSERT INTO standalone_documents (cari_id, document_date, document_type, document_path, document_name, document_mime, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cariId, $docDate, $docType, $doc['path'], $doc['name'], $doc['mime'], $desc, current_user()['id'] ?? null, now(), now()]);
            $newId = (int)db()->lastInsertId();
            log_action('Şirket evrakı eklendi', sirket_evrak_tip_label($docType));
            audit_action('belge', $newId, 'eklendi', null, ['document_type'=>$docType,'date'=>$docDate,'description'=>$desc], sirket_evrak_tip_label($docType));
            flash('success', 'Şirket evrakı eklendi.');
        }
        redirect('sirket-evraklari.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM standalone_documents WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            if (!empty($row['document_path'])) {
                $path = UPLOAD_DIR . '/' . $row['document_path'];
                if (is_file($path)) @unlink($path);
            }
            db()->prepare('DELETE FROM standalone_documents WHERE id=?')->execute([$id]);
            log_action('Şirket evrakı silindi', '#' . $id);
            audit_action('belge', $id, 'silindi', $row, null, sirket_evrak_tip_label($row['document_type'] ?? ''));
            flash('success', 'Şirket evrakı silindi.');
        }
        redirect('sirket-evraklari.php');
    }
}

$cariler = cariler_for_select();
$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM standalone_documents WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$q = trim($_GET['q'] ?? '');
$type = trim($_GET['document_type'] ?? '');
$where = ['sd.document_path IS NOT NULL', "sd.document_path != ''"];
$params = [];
if ($q !== '') { $where[] = '(sd.description LIKE ? OR sd.document_name LIKE ? OR c.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
if ($type !== '') { $where[] = 'sd.document_type=?'; $params[] = $type; }
$sql = 'SELECT sd.*, c.name AS cari_name FROM standalone_documents sd LEFT JOIN cariler c ON c.id=sd.cari_id WHERE ' . implode(' AND ', $where) . ' ORDER BY sd.document_date DESC, sd.id DESC LIMIT 500';
$stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
page_header('Şirket Evrakları', 'sirket_evraklari');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Şirket evrak arşivi</span>
    <h2>Vergi levhası, imza sirküsü, faaliyet belgesi ve sözleşmeleri tek yerde tut.</h2>
    <p>Bu alan cari hareketlerini, çekleri ve dashboard toplamlarını etkilemez; sadece evrak arşivi olarak çalışır.</p>
  </div>
  <div class="hero-actions"><a class="btn btn-secondary" href="belgeler.php?kind=standalone">Tüm serbest belgeler</a></div>
</section>

<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Evrak düzenle' : 'Yeni şirket evrakı'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" enctype="multipart/form-data" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <label>Evrak tipi<select name="document_type"><?php foreach(sirket_evrak_tipleri() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['document_type'] ?? '')===$key)?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></label>
      <label>Tarih<input type="date" name="document_date" value="<?php echo e($edit['document_date'] ?? date('Y-m-d')); ?>" required></label>
      <label>İlgili cari <select name="cari_id"><option value="">Cari yok / genel şirket evrakı</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo ((string)($edit['cari_id'] ?? '')===(string)$c['id'])?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label>
      <label>Açıklama<textarea name="description" rows="3" placeholder="Örn: 2026 güncel vergi levhası, tedarikçi sözleşmesi..."><?php echo e($edit['description'] ?? ''); ?></textarea></label>
      <label>Dosya <small>PDF, görsel veya belge; max 10 MB</small><input name="document" type="file" accept="image/*,application/pdf"></label>
      <?php if (!empty($edit['document_path'])): ?><p class="muted">Mevcut dosya: <a href="serbest-belge-indir.php?id=<?php echo e($edit['id']); ?>" target="_blank"><?php echo e($edit['document_name'] ?: 'Belge'); ?></a></p><?php endif; ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Evrak ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="sirket-evraklari.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Evrak ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Evrak listesi</h3><span><?php echo e(count($rows)); ?> evrak</span></div>
    <form class="filterbar multi" method="get">
      <input name="q" placeholder="Açıklama, dosya adı veya cari ara" value="<?php echo e($q); ?>">
      <select name="document_type"><option value="">Tüm evrak tipleri</option><?php foreach(sirket_evrak_tipleri() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $type===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
      <button class="btn btn-secondary" type="submit">Filtrele</button>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tarih</th><th>Evrak tipi</th><th>Cari</th><th>Açıklama</th><th>Dosya</th><th></th></tr></thead>
        <tbody>
          <?php if(!$rows): ?><tr><td colspan="6" class="empty">Şirket evrakı bulunamadı.</td></tr><?php endif; ?>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo e(tr_date($r['document_date'])); ?></td>
            <td><?php echo badge(sirket_evrak_tip_label($r['document_type']), 'neutral'); ?></td>
            <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id='.e($r['cari_id']).'">'.e($r['cari_name']).'</a>' : 'Genel'; ?></td>
            <td><?php echo e($r['description'] ?: '-'); ?></td>
            <td><a href="serbest-belge-indir.php?id=<?php echo e($r['id']); ?>" target="_blank"><?php echo e($r['document_name'] ?: 'Evrakı aç'); ?></a><small><?php echo e($r['document_mime'] ?: ''); ?></small></td>
            <td class="row-actions"><?php if(can_write()): ?><a href="sirket-evraklari.php?edit=<?php echo e($r['id']); ?>">Düzenle</a><form method="post" onsubmit="return confirm('Bu şirket evrakı silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($r['id']); ?>"><button>Sil</button></form><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>
<?php page_footer(); ?>
