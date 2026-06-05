<?php
require_once __DIR__ . '/layout.php';
require_login();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'profile') {
        $display = trim($_POST['display_name'] ?? '');
        if ($display === '') {
            flash('error', 'Görünen ad boş olamaz.');
            redirect('hesabim.php');
        }
        db()->prepare('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?')->execute([$display, now(), $u['id']]);
        log_action('Profil güncellendi', $display);
        flash('success', 'Profil bilgisi güncellendi.');
        redirect('hesabim.php');
    }
    if ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $repeat = $_POST['repeat_password'] ?? '';
        if (!password_verify($current, $u['password_hash'])) {
            log_action('Şifre değiştirme başarısız', 'Mevcut şifre hatalı');
            flash('error', 'Mevcut şifre hatalı.');
            redirect('hesabim.php');
        }
        if (strlen($new) < 10) {
            flash('error', 'Yeni şifre en az 10 karakter olmalı.');
            redirect('hesabim.php');
        }
        if ($new !== $repeat) {
            flash('error', 'Yeni şifre tekrarı aynı değil.');
            redirect('hesabim.php');
        }
        db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), now(), $u['id']]);
        log_action('Şifre değiştirildi', 'Kullanıcı kendi şifresini değiştirdi');
        flash('success', 'Şifre değiştirildi. Yeni şifreyle devam edebilirsiniz.');
        redirect('hesabim.php');
    }
}

$lastLogs = db()->prepare('SELECT * FROM logs WHERE user_id = ? ORDER BY id DESC LIMIT 8');
$lastLogs->execute([$u['id']]);
$lastLogs = $lastLogs->fetchAll();
page_header('Hesabım', 'hesabim');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Güvenlik merkezi</span>
    <h2>Şifre, oturum ve hesap bilgileri burada.</h2>
    <p>Panel 30 dakika işlem yapılmazsa otomatik çıkış yapar. İlk kurulumdan sonra geçici şifreyi burada değiştirmen önerilir.</p>
  </div>
  <div class="hero-actions"><?php if (is_admin()): ?><a class="btn btn-secondary" href="loglar.php">Logları gör</a><?php endif; ?></div>
</section>

<section class="stats-grid four">
  <article class="stat-card"><span>Kullanıcı adı</span><strong><?php echo e($u['username']); ?></strong><small>Giriş hesabı</small></article>
  <article class="stat-card"><span>Yetki</span><strong><?php echo e(role_label($u['role'])); ?></strong><small>Panel rolü</small></article>
  <article class="stat-card"><span>Son giriş</span><strong><?php echo e(tr_datetime($u['last_login'])); ?></strong><small>Kayıtlı son başarılı giriş</small></article>
  <article class="stat-card"><span>Otomatik çıkış</span><strong><?php echo (int)(SESSION_TIMEOUT_SECONDS / 60); ?> dk</strong><small>İşlem yapılmazsa</small></article>
</section>

<section class="content-grid compact">
  <article class="panel-card form-card">
    <div class="card-head"><h3>Profil bilgisi</h3></div>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="profile">
      <label>Görünen ad<input name="display_name" required value="<?php echo e($u['display_name']); ?>"></label>
      <button class="btn btn-primary" type="submit">Profili güncelle</button>
    </form>
  </article>
  <article class="panel-card form-card">
    <div class="card-head"><h3>Şifre değiştir</h3><span>En az 10 karakter</span></div>
    <form method="post" class="stack-form" autocomplete="off">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="password">
      <label>Mevcut şifre<input type="password" name="current_password" required autocomplete="current-password"></label>
      <label>Yeni şifre<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
      <label>Yeni şifre tekrar<input type="password" name="repeat_password" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Şifreyi değiştir</button>
    </form>
  </article>
</section>

<section class="panel-card">
  <div class="card-head"><h3>Son hesap hareketleri</h3><span>Son 8 kayıt</span></div>
  <div class="table-wrap"><table><thead><tr><th>Tarih</th><th>İşlem</th><th>Detay</th><th>IP</th></tr></thead><tbody>
    <?php if(!$lastLogs): ?><tr><td colspan="4" class="empty">Hesap hareketi yok.</td></tr><?php endif; ?>
    <?php foreach($lastLogs as $l): ?><tr><td><?php echo e(tr_datetime($l['created_at'])); ?></td><td><strong><?php echo e($l['action']); ?></strong></td><td><?php echo e($l['detail']); ?></td><td><?php echo e($l['ip']); ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php page_footer(); ?>
