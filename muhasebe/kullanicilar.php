<?php
require_once __DIR__ . '/layout.php';
require_user_manager();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    $action=$_POST['action']??'';
    if($action==='add'){
        $username=trim($_POST['username']??''); $display=trim($_POST['display_name']??''); $password=$_POST['password']??''; $role=$_POST['role']??'viewer';
        if($username===''||$display===''||strlen($password)<8){ flash('error','Kullanıcı adı, isim ve en az 8 karakter şifre gerekli.'); redirect('kullanicilar.php'); }
        if(!in_array($role, ['admin','editor','viewer'], true)) $role = 'viewer';
        try{
            db()->prepare('INSERT INTO users (username,display_name,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)')->execute([$username,$display,password_hash($password,PASSWORD_DEFAULT),$role,now(),now()]);
            $newUserId = (int)db()->lastInsertId();
            log_action('Kullanıcı eklendi',$username);
            audit_action('kullanici', $newUserId, 'eklendi', null, ['username'=>$username,'display_name'=>$display,'role'=>$role,'is_active'=>1], $username);
            flash('success','Kullanıcı eklendi.');
        }catch(Throwable $e){ flash('error','Kullanıcı eklenemedi. Aynı kullanıcı adı olabilir.'); }
    }
    if($action==='update'){
        $id=(int)($_POST['id']??0); $role=$_POST['role']??'viewer'; $active=isset($_POST['is_active'])?1:0; $display=trim($_POST['display_name']??'');
        if(!in_array($role, ['admin','editor','viewer'], true)) $role = 'viewer';
        if($id===(int)current_user()['id'] && !$active){ flash('error','Kendi kullanıcınızı pasifleştiremezsiniz.'); redirect('kullanicilar.php'); }
        $oldStmt = db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id=?');
        $oldStmt->execute([$id]);
        $oldUser = $oldStmt->fetch();
        if (!$oldUser) { flash('error','Kullanıcı bulunamadı.'); redirect('kullanicilar.php'); }
        if (($oldUser['role'] ?? '') === 'admin' && $role !== 'admin') {
            $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() <= 0) {
                flash('error','Son aktif yöneticinin yetkisi düşürülemez.');
                redirect('kullanicilar.php');
            }
        }
        db()->prepare('UPDATE users SET display_name=?, role=?, is_active=?, updated_at=? WHERE id=?')->execute([$display,$role,$active,now(),$id]);
        $passwordChanged = false;
        if(!empty($_POST['password'])){ if(strlen($_POST['password'])<8){ flash('error','Şifre en az 8 karakter olmalı.'); redirect('kullanicilar.php'); } db()->prepare('UPDATE users SET password_hash=?, updated_at=? WHERE id=?')->execute([password_hash($_POST['password'],PASSWORD_DEFAULT),now(),$id]); $passwordChanged = true; }
        log_action('Kullanıcı güncellendi','#'.$id);
        if ($oldUser) {
            audit_action('kullanici', $id, 'guncellendi', $oldUser, ['display_name'=>$display,'role'=>$role,'is_active'=>$active,'password_changed'=>$passwordChanged ? 'evet' : 'hayır'], $oldUser['username'] ?? ('#'.$id));
        }
        flash('success','Kullanıcı güncellendi.');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        if ($id <= 0) { flash('error','Silinecek kullanıcı bulunamadı.'); redirect('kullanicilar.php'); }
        if($id===(int)(current_user()['id'] ?? 0)){ flash('error','Kendi kullanıcınızı silemezsiniz.'); redirect('kullanicilar.php'); }
        $stmt = db()->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id=?');
        $stmt->execute([$id]);
        $oldUser = $stmt->fetch();
        if (!$oldUser) { flash('error','Kullanıcı bulunamadı.'); redirect('kullanicilar.php'); }
        if (($oldUser['role'] ?? '') === 'admin' && (int)($oldUser['is_active'] ?? 0) === 1) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id<>?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() <= 0) {
                flash('error','Son aktif yönetici silinemez.');
                redirect('kullanicilar.php');
            }
        }
        try {
            db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
            log_action('Kullanıcı silindi', (string)($oldUser['username'] ?? ('#'.$id)));
            audit_action('kullanici', $id, 'silindi', $oldUser, null, $oldUser['username'] ?? ('#'.$id));
            flash('success','Kullanıcı silindi. Eski işlem kayıtları korunur.');
        } catch (Throwable $e) {
            flash('error','Kullanıcı silinemedi. Önce pasif hale getirmeyi deneyin.');
        }
    }
    redirect('kullanicilar.php');
}
$users=db()->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
page_header('Kullanıcılar', 'kullanicilar');
?>

<section class="content-grid compact">
  <article class="panel-card form-card">
    <div class="card-head"><h3>Yeni kullanıcı</h3></div>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="add">
      <label>Kullanıcı adı<input name="username" required autocomplete="off"></label>
      <label>Ad soyad<input name="display_name" required></label>
      <label>Şifre <small>(en az 8 karakter)</small><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Yetki seviyesi
        <select name="role">
          <option value="viewer">👁 Görüntüleyici</option>
          <option value="editor">✏️ Düzenleyici</option>
          <option value="admin">⚙️ Yönetici</option>
        </select>
      </label>
      <button class="btn btn-primary">Kullanıcı ekle</button>
    </form>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Kullanıcı listesi</h3><span><?php echo count($users); ?> kullanıcı</span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Kullanıcı</th><th>Yetki</th><th>Son giriş</th><th>Durum</th><th>Güncelle</th><th>Sil</th></tr></thead>
        <tbody>
          <?php foreach($users as $u): ?>
          <tr>
            <form method="post">
              <?php echo csrf_field(); ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
              <td>
                <strong><?php echo e($u['username']); ?></strong>
                <input name="display_name" value="<?php echo e($u['display_name']); ?>">
                <small>Yeni şifre yazılırsa değişir</small>
                <input type="password" name="password" placeholder="Yeni şifre" autocomplete="new-password">
              </td>
              <td>
                <select name="role">
                  <option value="viewer" <?php echo $u['role']==='viewer'?'selected':''; ?>>👁 Görüntüleyici</option>
                  <option value="editor" <?php echo $u['role']==='editor'?'selected':''; ?>>✏️ Düzenleyici</option>
                  <option value="admin" <?php echo $u['role']==='admin'?'selected':''; ?>>⚙️ Yönetici</option>
                </select>
              </td>
              <td><?php echo e(tr_datetime($u['last_login'])); ?></td>
              <td><label class="check"><input type="checkbox" name="is_active" <?php echo $u['is_active']?'checked':''; ?>> Aktif</label></td>
              <td><button class="btn btn-secondary">Kaydet</button></td>
            </form>
            <td>
              <?php if ((int)$u['id'] !== (int)(current_user()['id'] ?? 0)): ?>
              <form method="post" onsubmit="return confirm('<?php echo e($u['username']); ?> kullanıcısı silinsin mi? Bu işlem geri alınamaz. Eski işlem kayıtları korunur.');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                <button class="btn btn-danger" type="submit">Sil</button>
              </form>
              <?php else: ?>
                <span class="muted">Kendin</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<!-- Yetki Matrisi -->
<section class="panel-card" style="margin-top:18px">
  <div class="card-head"><h3>Yetki matrisi</h3><span>Hangi rol ne yapabilir?</span></div>
  <div class="perm-matrix">
    <table>
      <thead>
        <tr>
          <th>Özellik</th>
          <th>👁 Görüntüleyici</th>
          <th>✏️ Düzenleyici</th>
          <th>⚙️ Yönetici</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $perms = [
            ['Dashboard görüntüleme', true, true, true],
            ['Cari listesi görme', true, true, true],
            ['Hareketleri görme', true, true, true],
            ['Çekleri görme', true, true, true],
            ['Raporlar & ekstreler', true, true, true],
            ['Cari ekleme / düzenleme', false, true, true],
            ['Hareket ekleme / düzenleme', false, true, true],
            ['Çek ekleme / düzenleme', false, true, true],
            ['Çek hızlı durum güncelleme', false, true, true],
            ['Kategori yönetimi', false, true, true],
            ['Belge yükleme', false, true, true],
            ['CSV / Excel çıktısı', true, true, true],
            ['Kullanıcı yönetimi', false, false, true],
            ['Yedekleme / geri yükleme', false, false, true],
            ['Sistem logları', false, false, true],
            ['Güvenlik ayarları', false, false, true],
        ];
        foreach ($perms as $row):
            [$label, $viewer, $editor, $admin] = $row;
            $icon = fn($v) => $v ? '<span class="perm-yes">✓</span>' : '<span class="perm-no">—</span>';
        ?>
        <tr>
          <td><?php echo e($label); ?></td>
          <td class="center"><?php echo $icon($viewer); ?></td>
          <td class="center"><?php echo $icon($editor); ?></td>
          <td class="center"><?php echo $icon($admin); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="margin:12px 0 0;font-size:13px">💡 Yetki seviyeleri kümülatiftir: Yönetici, Düzenleyici'nin; Düzenleyici ise Görüntüleyici'nin tüm yetkilerini içerir.</p>
</section>
<?php page_footer(); ?>
