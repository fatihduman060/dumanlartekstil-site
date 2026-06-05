<?php
require_once __DIR__ . '/auth.php';
require_login();
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title>Panel | Bitke Muhasebe</title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/muhasebe.css" />
</head>
<body class="panel-page">
  <header class="panel-topbar">
    <a class="panel-brand" href="dashboard.php" aria-label="Bitke Muhasebe Paneli">
      <img src="../assets/img/header-logo-only.png" alt="Bitke" />
      <span>Muhasebe Paneli</span>
    </a>
    <nav class="panel-actions" aria-label="Panel menüsü">
      <span><?php echo htmlspecialchars(current_user_name(), ENT_QUOTES, 'UTF-8'); ?></span>
      <a href="logout.php">Çıkış yap</a>
    </nav>
  </header>

  <main class="panel-layout">
    <section class="welcome-card">
      <span class="status-pill">Giriş başarılı</span>
      <h1>Panel hazır, muhasebe modülleri sonraki adımda eklenecek.</h1>
      <p>Bu aşamada yalnızca güvenli giriş sistemi kuruldu. Bir sonraki aşamada cariler, alınanlar, verilenler, gelir-gider ve rapor ekranlarını buraya bağlayabiliriz.</p>
    </section>

    <section class="placeholder-grid" aria-label="Gelecek modüller">
      <article>
        <strong>Cariler</strong>
        <span>Firma / kişi kartları</span>
      </article>
      <article>
        <strong>Alınanlar</strong>
        <span>Tahsilat kayıtları</span>
      </article>
      <article>
        <strong>Verilenler</strong>
        <span>Ödeme kayıtları</span>
      </article>
      <article>
        <strong>Raporlar</strong>
        <span>Aylık özetler</span>
      </article>
    </section>
  </main>
</body>
</html>
