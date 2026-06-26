<?php
require_once __DIR__ . '/bootstrap.php';

function super_admin_user_ids(): array
{
    $raw = setting_get('super_admin_user_ids', '[]') ?: '[]';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $ids = [];
    foreach ($decoded as $id) {
        $id = (int)$id;
        if ($id > 0) $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function is_super_admin(?int $userId = null): bool
{
    if ($userId === null) {
        $u = current_user();
        $userId = (int)($u['id'] ?? 0);
    }
    return $userId > 0 && in_array($userId, super_admin_user_ids(), true);
}

function set_user_super_admin(int $userId, bool $enabled): void
{
    if ($userId <= 0) return;
    $ids = super_admin_user_ids();
    if ($enabled) {
        $ids[] = $userId;
    } else {
        $ids = array_values(array_filter($ids, fn($id) => (int)$id !== $userId));
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    setting_set('super_admin_user_ids', json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        flash('error', 'Bu alan yalnızca süper yöneticiye açıktır.');
        redirect('dashboard.php');
    }
}

function page_header(string $title, string $active = ''): void
{
    $u = current_user();
    $nav = [
        ['dashboard', 'dashboard.php', 'Genel Bakış', '⌂'],
        ['cariler', 'cariler.php', 'Cariler', '◎'],
        ['ozel_alacaklar', 'ozel-alacaklar.php', 'Özel Alacak', '◆'],
        ['hareketler', 'hareketler.php', 'Hareketler', '↕'],
        ['hesaplar', 'hesaplar.php', 'Kasa/Banka', '▣'],
        ['hesap_dokumleri', 'hesap-dokumleri.php', 'Hesap Dökümleri', '▥'],
        ['cekler', 'cekler.php', 'Çekler', '◈'],
        ['belgeler', 'belgeler.php', 'Belgeler', '▤'],
        ['kategoriler', 'kategoriler.php', 'Kategoriler', '▦'],
        ['raporlar', 'raporlar.php', 'Raporlar', '◷'],
        ['hesabim', 'hesabim.php', 'Hesabım', '⚿'],
    ];
    if (is_super_admin()) {
        $nav[] = ['super_yonetim', 'super-yonetim.php', 'Süper Yönetim', '★'];
    }
    if (is_admin()) {
        $nav[] = ['yedekler', 'yedekler.php', 'Yedekleme', '⇩'];
        $nav[] = ['kullanicilar', 'kullanicilar.php', 'Kullanıcılar', '♙'];
        $nav[] = ['loglar', 'loglar.php', 'Loglar', '☰'];
    }
    ?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <title><?php echo e($title); ?> | <?php echo e(APP_NAME); ?></title>
  <link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/muhasebe.css?v=515" />
  <link rel="stylesheet" href="assets/cek-renkleri.css?v=1" />
</head>
<body class="app-page">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="dashboard.php" aria-label="Bitke Muhasebe">
        <img src="../assets/img/header-logo-only.png" alt="Bitke" />
        <span>Muhasebe <small><?php echo e(APP_VERSION); ?></small></span>
      </a>
      <nav class="side-nav" aria-label="Panel menüsü">
        <?php foreach ($nav as $item): ?>
          <a class="<?php echo $active === $item[0] ? 'active' : ''; ?>" href="<?php echo e($item[1]); ?>">
            <span class="nav-ico"><?php echo e($item[3]); ?></span>
            <span><?php echo e($item[2]); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="side-footer">
        <span><?php echo is_super_admin() ? 'Süper Yönetici' : e(role_label($u['role'] ?? 'viewer')); ?></span>
        <strong><?php echo e($u['display_name'] ?? 'Kullanıcı'); ?></strong>
      </div>
    </aside>
    <main class="main">
      <header class="topbar">
        <button class="menu-toggle" type="button" data-menu-toggle>☰</button>
        <div>
          <p>Bitke özel alan</p>
          <h1><?php echo e($title); ?></h1>
        </div>
        <div class="top-actions">
          <a class="ghost-link" href="../" target="_blank" rel="noopener">Siteyi aç</a>
          <span class="session-chip" title="İşlem yapılmazsa otomatik çıkış süresi">30 dk</span><a class="logout-link" href="logout.php">Çıkış</a>
        </div>
      </header>
      <?php foreach (get_flashes() as $flash): ?>
        <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
      <?php endforeach; ?>
<?php }

function page_footer(): void
{
    ?>
    </main>
  </div>
  <script src="assets/muhasebe.js?v=513"></script>
</body>
</html>
<?php }

function badge(string $label, string $tone = 'neutral'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($label) . '</span>';
}
