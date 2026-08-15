<?php
require_once __DIR__ . '/bootstrap.php';

if (is_logged_in() && current_user()) {
    redirect('dashboard.php');
}

$error = '';
$notice = isset($_GET['logged_out']) ? 'Oturum güvenli şekilde kapatıldı.' : (isset($_GET['timeout']) ? 'Güvenliğiniz için oturum otomatik kapatıldı.' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_is_locked($username)) {
        $error = 'Çok fazla hatalı deneme yapıldı. Lütfen ' . login_lock_remaining($username) . ' saniye sonra tekrar deneyin.';
    } elseif (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($username === '' || $password === '') {
        $error = 'Kullanıcı adı ve şifre alanlarını doldurun.';
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            register_failed_login($username);
            $error = 'Kullanıcı adı veya şifre hatalı.';
            log_action('Hatalı giriş', 'Kullanıcı adı: ' . $username);
        } else {
            session_regenerate_id(true);
            clear_login_failures($username);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['login_time'] = now();
            $_SESSION['last_activity'] = time();
            if (($_POST['remember_device'] ?? '') === '1') {
                remember_pwa_session();
            } else {
                forget_pwa_session();
            }
            db()->prepare('UPDATE users SET last_login = ?, updated_at = ? WHERE id = ?')->execute([now(), now(), $user['id']]);
            log_action('Giriş', 'Panel girişi yapıldı');
            redirect('dashboard.php');
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="theme-color" content="#17241c" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="Dumanlar" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo e(APP_NAME); ?> | Giriş</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml" />
  <link rel="apple-touch-icon" href="assets/app-icon-512.png" />
  <link rel="manifest" href="manifest.webmanifest" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/muhasebe.css?v=50" />
  <link rel="stylesheet" href="assets/mobil-panel.css?v=<?php echo (int)(@filemtime(__DIR__ . '/assets/mobil-panel.css') ?: 1); ?>" />
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-card" aria-labelledby="login-title">
      <div class="brand-box"><img src="../assets/img/header-logo-only.png" alt="Bitke" /></div>
      <div class="login-heading">
        <span>Özel muhasebe alanı · <?php echo e(APP_VERSION); ?></span>
        <h1 id="login-title">Güvenli giriş</h1>
        <p>Cari takip, tahsilat, ödeme ve rapor ekranları.</p>
      </div>
      <?php if ($notice): ?><div class="alert alert-success"><?php echo e($notice); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
      <form method="post" action="index.php" class="login-form" autocomplete="on">
        <?php echo csrf_field(); ?>
        <label for="username">Kullanıcı adı</label>
        <input id="username" name="username" type="text" autocomplete="username" required autofocus />
        <label for="password">Şifre</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
        <label class="remember-device" for="remember_device">
          <input id="remember_device" name="remember_device" type="checkbox" value="1" />
          <span><strong>Bu cihazda oturumu hatırla</strong><small>PWA’da güvenli oturum 30 gün etkin kalır. Çıkış düğmesi oturumu hemen kapatır.</small></span>
        </label>
        <button type="submit">Panele gir</button>
      </form>
      <p class="login-footnote">© <?php echo date('Y'); ?> Bitke. Yetkisiz erişim yasaktır.</p>
    </section>
  </main>
  <script>
  (function () {
    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    if (window.navigator.standalone === true) standalone = true;
    document.documentElement.classList.toggle('pwa-standalone', standalone);
    var remember = document.getElementById('remember_device');
    if (remember && standalone) remember.checked = true;
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('service-worker.js', { scope: './' });
  }());
  </script>
</body>
</html>
