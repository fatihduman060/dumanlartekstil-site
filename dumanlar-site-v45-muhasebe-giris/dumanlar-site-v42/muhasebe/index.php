<?php
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$notice = '';

if (isset($_GET['logged_out'])) {
    $notice = 'Oturum güvenli şekilde kapatıldı.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (login_is_locked()) {
        $error = 'Çok fazla hatalı deneme yapıldı. Lütfen ' . login_lock_remaining() . ' saniye sonra tekrar deneyin.';
    } elseif (!verify_csrf($token)) {
        $error = 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($username === '' || $password === '') {
        $error = 'Kullanıcı adı ve şifre alanlarını doldurun.';
    } elseif (!isset($APP_USERS[$username]) || !password_verify($password, $APP_USERS[$username]['password_hash'])) {
        register_failed_login();
        $error = 'Kullanıcı adı veya şifre hatalı.';
    } else {
        session_regenerate_id(true);
        clear_login_failures();
        $_SESSION['muhasebe_logged_in'] = true;
        $_SESSION['muhasebe_username'] = $username;
        $_SESSION['muhasebe_display_name'] = $APP_USERS[$username]['display_name'] ?? $username;
        $_SESSION['muhasebe_login_time'] = date('Y-m-d H:i:s');
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo htmlspecialchars($APP_NAME, ENT_QUOTES, 'UTF-8'); ?> | Giriş</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/muhasebe.css" />
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-card" aria-labelledby="login-title">
      <div class="brand-box">
        <img src="../assets/img/header-logo-only.png" alt="Bitke" />
      </div>

      <div class="login-heading">
        <span>Muhasebe Paneli</span>
        <h1 id="login-title">Güvenli giriş</h1>
        <p>Bu alan yetkili kullanıcılar içindir.</p>
      </div>

      <?php if ($notice): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="post" action="index.php" class="login-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />

        <label for="username">Kullanıcı adı</label>
        <input id="username" name="username" type="text" inputmode="text" autocomplete="username" required autofocus />

        <label for="password">Şifre</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />

        <button type="submit">Panele gir</button>
      </form>

      <p class="login-footnote">© <?php echo date('Y'); ?> Bitke. Yetkisiz erişim yasaktır.</p>
    </section>
  </main>
</body>
</html>
