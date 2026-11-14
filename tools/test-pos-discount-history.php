<?php
// Isolated in-memory test; never opens the live accounting database.
$testUser = ['username'=>'fatih','display_name'=>'Fatih Duman'];
function current_user(): array { global $testUser; return $testUser; }
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT,display_name TEXT)');
    $pdo->exec('CREATE TABLE cariler (id INTEGER PRIMARY KEY,name TEXT)');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY,username TEXT,action TEXT,created_at TEXT,new_value TEXT,entity_type TEXT,entity_id INTEGER)');
    $pdo->exec("INSERT INTO users VALUES (1,'fatih','Fatih Duman')");
    return $pdo;
}
function ensure_column(PDO $pdo,string $table,string $column,string $definition): void {
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $row) if ($row['name'] === $column) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}
function setting_get(string $key,string $fallback=''): string { return '1'; }
function setting_set(string $key,string $value): void {}
require __DIR__ . '/../muhasebe/barkod-satis-lib.php';
pos_db_ensure();
$pdo=db();
$pdo->exec("INSERT INTO pos_products (id,barcode,name,created_at,updated_at) VALUES (1,'123','Test çorap','2026-11-14','2026-11-14')");
$insert=$pdo->prepare("INSERT INTO pos_sales (id,receipt_no,sale_date,sale_time,payment_method,subtotal,discount_amount,grand_total,sale_type,is_cancelled,created_by,created_at) VALUES (?,?,?,'10:00:00','cash',100,?,?,?, ?,1,'2026-11-14 10:00:00')");
foreach ([
    [1,'POS-1','2026-11-14',10,90,'sale',0],
    [2,'POS-2','2026-11-14',0,100,'sale',0],
    [3,'POS-3','2026-11-14',10,90,'sale',1],
    [4,'POS-4','2026-11-14',10,90,'exchange',0],
    [5,'POS-5','2026-11-13',10,90,'sale',0],
] as $row) $insert->execute($row);
$item=$pdo->prepare("INSERT INTO pos_sale_items (sale_id,product_id,barcode,product_name,quantity,unit_price,line_total) VALUES (? ,1,'123','Test çorap',1,50,50)");
foreach ([1,1,2,3,4,5] as $saleId) $item->execute([$saleId]);
$sales=pos_history_audit('discounted_sales','2026-11-14');
if (count($sales)!==1 || (int)$sales[0]['id']!==1 || count($sales[0]['items'])!==2 || (float)$sales[0]['discount_amount']!==10.0) {
    throw new RuntimeException('İskontolu fiş filtresi veya ürünleri hatalı.');
}
$testUser=['username'=>'magaza','display_name'=>'Mağaza'];
try { pos_history_audit('discounted_sales','2026-11-14'); throw new RuntimeException('Mağaza kullanıcısı kayıtları gördü.'); }
catch (RuntimeException $e) { if ($e->getMessage()==='Mağaza kullanıcısı kayıtları gördü.') throw $e; }
echo "PASS: iskontolu satış ve ürünler, iptal/değişim/tarih filtresi, Fatih erişimi.\n";
