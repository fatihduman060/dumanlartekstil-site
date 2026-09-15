<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();
require_write();
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM checks WHERE id=?');
$stmt->execute([$id]);
$check = $stmt->fetch();

function cek_gorseli_back(string $fallback = 'cekler.php'): void
{
    $back = trim((string)($_POST['back'] ?? ''));
    if ($back !== '' && strpos($back, "\n") === false && strpos($back, "\r") === false && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $back) && strncmp($back, '//', 2) !== 0) {
        redirect($back);
    }
    redirect($fallback);
}

if (!$check || (int)($check['is_cancelled'] ?? 0) === 1) {
    flash('error', 'Çek bulunamadı veya iptal edilmiş çek için görsel yüklenemez.');
    cek_gorseli_back('cekler.php');
}

$oldDoc = [
    'path' => $check['document_path'] ?? null,
    'name' => $check['document_name'] ?? null,
    'mime' => $check['document_mime'] ?? null,
];

try {
    $doc = handle_upload('document', $oldDoc);
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    cek_gorseli_back('cekler.php?direction=' . urlencode((string)$check['direction']));
}

if (empty($doc['path'])) {
    flash('error', 'Çek görseli için dosya seçmelisin.');
    cek_gorseli_back('cekler.php?direction=' . urlencode((string)$check['direction']));
}

db()->prepare('UPDATE checks SET document_path=?, document_name=?, document_mime=?, updated_at=? WHERE id=?')
    ->execute([$doc['path'], $doc['name'], $doc['mime'], now(), $id]);
delete_replaced_upload($oldDoc, $doc);
log_action('Çek görseli yüklendi', '#' . $id . ' ' . ($doc['name'] ?: 'Belge'));
audit_action('cek', $id, 'gorsel_yuklendi', $check, ['document_path'=>$doc['path'], 'document_name'=>$doc['name'], 'document_mime'=>$doc['mime']], 'Çek görseli');
flash('success', 'Çek görseli eklendi.');
cek_gorseli_back('cekler.php?direction=' . urlencode((string)$check['direction']));
