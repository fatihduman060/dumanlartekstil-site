<?php
require_once __DIR__ . '/layout.php';
require_admin();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    $action=$_POST['action']??'';
    if($action==='add'){
        $username=trim($_POST['username']??''); $display=trim($_POST['display_name']??''); $password=$_POST['password']??''; $role=$_POST['role']??'viewer';
        if($username===''||$display===''||strlen($password)<8){ flash('error','Kullanıcı adı, isim ve en az 8 karakter şifre gerekli.'); redirect('kullanicilar.php'); }
        try{ db()->prepare('INSERT INTO users (username,display_name,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)')->execute([$username,$display,password_hash($password,PASSWORD_DEFAULT),$role,now(),now()]); log_action('Kullanıcı eklendi',$username); flash('success','Kullanıcı eklendi.'); }catch(Throwable $e){ flash('error','Kullanıcı eklenemedi. Aynı kullanıcı adı olabilir.'); }
    }
    if($action==='update'){
        $id=(int)($_POST['id']??0); $role=$_POST['role']??'viewer'; $active=isset($_POST['is_active'])?1:0; $display=trim($_POST['display_name']??'');
        if($id===(int)current_user()['id'] && !$active){ flash('error','Kendi kullanıcınızı pasifleştiremezsiniz.'); redirect('kullanicilar.php'); }
        db()->prepare('UPDATE users SET display_name=?, role=?, is_active=?, updated_at=? WHERE id=?')->execute([$display,$role,$active,now(),$id]);
        if(!empty($_POST['password'])){ if(strlen($_POST['password'])<8){ flash('error','Şifre en az 8 karakter olmalı.'); redirect('kullanicilar.php'); } db()->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?')->execute([password_hash($_POST['password'],PASSWORD_DEFAULT),now(),$id]); }
        log_action('Kullanıcı güncellendi','#'.$id); flash('success','Kullanıcı güncellendi.');
    }
    redirect('kullanicilar.php');
}
$users=db()->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
page_header('Kullanıcılar', 'kullanicilar');
?>
<section class="content-grid compact">
  <article class="panel-card form-card"><div class="card-head"><h3>Yeni kullanıcı</h3></div><form method="post" class="stack-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="add"><label>Kullanıcı adı<input name="username" required></label><label>Ad soyad<input name="display_name" required></label><label>Şifre<input type="password" name="password" required minlength="8"></label><label>Yetki<select name="role"><option value="viewer">Görüntüleyici</option><option value="editor">Düzenleyici</option><option value="admin">Yönetici</option></select></label><button class="btn btn-primary">Kullanıcı ekle</button></form></article>
  <article class="panel-card"><div class="card-head"><h3>Kullanıcı listesi</h3></div><div class="table-wrap"><table><thead><tr><th>Kullanıcı</th><th>Yetki</th><th>Son giriş</th><th>Durum</th><th>Güncelle</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo e($u['id']); ?>"><td><strong><?php echo e($u['username']); ?></strong><input name="display_name" value="<?php echo e($u['display_name']); ?>"><small>Yeni şifre yazılırsa değişir</small><input type="password" name="password" placeholder="Yeni şifre"></td><td><select name="role"><option value="viewer" <?php echo $u['role']==='viewer'?'selected':''; ?>>Görüntüleyici</option><option value="editor" <?php echo $u['role']==='editor'?'selected':''; ?>>Düzenleyici</option><option value="admin" <?php echo $u['role']==='admin'?'selected':''; ?>>Yönetici</option></select></td><td><?php echo e(tr_datetime($u['last_login'])); ?></td><td><label class="check"><input type="checkbox" name="is_active" <?php echo $u['is_active']?'checked':''; ?>> Aktif</label></td><td><button class="btn btn-secondary">Kaydet</button></td></form></tr><?php endforeach; ?></tbody></table></div></article>
</section>
<?php page_footer(); ?>
