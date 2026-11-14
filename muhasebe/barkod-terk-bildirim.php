<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();
if (!pos_can_delete_sales()) {
    http_response_code(403);
    exit('Bu ayarı yalnızca Fatih kullanıcısı değiştirebilir.');
}
pos_live_ensure();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
        redirect('barkod-terk-bildirim.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Geçerli bir e-posta adresi yazın.');
        } else {
            setting_set('pos_abandoned_alert_email', $email);
            if (setting_get('pos_abandoned_alert_email', '') !== $email) {
                flash('error', 'Bildirim adresi kaydedilemedi. Tekrar deneyin.');
            } else {
                log_action('Terk edilen sepet mail adresi güncellendi', $email === '' ? 'Bildirim kapatıldı' : $email);
                flash('success', $email === '' ? 'Mail bildirimi kapatıldı.' : 'Bildirim adresi kaydedildi. Yeni terk edilen sepetlere mail gönderilecek.');
            }
        }
    } elseif ($action === 'test') {
        $email = pos_abandoned_alert_recipient();
        if ($email === '') {
            flash('error', 'Önce bildirim adresini kaydedin.');
        } else {
            try {
                if (pos_abandoned_send_mail($email, 'Bitke terk edilen sepet bildirimi testi',
                    "Bu bir test mesajıdır. Terk edilen sepet bildirimi bu adrese gönderilecektir.\n")) {
                    flash('success', 'Test mesajı posta sunucusuna iletildi. Gelen kutusunu ve spam klasörünü kontrol edin.');
                } else {
                    flash('error', 'Posta sunucusu test mesajını kabul etmedi. Hosting mail ayarlarını kontrol edin.');
                }
            } catch (Throwable $e) {
                error_log('Bitke terk edilen sepet test mail hatası: ' . $e->getMessage());
                flash('error', 'Test maili gönderilemedi: ' . $e->getMessage());
            }
        }
    }
    redirect('barkod-terk-bildirim.php');
}

$recipient = pos_abandoned_alert_recipient();
page_header('Terk Edilen Sepet Maili', 'barkod_satis');
?>
<section class="panel-card" style="max-width:720px;padding:24px;display:grid;gap:16px">
  <p>Terk edilen sepetler mevcut listede kalır. İlk terk edilme kaydı oluştuğunda aşağıdaki adrese bir kez mail gönderilir.</p>
  <form method="post" style="display:grid;gap:12px">
    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="save">
    <label style="display:grid;gap:7px">Bildirim e-posta adresi
      <input type="email" name="email" value="<?php echo e($recipient); ?>" placeholder="ornek@eposta.com" autocomplete="email" style="padding:12px;border:1px solid #ccd4d0;border-radius:10px">
    </label>
    <small>Boş kaydederseniz mail bildirimi durur; terk edilen sepet kayıtları korunur.</small>
    <button type="submit" class="btn btn-primary">Adresi Kaydet</button>
  </form>
  <?php if ($recipient !== ''): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="test">
    <button type="submit" class="btn btn-secondary">Test Maili Gönder</button>
  </form>
  <?php endif; ?>
  <p><a href="barkod-satis.php">← Barkodlu Satış'a dön</a></p>
</section>
<?php page_footer(); ?>
