<?php
require_once __DIR__ . '/layout.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write(); require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $type = $_POST['type'] ?? 'genel';
        if ($name !== '') {
            try { db()->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)')->execute([$name,$type,now()]); $newCategoryId=(int)db()->lastInsertId(); log_action('Kategori eklendi',$name); audit_action('kategori', $newCategoryId, 'eklendi', null, ['name'=>$name,'type'=>$type], $name); flash('success','Kategori eklendi.'); }
            catch(Throwable $e){ flash('error','Bu kategori zaten var olabilir.'); }
        }
    }
    if ($action === 'delete') {
        $id=(int)($_POST['id']??0); $stmt=db()->prepare('SELECT * FROM categories WHERE id=?'); $stmt->execute([$id]); $oldCategory=$stmt->fetch(); $name=$oldCategory['name'] ?? ''; db()->prepare('DELETE FROM categories WHERE id=?')->execute([$id]); log_action('Kategori silindi',(string)$name); if($oldCategory) audit_action('kategori', $id, 'silindi', $oldCategory, null, (string)$name); flash('success','Kategori silindi.');
    }
    redirect('kategoriler.php');
}
$categories=categories();
page_header('Kategoriler', 'kategoriler');
?>
<section class="content-grid compact">
  <article class="panel-card form-card">
    <div class="card-head"><h3>Yeni kategori</h3></div>
    <?php if(can_write()): ?><form class="stack-form" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="add"><label>Kategori adı<input name="name" required></label><label>Tür<select name="type"><option value="gelir">Gelir</option><option value="gider">Gider</option><option value="genel" selected>Genel</option></select></label><button class="btn btn-primary">Ekle</button></form><?php else: ?><p class="muted">Görüntüleme yetkisi.</p><?php endif; ?>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Kategori listesi</h3><span><?php echo count($categories); ?> kayıt</span></div>
    <div class="table-wrap"><table><thead><tr><th>Kategori</th><th>Tür</th><th></th></tr></thead><tbody><?php foreach($categories as $cat): ?><tr><td><strong><?php echo e($cat['name']); ?></strong></td><td><?php echo badge(ucfirst($cat['type']), $cat['type']==='gider'?'danger':($cat['type']==='gelir'?'success':'neutral')); ?></td><td class="row-actions"><?php if(can_write()): ?><form method="post" onsubmit="return confirm('Kategori silinsin mi? Mevcut hareketlerde kategori boş kalır.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e($cat['id']); ?>"><button>Sil</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
  </article>
</section>
<?php page_footer(); ?>
