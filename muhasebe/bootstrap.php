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
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        echo 'Sunucuda PHP PDO SQLite eklentisi aktif değil. Hosting panelinden SQLite/PDO SQLite aktif edilmeli.';
        exit;
    }
    $dbDir = dirname(DB_PATH);
    if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
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
    ensure_column($pdo, 'cariler', 'tax_office', 'TEXT');
    ensure_column($pdo, 'cariler', 'authorized_person', 'TEXT');
    ensure_column($pdo, 'cariler', 'iban', 'TEXT');
    ensure_column($pdo, 'cariler', 'city', 'TEXT');

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cari_id INTEGER,
        direction TEXT NOT NULL DEFAULT 'alinacak',
        status TEXT NOT NULL DEFAULT 'bekliyor',
        amount REAL NOT NULL CHECK(amount >= 0),
        issue_date TEXT,
        due_date TEXT NOT NULL,
        bank_name TEXT,
        branch_name TEXT,
        check_no TEXT,
        drawer TEXT,
        description TEXT,
        document_path TEXT,
        document_name TEXT,
        document_mime TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_hash TEXT NOT NULL UNIQUE,
        username TEXT,
        ip TEXT,
        fail_count INTEGER NOT NULL DEFAULT 0,
        locked_until INTEGER,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_type TEXT NOT NULL DEFAULT 'kasa',
        name TEXT NOT NULL,
        iban TEXT,
        bank_name TEXT,
        opening_balance REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        notes TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_id INTEGER NOT NULL,
        direction TEXT NOT NULL,
        amount REAL NOT NULL CHECK(amount >= 0),
        transaction_date TEXT NOT NULL,
        source_type TEXT NOT NULL DEFAULT 'manual',
        source_id INTEGER,
        description TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    ensure_column($pdo, 'movements', 'account_id', 'INTEGER');
    ensure_column($pdo, 'movements', 'document_type', 'TEXT');
    ensure_column($pdo, 'movements', 'is_cancelled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'movements', 'cancelled_at', 'TEXT');
    ensure_column($pdo, 'movements', 'cancelled_by', 'INTEGER');
    ensure_column($pdo, 'movements', 'cancel_reason', 'TEXT');
    ensure_column($pdo, 'checks', 'account_id', 'INTEGER');
    ensure_column($pdo, 'checks', 'closed_at', 'TEXT');

    $accountCount = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    if ($accountCount === 0) {
        $now = now();
        $stmt = $pdo->prepare('INSERT INTO accounts (account_type, name, opening_balance, is_active, notes, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, ?)');
        $stmt->execute(['kasa', 'Ana Kasa', 0, 'Varsayılan nakit kasa hesabı', $now, $now]);
        $stmt->execute(['banka', 'Ana Banka', 0, 'Varsayılan banka hesabı', $now, $now]);
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $stmt = $pdo->prepare('INSERT INTO users (username, display_name, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $now = now();
        $stmt->execute([DEFAULT_ADMIN_USERNAME, DEFAULT_ADMIN_DISPLAY, DEFAULT_ADMIN_PASSWORD_HASH, 'admin', $now, $now]);
    }

    $catCount = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($catCount === 0) {
        $now = now();
        $defaults = [
            ['Satış', 'gelir'], ['Tahsilat', 'gelir'], ['Mal Alımı', 'gider'], ['Personel', 'gider'],
            ['Kira', 'gider'], ['Nakliye', 'gider'], ['Fatura', 'gider'], ['Genel', 'genel'], ['Çek', 'genel']
        ];
        $stmt = $pdo->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)');
        foreach ($defaults as $row) $stmt->execute([$row[0], $row[1], $now]);
    }
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) return;
    }
    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function now(): string { return date('Y-m-d H:i:s'); }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): void { header('Location: ' . $url); exit; }

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
function verify_csrf(?string $token): bool { return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token); }
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }
}

function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type, 'message'=>$message]; }
function get_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function current_user(): ?array
{
    if (!is_logged_in()) return null;
    static $cached = null;
    if ($cached !== null) return $cached;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}
function reset_current_user_cache(): void
{
    // current_user() statik cache kullandığı için profil/şifre güncelleme sonrası istek içinde taze veri gerekebilir.
}
function destroy_session_cookie(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
function enforce_session_timeout(): void
{
    if (!is_logged_in()) return;
    $last = (int)($_SESSION['last_activity'] ?? time());
    if (time() - $last > SESSION_TIMEOUT_SECONDS) {
        log_action('Otomatik çıkış', 'İşlem yapılmadığı için oturum kapatıldı');
        destroy_session_cookie();
        redirect('index.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}
function require_login(): void
{
    if (!is_logged_in() || !current_user()) redirect('index.php');
    enforce_session_timeout();
}
function user_role(): string { $u = current_user(); return $u['role'] ?? 'viewer'; }
function can_write(): bool { return in_array(user_role(), ['admin','editor'], true); }
function is_admin(): bool { return user_role() === 'admin'; }
function require_write(): void { require_login(); if (!can_write()) { flash('error','Bu işlem için düzenleme yetkiniz yok.'); redirect('dashboard.php'); } }
function require_admin(): void { require_login(); if (!is_admin()) { flash('error','Bu sayfa yalnızca yönetici içindir.'); redirect('dashboard.php'); } }

function client_ip(): string
{
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return trim(explode(',', (string)$raw)[0]);
}
function login_attempt_key(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . client_ip());
}
function login_attempt_row(string $username): ?array
{
    if (trim($username) === '') return null;
    $stmt = db()->prepare('SELECT * FROM login_attempts WHERE key_hash = ?');
    $stmt->execute([login_attempt_key($username)]);
    return $stmt->fetch() ?: null;
}
function login_is_locked(string $username = ''): bool
{
    $row = login_attempt_row($username);
    if (!$row) return false;
    return (int)($row['locked_until'] ?? 0) > time();
}
function login_lock_remaining(string $username = ''): int
{
    $row = login_attempt_row($username);
    return max(0, (int)($row['locked_until'] ?? 0) - time());
}
function register_failed_login(string $username = ''): void
{
    $username = trim($username);
    if ($username === '') return;
    $key = login_attempt_key($username);
    $row = login_attempt_row($username);
    $failCount = (int)($row['fail_count'] ?? 0) + 1;
    $lockedUntil = null;
    if ($failCount >= LOGIN_MAX_ATTEMPTS) {
        $lockedUntil = time() + LOGIN_LOCK_SECONDS;
        $failCount = 0;
    }
    db()->prepare('INSERT INTO login_attempts (key_hash, username, ip, fail_count, locked_until, updated_at) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(key_hash) DO UPDATE SET username=excluded.username, ip=excluded.ip, fail_count=excluded.fail_count, locked_until=excluded.locked_until, updated_at=excluded.updated_at')
        ->execute([$key, $username, client_ip(), $failCount, $lockedUntil, now()]);
}
function clear_login_failures(string $username = ''): void
{
    $username = trim($username);
    if ($username !== '') {
        db()->prepare('DELETE FROM login_attempts WHERE key_hash = ?')->execute([login_attempt_key($username)]);
    }
    unset($_SESSION['login_fail_count'], $_SESSION['login_locked_until']);
}
function log_action(string $action, string $detail = ''): void
{
    try {
        $u = current_user();
        $stmt = db()->prepare('INSERT INTO logs (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$u['id'] ?? null, $u['username'] ?? null, $action, $detail, client_ip(), now()]);
    } catch (Throwable $e) {}
}

function setting_get(string $key, ?string $default = null): ?string
{
    try {
        $stmt = db()->prepare('SELECT value FROM settings WHERE key=?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return ($row !== false) ? $row['value'] : $default;
    } catch (Throwable $e) { return $default; }
}
function setting_set(string $key, string $value): void
{
    try {
        db()->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
            ->execute([$key, $value, now()]);
    } catch (Throwable $e) {}
}

function money($amount): string { return number_format((float)$amount, 2, ',', '.') . ' TL'; }
function bytes_human($bytes): string
{
    $bytes = (float)$bytes;
    if ($bytes < 1024) return number_format($bytes, 0, ',', '.') . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / 1024 / 1024, 2, ',', '.') . ' MB';
    return number_format($bytes / 1024 / 1024 / 1024, 2, ',', '.') . ' GB';
}
function safe_back_url(string $fallback = 'dashboard.php'): string
{
    $back = $_POST['back'] ?? $_GET['back'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if (!$back) return $fallback;
    $parts = parse_url($back);
    if (!empty($parts['host']) && !empty($_SERVER['HTTP_HOST']) && strcasecmp($parts['host'], $_SERVER['HTTP_HOST']) !== 0) return $fallback;
    return basename($parts['path'] ?? $back) . (!empty($parts['query']) ? '?' . $parts['query'] : '');
}
function decimal_from_input($value): float
{
    $v = trim(str_replace(' ', '', (string)$value));
    if ($v === '') return 0.0;

    $lastComma = strrpos($v, ',');
    $lastDot = strrpos($v, '.');

    if ($lastComma !== false && $lastDot !== false) {
        // 1.234,56 veya 1,234.56 formatlarında son ayırıcı ondalık kabul edilir.
        if ($lastComma > $lastDot) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            $v = str_replace(',', '', $v);
        }
    } elseif ($lastComma !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif ($lastDot !== false) {
        if (substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        } else {
            $parts = explode('.', $v);
            if (isset($parts[1]) && strlen($parts[1]) === 3 && strlen($parts[0]) <= 3) {
                $v = str_replace('.', '', $v);
            }
        }
    }

    return (float)$v;
}
function tr_date(?string $date): string { if (!$date) return '-'; $ts = strtotime($date); return $ts ? date('d.m.Y', $ts) : e($date); }
function tr_datetime(?string $date): string { if (!$date) return '-'; $ts = strtotime($date); return $ts ? date('d.m.Y H:i', $ts) : e($date); }
function month_label(?string $ym): string { if (!$ym) return '-'; $ts = strtotime($ym . '-01'); return $ts ? date('m.Y', $ts) : e($ym); }

function movement_types(): array
{
    return [
        'alacak' => ['label'=>'Alacak','tone'=>'info'],
        'tahsilat' => ['label'=>'Tahsilat','tone'=>'success'],
        'verecek' => ['label'=>'Verecek','tone'=>'warning'],
        'odeme' => ['label'=>'Ödeme','tone'=>'danger'],
        'gelir' => ['label'=>'Gelir','tone'=>'success'],
        'gider' => ['label'=>'Gider','tone'=>'danger'],
    ];
}
function movement_label(string $type): string { $t = movement_types(); return $t[$type]['label'] ?? $type; }
function movement_tone(string $type): string { $t = movement_types(); return $t[$type]['tone'] ?? 'neutral'; }
function role_label(string $role): string { return ['admin'=>'Yönetici','editor'=>'Düzenleyici','viewer'=>'Görüntüleyici'][$role] ?? $role; }

function check_directions(): array
{
    return [
        'alinacak' => ['label'=>'Alınacak Çek', 'tone'=>'success'],
        'verilecek' => ['label'=>'Verilecek Çek', 'tone'=>'warning'],
    ];
}
function check_statuses(): array
{
    return [
        'bekliyor' => ['label'=>'Bekliyor','tone'=>'info'],
        'tahsil_edildi' => ['label'=>'Tahsil edildi','tone'=>'success'],
        'odendi' => ['label'=>'Ödendi','tone'=>'success'],
        'ciro_edildi' => ['label'=>'Ciro edildi','tone'=>'warning'],
        'iade' => ['label'=>'İade','tone'=>'neutral'],
        'karsiliksiz' => ['label'=>'Karşılıksız','tone'=>'danger'],
        'protestolu' => ['label'=>'Protestolu','tone'=>'danger'],
        'iptal' => ['label'=>'İptal','tone'=>'neutral'],
    ];
}
function check_direction_label(string $key): string { $m=check_directions(); return $m[$key]['label'] ?? $key; }
function check_direction_tone(string $key): string { $m=check_directions(); return $m[$key]['tone'] ?? 'neutral'; }
function check_status_label(string $key): string { $m=check_statuses(); return $m[$key]['label'] ?? $key; }
function check_status_tone(string $key): string { $m=check_statuses(); return $m[$key]['tone'] ?? 'neutral'; }

function cari_balance(?int $cariId): array
{
    if (!$cariId) return ['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0,'net_alacak'=>0,'net_verecek'=>0,'net'=>0];
    $stmt = db()->prepare('SELECT movement_type, SUM(amount) AS total FROM movements WHERE cari_id = ? AND COALESCE(is_cancelled,0)=0 GROUP BY movement_type');
    $stmt->execute([$cariId]);
    $totals = ['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0];
    foreach ($stmt->fetchAll() as $row) if (isset($totals[$row['movement_type']])) $totals[$row['movement_type']] = (float)$row['total'];
    $totals['net_alacak'] = $totals['alacak'] - $totals['tahsilat'];
    $totals['net_verecek'] = $totals['verecek'] - $totals['odeme'];
    $totals['net'] = $totals['net_alacak'] - $totals['net_verecek'];
    return $totals;
}

function dashboard_totals(?string $start = null, ?string $end = null): array
{
    $where=[]; $params=[];
    if ($start) { $where[]='movement_date >= ?'; $params[]=$start; }
    if ($end) { $where[]='movement_date <= ?'; $params[]=$end; }
    $where[]='COALESCE(is_cancelled,0)=0';
    $sql='SELECT movement_type, SUM(amount) AS total FROM movements';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY movement_type';
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $totals=['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0];
    foreach ($stmt->fetchAll() as $row) if (isset($totals[$row['movement_type']])) $totals[$row['movement_type']] = (float)$row['total'];
    $totals['net_alacak']=$totals['alacak']-$totals['tahsilat'];
    $totals['net_verecek']=$totals['verecek']-$totals['odeme'];
    $totals['net_gelir_gider']=$totals['gelir']-$totals['gider'];
    return $totals;
}

function check_totals(?string $start = null, ?string $end = null, bool $pendingOnly = true): array
{
    $where=[]; $params=[];
    if ($pendingOnly) $where[]="status = 'bekliyor'";
    if ($start) { $where[]='due_date >= ?'; $params[]=$start; }
    if ($end) { $where[]='due_date <= ?'; $params[]=$end; }
    $sql='SELECT direction, SUM(amount) AS total, COUNT(*) AS count_total FROM checks';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY direction';
    $stmt=db()->prepare($sql); $stmt->execute($params);
    $totals=['alinacak'=>0,'verilecek'=>0,'alinacak_count'=>0,'verilecek_count'=>0];
    foreach ($stmt->fetchAll() as $r) {
        if (isset($totals[$r['direction']])) {
            $totals[$r['direction']] = (float)$r['total'];
            $totals[$r['direction'].'_count'] = (int)$r['count_total'];
        }
    }
    return $totals;
}
function overdue_check_count(): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM checks WHERE status='bekliyor' AND due_date < ?");
    $stmt->execute([date('Y-m-d')]);
    return (int)$stmt->fetchColumn();
}
function monthly_summary(int $months = 6): array
{
    $months = max(1, min(24, $months));
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $stmt = db()->prepare("SELECT strftime('%Y-%m', movement_date) AS ym, movement_type, SUM(amount) AS total
        FROM movements WHERE movement_date >= ? AND COALESCE(is_cancelled,0)=0 GROUP BY ym, movement_type ORDER BY ym ASC");
    $stmt->execute([$start]);
    $rows = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime('-' . $i . ' months'));
        $rows[$ym] = ['ym'=>$ym,'label'=>month_label($ym),'gelir'=>0,'gider'=>0,'tahsilat'=>0,'odeme'=>0,'net'=>0];
    }
    foreach ($stmt->fetchAll() as $r) {
        $ym = $r['ym'];
        if (!isset($rows[$ym])) continue;
        $type = $r['movement_type'];
        if (isset($rows[$ym][$type])) $rows[$ym][$type] = (float)$r['total'];
    }
    foreach ($rows as &$r) $r['net'] = ($r['gelir'] + $r['tahsilat']) - ($r['gider'] + $r['odeme']);
    return array_values($rows);
}

function document_types(): array
{
    return [
        'fatura' => 'Fatura',
        'dekont' => 'Dekont',
        'makbuz' => 'Makbuz',
        'irsaliye' => 'İrsaliye',
        'sozlesme' => 'Sözleşme',
        'cek_gorseli' => 'Çek görseli',
        'diger' => 'Diğer',
    ];
}
function document_type_label(?string $key): string
{
    $m = document_types();
    return $m[$key ?: ''] ?? ($key ?: '-');
}
function account_types(): array
{
    return [
        'kasa' => ['label'=>'Kasa','tone'=>'success'],
        'banka' => ['label'=>'Banka','tone'=>'info'],
        'pos' => ['label'=>'POS','tone'=>'warning'],
        'diger' => ['label'=>'Diğer','tone'=>'neutral'],
    ];
}
function account_type_label(string $key): string { $m=account_types(); return $m[$key]['label'] ?? $key; }
function account_type_tone(string $key): string { $m=account_types(); return $m[$key]['tone'] ?? 'neutral'; }
function accounts_for_select(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM accounts';
    if ($activeOnly) $sql .= ' WHERE is_active=1';
    $sql .= ' ORDER BY account_type ASC, name ASC';
    return db()->query($sql)->fetchAll();
}
function account_balance(int $accountId): float
{
    $stmt = db()->prepare("SELECT opening_balance FROM accounts WHERE id=?");
    $stmt->execute([$accountId]);
    $opening = (float)($stmt->fetchColumn() ?: 0);
    $stmt = db()->prepare("SELECT direction, SUM(amount) AS total FROM account_transactions WHERE account_id=? GROUP BY direction");
    $stmt->execute([$accountId]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['direction'] === 'in') $opening += (float)$r['total'];
        if ($r['direction'] === 'out') $opening -= (float)$r['total'];
    }
    return $opening;
}
function account_summary(): array
{
    $rows = accounts_for_select(false);
    $summary = ['kasa'=>0,'banka'=>0,'pos'=>0,'diger'=>0,'total'=>0,'active'=>0];
    foreach ($rows as $a) {
        $bal = account_balance((int)$a['id']);
        $type = $a['account_type'] ?: 'diger';
        if (!isset($summary[$type])) $summary[$type] = 0;
        $summary[$type] += $bal;
        $summary['total'] += $bal;
        if ((int)$a['is_active'] === 1) $summary['active']++;
    }
    return $summary;
}
function movement_cash_direction(string $type): ?string
{
    if (in_array($type, ['tahsilat','gelir'], true)) return 'in';
    if (in_array($type, ['odeme','gider'], true)) return 'out';
    return null;
}
function sync_movement_account_transaction(int $movementId): void
{
    db()->prepare("DELETE FROM account_transactions WHERE source_type='movement' AND source_id=?")->execute([$movementId]);
    $stmt = db()->prepare('SELECT m.*, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE m.id=?');
    $stmt->execute([$movementId]);
    $m = $stmt->fetch();
    if (!$m || (int)($m['is_cancelled'] ?? 0) === 1) return;
    $direction = movement_cash_direction($m['movement_type']);
    if (!$direction || empty($m['account_id'])) return;
    $desc = movement_label($m['movement_type']);
    if (!empty($m['cari_name'])) $desc .= ' - ' . $m['cari_name'];
    if (!empty($m['description'])) $desc .= ' / ' . $m['description'];
    db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$m['account_id'], $direction, (float)$m['amount'], $m['movement_date'], 'movement', $movementId, $desc, $m['created_by'] ?: (current_user()['id'] ?? null), now()]);
}
function sync_check_account_transaction(int $checkId): void
{
    db()->prepare("DELETE FROM account_transactions WHERE source_type='check' AND source_id=?")->execute([$checkId]);
    $stmt = db()->prepare('SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=?');
    $stmt->execute([$checkId]);
    $ch = $stmt->fetch();
    if (!$ch || empty($ch['account_id'])) return;
    $direction = null;
    if ($ch['status'] === 'tahsil_edildi') $direction = 'in';
    if ($ch['status'] === 'odendi') $direction = 'out';
    if (!$direction) return;
    $desc = check_status_label($ch['status']) . ' - ' . check_direction_label($ch['direction']);
    if (!empty($ch['cari_name'])) $desc .= ' - ' . $ch['cari_name'];
    if (!empty($ch['check_no'])) $desc .= ' / Çek No: ' . $ch['check_no'];
    db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$ch['account_id'], $direction, (float)$ch['amount'], $ch['due_date'], 'check', $checkId, $desc, $ch['created_by'] ?: (current_user()['id'] ?? null), now()]);
}

function ensure_upload_dir(string $subdir): string
{
    $dir = UPLOAD_DIR . '/' . trim($subdir, '/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
function handle_upload(string $field, ?array $old = null): array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $old ?: ['path'=>null,'name'=>null,'mime'=>null];
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Belge yüklenemedi. Dosya hatası: ' . $file['error']);
    if ($file['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('Belge en fazla 5 MB olabilir.');
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf','image/heic'=>'heic','image/heif'=>'heif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (!isset($allowed[$mime])) throw new RuntimeException('Sadece JPG, PNG, WEBP, HEIC ve PDF belge yükleyebilirsiniz.');
    $subdir = date('Y/m');
    $dir = ensure_upload_dir($subdir);
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Belge sunucuya taşınamadı.');
    return ['path'=>$subdir . '/' . $filename, 'name'=>$file['name'], 'mime'=>$mime];
}

function categories(): array { return db()->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll(); }
function cariler_for_select(): array { return db()->query('SELECT id, name, cari_type FROM cariler ORDER BY name ASC')->fetchAll(); }
function download_file(string $path, string $downloadName, string $mime = 'application/octet-stream'): void
{
    if (!is_file($path)) { http_response_code(404); exit('Dosya bulunamadı.'); }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
    readfile($path);
    exit;
}

// Veritabanını ilk istekte hazırlansın ve migration çalışsın.
db();
