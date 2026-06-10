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
    $schemaVersion = defined('DB_SCHEMA_VERSION') ? (int)DB_SCHEMA_VERSION : 5020;

    // Settings tablosu schema versiyonunu okuyabilmek için en başta hazırlanır.
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL
    )");

    $currentSchemaVersion = 0;
    try {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key=?');
        $stmt->execute(['db_schema_version']);
        $currentSchemaVersion = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $currentSchemaVersion = 0;
    }

    // Schema güncelse her istekte PRAGMA/ALTER/CREATE kontrollerini tekrar yapma.
    if ($currentSchemaVersion >= $schemaVersion) {
        return;
    }

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS private_receivables (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cari_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'acik',
        amount REAL NOT NULL CHECK(amount >= 0),
        receivable_date TEXT NOT NULL,
        description TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE CASCADE,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    ensure_column($pdo, 'private_receivables', 'document_type', 'TEXT');
    ensure_column($pdo, 'private_receivables', 'document_path', 'TEXT');
    ensure_column($pdo, 'private_receivables', 'document_name', 'TEXT');
    ensure_column($pdo, 'private_receivables', 'document_mime', 'TEXT');

    $pdo->exec("CREATE TABLE IF NOT EXISTS standalone_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cari_id INTEGER,
        document_date TEXT NOT NULL,
        document_type TEXT,
        document_path TEXT NOT NULL,
        document_name TEXT,
        document_mime TEXT,
        description TEXT,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        entity_type TEXT NOT NULL,
        entity_id INTEGER,
        action TEXT NOT NULL,
        old_value TEXT,
        new_value TEXT,
        detail TEXT,
        ip TEXT,
        created_at TEXT NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    // Minimal audit log hızlı filtrelensin diye indeksler. Sadece kritik veri değişiklikleri tutulur.
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_created_at ON audit_logs(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity_type, entity_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_logs(action)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(username)");

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
    ensure_column($pdo, 'movements', 'source_type', 'TEXT');
    ensure_column($pdo, 'movements', 'source_id', 'INTEGER');
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_movements_source ON movements(source_type, source_id)");
    ensure_column($pdo, 'checks', 'account_id', 'INTEGER');
    ensure_column($pdo, 'checks', 'closed_at', 'TEXT');
    ensure_column($pdo, 'checks', 'is_cancelled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'checks', 'cancelled_at', 'TEXT');
    ensure_column($pdo, 'checks', 'cancelled_by', 'INTEGER');
    ensure_column($pdo, 'checks', 'cancel_reason', 'TEXT');

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

    // v50.20+: önceki paketlerden gelen kasa/banka kaynak hareketleri tek seferlik toparlansın.
    // v50.22+: vadeli nakit hareketlerinde işlem tarihi yerine vade/tahsil tarihi kullanılsın.
    // v50.23+: çekler cariye otomatik hareket olarak işlensin; bekleyen çek nakit sayılmasın.
    if ($currentSchemaVersion < 5023) {
        sync_all_check_cari_movements(false);
        repair_account_sync(false);
    }

    $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
        ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
        ->execute(['db_schema_version', (string)$schemaVersion, now()]);
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
    run_automatic_backup_if_due();
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

function audit_action(string $entityType, ?int $entityId, string $action, $oldValue = null, $newValue = null, string $detail = ''): void
{
    try {
        $u = current_user();
        $oldJson = $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $newJson = $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, username, entity_type, entity_id, action, old_value, new_value, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$u['id'] ?? null, $u['username'] ?? null, $entityType, $entityId, $action, $oldJson, $newJson, $detail, client_ip(), now()]);
    } catch (Throwable $e) {}
}

function audit_short(?string $json): string
{
    if (!$json) return '-';
    $data = json_decode($json, true);
    if (!is_array($data)) return (strlen((string)$json) > 120 ? substr((string)$json, 0, 120) . '...' : (string)$json);
    $parts = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($value === null || $value === '') continue;
        $parts[] = $key . ': ' . $value;
        if (count($parts) >= 5) break;
    }
    return $parts ? (strlen(implode(' · ', $parts)) > 160 ? substr(implode(' · ', $parts), 0, 160) . '...' : implode(' · ', $parts)) : '-';
}

function audit_entity_label(string $type): string
{
    return [
        'cari' => 'Cari',
        'hareket' => 'Hareket',
        'cek' => 'Çek',
        'ozel_alacak' => 'Özel Alacak',
        'hesap' => 'Kasa/Banka Hesabı',
        'hesap_hareketi' => 'Kasa/Banka Hareketi',
        'kullanici' => 'Kullanıcı',
        'yedek' => 'Yedekleme',
        'kategori' => 'Kategori',
        'belge' => 'Belge',
    ][$type] ?? $type;
}

function audit_action_label(string $action): string
{
    return [
        'eklendi' => 'Eklendi',
        'guncellendi' => 'Güncellendi',
        'silindi' => 'Silindi',
        'iptal' => 'İptal edildi',
        'durum_guncellendi' => 'Durum güncellendi',
        'virman' => 'Virman',
        'geri_yukleme' => 'Geri yükleme',
    ][$action] ?? $action;
}

function audit_action_tone(string $action): string
{
    return [
        'eklendi' => 'success',
        'guncellendi' => 'info',
        'durum_guncellendi' => 'info',
        'silindi' => 'danger',
        'iptal' => 'warning',
        'geri_yukleme' => 'danger',
        'virman' => 'special',
    ][$action] ?? 'neutral';
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
function movement_entry_types(): array
{
    $types = movement_types();
    $ordered = [];
    foreach ($types as $key => $meta) {
        $ordered[$key] = $meta;
        if ($key === 'alacak') {
            $ordered['ozel_alacak'] = ['label'=>'Özel Alacak','tone'=>'special'];
        }
    }
    return $ordered;
}
function is_private_receivable_movement(string $type): bool { return $type === 'ozel_alacak'; }
function movement_label(string $type): string { $t = movement_entry_types(); return $t[$type]['label'] ?? $type; }
function movement_tone(string $type): string { $t = movement_entry_types(); return $t[$type]['tone'] ?? 'neutral'; }
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

function check_source_types(): array
{
    return ['check_acceptance', 'check_reversal'];
}
function is_check_source_movement($sourceType): bool
{
    return in_array((string)$sourceType, check_source_types(), true);
}
function check_acceptance_movement_type(string $direction): string
{
    return $direction === 'verilecek' ? 'odeme' : 'tahsilat';
}
function check_reversal_movement_type(string $direction): string
{
    return $direction === 'verilecek' ? 'verecek' : 'alacak';
}
function check_reversal_required(string $status): bool
{
    return in_array($status, ['karsiliksiz', 'protestolu', 'iade', 'iptal'], true);
}
function check_entry_date(array $check): string
{
    $created = trim((string)($check['created_at'] ?? ''));
    if ($created !== '') return substr($created, 0, 10);
    $issue = trim((string)($check['issue_date'] ?? ''));
    if ($issue !== '') return substr($issue, 0, 10);
    return date('Y-m-d');
}
function check_close_date(array $check): string
{
    $closed = trim((string)($check['closed_at'] ?? ''));
    if ($closed !== '') return substr($closed, 0, 10);
    return date('Y-m-d');
}
function check_category_id(): ?int
{
    try {
        $stmt = db()->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
        $stmt->execute(['Çek']);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) { return null; }
}
function check_movement_description(array $check, string $kind): string
{
    $parts = [];
    $parts[] = $kind === 'reversal' ? 'Çek ödenmedi/geri döndü' : 'Çek cariye işlendi';
    $parts[] = check_direction_label((string)($check['direction'] ?? ''));
    if (!empty($check['bank_name'])) $parts[] = (string)$check['bank_name'];
    if (!empty($check['check_no'])) $parts[] = 'No: ' . (string)$check['check_no'];
    if (!empty($check['status']) && $kind === 'reversal') $parts[] = 'Durum: ' . check_status_label((string)$check['status']);
    if (!empty($check['description'])) $parts[] = (string)$check['description'];
    return '[Sistem] ' . implode(' / ', array_filter($parts, fn($v) => trim((string)$v) !== ''));
}
function find_legacy_check_acceptance_movement_id(array $check, string $movementType): ?int
{
    if (empty($check['cari_id']) || empty($check['due_date'])) return null;
    $sql = "SELECT id FROM movements
        WHERE cari_id = ?
          AND movement_type = ?
          AND ABS(amount - ?) < 0.005
          AND COALESCE(is_cancelled,0)=0
          AND (source_type IS NULL OR source_type = '')
          AND COALESCE(due_date,'') = ?
          AND (UPPER(COALESCE(payment_method,'')) LIKE '%ÇEK%'
               OR UPPER(COALESCE(payment_method,'')) LIKE '%CEK%'
               OR UPPER(COALESCE(description,'')) LIKE '%ÇEK%'
               OR UPPER(COALESCE(description,'')) LIKE '%CEK%')
        ORDER BY CASE WHEN account_id IS NULL THEN 0 ELSE 1 END, id ASC
        LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute([(int)$check['cari_id'], $movementType, (float)$check['amount'], (string)$check['due_date']]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}
function upsert_check_generated_movement(array $check, string $sourceType, string $movementType, string $movementDate, string $description): ?int
{
    if (empty($check['id']) || empty($check['cari_id']) || (float)($check['amount'] ?? 0) <= 0) return null;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM movements WHERE source_type=? AND source_id=? ORDER BY id ASC LIMIT 1');
    $stmt->execute([$sourceType, (int)$check['id']]);
    $movementId = (int)($stmt->fetchColumn() ?: 0);

    if ($movementId <= 0 && $sourceType === 'check_acceptance') {
        $movementId = (int)(find_legacy_check_acceptance_movement_id($check, $movementType) ?: 0);
    }

    $payload = [
        'cari_id' => (int)$check['cari_id'],
        'category_id' => check_category_id(),
        'account_id' => null,
        'movement_type' => $movementType,
        'amount' => (float)$check['amount'],
        'movement_date' => $movementDate,
        'due_date' => !empty($check['due_date']) ? (string)$check['due_date'] : null,
        'payment_method' => 'ÇEK',
        'description' => $description,
        'document_type' => null,
        'document_path' => null,
        'document_name' => null,
        'document_mime' => null,
        'source_type' => $sourceType,
        'source_id' => (int)$check['id'],
        'updated_at' => now(),
    ];

    if ($movementId > 0) {
        $sql = 'UPDATE movements SET cari_id=:cari_id, category_id=:category_id, account_id=:account_id, movement_type=:movement_type, amount=:amount, movement_date=:movement_date, due_date=:due_date, payment_method=:payment_method, description=:description, document_type=:document_type, document_path=:document_path, document_name=:document_name, document_mime=:document_mime, source_type=:source_type, source_id=:source_id, is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=:updated_at WHERE id=:id';
        $payload['id'] = $movementId;
        $pdo->prepare($sql)->execute($payload);
    } else {
        $sql = 'INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, source_type, source_id, created_by, created_at, updated_at) VALUES (:cari_id, :category_id, :account_id, :movement_type, :amount, :movement_date, :due_date, :payment_method, :description, :document_type, :document_path, :document_name, :document_mime, :source_type, :source_id, :created_by, :created_at, :updated_at)';
        $payload['created_by'] = $check['created_by'] ?? (current_user()['id'] ?? null);
        $payload['created_at'] = now();
        $pdo->prepare($sql)->execute($payload);
        $movementId = (int)$pdo->lastInsertId();
    }

    // Çekten oluşan cari hareketleri kasa/banka hareketi üretmez.
    sync_movement_account_transaction($movementId);
    return $movementId;
}
function cancel_check_generated_movements(int $checkId, ?string $sourceType = null, string $reason = 'Çek durumuna göre otomatik kapatıldı'): void
{
    $where = 'source_id=? AND source_type IN (\'' . implode("','", check_source_types()) . '\')';
    $params = [$checkId];
    if ($sourceType !== null) { $where = 'source_id=? AND source_type=?'; $params = [$checkId, $sourceType]; }
    db()->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=COALESCE(cancelled_at, ?), cancelled_by=COALESCE(cancelled_by, ?), cancel_reason=COALESCE(NULLIF(cancel_reason,''), ?), updated_at=? WHERE $where AND COALESCE(is_cancelled,0)=0")
        ->execute(array_merge([now(), current_user()['id'] ?? null, $reason, now()], $params));
}
function sync_check_cari_movement(int $checkId): void
{
    $stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
    $stmt->execute([$checkId]);
    $check = $stmt->fetch();
    if (!$check) return;

    if ((int)($check['is_cancelled'] ?? 0) === 1 || empty($check['cari_id']) || (float)($check['amount'] ?? 0) <= 0) {
        cancel_check_generated_movements($checkId, null, 'Çek iptal edildi veya cari/tutar eksik');
        return;
    }

    $acceptanceType = check_acceptance_movement_type((string)$check['direction']);
    upsert_check_generated_movement($check, 'check_acceptance', $acceptanceType, check_entry_date($check), check_movement_description($check, 'acceptance'));

    if (check_reversal_required((string)$check['status'])) {
        $reversalType = check_reversal_movement_type((string)$check['direction']);
        upsert_check_generated_movement($check, 'check_reversal', $reversalType, check_close_date($check), check_movement_description($check, 'reversal'));
    } else {
        cancel_check_generated_movements($checkId, 'check_reversal', 'Çek yeniden bekliyor/tahsil edildi/ödendi durumuna alındı');
    }
}
function sync_all_check_cari_movements(bool $withLog = true): array
{
    $ids = db()->query('SELECT id FROM checks ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $summary = ['checks' => 0];
    foreach ($ids as $id) {
        sync_check_cari_movement((int)$id);
        $summary['checks']++;
    }
    if ($withLog) {
        log_action('Çek cari hareket senkronu', 'Senkron edilen çek: ' . $summary['checks']);
        audit_action('cek', null, 'guncellendi', null, $summary, 'Çek cari hareket senkronu');
    }
    return $summary;
}

function private_receivable_statuses(): array
{
    return [
        'acik' => ['label'=>'Açık','tone'=>'info'],
        'kapandi' => ['label'=>'Kapandı','tone'=>'success'],
        'iptal' => ['label'=>'İptal','tone'=>'neutral'],
    ];
}
function private_receivable_status_label(string $key): string { $m=private_receivable_statuses(); return $m[$key]['label'] ?? $key; }
function private_receivable_status_tone(string $key): string { $m=private_receivable_statuses(); return $m[$key]['tone'] ?? 'neutral'; }
function private_receivable_summary(?int $cariId): array
{
    if (!$cariId) return ['acik'=>0,'kapandi'=>0,'iptal'=>0,'toplam'=>0,'count'=>0];
    $stmt = db()->prepare('SELECT status, SUM(amount) AS total, COUNT(*) AS total_count FROM private_receivables WHERE cari_id=? GROUP BY status');
    $stmt->execute([$cariId]);
    $summary = ['acik'=>0,'kapandi'=>0,'iptal'=>0,'toplam'=>0,'count'=>0];
    foreach ($stmt->fetchAll() as $row) {
        $status = $row['status'] ?: 'acik';
        if (!array_key_exists($status, $summary)) $summary[$status] = 0;
        $summary[$status] += (float)($row['total'] ?? 0);
        $summary['count'] += (int)($row['total_count'] ?? 0);
    }
    $summary['toplam'] = $summary['acik'] + $summary['kapandi'];
    return $summary;
}

function private_receivable_totals(array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters['status'])) { $where[] = 'pr.status=?'; $params[] = $filters['status']; }
    if (!empty($filters['cari_id'])) { $where[] = 'pr.cari_id=?'; $params[] = (int)$filters['cari_id']; }
    if (!empty($filters['start'])) { $where[] = 'pr.receivable_date>=?'; $params[] = $filters['start']; }
    if (!empty($filters['end'])) { $where[] = 'pr.receivable_date<=?'; $params[] = $filters['end']; }
    if (!empty($filters['q'])) { $where[] = '(pr.description LIKE ? OR c.name LIKE ? OR pr.document_name LIKE ?)'; $q = '%' . $filters['q'] . '%'; array_push($params, $q, $q, $q); }
    $sql = 'SELECT pr.status, SUM(pr.amount) AS total, COUNT(*) AS total_count FROM private_receivables pr JOIN cariler c ON c.id=pr.cari_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' GROUP BY pr.status';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $summary = ['acik'=>0,'kapandi'=>0,'iptal'=>0,'toplam'=>0,'count'=>0];
    foreach ($stmt->fetchAll() as $row) {
        $status = $row['status'] ?: 'acik';
        if (!array_key_exists($status, $summary)) $summary[$status] = 0;
        $summary[$status] += (float)($row['total'] ?? 0);
        $summary['count'] += (int)($row['total_count'] ?? 0);
    }
    $summary['toplam'] = $summary['acik'] + $summary['kapandi'];
    return $summary;
}

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


function cari_open_period_balance(?int $cariId): array
{
    $empty = [
        'alacak'=>0,'tahsilat'=>0,'net_alacak'=>0,
        'verecek'=>0,'odeme'=>0,'net_verecek'=>0,'net'=>0,
        'alacak_close_date'=>null,'verecek_close_date'=>null,
        'alacak_close_id'=>0,'verecek_close_id'=>0,
        'alacak_has_close'=>false,'verecek_has_close'=>false,
    ];
    if (!$cariId) return $empty;

    $stmt = db()->prepare("SELECT id, movement_type, amount, movement_date FROM movements WHERE cari_id = ? AND COALESCE(is_cancelled,0)=0 AND movement_type IN ('alacak','tahsilat','verecek','odeme') ORDER BY movement_date ASC, id ASC");
    $stmt->execute([$cariId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return $empty;

    $alacakRunning = 0.0;
    $verecekRunning = 0.0;
    $alacakCloseIndex = -1;
    $verecekCloseIndex = -1;
    $alacakCloseDate = null;
    $verecekCloseDate = null;
    $alacakCloseId = 0;
    $verecekCloseId = 0;

    foreach ($rows as $idx => $row) {
        $type = $row['movement_type'];
        $amount = (float)$row['amount'];
        if ($type === 'alacak') $alacakRunning += $amount;
        if ($type === 'tahsilat') $alacakRunning -= $amount;
        if ($type === 'verecek') $verecekRunning += $amount;
        if ($type === 'odeme') $verecekRunning -= $amount;

        if (($type === 'alacak' || $type === 'tahsilat') && abs(round($alacakRunning, 2)) < 0.005) {
            $alacakCloseIndex = $idx;
            $alacakCloseDate = $row['movement_date'] ?? null;
            $alacakCloseId = (int)$row['id'];
        }
        if (($type === 'verecek' || $type === 'odeme') && abs(round($verecekRunning, 2)) < 0.005) {
            $verecekCloseIndex = $idx;
            $verecekCloseDate = $row['movement_date'] ?? null;
            $verecekCloseId = (int)$row['id'];
        }
    }

    $period = $empty;
    $period['alacak_has_close'] = $alacakCloseIndex >= 0;
    $period['verecek_has_close'] = $verecekCloseIndex >= 0;
    $period['alacak_close_date'] = $alacakCloseDate;
    $period['verecek_close_date'] = $verecekCloseDate;
    $period['alacak_close_id'] = $alacakCloseId;
    $period['verecek_close_id'] = $verecekCloseId;

    foreach ($rows as $idx => $row) {
        $type = $row['movement_type'];
        $amount = (float)$row['amount'];
        if (($type === 'alacak' || $type === 'tahsilat') && $idx > $alacakCloseIndex) {
            $period[$type] += $amount;
        }
        if (($type === 'verecek' || $type === 'odeme') && $idx > $verecekCloseIndex) {
            $period[$type] += $amount;
        }
    }

    $period['net_alacak'] = $period['alacak'] - $period['tahsilat'];
    $period['net_verecek'] = $period['verecek'] - $period['odeme'];
    $period['net'] = $period['net_alacak'] - $period['net_verecek'];
    return $period;
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
    $where=["COALESCE(is_cancelled,0)=0"]; $params=[];
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
    $stmt = db()->prepare("SELECT COUNT(*) FROM checks WHERE COALESCE(is_cancelled,0)=0 AND status='bekliyor' AND due_date < ?");
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
function account_balance(int $accountId, ?string $asOfDate = null): float
{
    $asOfDate = $asOfDate ?: date('Y-m-d');
    $stmt = db()->prepare("SELECT opening_balance FROM accounts WHERE id=?");
    $stmt->execute([$accountId]);
    $opening = (float)($stmt->fetchColumn() ?: 0);
    $stmt = db()->prepare("SELECT direction, SUM(amount) AS total FROM account_transactions WHERE account_id=? AND transaction_date <= ? GROUP BY direction");
    $stmt->execute([$accountId, $asOfDate]);
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
function movement_effective_cash_date(array $movement): string
{
    $type = (string)($movement['movement_type'] ?? '');
    $dueDate = trim((string)($movement['due_date'] ?? ''));
    $movementDate = trim((string)($movement['movement_date'] ?? ''));
    if (movement_cash_direction($type) && $dueDate !== '') return $dueDate;
    return $movementDate !== '' ? $movementDate : date('Y-m-d');
}
function check_effective_cash_date(array $check): string
{
    $closedAt = trim((string)($check['closed_at'] ?? ''));
    if ($closedAt !== '') return substr($closedAt, 0, 10);
    return trim((string)($check['due_date'] ?? '')) ?: date('Y-m-d');
}
function cashflow_totals(?string $start = null, ?string $end = null): array
{
    $totals = ['in'=>0,'out'=>0,'net'=>0,'gelir'=>0,'tahsilat'=>0,'gider'=>0,'odeme'=>0,'cek_giris'=>0,'cek_cikis'=>0];
    $cashDate = "COALESCE(NULLIF(due_date,''), movement_date)";
    $where = ["COALESCE(is_cancelled,0)=0", "COALESCE(source_type,'') NOT IN ('check_acceptance','check_reversal')", "movement_type IN ('tahsilat','gelir','odeme','gider')"];
    $params = [];
    if ($start) { $where[] = $cashDate . ' >= ?'; $params[] = $start; }
    if ($end) { $where[] = $cashDate . ' <= ?'; $params[] = $end; }
    $stmt = db()->prepare('SELECT movement_type, SUM(amount) AS total FROM movements WHERE ' . implode(' AND ', $where) . ' GROUP BY movement_type');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $type = (string)$r['movement_type'];
        $amount = (float)$r['total'];
        if (isset($totals[$type])) $totals[$type] += $amount;
        $direction = movement_cash_direction($type);
        if ($direction) $totals[$direction] += $amount;
    }

    $checkDate = "date(COALESCE(NULLIF(closed_at,''), due_date))";
    $where = ["COALESCE(is_cancelled,0)=0", "status IN ('tahsil_edildi','odendi')"];
    $params = [];
    if ($start) { $where[] = $checkDate . ' >= ?'; $params[] = $start; }
    if ($end) { $where[] = $checkDate . ' <= ?'; $params[] = $end; }
    $stmt = db()->prepare('SELECT status, SUM(amount) AS total FROM checks WHERE ' . implode(' AND ', $where) . ' GROUP BY status');
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $amount = (float)$r['total'];
        if ($r['status'] === 'tahsil_edildi') { $totals['cek_giris'] += $amount; $totals['in'] += $amount; }
        if ($r['status'] === 'odendi') { $totals['cek_cikis'] += $amount; $totals['out'] += $amount; }
    }

    $totals['net'] = $totals['in'] - $totals['out'];
    return $totals;
}
function sync_movement_account_transaction(int $movementId): void
{
    db()->prepare("DELETE FROM account_transactions WHERE source_type='movement' AND source_id=?")->execute([$movementId]);
    $stmt = db()->prepare('SELECT m.*, c.name AS cari_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE m.id=?');
    $stmt->execute([$movementId]);
    $m = $stmt->fetch();
    if (!$m || (int)($m['is_cancelled'] ?? 0) === 1) return;
    if (is_check_source_movement($m['source_type'] ?? '')) return;
    $direction = movement_cash_direction($m['movement_type']);
    if (!$direction || empty($m['account_id'])) return;
    $desc = movement_label($m['movement_type']);
    if (!empty($m['cari_name'])) $desc .= ' - ' . $m['cari_name'];
    if (!empty($m['description'])) $desc .= ' / ' . $m['description'];
    $cashDate = movement_effective_cash_date($m);
    db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$m['account_id'], $direction, (float)$m['amount'], $cashDate, 'movement', $movementId, $desc, $m['created_by'] ?: (current_user()['id'] ?? null), now()]);
}
function sync_check_account_transaction(int $checkId): void
{
    db()->prepare("DELETE FROM account_transactions WHERE source_type='check' AND source_id=?")->execute([$checkId]);
    $stmt = db()->prepare('SELECT ch.*, c.name AS cari_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE ch.id=?');
    $stmt->execute([$checkId]);
    $ch = $stmt->fetch();
    if (!$ch || (int)($ch['is_cancelled'] ?? 0) === 1 || empty($ch['account_id'])) return;
    $direction = null;
    if ($ch['status'] === 'tahsil_edildi') $direction = 'in';
    if ($ch['status'] === 'odendi') $direction = 'out';
    if (!$direction) return;
    $desc = check_status_label($ch['status']) . ' - ' . check_direction_label($ch['direction']);
    if (!empty($ch['cari_name'])) $desc .= ' - ' . $ch['cari_name'];
    if (!empty($ch['check_no'])) $desc .= ' / Çek No: ' . $ch['check_no'];
    $cashDate = check_effective_cash_date($ch);
    db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([(int)$ch['account_id'], $direction, (float)$ch['amount'], $cashDate, 'check', $checkId, $desc, $ch['created_by'] ?: (current_user()['id'] ?? null), now()]);
}

function repair_account_sync(bool $withLog = true): array
{
    $pdo = db();
    $summary = [
        'deleted' => 0,
        'movement_synced' => 0,
        'check_synced' => 0,
    ];

    $pdo->beginTransaction();
    try {
        $summary['deleted'] = (int)$pdo->exec("DELETE FROM account_transactions WHERE source_type IN ('movement','check')");

        $movementIds = $pdo->query("SELECT id FROM movements
            WHERE COALESCE(is_cancelled,0)=0
              AND COALESCE(source_type,'') NOT IN ('check_acceptance','check_reversal')
              AND account_id IS NOT NULL
              AND movement_type IN ('tahsilat','gelir','odeme','gider')
            ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($movementIds as $movementId) {
            sync_movement_account_transaction((int)$movementId);
            $summary['movement_synced']++;
        }

        $checkIds = $pdo->query("SELECT id FROM checks
            WHERE COALESCE(is_cancelled,0)=0
              AND account_id IS NOT NULL
              AND status IN ('tahsil_edildi','odendi')
            ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($checkIds as $checkId) {
            sync_check_account_transaction((int)$checkId);
            $summary['check_synced']++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($withLog) {
        $detail = 'Silinen eski kaynak hareket: ' . $summary['deleted'] . ' · Hareket senkron: ' . $summary['movement_synced'] . ' · Çek senkron: ' . $summary['check_synced'];
        log_action('Kasa/Banka senkron kontrolü', $detail);
        audit_action('hesap_hareketi', null, 'guncellendi', null, $summary, 'Senkron kontrolü');
    }

    return $summary;
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
    if ($file['size'] > MAX_UPLOAD_BYTES) throw new RuntimeException('Belge en fazla 10 MB olabilir.');
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
function delete_uploaded_file(?string $relativePath): bool
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') return false;
    $base = realpath(UPLOAD_DIR);
    if (!$base) return false;
    $full = realpath(UPLOAD_DIR . '/' . $relativePath);
    if (!$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full)) return false;
    return @unlink($full);
}
function delete_replaced_upload(?array $oldDoc, array $newDoc): void
{
    $oldPath = trim((string)($oldDoc['path'] ?? ''));
    $newPath = trim((string)($newDoc['path'] ?? ''));
    if ($oldPath !== '' && $newPath !== '' && $oldPath !== $newPath) {
        delete_uploaded_file($oldPath);
    }
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

function system_create_backup_file(string $prefix = 'bitke-muhasebe-yedek', bool $includeUploads = true): ?string
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    $safePrefix = preg_replace('/[^a-z0-9\-]/i', '-', $prefix) ?: 'bitke-muhasebe-yedek';
    $name = $safePrefix . '-' . date('Ymd-His');
    $zipPath = BACKUP_DIR . '/' . $name . '.zip';
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            if (is_file(DB_PATH)) $zip->addFile(DB_PATH, 'bitke_muhasebe.sqlite');
            if ($includeUploads) {
                $uploadBase = realpath(UPLOAD_DIR);
                if ($uploadBase && is_dir($uploadBase)) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadBase, FilesystemIterator::SKIP_DOTS));
                    foreach ($it as $file) {
                        if ($file->isFile()) {
                            $rel = 'uploads/' . substr($file->getPathname(), strlen($uploadBase) + 1);
                            $zip->addFile($file->getPathname(), $rel);
                        }
                    }
                }
            }
            $zip->close();
            return $zipPath;
        }
    }
    $sqliteCopy = BACKUP_DIR . '/' . $name . '.sqlite';
    if (is_file(DB_PATH) && copy(DB_PATH, $sqliteCopy)) return $sqliteCopy;
    return null;
}

function backup_file_is_allowed(string $file): bool
{
    return (bool)preg_match('/^bitke-muhasebe-(yedek|otomatik|geri-yukleme-oncesi)-\d{8}-\d{6}\.(zip|sqlite)$/', basename($file));
}

function backup_file_list(): array
{
    $files = [];
    if (!is_dir(BACKUP_DIR)) return $files;
    foreach (glob(BACKUP_DIR . '/bitke-muhasebe-*.*') ?: [] as $f) {
        $bn = basename($f);
        if (backup_file_is_allowed($bn)) $files[] = ['name'=>$bn, 'size'=>filesize($f), 'time'=>filemtime($f)];
    }
    usort($files, fn($a,$b)=>$b['time']<=>$a['time']);
    return $files;
}

function cleanup_automatic_backups(int $keep = 5): void
{
    $autos = array_values(array_filter(backup_file_list(), fn($f) => strpos($f['name'], 'bitke-muhasebe-otomatik-') === 0));
    usort($autos, fn($a,$b)=>$b['time']<=>$a['time']);
    foreach (array_slice($autos, max(1, $keep)) as $f) {
        $path = BACKUP_DIR . '/' . $f['name'];
        if (is_file($path)) @unlink($path);
    }
}

function run_automatic_backup_if_due(): void
{
    static $checked = false;
    if ($checked || !is_logged_in()) return;
    $checked = true;
    $today = date('Y-m-d');
    if (setting_get('auto_backup_last_date', '') === $today) return;
    $lockUntil = (int)setting_get('auto_backup_lock_until', '0');
    if ($lockUntil > time()) return;
    setting_set('auto_backup_lock_until', (string)(time() + 600));
    try {
        $created = system_create_backup_file('bitke-muhasebe-otomatik', true);
        if ($created) {
            setting_set('auto_backup_last_date', $today);
            setting_set('auto_backup_last_file', basename($created));
            setting_set('last_backup_date', $today);
            setting_set('last_backup_file', basename($created));
            cleanup_automatic_backups(5);
            log_action('Otomatik yedek oluşturuldu', basename($created));
        }
    } catch (Throwable $e) {
        log_action('Otomatik yedek hatası', $e->getMessage());
    }
    setting_set('auto_backup_lock_until', '0');
}

// Veritabanını ilk istekte hazırlansın ve migration çalışsın.
db();
