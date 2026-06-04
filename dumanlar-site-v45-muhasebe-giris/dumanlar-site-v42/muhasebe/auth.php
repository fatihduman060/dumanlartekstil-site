<?php
require_once __DIR__ . '/config.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() === PHP_SESSION_NONE) {
    session_name('bitke_muhasebe_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/muhasebe',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['muhasebe_logged_in']) && $_SESSION['muhasebe_logged_in'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function current_user_name(): string
{
    return $_SESSION['muhasebe_display_name'] ?? 'Yönetici';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function login_is_locked(): bool
{
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;
    return $lockedUntil > time();
}

function login_lock_remaining(): int
{
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;
    return max(0, $lockedUntil - time());
}

function register_failed_login(): void
{
    $_SESSION['login_fail_count'] = ($_SESSION['login_fail_count'] ?? 0) + 1;

    if ($_SESSION['login_fail_count'] >= 5) {
        $_SESSION['login_locked_until'] = time() + 60;
        $_SESSION['login_fail_count'] = 0;
    }
}

function clear_login_failures(): void
{
    unset($_SESSION['login_fail_count'], $_SESSION['login_locked_until']);
}
