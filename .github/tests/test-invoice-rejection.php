<?php

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE invoices (
    id INTEGER PRIMARY KEY, direction TEXT, invoice_no TEXT, invoice_date TEXT,
    cari_id INTEGER, subtotal REAL, vat_amount REAL, total_amount REAL, currency TEXT,
    cari_movement_id INTEGER,
    is_cancelled INTEGER DEFAULT 0, posted_to_cari INTEGER DEFAULT 0,
    cancelled_at TEXT, cancelled_by INTEGER, cancel_reason TEXT,
    rejected_at TEXT, rejected_by INTEGER, updated_at TEXT
)");
$pdo->exec("CREATE TABLE movements (
    id INTEGER PRIMARY KEY, cari_id INTEGER, amount REAL, currency TEXT,
    movement_type TEXT, account_id INTEGER, is_cancelled INTEGER DEFAULT 0,
    cancelled_at TEXT, cancelled_by INTEGER, cancel_reason TEXT, updated_at TEXT
)");
$pdo->exec('CREATE TABLE warehouse_dispatches (id INTEGER PRIMARY KEY, cari_id INTEGER, dispatch_date TEXT, total REAL, currency TEXT, cari_movement_id INTEGER, posted_to_cari INTEGER DEFAULT 0, is_cancelled INTEGER DEFAULT 0, updated_at TEXT)');
$pdo->exec('CREATE TABLE sale_document_links (invoice_id INTEGER, dispatch_id INTEGER, movement_source TEXT)');
$pdo->exec('CREATE TABLE audit_logs (user_id INTEGER, username TEXT, entity_type TEXT, entity_id INTEGER, action TEXT, old_value TEXT, new_value TEXT, detail TEXT, ip TEXT, created_at TEXT)');

$stockSyncs = [];
$accountSyncs = [];
$stockFailureId = 0;
function db(): PDO { global $pdo; return $pdo; }
function now(): string { return '2026-11-12 12:00:00'; }
function current_user(): array { return ['id'=>7, 'username'=>'test']; }
function client_ip(): string { return '127.0.0.1'; }
function fatura_getir(int $id): ?array {
    $s = db()->prepare('SELECT * FROM invoices WHERE id=?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}
function fatura_hareket_acik_mi(int $id): bool {
    $s = db()->prepare('SELECT COUNT(*) FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $s->execute([$id]);
    return (int)$s->fetchColumn() > 0;
}
function sync_movement_account_transaction(int $id): void {
    global $accountSyncs;
    $accountSyncs[] = $id;
}
function stok_fatura_senkronla(int $id, string $direction, string $date, array $lines, bool $cancelled): void {
    global $stockSyncs, $stockFailureId;
    if ($id === $stockFailureId) throw new RuntimeException('Stok senkronizasyonu başarısız');
    $stockSyncs[] = [$id, $direction, $date, $lines, $cancelled];
}
function log_action(string $event, string $detail): void {}
function audit_action(string $type, ?int $id, string $event, $before, $after, string $detail): void {}

require_once dirname(__DIR__, 2) . '/muhasebe/satis-belge-bag-lib.php';
require_once dirname(__DIR__, 2) . '/muhasebe/fatura-numara-lib.php';
require_once dirname(__DIR__, 2) . '/muhasebe/fatura-red-lib.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expect_rejection(int $id, string $message): void {
    try { fatura_red_uygula($id); }
    catch (RuntimeException $e) { check(strpos($e->getMessage(), $message) !== false, $e->getMessage()); return; }
    throw new RuntimeException('Beklenen engel oluşmadı: ' . $message);
}
function insert_invoice(int $id, string $direction, string $number, int $movementId): void {
    $s = db()->prepare('INSERT INTO invoices(id,direction,invoice_no,invoice_date,cari_id,subtotal,vat_amount,total_amount,currency,cari_movement_id,posted_to_cari) VALUES(?,?,?,?,?,?,?,?,?,?,1)');
    $s->execute([$id,$direction,$number,'2026-10-19',100,1000.0,180.0,1180.0,'TL',$movementId]);
    db()->prepare('INSERT INTO movements(id,cari_id,amount,currency,movement_type) VALUES(?,?,?,?,?)')->execute([$movementId,100,1180.0,'TL',$direction==='giden'?'alacak':'verecek']);
}
function active_sum(string $field): float {
    return (float)db()->query("SELECT COALESCE(SUM($field),0) FROM invoices WHERE direction='giden' AND COALESCE(is_cancelled,0)=0")->fetchColumn();
}
function active_balance(): float {
    return (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM movements WHERE cari_id=100 AND movement_type='alacak' AND COALESCE(is_cancelled,0)=0")->fetchColumn();
}

db()->exec("INSERT INTO movements(id,cari_id,amount,currency,movement_type) VALUES(999,100,5000,'TL','alacak')");
insert_invoice(201, 'giden', 'TST2026000000001', 1087);
check(active_sum('vat_amount') === 180.0, 'Başlangıç KDV yanlış');
check(active_balance() === 6180.0, 'Başlangıç cari bakiyesi yanlış');
check(giden_fatura_numara_kayit_id('tst 2026-000000001') === 201, 'Numara normalleştirmesi yanlış');
$original = fatura_red_uygula(201);
check($original['invoice_no'] === 'TST2026000000001', 'Yanlış fatura işlendi');
$invoice = fatura_getir(201);
check((int)$invoice['is_cancelled'] === 1 && $invoice['rejected_at'] !== null, 'RED durumu kaydedilmedi');
check((int)$invoice['posted_to_cari'] === 0, 'Fatura cariye işlenmiş görünüyor');
check($invoice['invoice_no'] === 'TST2026000000001', 'Fatura numarası değişti');
check(!fatura_hareket_acik_mi(1087), 'Bağlı cari hareketi açık kaldı');
check(active_balance() === 5000.0, 'Eski cari bakiye korumadı');
check(active_sum('vat_amount') === 0.0, 'RED KDV toplamından çıkarılmadı');
check(giden_fatura_numara_kayit_id('TST2026000000001') === 201, 'RED numarası yeniden kullanılabilir görünüyor');
check(count($stockSyncs) === 1 && $stockSyncs[0][4] === true, 'Fatura stok düşümü kapatılmadı');
check($accountSyncs === [1087], 'Cari hesap senkronizasyonu yapılmadı');
expect_rejection(201, 'Aktif giden fatura');

insert_invoice(202, 'giden', 'TST2026000000002', 1088);
db()->exec("INSERT INTO warehouse_dispatches(id,cari_id,dispatch_date,total,currency,cari_movement_id,posted_to_cari) VALUES(5,100,'2026-10-19',1180.0,'TL',1088,1)");
db()->exec("INSERT INTO sale_document_links(invoice_id,dispatch_id,movement_source) VALUES(202,5,'invoice')");
fatura_red_uygula(202);
check((int)fatura_getir(202)['is_cancelled'] === 1 && !fatura_hareket_acik_mi(1088), 'Ortak hareket açık kaldı');
check(active_balance() === 5000.0, 'Depo bağlantısında eski cari bakiye değişti');
$dispatch = db()->query('SELECT * FROM warehouse_dispatches WHERE id=5')->fetch();
check((int)$dispatch['is_cancelled'] === 0 && $dispatch['cari_movement_id'] === null && (int)$dispatch['posted_to_cari'] === 0, 'Depo fişi ile iptal edilen hareket ayrılmadı');
check(satis_bag_get('invoice', 202) === null, 'Eski belge bağlantısı açık kaldı');

insert_invoice(205, 'giden', 'TST2026000000004', 1091);
db()->exec("INSERT INTO invoices(id,direction,invoice_no,invoice_date,cari_id,subtotal,vat_amount,total_amount,currency,cari_movement_id,posted_to_cari) VALUES(206,'giden','TST2026000000005','2026-10-19',100,1000.0,180.0,1180.0,'TL',1091,1)");
expect_rejection(205, 'başka aktif belgeyle ortak');
check((int)fatura_getir(205)['is_cancelled'] === 0 && fatura_hareket_acik_mi(1091), 'Başka faturanın ortak hareketi değişti');

insert_invoice(203, 'gelen', 'ALI2026000000001', 1089);
expect_rejection(203, 'Aktif giden fatura');

insert_invoice(204, 'giden', 'TST2026000000003', 1090);
$stockFailureId = 204;
expect_rejection(204, 'Stok senkronizasyonu başarısız');
check((int)fatura_getir(204)['is_cancelled'] === 0 && fatura_hareket_acik_mi(1090), 'Hata sonrası veritabanı geri alınmadı');

echo "Fatura RED testi geçti: cari, KDV, numara, depo koruması, tekrar ve geri alma.\n";
