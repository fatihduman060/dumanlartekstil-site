<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/fatura-no-onar.php';

// Önceden kurulmuş veritabanlarında eksik olan Ödeme kategorisini bir kez ekle.
if (setting_get('migration_odeme_category_v1', '0') !== '1') {
    db()->prepare('INSERT OR IGNORE INTO categories (name, type, created_at) VALUES (?, ?, ?)')
        ->execute(['Ödeme', 'gider', now()]);
    setting_set('migration_odeme_category_v1', '1');
}

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

function can_manage_users(): bool
{
    // İlk kurulumda sistem kilitlenmesin diye hiç süper yönetici yoksa mevcut yöneticiler kullanıcı atayabilir.
    // En az bir süper yönetici tanımlandıktan sonra kullanıcı yönetimi yalnızca süper yöneticiye geçer.
    $ids = super_admin_user_ids();
    return empty($ids) ? is_admin() : is_super_admin();
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

function require_user_manager(): void
{
    require_login();
    if (!can_manage_users()) {
        flash('error', 'Kullanıcı tanımları yalnızca süper yönetici tarafından değiştirilebilir.');
        redirect('dashboard.php');
    }
}

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'kullanicilar.php') {
    if (is_logged_in() && current_user() && !can_manage_users()) {
        flash('error', 'Kullanıcı tanımları yalnızca süper yönetici tarafından yönetilebilir.');
        redirect('dashboard.php');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && ($_POST['action'] ?? '') === 'update'
        && verify_csrf($_POST['csrf_token'] ?? null)
        && is_logged_in()
        && current_user()
        && can_manage_users()) {
        $targetUserId = (int)($_POST['id'] ?? 0);
        if ($targetUserId > 0) {
            $postedRole = (string)($_POST['role'] ?? '');
            $makeSuper = $postedRole === 'super_admin' || (isset($_POST['is_super_admin']) && (string)$_POST['is_super_admin'] === '1');
            set_user_super_admin($targetUserId, $makeSuper);
            if ($postedRole === 'super_admin') {
                $_POST['role'] = 'admin';
            }
        }
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
    ];
    if (is_admin()) {
        $nav[] = ['hesaplar', 'hesaplar.php', 'Kasa/Banka', '▣'];
        $nav[] = ['faturalar', 'faturalar.php', 'Faturalar', '▤'];
        $nav[] = ['hesap_dokumleri', 'hesap-dokumleri.php', 'Hesap Dökümleri', '▥'];
        $nav[] = ['maaslar', 'maaslar.php', 'Maaşlar', '₺'];
    }
    $nav = array_merge($nav, [
        ['cekler', 'cekler.php', 'Çekler', '◈'],
        ['belgeler', 'belgeler.php', 'Belgeler', '▤'],
        ['teklif_ver', 'teklif-ver.php', 'Teklif Ver', '✎'],
        ['tahsilat_makbuzu', 'tahsilat-makbuzu.php', 'Tahsilat Makbuzu', '₺'],
        ['kategoriler', 'kategoriler.php', 'Kategoriler', '▦'],
        ['raporlar', 'raporlar.php', 'Raporlar', '◷'],
        ['hesabim', 'hesabim.php', 'Hesabım', '⚿'],
    ]);
    if (is_admin()) {
        $nav[] = ['yedekler', 'yedekler.php', 'Yedekleme', '⇩'];
        $nav[] = ['loglar', 'loglar.php', 'Loglar', '☰'];
    }
    if (can_manage_users()) {
        $nav[] = ['kullanicilar', 'kullanicilar.php', 'Kullanıcılar', '♙'];
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
  <style>.sidebar .brand img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:12px;padding:4px}.sidebar .brand span{line-height:1.05}</style>
</head>
<body class="app-page">
  <div class="app-shell">
    <aside class="sidebar">
      <a class="brand" href="dashboard.php" aria-label="Dumanlar Muhasebe">
        <img src="assets/dumanlar-logo-arkaplansiz.png?v=1" alt="Dumanlar" />
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
  <script>window.BITKE_SUPER_ADMIN_IDS = <?php echo json_encode(super_admin_user_ids(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>; window.BITKE_COMPANY_TAX_NO = <?php echo json_encode(preg_replace('/\D+/', '', (string)setting_get('company_tax_no', '3140036788')) ?: '3140036788', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
  <script src="assets/muhasebe.js?v=516"></script>
  <script src="assets/super-admin-role.js?v=1"></script>
  <script src="assets/muhasebe-polish.js?v=1"></script>
  <script src="assets/teklif-hesap-fix.js?v=3"></script>
  <script src="assets/teklif-barkod-auto.js?v=1"></script>
  <script src="assets/cek-vade-uyari.js?v=2"></script>
  <script src="assets/cari-doviz-bakiye.js?v=1"></script>
  <script src="assets/dashboard-cari-pozisyon.js?v=3"></script>
  <script src="assets/dashboard-nakit-cek-detay.js?v=1"></script>
  <script src="assets/dashboard-acik-cekler.js?v=2"></script>
  <script src="assets/dashboard-vade-hatirlatmalari.js?v=3"></script>
  <script src="assets/fatura-okuma-core.js?v=3"></script>
  <script src="assets/fatura-pdf-oku.js?v=3"></script>
  <script src="assets/fatura-kdv-devir.js?v=1"></script>
  <script src="assets/fatura-toplu-link.js?v=2"></script>
  <script src="assets/fatura-toplu-yukle.js?v=3"></script>
  <script src="assets/fatura-no-onar.js?v=2"></script>
  <script src="assets/fatura-sira-kontrol.js?v=2"></script>
  <script src="assets/fatura-cari-sec.js?v=2"></script>
  <script src="assets/fatura-cari-okuma-duzelt.js?v=3"></script>
  <script src="assets/fatura-yon-sec.js?v=1"></script>
  <script src="assets/fatura-turleri.js?v=2"></script>
  <script src="assets/fatura-tur-otomatik.js?v=1"></script>
  <script src="assets/cari-hareket-kaynak.js?v=4"></script>
  <script src="assets/cek-liste-toplam.js?v=1"></script>
  <script src="assets/cek-kapali-ayir.js?v=1"></script>
  <script src="assets/maas-excel-aktar.js?v=1"></script>
  <script src="assets/hesap-banka-detay.js?v=1"></script>
</body>
</html>
<?php }

function badge(string $label, string $tone = 'neutral'): string
{
    return '<span class="badge badge-' . e($tone) . '">' . e($label) . '</span>';
}
