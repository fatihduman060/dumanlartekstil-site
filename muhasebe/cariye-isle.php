<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_write();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('dashboard.php');
require_csrf();

function ci_add_cols(string $table): void
{
    $pdo = db();
    ensure_column($pdo, $table, 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, $table, 'cari_movement_id', 'INTEGER');
    ensure_column($pdo, $table, 'posted_at', 'TEXT');
    ensure_column($pdo, $table, 'posted_by', 'INTEGER');
}

function ci_category(?string $name): ?int
{
    if (!$name) return null;
    try {
        $s = db()->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $s->execute([$name]);
        $id = (int)($s->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) { return null; }
}

function ci_active_movement(int $id): bool
{
    if ($id <= 0) return false;
    $s = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

function ci_make_movement(int $cariId, string $type, float $amount, string $date, string $method, string $desc, ?int $categoryId, ?string $docType): int
{
    $s = db()->prepare('INSERT INTO movements (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)');
    $now = now();
    $s->execute([$cariId, $categoryId, $type, $amount, $date, $method, $desc, $docType, current_user()['id'] ?? null, $now, $now]);
    return (int)db()->lastInsertId();
}

function ci_post_offer(int $id): int
{
    ci_add_cols('offers');
    $s = db()->prepare('SELECT * FROM offers WHERE id=? AND COALESCE(is_deleted,0)=0');
    $s->execute([$id]);
    $o = $s->fetch();
    if (!$o) throw new RuntimeException('Sipariş fişi bulunamadı.');
    if (ci_active_movement((int)($o['cari_movement_id'] ?? 0))) throw new RuntimeException('Bu sipariş fişi zaten cariye işlenmiş.');
    $cariId = (int)($o['cari_id'] ?? 0);
    if ($cariId <= 0) throw new RuntimeException('Cariye işlemek için sipariş fişinde cari seçilmiş olmalı.');
    $amount = (float)($o['grand_total'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Cariye işlenecek tutar bulunamadı.');
    if ((string)($o['currency'] ?? 'TL') !== 'TL') throw new RuntimeException('Cari hareketler TL çalışıyor. Cariye işlemek için para birimi TL olmalı.');
    $no = trim((string)($o['offer_no'] ?? ''));
    $title = trim((string)($o['document_title'] ?? 'SİPARİŞ FİŞİ')) ?: 'SİPARİŞ FİŞİ';
    $mid = ci_make_movement($cariId, 'alacak', $amount, $o['offer_date'] ?: date('Y-m-d'), 'Sipariş fişi', $title . ' no: ' . $no . ' / Ürün satışı', ci_category('Satış'), 'siparis_fisi');
    db()->prepare('UPDATE offers SET posted_to_cari=1, cari_movement_id=?, posted_at=?, posted_by=?, updated_at=? WHERE id=?')->execute([$mid, now(), current_user()['id'] ?? null, now(), $id]);
    log_action('Sipariş fişi cariye işlendi', $no . ' - ' . money($amount));
    audit_action('teklif', $id, 'cariye_islendi', $o, ['movement_id'=>$mid,'amount'=>$amount], $no);
    return $mid;
}

function ci_receipt_label(string $type): string
{
    return ['nakit'=>'Nakit','cek'=>'Çek','senet'=>'Senet','havale_eft'=>'Havale / EFT','kredi_karti'=>'Kredi Kartı'][$type] ?? $type;
}

function ci_post_receipt(int $id): int
{
    ci_add_cols('collection_receipts');
    $s = db()->prepare('SELECT * FROM collection_receipts WHERE id=? AND COALESCE(is_deleted,0)=0');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) throw new RuntimeException('Tahsilat makbuzu bulunamadı.');
    if (ci_active_movement((int)($r['cari_movement_id'] ?? 0))) throw new RuntimeException('Bu tahsilat makbuzu zaten cariye işlenmiş.');
    $cariId = (int)($r['cari_id'] ?? 0);
    if ($cariId <= 0) throw new RuntimeException('Cariye işlemek için makbuzda cari seçilmiş olmalı.');
    $amount = (float)($r['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Cariye işlenecek tahsilat tutarı bulunamadı.');
    if ((string)($r['currency'] ?? 'TL') !== 'TL') throw new RuntimeException('Cari hareketler TL çalışıyor. Cariye işlemek için para birimi TL olmalı.');
    $no = trim((string)($r['receipt_no'] ?? ''));
    $label = ci_receipt_label((string)($r['payment_type'] ?? 'nakit'));
    $desc = 'Tahsilat makbuzu no: ' . $no . ' / ' . $label . ' tahsilat';
    if (trim((string)($r['description'] ?? '')) !== '') $desc .= ' - ' . trim((string)$r['description']);
    $mid = ci_make_movement($cariId, 'tahsilat', $amount, $r['receipt_date'] ?: date('Y-m-d'), $label, $desc, ci_category('Tahsilat'), 'tahsilat_makbuzu');
    db()->prepare('UPDATE collection_receipts SET posted_to_cari=1, cari_movement_id=?, posted_at=?, posted_by=?, updated_at=? WHERE id=?')->execute([$mid, now(), current_user()['id'] ?? null, now(), $id]);
    log_action('Tahsilat makbuzu cariye işlendi', $no . ' - ' . money($amount));
    audit_action('tahsilat_makbuzu', $id, 'cariye_islendi', $r, ['movement_id'=>$mid,'amount'=>$amount], $no);
    return $mid;
}

$source = (string)($_POST['source_type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$back = safe_back_url($source === 'tahsilat' ? 'tahsilat-makbuzu.php' : 'teklif-ver.php');
try {
    if ($source === 'offer') {
        $mid = ci_post_offer($id);
        flash('success', 'Sipariş fişi cariye alacak olarak işlendi. Cari hareket no: #' . $mid);
    } elseif ($source === 'tahsilat') {
        $mid = ci_post_receipt($id);
        flash('success', 'Tahsilat makbuzu cariden düşüldü. Cari hareket no: #' . $mid);
    } else {
        throw new RuntimeException('Belge türü bulunamadı.');
    }
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}
redirect($back);
