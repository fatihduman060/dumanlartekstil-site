<?php
require_once __DIR__ . '/bootstrap.php';
if (is_logged_in()) {
    log_action('Çıkış', 'Oturum kapatıldı');
}
destroy_session_cookie();
redirect('index.php?logged_out=1');
