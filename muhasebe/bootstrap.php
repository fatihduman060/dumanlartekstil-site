<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (session_status() === PHP_SESSION_NONE) {
    session_name('bitke_muhasebe_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH,
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        echo 'Sunucuda PHP PDO SQLite eklentisi aktif değil. Hosting panelinden SQLite/PDO SQLite aktif edilmeli.';
        exit;
    }
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'editor',
        is_active INTEGER NOT NULL DEFAULT 1,
        last_login TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cariler (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cari_type TEXT NOT NULL DEFAULT 'Firma',
        name TEXT NOT NULL,
        tax_no TEXT,
        phone TEXT,
        email TEXT,
        address TEXT,
        notes TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        type TEXT NOT NULL DEFAULT 'genel',
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cari_id INTEGER,
        category_id INTEGER,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL CHECK(amount >= 0),
        movement_date TEXT NOT NULL,
        due_date TEXT,
        payment_method TEXT,
        description TEXT,
        document_path TEXT,
        document_name TEXT,
        document_mime TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        detail TEXT,
        ip TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $now = now();
        $stmt->execute([DEFAULT_ADMIN_USERNAME, DEFAULT_ADMIN_DISPLAY, DEFAULT_ADMIN_PASSWORD_HASH, 'admin', $now, $now]);
    }

    $catCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($catCount === 0) {
        $now = now();
        $defaults = [
            ['Satış', 'gelir'], ['Tahsilat', 'gelir'], ['Mal Alımı', 'gider'], ['Personel', 'gider'],
            ['Kira', 'gider'], ['Nakliye', 'gider'], ['Fatura', 'gider'], ['Genel', 'genel']
        ];
        $stmt = $pdo->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)');
        foreach ($defaults as $row) {
            $stmt->execute([$row[0], $row[1], $now]);
        }
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function require_login(): void
{
    if (!is_logged_in() || !current_user()) {
        redirect('index.php');
    }
}

function user_role(): string
{
    $u = current_user();
    return $u['role'] ?? 'viewer';
}

function can_write(): bool
{
    return in_array(user_role(), ['admin', 'editor'], true);
}

function is_admin(): bool
{
    return user_role() === 'admin';
}

function require_write(): void
{
    require_login();
    if (!can_write()) {
        flash('error', 'Bu işlem için düzenleme yetkiniz yok.');
        redirect('dashboard.php');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        flash('error', 'Bu sayfa yalnızca yönetici içindir.');
        redirect('dashboard.php');
    }
}

function login_is_locked(): bool
{
    return (int)($_SESSION['login_locked_until'] ?? 0) > time();
}

function login_lock_remaining(): int
{
    return max(0, (int)($_SESSION['login_locked_until'] ?? 0) - time());
}

function register_failed_login(): void
{
    $_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
    if ($_SESSION['login_fail_count'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60;
        $_SESSION['login_fail_count'] = 0;
    }
}

function clear_login_failures(): void
{
    unset($_SESSION['login_fail_count'], $_SESSION['login_locked_until']);
}

function client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

function log_action(string $action, string $detail = ''): void
{
    try {
        $u = current_user();
        $stmt = db()->prepare('INSERT INTO logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$u['id'] ?? null, $u['username'] ?? null, $action, $detail, client_ip(), now()]);
    } catch (Throwable $e) {
        // Log hatası paneli bozmasın.
    }
}

function money($amount): string
{
    return number_format((float)$amount, 2, ',', '.') . ' TL';
}

function tr_date(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    if (!$ts) return e($date);
    return date('d.m.Y', $ts);
}

function tr_datetime(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    if (!$ts) return e($date);
    return date('d.m.Y H:i', $ts);
}

function movement_types(): array
{
    return [
        'alacak' => ['label' => 'Alacak', 'tone' => 'info'],
        'tahsilat' => ['label' => 'Tahsilat', 'tone' => 'success'],
        'verecek' => ['label' => 'Verecek', 'tone' => 'warning'],
        'odeme' => ['label' => 'Ödeme', 'tone' => 'danger'],
        'gelir' => ['label' => 'Gelir', 'tone' => 'success'],
        'gider' => ['label' => 'Gider', 'tone' => 'danger'],
    ];
}

function movement_label(string $type): string
{
    $types = movement_types();
    return $types[$type]['label'] ?? $type;
}

function movement_tone(string $type): string
{
    $types = movement_types();
    return $types[$type]['tone'] ?? 'neutral';
}

function role_label(string $role): string
{
    return ['admin' => 'Yönetici', 'editor' => 'Düzenleyici', 'viewer' => 'Görüntüleyici'][$role] ?? $role;
}

function cari_balance(?int $cariId): array
{
    if (!$cariId) {
        return ['alacak' => 0, 'tahsilat' => 0, 'verecek' => 0, 'odeme' => 0, 'net_alacak' => 0, 'net_verecek' => 0, 'net' => 0];
    }
    $stmt = db()->prepare("SELECT movement_type, SUM(amount) AS total FROM movements WHERE cari_id = ? GROUP BY movement_type");
    $stmt->execute([$cariId]);
    $totals = ['alacak' => 0, 'tahsilat' => 0, 'verecek' => 0, 'odeme' => 0];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($totals[$row['movement_type']])) {
            $totals[$row['movement_type']] = (float)$row['total'];
        }
    }
    $totals['net_alacak'] = $totals['alacak'] - $totals['tahsilat'];
    $totals['net_verecek'] = $totals['verecek'] - $totals['odeme'];
    $totals['net'] = $totals['net_alacak'] - $totals['net_verecek'];
    return $totals;
}

function dashboard_totals(?string $start = null, ?string $end = null): array
{
    $where = [];
    $params = [];
    if ($start) { $where[] = 'movement_date >= ?'; $params[] = $start; }
    if ($end) { $where[] = 'movement_date <= ?'; $params[] = $end; }
    $sql = 'SELECT movement_type, SUM(amount) AS total FROM movements';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY movement_type';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $totals = ['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($totals[$row['movement_type']])) $totals[$row['movement_type']] = (float)$row['total'];
    }
    $totals['net_alacak'] = $totals['alacak'] - $totals['tahsilat'];
    $totals['net_verecek'] = $totals['verecek'] - $totals['odeme'];
    $totals['net_gelir_gider'] = $totals['gelir'] - $totals['gider'];
    return $totals;
}

function ensure_upload_dir(string $subdir): string
{
    $dir = UPLOAD_DIR . '/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function handle_upload(string $field, ?array $old = null): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $old ?: ['path' => null, 'name' => null, 'mime' => null];
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Belge yüklenemedi. Dosya hatası: ' . $file['error']);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Belge en fazla 5 MB olabilir.');
    }
    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'image/heic' => 'heic', 'image/heif' => 'heif'
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Sadece JPG, PNG, WEBP, HEIC ve PDF belge yükleyebilirsiniz.');
    }
    $subdir = date('Y/m');
    $dir = ensure_upload_dir($subdir);
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Belge sunucuya taşınamadı.');
    }
    return ['path' => $subdir . '/' . $filename, 'name' => $file['name'], 'mime' => $mime];
}

function categories(): array
{
    return db()->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
}

function cariler_for_select(): array
{
    return db()->query('SELECT id, name, cari_type FROM cariler ORDER BY name ASC')->fetchAll();
}

// Veritabanını ilk istekte hazırlansın.
db();
