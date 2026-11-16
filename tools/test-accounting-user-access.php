<?php
// Isolated permission check; no production database or account is opened.
$testUser = ['username'=>'MUHASEBE','display_name'=>'MUHASEBE','role'=>'editor'];
function current_user(): array { global $testUser; return $testUser; }
function db(): PDO { throw new RuntimeException('Database must not be opened by this test.'); }
function is_logged_in(): bool { return false; }
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function can_write(): bool { return in_array(current_user()['role'] ?? '', ['admin','editor'], true); }
require __DIR__ . '/../muhasebe/magaza-kullanici.php';

if (!is_accounting_user() || !can_access_warehouse_dispatch() || !can_process_warehouse_dispatch()) {
    throw new RuntimeException('MUHASEBE düzenleyicisine depo yetkisi verilmedi.');
}
$testUser = ['username'=>'BASKA','display_name'=>'MUHASEBE','role'=>'editor'];
if (is_accounting_user() || can_access_warehouse_dispatch() || can_process_warehouse_dispatch()) {
    throw new RuntimeException('Yetki başka düzenleyiciye açıldı.');
}
$testUser = ['username'=>'MUHASEBE','display_name'=>'MUHASEBE','role'=>'viewer'];
if (is_accounting_user() || can_access_warehouse_dispatch() || can_process_warehouse_dispatch()) {
    throw new RuntimeException('Düzenleme rolü olmayan kullanıcıya yetki açıldı.');
}
echo "PASS: only the MUHASEBE editor receives the new warehouse access.\n";
